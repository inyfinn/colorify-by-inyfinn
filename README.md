# Colorify by INYFINN

Kolory panelu WordPress (wp-admin) i strony logowania: gotowe schematy, własna paleta, tryb jasny i ciemny.
Ustawienia per użytkownik albo jedne dla całej witryny.

Autor: [INYFINN](https://inyfinn.art). Licencja: GPL-2.0-or-later.

**Interfejs wtyczki jest po polsku.** Wtyczka nie dostarcza tłumaczeń (nie ma plików `.mo`); angielskie panele
WordPressa zobaczą polskie teksty Colorify.

## Wymagania

- WordPress 7.0 lub nowszy
- PHP 7.4 lub nowszy

Na starszym środowisku wtyczka pokazuje komunikat w panelu i nie ładuje reszty (front strony działa bez zmian).

## Jak działa

- **Tryb jasny.** Colorify rejestruje własny schemat kolorów przez mechanizm WordPressa (`wp_admin_css_color`)
  i ustawia zmienne `--wp-admin-theme-color*`. Menu boczne i górny pasek mają kolory schematu, treść zostaje jasna.
  Kolory nie przykrywają panelu setkami reguł `!important`.
- **Tryb ciemny.** Przelicza kolory silnik [Dark Reader](https://darkreader.org) (licencja MIT), dołączony do wtyczki
  (`assets/vendor/`). Jest ładowany tylko w trybie ciemnym. Gdy się nie uruchomi, panel zostaje czytelny: ciemna rama,
  jasna treść.
- **Strażnik kontrastu.** Mały skrypt mierzy teksty i poprawia te o zbyt niskim kontraście (poniżej 4.5:1; ikony
  poniżej 3:1) atrybutem i regułą, nie stylem inline. W trybie ciemnym obejmuje cały panel, w jasnym tylko menu
  boczne i górny pasek. Poprawka działa w stanie spoczynku: elementy najechane i wciśnięte zachowują własne kolory.
- **Schemat domyślny: Grafit i złoto** (ciemne menu i pasek `#1a1a1a`, złoty akcent `#b1976b`). Osoby z zapisanym
  własnym wyborem zachowują go.
- **Kafle na listach wpisów.** Na każdej liście (Wpisy, Strony, własne rodzaje treści) przełącznik "Kafle / Lista"
  i suwak wielkości kafli. Administrator w trybie "Dostosuj kafelki" wybiera, co widać na kafelku. Wtyczki stron dokładają
  własne pola filtrem `colorify_list_tile_fields`.
- **Ograniczenie:** edytor blokowy działa w ramce (iframe), do której Colorify nie sięga; obszar edycji treści
  zostaje jasny.

## Instalacja

1. Pobierz `colorify-by-inyfinn.zip` z [wydań na GitHubie](https://github.com/inyfinn/colorify-by-inyfinn/releases).
2. **Wtyczki, Dodaj nową, Wyślij wtyczkę**, wskaż plik, włącz. Albo: `wp plugin install colorify-by-inyfinn.zip --force --activate`.
3. Kolory wybierasz w **Użytkownicy, Profil** (swoje) i **Ustawienia, Colorify** (wygląd witryny, strona logowania,
   wybór: każdy osobno albo jeden wygląd dla wszystkich).

Wtyczka nie może działać razem z modułem MU `colorify/` (komunikat w panelu): działa jeden silnik naraz.

## Aktualizacje

Wtyczka aktualizuje się jak zwykłe wtyczki WordPressa, z GitHub Releases. Nagłówek `Update URI` wskazuje
repozytorium, a WordPress przy swoim zwykłym sprawdzaniu (mniej więcej co 12 godzin) pyta GitHuba o ostatnie wydanie.
Nowa wersja pokazuje się paskiem "Dostępna jest nowa wersja" na ekranie Wtyczki, instaluje ją "Zaktualizuj teraz"
(albo `wp plugin update colorify-by-inyfinn`) i wtyczka zostaje włączona.

- **Od razu:** ekran **Aktualizacje, Sprawdź ponownie** (albo link "Sprawdź aktualizacje" w wierszu wtyczki
  i w Ustawieniach) czyści cache wydania i pyta ponownie. Cache trwa 11 godzin (krócej niż cykl WordPressa).
- **Z wersji 1.x:** stary kod nie ma przycisku sprawdzania. Raz kliknij link **Aktualizuj** w wierszu wtyczki
  (albo użyj `wp plugin install ... --force`). Od 2.0 aktualizacje przychodzą same.
- **Bezpieczeństwo paczki:** instalowany jest wyłącznie asset `colorify-by-inyfinn.zip` z adresu
  `https://github.com/<repo>/releases/download/` (nigdy zipball). Gdy API GitHuba podaje sumę SHA-256 assetu,
  pobrany plik musi się z nią zgadzać, inaczej instalacja jest wstrzymana (brak sumy: instalacja jak dotąd).
  Paczka o złym katalogu głównym albo brakującym pliku głównym nie jest instalowana (rdzeń przywraca poprzednią wersję),
  a wydanie wymagające nowszego PHP lub WordPressa niż na stronie jest wstrzymywane.
- **Repozytorium** można zmienić w `wp-config.php` (np. na próbę na repozytorium testowym):

  ```php
  define( 'COLORIFY_GITHUB_REPO', 'wlasciciel/repozytorium' );
  ```

Zwykłe wydanie oznaczone na GitHubie jako Latest jest oferowane stronom. Pre-release nie jest (endpoint
`releases/latest` go nie zwraca).

### Kokpit "Kalendarz zleceń" (2.10.0)

Bento na 100% szerokości (CSS grid z nazwanymi obszarami: ≥1600 px, ≥1100 px, tablet, telefon): liczby w równych kafelkach, kalendarz miesiąca na całą szerokość, duży kafel "Najbliższe wesele" ze wszystkimi szczegółami (każda wartość kopiuje się kliknięciem), "Do zrobienia w tym tygodniu", "Najbliższe" i "Zadatki do wpłaty" (listy po 6 pozycji i "Pokaż wszystkie", bez przewijania w kafelkach). Wydarzenia mają ikony rodzaju i kolor statusu; najechanie, fokus albo dotyk otwiera kartę szczegółów (znika po 0,5 s po opuszczeniu, nie znika po powrocie), z przyciskami "Skopiuj informacje" (tekst do Messengera), "Otwórz zlecenie", "Zadzwoń", "Trasa" i "Poprzednie/Następne" po wydarzeniach w czasie. Miesiąc i rok zmieniasz kliknięciem w nagłówek (siatka miesięcy + pole roku), strzałkami ←/→ (miesiąc) i Shift + ←/→ (rok). Na telefonie siatka zastępowana jest listą dni (agenda).

## Filtry

| Filtr | Domyślnie | Do czego |
|-------|-----------|----------|
| `colorify_contrast_guard` | `true` | `false` wyłącza strażnika kontrastu w trybie ciemnym. |
| `colorify_dark_engine_screens` | pusta lista | Identyfikatory ekranów (np. `post`), na których silnik trybu ciemnego się nie włącza: ekran zostaje jasny i czytelny, reszta panelu jest ciemna. Wyjście awaryjne, gdy ciemny tryb zwalnia ciężki ekran. |
| `colorify_calendar_events` | pusta lista | Wydarzenia dla kokpitu "Kalendarz zleceń" i "Rezerwacje". Argumenty: `$events`, `$from` (Y-m-d), `$to` (Y-m-d). Element: `date` (Y-m-d), `title`, `url`, `type` (`wesele`, `kontakt`, `oddanie`, `przygotowanie`, `blokada` albo własny), `status` (np. `nowe`, `wstepnie`, `umowa`, `zadatek`, `zrealizowane`), `amount` (liczba lub null), `meta`. Opcjonalnie, dla pełnej karty szczegółów: `id` (zlecenia; łączy przypomnienia ze zleceniem), `phone`, `email`, `route_url`, `status_label`, `reminder_done`, `details` (lista `[ 'label' => ..., 'value' => ... ]`: Miejsce, Sala, Dojazd, Pakiet, Kwota...; każda wartość da się skopiować jednym kliknięciem). |
| `colorify_dashboard_kpis` | pusta lista | Liczby dla kokpitu "Kalendarz zleceń" i "Rezerwacje": lista elementów `label`, `value`, `hint`, `url`. |
| `colorify_calendar_capability` | `edit_posts` | Uprawnienie, które pozwala zobaczyć dane zleceń (imiona, telefon, e-mail) w kokpicie kalendarza. |
| `colorify_scrollbar_color` | `''` | Kolor uchwytu cienkiego paska przewijania w panelu (np. `#B8955A`); domyślnie akcent schematu. Można też ustawić zmienną CSS `--colorify-scrollbar-thumb`. |
| `colorify_about_redirect_pages` | `about.php`, `credits.php`, `freedoms.php`, `contribute.php` | Ekrany "Witaj" WordPressa, które przekierowują na kokpit (opcja w Ustawienia, Colorify: "Ekran powitalny WordPressa"). |
| `colorify_hide_wp_logo` | `true` | `false` zostawia logo WordPressa na pasku administratora. |
| `colorify_dark_literal_color_selectors` | próbki kolorów (`.wp-color-result` i podobne) | Selektory elementów, których kolory z atrybutu `style` tryb ciemny zostawia dosłownie (próbki kolorów nie mogą się przekolorować). |

## Znane ograniczenia

- **Limit API GitHuba:** 60 zapytań na godzinę na adres IP (bez tokenu). Przy wielu stronach za jednym IP sprawdzenie
  może się nie udać; błąd jest zapamiętany na 15 minut, potem następuje ponowna próba.
- **Edytor w ramce** (iframe) zostaje jasny (patrz wyżej).
- **Komunikat "pliki w trakcie aktualizacji"**: podczas ręcznego kopiowania plików (stary link "Aktualizuj", FTP)
  wtyczka przez chwilę sprawdza kompletność plików i nic nie ładuje. Odśwież stronę; jeśli komunikat nie znika,
  zainstaluj wtyczkę ponownie. To samo zobaczysz, gdy pliki zostały zmienione po instalacji (np. przesłane
  w trybie tekstowym FTP).
- Dark Reader dokłada skrypt około 350 KB w panelu, tylko w trybie ciemnym.
- **Wyłączona wtyczka nie dostaje ofert aktualizacji** (updater działa tylko, gdy wtyczka jest włączona).
  W multisite włączaj Colorify sieciowo; włączony na jednej podstronie pokazuje i chowa ofertę zależnie od tego,
  która witryna uruchomiła sprawdzenie.
- **Suma kontrolna chroni transport, nie konto GitHub.** Suma pochodzi z tej samej odpowiedzi API co adres paczki:
  wykryje uszkodzony albo podmieniony plik po drodze, nie wykryje wydania opublikowanego z przejętego konta.
- Przycisk "Aktualizuj" w Ustawieniach i "Zaktualizuj teraz" na ekranie Wtyczki instalują przez ścieżkę zbiorczą rdzenia
  (tryb konserwacji, wtyczka zostaje włączona, kontrola wymagań). Błąd instalacji nie wyłącza Colorify.

## Wydanie nowej wersji

1. Podbij wersję w dwóch miejscach `colorify-by-inyfinn.php`: nagłówek `Version:` i stała `COLORIFY_PLUGIN_VERSION`
   (muszą być równe).
2. Dopisz sekcję `## <wersja>` na górze `CHANGELOG.md` (jej treść trafia do opisu wydania; wersja z sufiksem
   `-rc1` bez własnej sekcji bierze sekcję wersji bazowej).
3. Zatwierdź i wypchnij zmiany (`git push`): `release.sh` wymaga, żeby commit istniał na zdalnym repo
   i żeby tagu `v<wersja>` jeszcze tam nie było.
4. `tools/release.sh <wersja> [owner/repo] --dry-run` buduje paczkę i pokazuje, co zostanie opublikowane;
   bez `--dry-run` tworzy wydanie `v<wersja>` z assetem `colorify-by-inyfinn.zip`. `--prerelease` oznacza je jako
   pre-release (strony go nie dostaną).

`node tools/build-zip.mjs <wersja>` buduje samą paczkę `dist/colorify-by-inyfinn.zip`: jeden katalog główny
`colorify-by-inyfinn/`, bez `tools/`, `docs/`, `.git*`, `node_modules`, `dist/`. Odmawia, gdy nagłówek i stała
wersji różnią się od argumentu, i pilnuje listy plików sprzątanych po starszych wersjach (`ORPHANS` w updaterze).
Czego nie robić przy wydaniach:

- **Nie zmieniaj nazwy konta ani repozytorium** `inyfinn/colorify-by-inyfinn`. Adres paczki musi zaczynać się od
  `https://github.com/<repo>/releases/download/`, więc po zmianie nazwy strony przestają widzieć wydania. Przy
  przenosinach najpierw wydaj wersję z nową domyślną stałą `COLORIFY_GITHUB_REPO`.
- **Nie podmieniaj assetu opublikowanego wydania.** Strony z zapamiętaną ofertą dostają błąd sumy kontrolnej przez
  do 12 godzin. Błąd w paczce naprawiaj nowym numerem wersji.
- Wydanie oznaczone jako Latest trafia od razu do stron z 1.x (ich stary updater nie zna wymagań): sprawdź wersje
  WordPressa na tych stronach przed wydaniem.

`tools/mock-release/` to atrapa GitHuba do prób aktualizacji (tylko narzędzie testowe, patrz jego README).

## Licencje

- Colorify: GPL-2.0-or-later.
- Dark Reader (`assets/vendor/darkreader.js`): MIT, patrz `assets/vendor/darkreader.LICENSE.txt`.
- EB Garamond i IBM Plex Sans (`assets/fonts/`): SIL Open Font License 1.1, patrz `assets/fonts/EBGaramond-OFL.txt`
  i `assets/fonts/IBMPlexSans-OFL.txt`.

## Wtyczki stron klientów

Folder `site-plugins/` trzyma wtyczki pojedynczych stron w stylu Colorify (np. `kw-uslugi` dla Kancelarii Wieczorek). Nie wchodzą do paczki Colorify; każde wydanie dołącza je jako osobne pliki ZIP. Szczegóły: `site-plugins/README.md`.
