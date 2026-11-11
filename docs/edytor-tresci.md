# Moduł „Edytor treści” (od 2.11.0)

Panel **Treści strony** w wp-admin: teksty, zdjęcia (z kadrowaniem), filmy i listy strony w jednym miejscu, z podglądem
na żywo prawdziwej strony obok formularza, historią 10 wersji, bramką „nie nadpisuj, czego nie widziałeś” i poleceniem
WP-CLI dla agentów. Moduł jest **domyślnie wyłączony** (Ustawienia, Colorify, „Edytor treści”) i działa tylko z motywem,
który zarejestruje schemat filtrem `colorify_tresci_rejestr`. Bez schematu włączenie niczego nie zmienia.

Zasada: **jedno źródło prawdy.** Treści żyją w jednej opcji (tylko klucze różne od domyślnych ze schematu). Panel, WP-CLI
i agent AI piszą tą samą funkcją (`colorify_tresci_z_formularza()` + `colorify_tresci_zapisz()`), więc zmiana z jednej
strony jest od razu widoczna w drugiej i na stronie.

## 1. Rejestracja w motywie

```php
add_filter( 'colorify_tresci_rejestr', function ( $r ) {
	return array(
		'opcja'    => 'moja_tresci',            // nazwa opcji z danymi (historia: moja_tresci_historia)
		'slug'     => 'moja-tresci',            // slug menu (admin.php?page=moja-tresci)
		'cli'      => 'moja-tresci',            // alias polecenia WP-CLI obok wp colorify-tresci (opcjonalnie)
		'tytul'    => 'Treści strony',
		'ikona'    => 'dashicons-edit-page',
		'pozycja'  => 22,
		'cap'      => 'edit_pages',
		'sekcje'   => moj_schemat(),            // rozdział 2
		'listy'    => array(),                  // własne typy list (rozdział 3); faq, ig, punkty, menu są wbudowane
		'strony'   => array(                    // strony, które panel podgląda; klucz = wartość 'strona' sekcji
			'home'  => array( 'url' => home_url( '/' ), 'nazwa' => 'Strona główna', 'post' => (int) get_option( 'page_on_front' ), 'karta' => 'hero' ),
			'omnie' => array( 'url' => home_url( '/o-mnie/' ), 'nazwa' => 'O mnie', 'post' => 12, 'karta' => 'o1' ),
		),
		'plik_url' => 'moj_plik_url',           // callable: "img:plik.jpg" -> URL pliku motywu ('' gdy nieznane)
		'gdzie'    => array( array( 'Pakiety i ceny', 'Usługi', 'admin.php?page=uslugi', 'metka' ) ), // kafelki "Resztę edytujesz tutaj": tytuł, podpis, adres, ikona (metka, serce, koperta, podpis, ksiazka, tarcza, ciastko, dymek, nazwy z kokpitu albo <svg>)
		'lead'     => '',                       // zdanie pod tytułem (puste = domyślne)
		'fonty'    => '',                       // URL arkusza czcionek dla panelu (np. Google Fonts), puste = czcionki Colorify
		'przyciski_regex' => 'przycisk|link',   // klucze pól trafiające do grupy "Przyciski i linki"
	) + (array) $r;
} );
```

`post` w `strony` sprawia, że „Edytuj stronę” (lista Stron, pasek admina) otwiera panel na karcie `karta` zamiast pustego
edytora blokowego. `url` jest podglądany w iframe z parametrem `?colorify_tresci_podglad=1` (tylko zalogowani
z uprawnieniem `cap`).

## 2. Schemat: sekcje i pola

Sekcja = karta w panelu, w kolejności strony:

