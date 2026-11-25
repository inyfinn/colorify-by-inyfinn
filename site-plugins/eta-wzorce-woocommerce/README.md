# Wzorce WooCommerce z ETA Innovations (kopie do czytania)

To NIE jest wtyczka do zainstalowania. To kopie plików z `wp-content/mu-plugins/` strony etainnovations.com, stan
z 25.11.2026, jako wzór dla innych sklepów. Opis wzorców, pułapek i sposobu testowania: `docs/woocommerce-przepisy.md`.

Paczka `eta-wzorce-woocommerce.zip` z wydania Colorify nie zainstaluje się przez Wtyczki, Dodaj nową (pliki leżą
w podfolderze, WordPress nie znajdzie nagłówka wtyczki). Tak ma być: plik bierzesz, czytasz i dopasowujesz do strony.

| Plik | Wersja | md5 na serwerze | Co pokazuje |
|---|---|---|---|
| `mu-plugins/eta-product-card-texts.php` | 1.3.0 | `2a26cdfe6a4fda67418b0ddc1d3fd04e` | teksty karty produktu: produkt → kategoria (opcja) → kod; pudełka w edycji produktu; na końcu rejestracja kart dla Edytora produktu Colorify |
| `mu-plugins/assets/js/eta-card-texts-admin.js` | | `95c314ad433fbd91fbf05b3e708ee371` | lista punktów (dodaj, duplikuj, przesuń, usuń, przywróć domyślne), lista napisów przycisku |
| `mu-plugins/assets/css/eta-card-texts-admin.css` | | `f3685afbc768cf105b5c45da10400d06` | układ pudełek; selektor nagłówka zasięgu mocniejszy niż `.notice p` Colorify |
| `mu-plugins/eta-order-report-form.php` | 1.0.0 | `cf4b737b3ec62e2cb72178b8fe7d7c41` | „Forma wyników” (papier / PDF) w edycji zamówienia, na liście zamówień, w mailach, na podziękowaniu i w Moje konto: jedna funkcja, pięć miejsc |

## Czego te pliki potrzebują (na innej stronie nie ma tego)

- `eta-product-card-texts.php`: klasa `ETA_Shop_Template` (motyw sklepu ETA: domyślne punkty, ramka, pasek kafli,
  zakładka zwrotów, `product_is_lab_service()`). Na innej stronie zastąp ją własnym źródłem tekstów domyślnych.
  Lista gotowych napisów przycisku i literał „Zleć badanie” są wpisane ręcznie (komentarze `shortcut` w pliku).
- `eta-order-report-form.php`: meta zamówienia `_eta_paper_report_requested` i `_eta_digital_delivery` (zapisuje je
  kod kasy) oraz `ETA_Shipping_Payments::product_is_digital_service()`; bez tej klasy czyta meta `_eta_is_service`.

Kopie są bajt w bajt takie jak na serwerze (md5 wyżej). Nie zawierają adresów e-mail, numerów kont ani kluczy.
