# Analityka kokpitu Colorify: dane, pomiar, API

Warstwa danych pod widok analityki w kokpicie. Interfejs jej nie liczy, tylko czyta przez funkcje z punktu 4.
Decyzje produktowe: SPEC rady z 12.11.2026 (odsłony bez botów i zalogowanych, dzienny szacunek odwiedzających,
bez czasu na stronie, jedno źródło odsłon na instalację).

## 1. Pliki

| Plik | Rola |
|------|------|
| `includes/colorify-analytics.php` | tabele, kolektor odsłon (PHP), szacunek odwiedzających, zapis, migracja starego licznika, cron, dziennik odsłon (strona), odinstalowanie, akapit polityki prywatności. Ładuje trzy pliki niżej. |
| `includes/colorify-analytics-rules.php` | reguły wspólne z odbiornikiem: bot czy przeglądarka, urządzenie, klasa źródła. Bez efektów ubocznych. |
| `includes/colorify-analytics-query.php` | API zapytań dla kokpitu (punkt 4). |
| `includes/colorify-analytics-cli.php` | `wp colorify-analytics …` (ładowany tylko w WP-CLI). |
| `includes/colorify-beacon.php` | odbiornik dziennika odsłon, działa **bez WordPressa** (tylko strony z cache całych stron). |
| `uninstall.php` | przy usunięciu wtyczki: tabele, opcje i pliki analityki. |
| `tools/test-analytics.php` | testy: `wp eval-file tools/test-analytics.php` (tabele testowe, sprząta po sobie). |

Ładowanie: `require_once COLORIFY_PLUGIN_DIR . 'includes/colorify-analytics.php';` w `colorify-by-inyfinn.php`
(po `colorify-dashboard.php`). Obecność sprawdzaj `function_exists( 'colorify_analytics_totals' )`.

## 2. Co i jak liczymy

**Odsłona** (metoda `php1`, „Licznik serwerowy”): `template_redirect` z priorytetem 99, czyli po przekierowaniach
kanonicznych. Liczy się zwykłe GET strony publicznej od niezalogowanej przeglądarki.

Nie liczymy (nic nie trafia do tabel): zalogowanych, panelu, AJAX, REST, cron, kanałów RSS, `robots.txt`, favicon,
mapy witryny, osadzeń (embed), trackbacków, podglądów (`preview`, `?ej_podglad`, `?colorify_tresci_podglad`,
Customizer), żądań innych niż GET i HEAD.

Liczymy osobno jako **boty** (`bots`, kolumna w `daily_totals`): HEAD, pusty albo krótki UA, UA spoza rodziny
`Mozilla/`/`Opera/`, UA z listy (`Colorify_Analytics_Rules::BOT_RE`: boty wyszukiwarek, SEO, AI, podglądy linków,
narzędzia pomiarowe, biblioteki HTTP, adres lub e-mail w UA), brak `text/html` w Accept, skanery plików przy 404
(`.php`, `.env`, kopie, `wp-content/` itd.).

**Prefetch.** WordPress (6.8+) wstawia `speculationrules` z `prefetch` i `conservative`: przeglądarka pobiera stronę
przy rozpoczęciu kliknięcia i potem pokazuje ją bez drugiego żądania. Dlatego `Sec-Purpose: prefetch` jest liczone
jako odsłona (z osobnym licznikiem `prefetch`). Przy przeglądarkach z prefetchem (conservative) odsłona liczy się
przy rozpoczęciu kliknięcia; anulowane kliknięcie może dać pojedynczą nadwyżkę.
Pomijane (licznik `spec`): `prefetch;prerender`, `prefetch;anonymous-client-ip` (wyniki Google przez proxy),
`Purpose: prefetch`, `X-Purpose: preview`, `X-Moz: prefetch`.

**404** od ludzi idą do `daily_errors` (ścieżka bez parametrów, z wielkością liter) z odsyłaczem: domena,
`internal:/ścieżka/` (link na tej stronie, do naprawy) albo pusty (bez odnośnika). Nie są odsłonami.