```php
'hero' => array(
	'title'   => 'Hero',
	'where'   => 'Pierwszy ekran strony głównej',
	'strona'  => 'home',           // klucz z 'strony' albo 'oba' (każda podstrona)
	'kotwica' => '.hero',          // selektor sekcji w podglądzie (przewijanie po otwarciu karty)
	'link'    => array( 'Ceny zmieniasz w Usługach', 'admin.php?page=uslugi' ), // opcjonalna karta-przekierowanie
	'kafel'   => array( 'typ' => 'hero', 'img' => array( 'hero_plakat' ), 'txt' => 'hero_tytul' ), // miniatura w nagłówku karty
	'fields'  => array(
		'hero_tytul'  => colorify_tresci_pole( 'text', 'Duży napis', 'Witaj!', 'Podpowiedź pod polem', array( 'req' => true ) ),
		'hero_tekst'  => colorify_tresci_pole( 'area', 'Akapit', 'Tekst z <em>kursywą</em>.<br>Nowa linia.', '', array( 'rows' => 4 ) ),
		'hero_plakat' => colorify_tresci_pole( 'media', 'Zdjęcie', 'img:hero.jpg', '', array(
			'kind' => 'image',
			'kadr' => array( 'r' => '1380/1000', 'rm' => '390/844', 'rl' => '1860/1000', 'xy' => array( 50, 50 ) ),
			'removable' => true,
		) ),
		'hero_film'   => colorify_tresci_pole( 'media', 'Film', 'media:hero.mp4', '', array( 'kind' => 'video' ) ),
		'hero_link'   => colorify_tresci_pole( 'url', 'Adres przycisku', home_url( '/#oferta' ) ),
		'faq'         => colorify_tresci_pole( 'faq', 'Pytania', array( array( 'q' => 'Pytanie?', 'a' => 'Odpowiedź.' ) ) ),
	),
),
```

Typy pól: `text` (jedna linia), `area` (akapit: lekki HTML `br`, `em`, `strong`, `span.class`, `a`; `'czysty' => true`
= bez formatowania, np. opis SEO; `'multi'` w kolumnie listy = akapity oddzielone pustą linią), `url`, `email`, `media`
(ID załącznika; domyślna to odwołanie do pliku motywu rozwiązywane przez `plik_url`; `'removable' => true` pozwala usunąć
zdjęcie: na stronie element znika), typy list (`faq`, `ig`, `punkty`, `menu` i własne). Pole z `'req' => true` nie może być
puste (puste = domyślna). Pole z `'grupa' => 'przyciski'` wymusza grupę w karcie.

**Kadr.** Pole `media` z `kadr` dostaje w panelu ramkę w proporcjach **dokładnie takich, jak na stronie** (`r` komputer,
`rm` telefon, `rl` opcjonalnie duży ekran, zmierzone na froncie; `xy` = domyślny punkt ostrości = `object-position`
z CSS), przeciąganie punktu ostrości, suwak przybliżenia 1–2,5× i wirtualne pole `<klucz>__kadr` = `array( x, y, z )`.
Na stronie szablon drukuje `colorify_tresc_kadr_style( 'hero_plakat' )` w tagu `<img>` / `<video>`: inline
`object-position` oraz przy `z > 1` `transform: scale` + `transform-origin` + `clip-path: inset` (przycięcie zostaje
w ramce elementu, żadnych wrapperów). Bez zapisanego kadru helper nie drukuje nic, więc HTML jest taki jak dotąd.

**Kafel** (`kafel.typ`): `hero` (zdjęcie w tle + napis), `zdjecia` (rząd zdjęć), `rolki` (pionowe plakaty), `boksy`
(napis + `ile` kafelków), `ciemny` (ciemne tło + napis), `cytat`, `linie`, `pasek` (`lista` => klucz listy ze zdjęciami,
`kolumna` domyślnie `img`), `logo`, `seo`, `tekst` (napis + linie + zdjęcie). Bez `kafel`: `linie` z pierwszym polem
tekstowym sekcji. Napisy i zdjęcia kafli aktualizują się w trakcie pisania.

## 3. Listy

Wbudowane: `faq` (q, a), `ig` (img z kadrem 4/5, url, alt), `punkty` (t), `menu` (t, u). Własny typ:

```php
'listy' => array( 'zespol' => array(
	'imie'  => array( 'text', 'Imię' ),                     // pierwsza kolumna wymagana (pusta = wiersz pominięty)
	'opis'  => array( 'area', 'Opis', array( 'rows' => 3 ) ),
	'foto'  => array( 'media', 'Zdjęcie', array( 'kind' => 'image', 'kadr' => array( 'r' => '1/1', 'rm' => '1/1', 'xy' => array( 50, 30 ) ) ) ),
	'link'  => array( 'url', 'Strona' ),
) ),
```

