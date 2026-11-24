# Moduł „Edytor produktu z podglądem” (od 2.16.0)

Ekran **Produkty, Edytor z podglądem** dla sklepów WooCommerce: po lewej karty z każdym tekstem i elementem strony
produktu (w kolejności strony), po prawej prawdziwa strona produktu z niezapisanymi zmianami. Moduł nie trzyma własnej
kopii treści: czyta i zapisuje te same pola co zwykły ekran produktu.

Domyślnie wyłączony: **Ustawienia, Colorify, „Edytor produktu z podglądem”** (opcja `colorify_produkt` = `yes`).
Bez WooCommerce włączenie niczego nie zmienia. Włączony moduł dodaje produktom rewizje WordPressa.

Pliki: `includes/colorify-produkt.php` (karty, odczyt, walidacja, zapis, filtry podglądu),
`includes/colorify-produkt-screen.php` (ekran, AJAX, wejścia), `assets/colorify-produkt.js|css` (dodatki).
Układ, karty, granica panel / podgląd i ramka podglądu to `assets/colorify-tresci.js|css` (ten sam DOM co Edytor treści).

## Karty wbudowane (WooCommerce)

| Karta (id) | Pole | Zapis |
|---|---|---|
| Nazwa produktu (`nazwa`) | `post_title` | `set_name()` |
| Zdjęcie główne (`zdjecie`) | `_thumbnail_id` | `set_image_id()` |
| Dodatkowe zdjęcia (`galeria`) | `_product_image_gallery` | `set_gallery_image_ids()` |
| Cena (`cena`) | `_regular_price`, `_sale_price` | `set_regular_price()`, `set_sale_price()` |
| Tekst pod ceną (`krotki`) | `post_excerpt` | `set_short_description()` |
| Kategorie i tagi (`kategorie`) | `product_cat`, `product_tag` | `set_category_ids()`, `set_tag_ids()` |
| Opis — zakładka »Opis« (`opis`) | `post_content` | `set_description()` |
| Opinie — zakładka »Opinie« (`opinie`) | `comment_status` | `set_reviews_allowed()` |
| Powiązane produkty (`powiazane`) | brak | tylko informacja |

Kolejność (`kolejnosc`): 10, 20, 25, 30, 40, 60, 70, 80, 90.

## Karty strony: filtr `colorify_produkt_rejestr`

Argumenty: rejestr (tablica), ID produktu. Wtyczka strony zwraca:

```php
add_filter( 'colorify_produkt_rejestr', function ( $r, $id ) {
	// Elementy strony dla kart wbudowanych (CSS w podglądzie): klik otwiera kartę, fokus w karcie podświetla element.
	$r['selektory'] = array( 'nazwa' => '.moj-baner__tytul, .product_title' );

	$r['karty']['punkty'] = array(
		'tytul'     => 'Punkty pod tekstem',          // to, co widać na stronie
		'gdzie'     => 'Lista pod tekstem pod ceną',   // podpis w nagłówku karty
		'kolejnosc' => 45,                             // między kartami wbudowanymi
		'selektor'  => '.moje-punkty',                 // element karty w podglądzie
		'zakladka'  => '',                             // id zakładki WooCommerce (np. 'description'), gdy karta jest zakładką
		'kafel'     => 'linie',                        // linie | ciemny | boksy
		'info'      => '',                             // akapit informacyjny (karta bez pól = sama informacja)
		'pola'      => array(                          // meta produktu: klucz = klucz meta
			'_moje_punkty' => array( 'typ' => 'linie', 'etykieta' => 'Punkty (jeden w wierszu)', 'domyslne' => "A\nB", 'selektor' => '.moje-punkty' ),
			'_moje_punkty_ukryj' => array( 'typ' => 'bool', 'etykieta' => 'Nie pokazuj punktów', 'tak' => 'yes' ),
		),
		'wspolne'   => array(                          // teksty wspólne dla wielu produktów: osobny zapis z potwierdzeniem
			'opcja'  => 'moje_teksty',                 // jedna opcja strony (tablica)
			'tytul'  => 'Punkty domyślne',
			'zasieg' => 'Zmiana dotyczy: wszystkie usługi (61 produktów).', // zdanie pokazywane przy polach i w potwierdzeniu
			'cap'    => 'manage_woocommerce',
			'pola'   => array(
				'bullets' => array( 'typ' => 'linie', 'etykieta' => 'Punkty domyślne', 'sciezka' => array( 'uslugi', 'bullets' ), 'tablica' => true, 'domyslne' => "A\nB" ),
			),
		),
	);
	return $r;
}, 10, 2 );
```