**Odwiedzający dziennie · szacunek.** Dla każdej odsłony liczymy HMAC-SHA-256(IP|UA) z losowym kluczem dnia
(opcja `colorify_an_secret_RRRRMMDD`, tworzona atomowo przez pierwsze żądanie dnia) i zapisujemy 16 bajtów w
`daily_seen` (sam dzień i skrót, bez strony, źródła i czasu). Pierwszy raz w dniu = +1 w `daily_totals.visitors`.
Klucz i skróty z poprzednich dni kasuje pierwsze żądanie nowego dnia (zapas: cron co godzinę), więc skrót żyje
najwyżej ok. 25 godzin (limit z SPEC: 48). Wspólne IP i przeglądarka łączą różnych ludzi, zmiana sieci rozdziela
jedną osobę, a powracający liczą się każdego dnia od nowa: to szacunek dzienny, **nigdy „unikalni” w zakresie**.

**Źródło** (z nagłówka Referer, w chwili żądania; zapisujemy tylko klasę i domenę, bez ścieżki):
`search` (wyszukiwarki i asystenci AI z wyszukiwaniem: Google, Bing, DuckDuckGo, ChatGPT, Perplexity…),
`social` (Facebook, Instagram, X, LinkedIn, Pinterest, TikTok, YouTube, Reddit, Wykop, komunikatory…),
`referral` (inne domeny), `internal` (ta sama strona), `direct_unknown` (brak sygnału).
Bez Referera, ale z parametrem kliknięcia w adresie: `fbclid` → social facebook.com, `igshid` → instagram.com,
`gclid`/`gbraid`/`wbraid` → search google.com, `msclkid` → bing.com (sama nazwa parametru, bez wartości).
Aplikacje Androida (`android-app://pakiet`) mapowane w `APPS`. UTM: `utm_source`, `utm_medium`, `utm_campaign`
po oczyszczeniu (małe litery, cyfry, `._-`, do 40 znaków), inne parametry są odrzucane.
**Wejście z zewnątrz** = odsłona z klasą search/social/referral, przypisana do strony docelowej (`daily_pages.entries`).

**Urządzenie** z UA w chwili żądania: `mobile`, `tablet`, `desktop`, `unknown`. iPadOS 13+ przedstawia się jako Mac,
więc liczy się jako komputer. UA nie jest zapisywany.

**Klucz strony**: ID wpisu/strony (cyfry) dla pojedynczych treści i strony głównej-strony, `'/?s'` dla wyników
wyszukiwania, znormalizowana ścieżka dla reszty (bez parametrów, bez `/page/N/` i `/comment-page-N/`, małe litery,
do 191 znaków), `'*'` = „Inne”.

**Limity dzienne** (nadmiar → `'*'`, „Inne”): 300 ścieżek nie będących ID, 100 domen odsyłających, 50 wartości
każdego UTM, 300 wierszy 404. ID wpisów nie mają limitu (jest ich tyle, ile treści).

Czego nie zapisujemy nigdzie: IP, pełnego UA, pełnych URL-i i Refererów, parametrów poza UTM, treści formularzy,
identyfikatorów, ciasteczek, nic w przeglądarce.

## 3. Tabele (prefiks `{$wpdb->prefix}colorify_`, schemat 1, InnoDB, utf8mb4)

| Tabela | Klucz główny | Kolumny |
|--------|--------------|---------|
| `daily_totals` | day, method | views, visitors (szacunek), bots, prefetch (w tym w views), spec (pominięte), state (`ok`, `partial` = dzień startu metody, `cache` = licznik PHP przy wykrytym cache, `legacy`) |
| `daily_pages` | day, method, page | views, entries (wejścia z zewnątrz); KEY (page, day) |
| `daily_breakdowns` | day, method, page, dim, val | views; page `''` = cała strona; dim: `source_class`, `source_domain`, `device` (cała strona i podstrona), `utm_source`/`utm_medium`/`utm_campaign` (cała strona) |
| `hourly_totals` | day, method, hour | views; dzień i godzina w strefie WordPressa (ponytail: przy cofnięciu zegara dwie godziny 2:00 łączą się w jedną) |
| `daily_errors` | day, method, path, ref | hits |
| `daily_seen` | day, h (binary 16) | tylko skróty dnia bieżącego |

