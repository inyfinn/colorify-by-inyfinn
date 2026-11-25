# Przepisy WooCommerce z wdrożeń

Spisane po naprawie sklepu ETA Innovations (24–25.11.2026). Dla człowieka i dla agenta AI, który robi podobną zmianę
na innej stronie. Każdy rozdział: zasada, co poszło źle, jak to zrobić, plik wzorcowy.
Pliki wzorcowe: `site-plugins/eta-wzorce-woocommerce/mu-plugins/` (kopie do czytania, nie do instalacji).

## 1. Strona pokazuje to, co jest w edytorze

**Zasada.** Opis produktu i tekst pod ceną wyświetlają się tak, jak je zapisał edytor. Przy wyświetlaniu wolno tylko
to, co robi sam WordPress (`wpautop`, shortcode'y). Żadnego kodu, który „poprawia” treść przy każdym wejściu na stronę.

**Co poszło źle.** Mu-plugin typografii przepisywał `post_content` wyrażeniami regularnymi przy każdym wyświetleniu
(usuwał „powtórzony tytuł”, przestawiał bloki, łamał wiersze). Gdy klient poprawił opis, kod nie rozpoznał swojego
formatu i wyciął większość tekstu. Wcześniejsze przebiegi wsadowe zapisały zepsuty tekst do bazy (zjedzone początki
zdań, sklejone wyrazy). Klient widział w edytorze co innego niż na stronie i nie miał jak tego naprawić.

**Jak to robić.**
- Renderer zakładki Opis: `wpautop` + `do_shortcode` na `post_content` i nic więcej.
- Tekst pod ceną = `post_excerpt` dosłownie. Wolno go zbudować z opisu tylko wtedy, gdy pole jest puste.
- Stałe dopiski (np. adres do wysyłki próbek) są częścią treści produktu, nie doklejką w rendererze.
- Sprawdź CSS motywu: motyw nadrzędny potrafi ukrywać pierwszy nagłówek opisu.

**Naprawa treści w bazie: raz, z dowodem.**
1. Kopia wierszy do JSON (ID, `post_content`, `post_excerpt`, `post_modified`) przed czymkolwiek. Zapisz md5 pliku.
2. Dry run: raport PRZED / PO dla każdego produktu, nic nie zapisuje. Punktem wyjścia jest obecna treść z bazy
   (dopiski klienta zostają), zmiany są mechaniczne i wypisane.
3. Ładunek zapisu niesie dla każdego produktu `post_modified` i md5 treści z chwili dry runu. Skrypt zapisu porównuje
   je z bazą; różnica = STOP dla tego produktu (ktoś edytował w międzyczasie).
4. Zapis: `wp_update_post( wp_slash( … ) )` między `kses_remove_filters()` a `kses_init()`, potem odczyt z bazy
   i porównanie 1:1 z tym, co wysłano. Przywróć `_edit_last`, żeby autorem ostatniej zmiany nie został agent.
5. Pilot na 3 produktach, pokazany właścicielowi, dopiero potem reszta.

## 2. Teksty karty produktu: produkt, potem kategoria, potem kod

**Zasada.** Krótkie teksty wokół ceny (punkty, ramka informacyjna, napis na przycisku) mają tekst domyślny wspólny
dla grupy produktów i możliwość zmiany dla jednego produktu. Kolejność odczytu:
meta produktu → opcja strony (`eta_card_globals[wariant]`) → tekst z kodu. Puste pole zawsze znaczy „idź dalej”.

**Wzorzec:** `eta-product-card-texts.php` (+ `assets/js`, `assets/css`).
- Meta: `_eta_card_bullets` (wiersze), `_eta_card_bullets_hide`, `_eta_card_notice` (HTML: `strong`, `em`, `a`, `br`),
  `_eta_card_notice_hide`, `_eta_card_button`. Opcja: `[wariant] => bullets[], notice_html, button_text`
  oraz `['_all'] => trust_strip[3], returns_html` dla tekstów wspólnych całego sklepu.
- Jedno publiczne `defaults( $id )`: teksty domyślne, wariant, nazwa i liczność zasięgu. Korzysta z niego pudełko
  w edycji produktu i Edytor produktu Colorify.
- **Pole zawsze pokazuje tekst, który jest na stronie** (domyślny wpisany jako wartość, nie jako szara podpowiedź).
  Przy zapisie: wartość równa domyślnej = `delete_post_meta`. Produkt bez własnego wpisu idzie za zmianą domyślnego.
- Punkty: lista wierszy z przyciskami dodaj, duplikuj, w górę, w dół, usuń i „Przywróć domyślne”. Nie jedno pole
  tekstowe, w którym trzeba pilnować Enterów.
- Ramka: `wp_editor` w trybie `teeny` z paskiem tylko pogrubienie, kursywa, odnośnik. Wynik TinyMCE (`<p>`, twarde
  spacje) czyści `clean_notice()` do formatu, który strona wstawia w swój akapit.
- Napis na przycisku: lista (`selectWoo`) z napisami już używanymi w sklepie + pole „własny napis”.
- Na górze każdego pudełka zdanie o zasięgu: „Tekst domyślny jest wspólny dla wszystkich usług laboratoryjnych
  (43 produkty). Wpis tutaj zmienia tylko ten produkt.”
- Tytuły pudełek mówią, co widać na stronie („Tekst pod ceną — krótki opis”, „Zdjęcie główne”), nie jak nazywa się
  pole w WordPressie. Te same nazwy w każdym miejscu, w którym pole się pojawia.
- Pudełka nie mogą wystartować zwinięte ani wylądować na dole: filtry `postbox_classes_product_<id>`
  i `get_user_option_meta-box-order_product`.

**Pułapki.**
- Colorify w stylu „modern” ustawia `#wpbody-content .notice p` na 13 px. Własny większy nagłówek w `.notice` potrzebuje
  mocniejszego selektora, np. `body #wpbody-content .postbox .notice.moja-klasa p.moj-naglowek`.
- Mu-pluginy ładują się alfabetycznie. Klasę z późniejszego pliku sprawdzaj w hooku (`init`), nie przy ładowaniu.
- Podmiana renderera: `remove_action` z tym samym priorytetem na `init` po zarejestrowaniu oryginału, własny renderer
  woła oryginał, gdy nie ma wpisu. Wygląd zostaje identyczny, bo klasy CSS pochodzą z oryginału.
- `wp_kses` wycina klasy. Jeśli CSS strony wisi na klasach listy, dodaj je przy wyświetlaniu.
- Filtr napisu przycisku bywa wyłączony w panelu (`is_admin()`), więc tekst domyślny w panelu trzeba wziąć inaczej.

## 3. Usługa cyfrowa, raport papierowy, wysyłka

**Jedna funkcja „czy to usługa”.** Każde miejsce (koszyk, wysyłka, maile, karta produktu) pyta tę samą funkcję.
Na ETA: meta `_eta_is_service = yes` albo produkt wirtualny bez wysyłki. Kilka różnych testów w kilku plikach
skończyło się usługami z doliczoną wysyłką i usługami bez ramki informacyjnej.

**Wybór klienta w sesji dotyczy jednego koszyka.** Zaznaczenie „chcę raport papierowy” siedziało w sesji WooCommerce
i zostawało po zamówieniu: następna usługa miała doliczoną wysyłkę 18 zł i zaznaczone pole. WooCommerce po zamówieniu
czyści tylko własne klucze sesji. Własną flagę zeruj sam:

```php
foreach ( array( 'woocommerce_checkout_order_processed', 'woocommerce_cart_emptied', 'woocommerce_add_to_cart',
	'woocommerce_cart_item_removed', 'woocommerce_after_cart_item_quantity_update' ) as $hook ) {
	add_action( $hook, array( __CLASS__, 'reset_paper_report_session' ), 20 );
}
```

Meta zamówienia zapisuj dla obu odpowiedzi: przy „nie” usuń stare „tak” (zamówienie oczekujące na płatność bywa
używane ponownie).

**To, co klient wybrał, ma być widać wszędzie, gdzie ktoś podejmuje decyzję.** Wzorzec `eta-order-report-form.php`:
jedna funkcja `describe( $order, $for_admin )` zwraca rodzaj, krótką etykietę i pełne zdanie, a pięć hooków je pokazuje:
edycja zamówienia (`woocommerce_admin_order_data_after_order_details`), lista zamówień (kolumna, HPOS i klasyczna),
maile do sprzedawcy i klienta, HTML i tekstowe (`woocommerce_email_before_order_table`), podziękowanie i Moje konto
(`woocommerce_order_details_before_order_table`). W panelu używa klas statusów WooCommerce zamiast własnych kolorów.
Przed zmianą sprzedawca nie widział nigdzie, że ma wysłać papier.

## 4. Testy na hostingu z limitem żądań

Hosting współdzielony blokuje adres IP po serii żądań (HTTP 429 po 2–3 pełnych stronach; ekran edycji produktu
to kilkaset żądań). Blokada obejmuje całe biuro i SSH.

- **Stronę pobieraj z serwera:** `curl` uruchomiony przez SSH na serwerze nie podlega limitowi. HTML sprawdzaj grepem.
- **Logikę sprawdzaj w WP-CLI:** skrypt lokalnie, kopia do katalogu tymczasowego konta, `wp eval-file`, usunięcie pliku.
  Jedno polecenie SSH naraz.
- **Koszyk i wysyłkę symuluj w CLI:** sesja WooCommerce żyje wtedy w pamięci i nic nie trafia do bazy.
- **Maile renderuj do pliku, nie wysyłaj.** Nigdy `$email->trigger()` na żywym sklepie:

```php
$e = WC()->mailer()->get_emails()['WC_Email_New_Order'];
$e->object = wc_get_order( $id );
$html = $e->style_inline( $e->get_content_html() ); // plik do obejrzenia; tekstowa wersja: get_content_plain()
```

- **Zapis produktu testuj na kopii:** duplikat jako szkic (`WC_Admin_Duplicate_Product::product_duplicate()`),
  test, `wp_trash_post()` w bloku `finally`.
- **Przeglądarka na końcu i oszczędnie:** jedna strona na kilka minut, zrzuty z blokadą obrazów i czcionek.
- Przed zmianą pliku: kopia do `wp-content/_backup/<plik>-<data>-<godzina>/`, po zmianie `php -l` i kod 200 strony
  głównej. Błąd 500 = natychmiast przywróć kopię.
- Konto hostingowe bywa wspólne dla kilku stron z własnymi `mu-plugins`. Nie synchronizuj katalogów na ślepo.

## 5. Edytor produktu z podglądem (moduł Colorify, jeszcze niewydany)

Karty z każdym tekstem i elementem strony produktu obok podglądu prawdziwej strony; zapis do tych samych pól co zwykły
ekran produktu. **Stan na 25.11.2026:** kod jest na gałęzi `feature/edytor-produktu`, nie ma go w wydaniach. Zapis
i odczyt są sprawdzone testem na żywym WooCommerce (`tools/test-produkt.php` na tej gałęzi), ekran nie był jeszcze
otwarty w przeglądarce. Pełny opis na gałęzi: `docs/edytor-produktu.md`.

Po wydaniu: Ustawienia, Colorify, „Edytor produktu z podglądem” (opcja `colorify_produkt` = `yes`), potem Produkty,
„Edytor z podglądem”. Karty WooCommerce (nazwa, zdjęcia, cena, tekst pod ceną, kategorie i tagi, opis, opinie) są
wbudowane. Karty strony dokłada wtyczka strony filtrem `colorify_produkt_rejestr( $rejestr, $id_produktu )`:

- `karty[id]`: `tytul`, `gdzie`, `kolejnosc`, `selektor` (element w podglądzie), `pola` (klucz meta → typ `tekst`,
  `linie`, `lekki`, `html`, `bool`, z tekstem `domyslne`), `wspolne` (opcja strony, `sciezka` kluczy, zdanie `zasieg`;
  osobny zapis z potwierdzeniem);
- `selektory`: elementy strony dla kart wbudowanych.

Gotowy przykład (9 kart, teksty wspólne z podpisem zasięgu): koniec pliku `eta-product-card-texts.php`.
Filtr jest wołany tylko przez moduł, więc blok można mieć w pliku strony już teraz.

## 6. Zanim zmienisz coś na żywym sklepie

1. Czy zmiana zapisuje dane tam, skąd strona je czyta? Druga kopia tej samej treści zawsze się rozjedzie.
2. Czy sprzedawca zobaczy w panelu to samo, co klient na stronie, i czy pole nazywa się tak jak to, co widać?
3. Kopia przed, dry run, zapis z kontrolą wersji, odczyt po. Pilot przed całością.
4. Dowód z prawdziwej strony albo z prawdziwej bazy, nie z czytania kodu.