Wiersze mają dodawanie, usuwanie i przesuwanie; wiersz z kadrem ma w danych klucz `kadr`. Na stronie:
`foreach ( colorify_tresc_lista( 'zespol' ) as $i => $w )`, a atrybut kadru wiersza: `colorify_kadr_attr_wiersza( $w, 'zespol', 'zespol.' . $i . '.foto' )`.

## 4. Funkcje dla szablonów

| Funkcja | Co robi |
|---|---|
| `colorify_tresc( $klucz )` | wartość (zapisana albo domyślna) |
| `colorify_tresc_html( $klucz )` | gotowe do HTML (tekst zescape'owany, akapit przez `wp_kses`) |
| `colorify_tresci_e( $klucz )` | drukuje `colorify_tresc_html()` (w podglądzie owinięte w `<span data-cft-key>`) |
| `colorify_tresci_tag( $klucz, 'h2', 'klasa' )` | drukuje `<h2 class="klasa">…</h2>` albo nic, gdy pole puste |
| `colorify_tresc_media( $klucz )` | URL zdjęcia/filmu (załącznik, domyślny plik motywu, `''` gdy usunięte) |
| `colorify_tresc_kadr_style( $klucz )` | drukuje ` style="…"` z kadrem (+ `data-cft-key` w podglądzie) |
| `colorify_tresc_lista( $klucz )` | wiersze listy |
| `colorify_tresc_url( $klucz )` | `esc_url()` pola |
| `colorify_tresci_podglad_attr( $klucz )` | drukuje `data-cft-key="…"` tylko w trybie podglądu (dla elementów bez `_tag`/`_e`) |
| `colorify_tresci_faq_odp( $tekst )` | akapity `<p>` z odpowiedzi FAQ |
| `colorify_tresci_link_aktywny( $url )` | czy link menu wskazuje bieżącą stronę |

Motyw może trzymać własne aliasy (np. `ej_tresc()` → `colorify_tresc()`) i własny minimalny czytnik na wypadek
wyłączenia Colorify: dane są w zwykłej opcji, więc strona nigdy nie zależy od wtyczki (przykład: motyw `elarisse`,
`inc/tresci.php`).

## 5. Podgląd na żywo

Iframe tej samej domeny (`url` strony + `?colorify_tresci_podglad=1`). W tym trybie elementy drukowane przez
`colorify_tresci_tag/_e/_kadr_style/_podglad_attr` dostają `data-cft-key`, a wartości pochodzą z transientu
`colorify_tresci_podglad_<user>` (formularz wysyłany AJAX-em `colorify_tresci_podglad`), nie z opcji. Panel modyfikuje DOM
iframe bezpośrednio (pisanie, kadr = od razu; zmiany struktury list i usunięcie zdjęcia = przeładowanie podglądu).
Fokus pola przewija podgląd i podświetla element; kliknięcie w podglądzie otwiera pole. Szerokości: Komputer 1440,
Duży ekran 1920, Telefon 390 (te same, na których mierzy się proporcje kadrów). Publiczny HTML nie ma tych atrybutów.

## 6. WP-CLI dla agentów

```
wp colorify-tresci list [--zmienione] [--format=json]
wp colorify-tresci get <klucz> [--domyslna]
wp colorify-tresci set <klucz> "<wartość>" [--etykieta=…] [--wersja=<skrót>]
wp colorify-tresci reset <klucz> | --all
wp colorify-tresci wersja
```

Wartości: tekst; akapit = lekki HTML albo nowe linie; lista = JSON tablicy wierszy; kadr `<klucz>__kadr` = `"x,y,zoom"`;
zdjęcie = ID załącznika, `-` usuwa, puste = domyślne. Zasada dla agentów: zmiany treści **tylko** przez `set`, nigdy przez
edycję domyślnych w schemacie (domyślne zmienia się, gdy zmienia się struktura strony, z domyślną = obecny tekst).

## 7. Bramka wersji i historia

`colorify_tresci_wersja()` = skrót zapisanej opcji. Formularz panelu niesie wersję z chwili otwarcia; zapis przy innej
wersji jest odrzucony z listą pól zmienionych w międzyczasie i wyborem: odśwież (odrzuć moje) albo scal (moje pola
wchodzą, cudze zostają). Panel sprawdza wersję co 60 s i po powrocie do karty (pasek „Treści zmieniły się poza
panelem”). WP-CLI: `wersja` + `set --wersja=` to samo. Historia (`<opcja>_historia`, 10 wpisów) ma czas `t`, autora `u`
(„AI/WP-CLI” z CLI), etykietę `l`, migawkę `d` i wersję `w`; przywracanie z panelu.

## 8a. Wygląd tekstu i odstępy (od 2.13.0)

Przy każdym polu `text`/`area`, które jest osobnym elementem na stronie, panel ma przycisk **„Wygląd”**. Otwiera pod polem
edytor: **Komputer / Telefon** (telefon = do 767 px, nadpisuje tylko to, co ustawisz; tablet bierze komputer), krój
(Nagłówkowy / Tekstowy), rozmiar XS–XXL (`clamp()`), grubość, kolor z palety marki, wyrównanie, odstęp pod spodem, a w
„Więcej ustawień”: interlinia 1–2,2, odstęp liter, odstęp nad, szerokość tekstu, wielkie litery, kursywa. Każde ustawienie
ma kropkę „zmienione” i reset, jest „Tylko zmienione” i „Przywróć cały wygląd”. Sekcja (rejestr `uklad`) ma odstęp nad i pod.

Dane: klucz zastrzeżony `__style` w tej samej opcji: `cel => array( 'b' => array( wlasciwosc => token ), 'm' => … )`.
Cel: klucz pola, `grupa.p-…` (akapit grupy), `s-….pole` (pole nowej sekcji), `#id` (sekcja). Do strony nie trafia nic
z tego, co wpisano: token jest zamieniany na wartość z mapy (`colorify_tresci_tokeny()`, motyw podmienia listy kluczem
rejestru `'tokeny' => array( 'c' => …, 'ff' => … )`). Na stronie element dostaje tylko zmienne `style="--cft-fz:…;--cft-fz-m:…"`,
a `<style id="cft-styl">` w `<head>` (około 1,5 KB) ma reguły `[style*="--cft-fz:"]{font-size:var(--cft-fz)!important}` itd.
Bez zapisanego wyglądu nie ma ani zmiennych, ani arkusza: HTML jest taki jak przed 2.13. Od 2.14 panel pokazuje też stan „z projektu”
(computed style elementu w ramce → najbliższy token, przerywana ramka + faktyczna wartość) i ma „Kopiuj wygląd” / „Wklej” oraz
„Jak powyżej” / „Jak poniżej” (wklejenie zapisuje tylko różnice względem projektu pola). Pola nowych sekcji nie mogą nazywać się
`typ`, `strona` ani `usun` (to dane samej sekcji). Podgląd zmienia zmienne w ramce
bez przeładowania. Rejestr `'styl_bez' => regex` wyłącza przycisk dla pól, które nie są osobnym elementem (np. opisy zdjęć).

## 8b. Akapity: pole `akapity` (od 2.13.0)

```php
'omnie_akapity' => colorify_tresci_pole( 'akapity', 'Akapity', null, 'Podpowiedź', array(
	'z_pol'    => array( 'omnie_akapit1' => 'lead', 'omnie_akapit2' => 'lead', 'omnie_akapit3' => 'punch' ), // stare pola w kolejności strony
	'warianty' => array( 'lead' => array( 'Akapit', 'klasy p' ), 'punch' => array( 'Wyróżnione zdanie', 'klasy p' ) ),
	'nowy'     => 'lead',
) ),
```

Szablon: `colorify_tresci_akapity( 'omnie_akapity', $wciecie )` (motyw: alias `ej_akapity()`). Blok starego pola bierze tekst
z tego pola (zapisane treści, panel i `set omnie_akapit1` działają jak dotąd), nowy blok ma id `p-…` i własny tekst. Domyślna
lista = stare pola w kolejności `z_pol` z ich wariantami, więc dopóki nikt nie zmieni listy, HTML jest bajt w bajt ten sam
(`$wciecie` = wcięcie z szablonu między dawnymi wywołaniami `ej_tag()`). Panel: dodaj, przesuń, duplikuj, usuń, rodzaj.

## 8c. Układ stron i nowe sekcje (od 2.13.0)

Motyw rejestruje sekcje stron (`'uklad' => strona => id => array( 'label', 'render' => callable, 'stala', 'karta', 'kotwica_id' )`)
i w szablonie woła `colorify_render_page( 'home' )` zamiast wypisywać sekcje po kolei. Funkcje `render` to dosłowne
wycinki dawnego szablonu (motyw `elarisse`: `template-parts/strona-glowna.php`, `o-mnie.php`). `stala` = zawsze na górze
i widoczna (np. pierwszy ekran, który zamyka wrapper z `header.php`); `kotwica_id` = panel ostrzega przed ukryciem sekcji,
do której prowadzi menu. Dane: `__uklad` (kolejność `o`, ukryte `h`, zapisywane tylko gdy różne od domyślnych) i `__sekcje`
(nowe sekcje: `id => array( 'typ', 'strona', 'v' => pola )`, id `s-` + 8 znaków, nigdy numer pozycji). Normalizacja:
nieznane id pomijane, nowe sekcje motywu wstawiane za domyślnym poprzednikiem, sekcje nowe bez miejsca na końcu.

Biblioteka (`colorify_tresci_typy_sekcji()`): **Tekst**, **Tekst + zdjęcie**, **Cytat**, **Przycisk z zachętą**, **Odstęp**.
Markup z klasami motywu (rejestr `'klasy'`, np. `sekcja`, `kontener`, `naglowek`, `warianty` akapitów, `przycisk`,
`tlo_kremowe`), żeby nowa sekcja wyglądała jak reszta strony; bez klas Colorify ma własne `cft-sek*` z małym arkuszem.
Pełny własny renderer typu: rejestr `'typy' => typ => array( 'render' => callable( $id, $wartosci ) )`.
Panel: „Układ strony” nad kartami strony (wyżej, niżej, ukryj/pokaż, odstępy sekcji, „Dodaj sekcję tutaj”, a dla nowych
duplikuj i usuń z cofnięciem do zapisu), ten sam pasek w karcie sekcji. Zmiany struktury przeładowują podgląd z roboczego
dokumentu (transient), więc niezapisane zmiany nie znikają.

## 8d. WP-CLI dla wyglądu, układu i sekcji

```
wp colorify-tresci tokeny
wp colorify-tresci styl <cel> [wl=token ...] [--telefon] [--reset] [--wersja=<skrót>]
wp colorify-tresci uklad [<strona>] [--kolejnosc=a,b,c] [--ukryj=id] [--pokaz=id] [--wersja=<skrót>]
wp colorify-tresci sekcja list | dodaj <typ> --strona=home --po=<id> [--pola='{json}'] | set <id> '<json>' | usun <id>
wp colorify-tresci get __style | __uklad | __sekcje
```

Wszystko przez `colorify_tresci_z_formularza()` + `colorify_tresci_zapisz()` (jedna walidacja, historia, bramka wersji).
Zapis trzyma blokadę (`<opcja>_zapis`, `INSERT IGNORE`), więc panel i agent nie zapiszą w tej samej chwili.

## 8d2. Przełączniki właściciela (od 2.14.0)

Ustawienia, Colorify: wiersze **„Edytor treści”** (cały moduł) i **„Zaawansowana edycja (tylko właściciel)”** (Wygląd,
akapity jako bloki, Układ strony, nowe sekcje) widzi i zmienia wyłącznie właściciel Colorify:

```php
// wp-config.php (repozytorium wtyczki jest publiczne: adresy e-mail tylko tutaj, nigdy w kodzie)
define( 'COLORIFY_WLASCICIEL', 'login1,osoba@example.com' ); // loginy albo e-maile po przecinku, wielkość liter bez znaczenia
```

Bez stałej właścicielem jest konto o ID 1 (jeśli ma `manage_options`). Funkcja: `colorify_jest_wlascicielem( $user = null )`.
Ochrona po stronie serwera: filtr `pre_update_option_colorify_tresci` i `pre_update_option_colorify_tresci_zaaw` zostawia
starą wartość, gdy zapisuje ktoś inny (formularz, options.php, REST, `wp option update`). WP-CLI:
`wp option update colorify_tresci_zaaw yes --user=<login właściciela>`. Domyślnie oba wyłączone.

Wyłączona zaawansowana edycja: panel jak w 2.12 (stare pola akapitów osobno, bez przycisków „Wygląd”, bez „Układu strony”),
WP-CLI `styl`, `uklad`, `sekcja` i `get __style…` odmawiają, a `colorify_tresci_z_formularza()` ignoruje przysłane klucze
`__style`, `__uklad`, `__sekcje` i listy akapitów (zostają zapisane). Strona dalej pokazuje to, co już zapisano (decyzja:
wyłączenie przełącznika nie może zmienić wyglądu strony; cofnięcie = włączyć i zresetować albo przywrócić wersję z Historii).

## 8e. Twarde reguły edytora (dla każdej strony i każdej sesji)

Każdy edytor treści Colorify (ten moduł albo jego następca) MUSI dawać właścicielce strony, bez programisty:

1. **Typografię** każdego widocznego tekstu: krój (z kroju marki), rozmiar, grubość (tylko istniejące w foncie), kolor
   z palety, wyrównanie, interlinię, odstęp liter, wielkie litery, kursywę; osobno komputer i telefon.
2. **Odstępy**: pod i nad akapitem, szerokość tekstu, odstęp nad i pod sekcją; osobno komputer i telefon.
3. **Akapity**: dodaj, usuń, przesuń, duplikuj, zmień rodzaj, w każdej sekcji z kilkoma akapitami.
4. **Sekcje**: przenieś wyżej/niżej, ukryj i przywróć sekcję szablonu (nigdy jej nie kasuj), dodaj nową z biblioteki
   w wybranym miejscu, edytuj, duplikuj, usuń dodaną (z cofnięciem do zapisu i z historii).
5. **Czytelność panelu**: ikona + podpis albo ikona z dymkiem (hover i fokus klawiatury) + `aria-label` na każdej
   kontrolce; cel dotykowy 44 px; zrozumiały komunikat zamiast martwego przycisku (wyszarzone = dymek „dlaczego”).
   Klik w element podglądu otwiera jego kartę i stawia pole w pionowym środku panelu, z fokusem.
6. **Podgląd na żywo** prawdziwej strony (styl bez przeładowania, struktura z roboczego dokumentu) i **historia**
   z przywracaniem całości (treść + wygląd + układ) oraz **bramka wersji** dla panelu i agentów.
7. **Tylko tokeny marki, zero dowolnego CSS**: żadnych pól px, własnych fontów, kodu CSS/HTML wpisywanego przez użytkownika;
   wartość zapisana to token z allowlisty, jedna funkcja walidacji dla panelu, WP-CLI i AI.
8. **HTML szablonu bez zmian po refaktorze**: przeniesienie sekcji do rendererów musi dać identyczny HTML (diff przed/po
   na tym samym stanie treści); bez zapisanych zmian strona wygląda i waży tyle samo co przed modułem.
9. **RWD 320–1920**: każda nowa kontrolka i każda nowa sekcja bez poziomego przewijania i nachodzenia na 320, 375, 390,
   768, 1024, 1440, 1920; zero nowych bibliotek JS na froncie (PageSpeed).
10. **Nic nie ginie**: stare klucze danych czytane jak dotąd, migracje tylko addytywne, „ukryj” zamiast „usuń” dla szablonu.
11. **Przełączniki modułu i zaawansowanej edycji zmienia tylko właściciel Colorify** (`COLORIFY_WLASCICIEL`), egzekwowane
    po stronie serwera; domyślnie wyłączone; wyłączenie nie zmienia wyglądu strony.

## 8. Co dzieje się na innych stronach

Moduł wyłączony (domyślnie) albo brak schematu: `includes/colorify-tresci.php` definiuje funkcje i wiersz w ustawieniach,
nie podpina żadnego hooka (menu, AJAX, admin-post, podgląd, WP-CLI ładują się dopiero w `init`, gdy
`colorify_tresci_dostepny()` jest prawdą). Aktualizacja Colorify niczego więc nie zmienia na stronach bez schematu.