Każdy zapis to `INSERT … ON DUPLICATE KEY UPDATE x = x + n` (atomowy, bez gubienia równoległych wejść; test: 30
równoległych żądań = 30). Nieudany zapis zwiększa `colorify_an_lost` (karta jakości). Brak tabel (np. po
przywróceniu bazy) = ponowna instalacja przy następnym żądaniu.

**Rejestr metod** (opcja `colorify_an_registry`, autoload): `methods` = ciągłe przedziały dni `[method, from, to,
started]`, dla każdego dnia prawdą jest dokładnie jedna metoda, a raporty czytają tylko jej wiersze (nigdy suma).
Metody: `legacy` (stary licznik z opcji `colorify_visits`), `php1` (licznik serwerowy), `log1` (dziennik odsłon).
Dodatkowo: `cache` (wynik detekcji), `beacon` (`auto`/`on`/`off`), `legacy_final`, `daily`.

**Migracja starego licznika**: przy instalacji dni z `colorify_visits` (opcja zostaje nietknięta) trafiają do
`daily_totals`/`daily_pages` z metodą `legacy`; stary licznik jest prawdą do dnia instalacji włącznie, nowy od
następnego dnia (pierwszy pełny). Dzień instalacji dochodzi kolejnym przebiegiem dziennym, gdy się skończy.
Stary licznik nie zapisywał dni bez wejść, więc w jego okresie są luki (`measured: false`), oraz liczył część botów.

**Cron** `colorify_an_cron` co godzinę: wiersz dnia z zerami („pomiar działał”, dzień bez wejść to 0, nie luka),
kasowanie kluczy i skrótów z poprzednich dni, agregacja dziennika odsłon, retencja (800 dni, partiami po 1000
wierszy), raz dziennie: dokończenie migracji, detekcja cache, ewentualna zmiana metody. Na hostingu bez ruchu
WP-Cron może nie ruszać: cron hostingu co 15 minut
`cd ~/domains/DOMENA/public_html && wp cron event run --due-now >/dev/null 2>&1`
(albo `wget -q -O /dev/null "https://DOMENA/wp-cron.php?doing_wp_cron"`).

## 4. API zapytań (dla interfejsu)

Wspólne zasady:

- daty `'Y-m-d'` w strefie WordPressa, zakresy domknięte z obu stron; wynik SQL w cache 5 minut (transient);
- **uprawnień funkcje nie sprawdzają**: wywołujący sprawdza `current_user_can()` przed wywołaniem;
- wyniki to tablice bez HTML; teksty PL są zwykłym tekstem z twardymi spacjami (U+00A0): wyświetlaj `esc_html()`;
- brak pomiaru = `null` i `measured: false`, nigdy 0; `low_sample: true`, gdy liczba podstawowa < 50
  (`COLORIFY_ANALYTICS_LOW_SAMPLE`): pokaż liczbę, ale bez wniosków i bez procentów zmian;
- przekroje (źródła, urządzenia, godziny, wejścia, 404) istnieją od `measured_from`; `partial: true`, gdy zakres
  zaczyna się wcześniej (stary licznik ich nie miał) — pokazuj „Zbieramy od …”, nie zero.

### colorify_analytics_range( array $args ): array

`days` 7|30|90|365 (domyślnie 30), `today` (bool, dokleja dzisiejszy niepełny dzień), albo `from` + `to`
(własne daty; `to` = dziś włącza `with_today`; ponad 800 dni = przycięte). Preset = pełne dni do wczoraj.

```php
colorify_analytics_range( array( 'days' => 7 ) );
// ['from'=>'2026-11-05','to'=>'2026-11-11','days'=>7,'preset'=>7,'with_today'=>false,'today'=>'2026-11-12',
//  'prev_from'=>'2026-10-29','prev_to'=>'2026-11-04','error'=>'']   // error: tekst PL, gdy zakres poprawiono
```