Karta o id karty wbudowanej nadpisuje jej klucze płytko (np. samo `gdzie` albo `info`).

### Typy pól

| `typ` | Kontrolka | W bazie |
|---|---|---|
| `tekst` | pole jednowierszowe | `sanitize_text_field` |
| `linie` | pole wielowierszowe, jeden wiersz = jedna pozycja | wiersze złączone `\n`; z `'tablica' => true` (teksty wspólne) tablica wierszy |
| `lekki` | pole wielowierszowe z przyciskami pogrubienia, kursywy, odnośnika | `wp_kses` (`strong`, `em`, `br`, `a[href,title]` albo własne `'kses'`) |
| `html` | edytor WP | `'kses'` z rejestru albo zasady rdzenia (`unfiltered_html` / `wp_kses_post`) |
| `bool` | pole wyboru | wartość `'tak'` (domyślnie `yes`) albo pusto |

Meta produktu: pusta wartość usuwa meta (`delete_meta_data`). `domyslne` to tekst, który strona pokazuje, gdy pole jest
puste: w polu widać go na szaro; w edytorze WP tekstu wspólnego jest treścią startową i niezmieniony nie trafia do opcji.

Teksty wspólne: `sciezka` to klucze w tablicy opcji. Pusta wartość usuwa klucz (strona wraca do tekstu z kodu);
`'zostaw_puste' => true` zostawia pusty tekst na miejscu (listy pozycyjne). Kod strony czyta opcję z fallbackiem
na swój tekst. Po zapisie: akcja `colorify_produkt_wspolne_zapisano` ( nazwa opcji, wartość, id karty ).

## Podgląd

Ramka ładuje stronę produktu z `?colorify_produkt_podglad=<id>` (tylko zalogowany z `edit_post` dla tego produktu,
`nocache_headers`, bez paska admina). Formularz idzie przez `admin-ajax` (`colorify_produkt_podglad`) i tę samą walidację
co zapis do transientu `colorify_produkt_podglad_<konto>_<id>` (1 h). Strona czyta go przez `woocommerce_product_get_*`,
`the_title`, `get_post_metadata`, `get_the_terms`, `woocommerce_get_product_terms`, `pre_option_<opcja>` i podmianę
pól zapytanego wpisu. W bazie nic się nie zmienia do „Zapisz zmiany”. Selektory służą tylko do przewijania i
podświetlania; poprawność podglądu od nich nie zależy.

Nazwa podmienia się w ramce w trakcie pisania. Reszta: przeładowanie ramki po 2,5 s przerwy w pisaniu albo przyciskiem
„Odśwież podgląd”. Gdy serwer odpowie 429, samoczynne przeładowania wyłączają się do końca wizyty.

## Zapis i równoczesna edycja

- `admin-post` (`colorify_produkt_zapis`): nonce na produkt, `current_user_can( 'edit_post', $id )`, tylko zmienione
  pola, `WC_Product::save()`. Edytor WP jest brany pod uwagę tylko, gdy ktoś w nim pisał (pole `cpe_tkniete`).
- Bramka wersji: skrót wszystkich wartości kart. Zapis na starej wersji jest zatrzymany, formularz wraca z wartościami
  osoby, która pisała, i listą pól zmienionych w międzyczasie.
- Blokada wpisu WordPressa: zwykły ekran produktu pokaże innym „… teraz edytuje”. Zwykły ekran sam sprawdza wersję
  po powrocie do karty przeglądarki i ostrzega, gdy produkt zmienił się w edytorze z podglądem.
- Ograniczenie: `WC_Product::save()` gubi odwrotne ukośniki w nazwie i opisach (jak REST API WooCommerce).

## Test

`CPE_TEST_SRC=<ID> wp eval-file tools/test-produkt.php --user=<administrator>`: tworzy kopię produktu (szkic), sprawdza
zapis („zapisane == wysłane”), nietknięty edytor, bramkę wersji, cenę promocyjną, meta strony i dane podglądu,
na końcu przenosi kopię do kosza.
