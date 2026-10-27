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
		'gdzie'    => array( array( 'Pakiety i ceny', 'Usługi', 'admin.php?page=uslugi' ) ), // linki "Resztę edytujesz tutaj"
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

## 8. Co dzieje się na innych stronach

Moduł wyłączony (domyślnie) albo brak schematu: `includes/colorify-tresci.php` definiuje funkcje i wiersz w ustawieniach,
nie podpina żadnego hooka (menu, AJAX, admin-post, podgląd, WP-CLI ładują się dopiero w `init`, gdy
`colorify_tresci_dostepny()` jest prawdą). Aktualizacja Colorify niczego więc nie zmienia na stronach bez schematu.