### colorify_analytics_totals( string $from, string $to ): array

```php
['from','to','days','measured_days','views','views_per_day',
 'visitors_avg'   => 1.5,    // średnia dzienna szacunku przez dni zmierzone (dni z zerem wliczone); null: metoda bez szacunku
 'visitors_sum'   => 3,      // „suma dziennych szacunków” (do szczegółów, z wyjaśnieniem), null jak wyżej
 'bots'           => 4,      // null dla starego licznika
 'bots_share'     => 0.33,   // boty / (boty + odsłony)
 'prefetch_views' => 1,
 'methods'        => ['php1'],
 'prev'           => ['from','to','measured_days','views','visitors_avg'],
 'compare_allowed'=> false,
 'compare_reason' => 'method_change', // '' | partial_today | no_history | gaps | method_change
 'change'         => null,   // odsłony teraz − poprzednio, tylko gdy compare_allowed
 'change_pct'     => null,   // tylko gdy poprzednio ≥ 50
 'change_visitors_avg' => null,
 'low_sample'     => true]
```

Porównanie jest dozwolone tylko, gdy oba okresy są w całości zmierzone tą samą metodą, a zakres kończy się przed dziś.

### colorify_analytics_series( string $from, string $to, string $step = 'day' ): array

`['from','to','step','points'=>[…],'annotations'=>[['date','type'=>'method','label']],'max','low_sample']`.
Punkt dzienny: `['date','views'|null,'visitors'|null,'bots'|null,'measured','method','state']`.
Tydzień (`week`, od poniedziałku): `['date','end','views','visitors_sum','measured_days','days','partial']`.

### colorify_analytics_pages( string $from, string $to, array $args = [] ): array

`$args`: `sort` (views|change|title|entries), `order` (asc|desc), `search` (tytuł albo ścieżka), `page` (od 1),
`per_page` (1–500, domyślnie 50). Wynik: `['rows','total_rows','sum_views','page','per_page','pages',
'compare_allowed','compare_reason','low_sample']`. Wiersz = `page_info` + `['views','entries','share' (0–1),
'prev_views'|null,'change'|null]`. Przy dozwolonym porównaniu są też strony, które spadły do zera.

### colorify_analytics_page_info( string $key ): array

`['key','title','url','edit_url','type','front']`. `type`: typ wpisu (`page`, `post`…), `path` (archiwum, inny
adres), `search`, `other` (`'*'`), `deleted`. `edit_url` zależy od uprawnień oglądającego (WordPress), więc
nie jest cache'owany. „Zobacz stronę” = `url`, „Edytuj treść” = `edit_url` (albo ekran Treści strony).

### colorify_analytics_page_report( string $key, string $from, string $to ): array

`['page'(page_info),'from','to','views','entries','share_of_site','prev_views','change','compare_allowed',
'compare_reason','series'=>[['date','views'|null,'measured']],'annotations','sources'(jak niżej),'devices',
'low_sample']`.

### colorify_analytics_sources( string $from, string $to, string $page = '' ): array

```php
['classes' => [['class'=>'search','label'=>'Wyszukiwarki','views'=>5,'share'=>0.71], /* social, referral, direct_unknown */],
 'domains' => [['domain'=>'google.pl','label'=>'google.pl','class'=>'search','views'=>5,'share'=>1.0]], // '*' = 'Inne domeny…'
 'utm'     => ['source'=>[['value'=>'newsletter','views'=>1]],'medium'=>[],'campaign'=>[]],
 'external_views'=>7,  // suma czterech klas (mianownik udziałów)
 'internal_views'=>12, // przejścia wewnątrz strony, bez udziału
 'measured_from'=>'2026-11-13','partial'=>false,'low_sample'=>true]
```

Kolejność klas stała. Udział domen liczony od search + social + referral.

### colorify_analytics_devices( string $from, string $to, string $page = '' ): array

`['devices'=>[['device'=>'mobile','label'=>'Telefon','views','share'], tablet, desktop, unknown],'views',
'measured_from','partial','low_sample']`.

### colorify_analytics_hours_grid( string $from, string $to ): array

`['timezone','grid'=>[7 × ['weekday'=>1..7 (pon.=1),'label'=>'pon.','hours'=>[24 liczby],'total','days']],
'by_hour'=>[24],'max','total','measured_from','partial','low_sample']`. `days` = ile takich dni tygodnia z danymi
jest w zakresie (do średnich). SPEC: domyślnie 90 dni, liczby i udział, bez porad.

### colorify_analytics_entry_pages( string $from, string $to, int $limit = 50 ): array

Strony z wejściami z zewnątrz (to nie jest pełny raport stron wejścia sesji):
`['rows'=>[page_info + ['entries','views','share']],'total_rows','entries','measured_from','partial','low_sample']`.

### colorify_analytics_errors404( string $from, string $to, int $limit = 100 ): array

```php
['rows' => [['path'=>'/stara-oferta/','url'=>'https://…/stara-oferta/','label'=>'/stara-oferta/','hits'=>3,'last_day'=>'2026-11-20',
             'refs'=>[['type'=>'internal','ref'=>'','from'=>'https://…/o-mnie/','label'=>'Link na tej stronie: /o-mnie/','hits'=>2],
                      ['type'=>'none','ref'=>'','from'=>'','label'=>'Bez odnośnika (zakładka, stary link, wpisany adres)','hits'=>1]]]],
 'total_rows','hits','measured_from','partial','low_sample']
```

`type`: `external` (domena w `ref`), `internal` (`from` = strona z błędnym linkiem, gdy znana), `none`.
SPEC: „Zaproponuj naprawę” otwiera formularz przekierowania z walidacją, nigdy automatyczne przekierowanie.

### colorify_analytics_coverage(): array

Karta jakości danych:

```php
['method'=>'legacy','method_label'=>'Stary licznik (do wersji 2.14)','method_about'=>'…definicja jednym zdaniem…',
 'next_method'=>'php1','next_from'=>'2026-11-13',     // zaplanowana zmiana metody ('' gdy brak)
 'since'=>'2026-10-21',                               // od kiedy obecna metoda
 'data_since'=>'2026-10-21',                          // „Dane dostępne od…”
 'dims_since'=>'2026-11-13',                          // źródła, urządzenia, godziny, 404 od
 'methods'=>[['method','label','about','from','to','started']],
 'gaps'=>[['from','to','days','method']],             // dni bez pomiaru do wczoraj
 'bots'=>['days','views','bots','share','ready'],     // ostatnie 7 pełnych dni; ready = 7 dni
 'prefetch'=>['counted','skipped','days'],
 'lost_writes'=>0,
 'cache'=>['detected'=>false,'signals'=>[],'checked'=>'2026-11-13 00:10'],
 'beacon'=>['setting'=>'auto','active'=>false,'supported'=>true],
 'state'=>'starting', // ok | starting | incomplete
 'state_label'=>'Licznik dopiero zaczął: pierwszy pełny dzień jutro',
 'views_30d'=>244,'low_sample'=>false,'low_sample_below'=>50,
 'engagement'=>['measured'=>false,'unlock'=>3000,'label'=>'Czasu na stronie nie mierzymy. Odblokuje się przy 3000 odsłon miesięcznie; teraz 244.']]
```

## 5. Dziennik odsłon (strony z cache całych stron)

Gdy strona serwuje cache całych stron, PHP nie widzi każdego wejścia. Wtedy (domyślnie `beacon = auto` i wykryty
cache; `on` wymusza, `off` wyłącza) w stopce strony ładuje się skrypt (ok. 600 bajtów, inline): po `load` albo
przy ukryciu karty wysyła raz `navigator.sendBeacon` (zapas: obrazek) do `includes/colorify-beacon.php` z polami
`t` (v/e), `k` (ID strony, `s` albo ścieżka), `r` (domena z document.referrer), `x` (nazwa parametru kliknięcia),
`us/um/uc` (UTM). Nie wysyła w prerenderze (czeka na aktywację), nie ponawia, nie używa ciasteczek ani pamięci
przeglądarki. Bez wykrytego cache skrypt się **nie ładuje** (tak jest na ej.patrz.com.pl).

Odbiornik działa bez WordPressa, tylko gdy istnieje `uploads/colorify-log/on.php`. Filtruje boty tą samą regułą
(bot = linia `b`, bez danych), sprawdza każde pole listą znaków, klasę urządzenia liczy z UA, czas zaokrągla do
5 minut, dopisuje linię (flock) do `uploads/colorify-log/log-RRRRMMDDGG.php` (plik godzinowy, z `<?php exit;`
na początku; katalog ma `.htaccess` deny all i `index.php`; z zewnątrz 403). Limit 20 MB na godzinę.
Bez IP, pełnego UA i identyfikatora, więc metoda `log1` **nie ma szacunku odwiedzających** (`visitors_avg: null`).

Cron co godzinę agreguje zamknięte pliki (2 minuty po końcu godziny) do tych samych tabel z metodą `log1`,
plik przenosi do `done-*.php` i kasuje po 48 godzinach. Detekcja cache (raz dziennie): `WP_CACHE`, znane wtyczki
cache, nagłówki odpowiedzi strony głównej (`x-litespeed-cache`, `x-cache`, `cf-cache-status` HIT, `x-varnish`…;
jedno żądanie z nagłówkiem `X-Colorify-Probe`, które kolektor pomija). Po wykryciu dziennik zbiera od razu, a prawdą
staje się od następnego dnia (rejestr metod, adnotacja na wykresie); po zniknięciu cache wraca licznik PHP.
Wymaga standardowego układu `wp-content/uploads` (inaczej `beacon.supported = false` i stan „Pomiar niepełny”).

Opis metody: „Strona korzysta z cache całych stron, więc odsłonę zgłasza przeglądarka krótkim sygnałem po
załadowaniu strony (bez IP i identyfikatora). Dlatego nie ma szacunku odwiedzających. Przeglądarki bez JavaScriptu
i część blokerów sygnału nie wyślą.” Do polityki prywatności dochodzi wtedy akapit „Sygnał odsłony” (punkt 6).

## 6. Polityka prywatności

`colorify_analytics_privacy_text( bool $heading = true, ?bool $beacon = null ): string` zwraca HTML (PL, prosty
język, twarde spacje): co jest liczone, czego nie zapisujemy, szacunek odwiedzających (skrót, klucz dnia, 48 h),
akapit o sygnale odsłony (gdy instalacja używa dziennika), retencja 800 dni, podstawa (art. 6 ust. 1 lit. f RODO).
Ten sam tekst trafia do przewodnika WordPressa (Ustawienia → Prywatność). Po włączeniu dziennika na stronie z
polityką wpisaną ręcznie trzeba dopisać akapit „Sygnał odsłony”.

## 7. WP-CLI

```
wp colorify-analytics status            # schemat, tabele, metoda i „dane od”, dziś, boty, cache, dziennik, cron, stary vs nowy licznik
wp colorify-analytics migrate           # ponowna migracja starego licznika (idempotentna)
wp colorify-analytics purge [--dry-run] # retencja 800 dni + skróty z poprzednich dni
wp colorify-analytics beacon <auto|on|off>
```

## 8. Testy

`wp eval-file tools/test-analytics.php` na żywej stronie: przestrzeń `colorifytest` (osobne tabele i opcje),
75 sprawdzeń (filtr botów 22 UA + 10 przeglądarek, prefetch, urządzenia, ścieżki, źródła, UTM, brak IP/UA/URL
w tabelach, HMAC i dedup, granice dni i stref, zakresy, migracja i jedno źródło na dzień, limity „Inne”, retencja,
agregacja dziennika, zmiana metody, polityka, odinstalowanie). Test współbieżności: token w opcji
`colorify_an_test_token` + nagłówek `X-Colorify-Analytics-Test` kieruje zapis do tabel testowych; 30 równoległych
`curl` z serwera = 30 odsłon.
