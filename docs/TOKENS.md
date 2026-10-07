# Tokeny kolorów Colorify 2.0 (specyfikacja)

Ten dokument jest źródłem prawdy dla `includes/colorify-color-math.php`, `includes/colorify-tokens.php`
i `tools/test-tokens.php`. Jedna implementacja (PHP). JavaScript nie liczy kolorów: podgląd na żywo pobiera
gotowe tokeny z serwera.

## 1. Zasady

1. **WordPress nakłada kolory, Colorify podaje wartości.** Tokeny mają dokładnie te role, które zna
   `wp-admin/css/colors/_admin.scss` rdzenia, plus rodzinę `--wp-admin-theme-color`.
2. **Akcent ma dwie role.** WordPress używa koloru motywu jako tła pod biały tekst (przycisk główny ma `#fff`
   wpisane na sztywno) i jako koloru tekstu na jasnym tle (linki, przyciski drugorzędne). Dlatego:
   - `theme` (kolor motywu dla treści) musi mieć kontrast co najmniej 4.5:1 z bielą i z tłem ekranu,
   - jaskrawy akcent marki (`fill`) pojawia się tylko tam, gdzie sami dobieramy kolor tekstu (menu, plakietki).
3. **Tylko pary.** Każdy token tekstu ma wskazane tło, na którym musi być czytelny. Test sprawdza wszystkie pary.
4. **Paleta usera jest szanowana tam, gdzie to bezpieczne**: tło menu i jaskrawy akcent bierzemy dosłownie,
   kolory tekstu i akcent treści wyliczamy.

## 2. Matematyka koloru (`includes/colorify-color-math.php`)

Wszystkie funkcje czyste (bez WordPressa), z prefiksem `colorify_cm_`. Wejście i wyjście: `#rrggbb` małymi literami.

| Funkcja | Opis |
|---------|------|
| `colorify_cm_normalize( string $hex, string $fallback ): string` | przyjmuje `#rgb`, `#rrggbb`, wielkie litery; zwraca `#rrggbb`; błędne wejście → `$fallback` |
| `colorify_cm_luminance( string $hex ): float` | luminancja względna WCAG 2.1 |
| `colorify_cm_contrast( string $a, string $b ): float` | `(Lmax + 0.05) / (Lmin + 0.05)` |
| `colorify_cm_to_oklch( string $hex ): array{0:float,1:float,2:float}` | L (0..1), C, h (stopnie), wzory Björna Ottossona (OKLab) |
| `colorify_cm_from_oklch( float $l, float $c, float $h ): string` | z mapowaniem do gamutu sRGB: gdy kolor poza gamutem, zmniejszaj C wyszukiwaniem binarnym (24 kroki) aż wszystkie kanały liniowe mieszczą się w `[-0.0001, 1.0001]`; potem przytnij i zaokrąglij |
| `colorify_cm_with_l( string $hex, float $l ): string` | ten sam odcień i chroma, nowa jasność L (przycięta do 0..1) |
| `colorify_cm_shift_l( string $hex, float $delta ): string` | `with_l( $hex, L + $delta )` |
| `colorify_cm_mix( string $a, string $b, float $t ): string` | interpolacja liniowa kanałów sRGB (0 = `$a`, 1 = `$b`) |
| `colorify_cm_seek( string $hex, callable $ok, int $dir, float $step = 0.004 ): string` | jeśli `$ok( $hex )` → zwróć `$hex`; inaczej zmieniaj L w kierunku `$dir` (`-1` ciemniej, `+1` jaśniej) krokami `$step`, zwróć pierwszy kolor spełniający `$ok`; po dojściu do 0 lub 1 zwróć `#000000` albo `#ffffff` |
| `colorify_cm_on( string $bg, string $dark, string $light ): string` | zwróć kandydata o wyższym kontraście z `$bg`; jeśli najlepszy ma poniżej 4.5 → zwróć `#000000` albo `#ffffff` (ten o wyższym kontraście) |
| `colorify_cm_rgb_csv( string $hex ): string` | `"88, 112, 0"` |

Progi: `AA = 4.5`, `AA_SAFE = 4.6` (zapas na zaokrąglenia; do niego dobieramy), `STRONG = 7.0`.

## 3. Wejście

`$def` z `colorify_admin_get_resolved_scheme()` po `colorify_admin_apply_user_tuning_to_scheme()`:
`accent`, `accent_soft`, `bg_dark`, `bg2_dark`, `bg_light`, `bg2_light`. Braki lub błędy → wartości schematu Limonka
(`#b4e717`, `#92c200`, `#050f0c`, `#0b231c`, `#fefefd`, `#f7f6f3`). Tryb: `dark` albo `light`.
Kluczy `accent_light`, `accent_soft_light` nowy silnik nie używa.

## 4. Wyliczenie

Oznaczenia: `A` = `accent`, `h(A)` = odcień OKLCH, `cr()` = kontrast, `WHITE = #ffffff`.

### 4.1 Powierzchnie

| Symbol | Tryb jasny | Tryb ciemny |
|--------|-----------|-------------|
| `canvas` (tło ekranu) | `from_oklch( 0.955, min( C(bg2_light), 0.012 ), h(A) )`; test: luminancja co najmniej 0.86 | `with_l( bg_dark, min( L(bg_dark), 0.22 ) )` (ciemniejszy z pary `bg_dark`, `bg2_dark` idzie jako `bg_dark`) |
| `surface` (karta, biegun tła Dark Readera) | `#ffffff` | `shift_l( canvas, +0.035 )` |
| `surface2` | `#f6f7f7` | `shift_l( surface, +0.03 )` |
| `surface_high` (najjaśniejsza ciemna powierzchnia, do sprawdzania tekstu) | `canvas` | `shift_l( surface, +0.07 )` |
| `text` (biegun tekstu) | `#1e1e1e` | `from_oklch( 0.93, 0.01, h(A) )`; test: `cr( text, surface_high )` co najmniej 9 |
| `text_muted` | `#50575e` | `seek( mix( text, surface, 0.38 ), cr z surface2 >= AA_SAFE, +1 )` |
| `border` | `#dcdcde` | `mix( surface, text, 0.18 )` |

### 4.2 Rama: menu boczne i górny pasek (dosłownie z palety)

`menu_bg` = `bg2_<tryb>` bez zmian (wyjątek: tryb ciemny i para odwrócona: jaśniejszy z `bg_dark`, `bg2_dark`).
Kandydaci tekstu: `DARK_T = from_oklch( 0.18, 0.02, h(menu_bg) )`, `LIGHT_T = from_oklch( 0.96, 0.008, h(menu_bg) )`.

| Token CSS | Wyliczenie | Musi być czytelny na |
|-----------|-----------|----------------------|
| `--colorify-menu-background` | `menu_bg` | - |
| `--colorify-menu-text` | `on( menu_bg, DARK_T, LIGHT_T )` | `menu_bg` (cel 7, minimum 4.5) |
| `--colorify-menu-icon` | `seek( mix( menu_text, menu_bg, 0.30 ), cr z menu_bg >= AA_SAFE, kierunek do menu_text )` | `menu_bg` 4.5 |
| `fill` (akcent marki) | `A`; jeśli `cr( A, menu_bg ) < 1.5` → `seek( A, cr z menu_bg >= 1.5, +1 gdy menu ciemne, -1 gdy jasne )` | - |
| `on_fill` | `on( fill, from_oklch( 0.18, 0.03, h(A) ), WHITE )` | `fill` 4.5 |
| `--colorify-menu-highlight-background`, `--colorify-menu-current-background` | `fill` | - |
| `--colorify-menu-highlight-text`, `-highlight-icon`, `--colorify-menu-current-text`, `-current-icon` | `on_fill` | `fill` 4.5 |
| `--colorify-menu-submenu-background` | menu ciemne: `shift_l( menu_bg, -0.035 )`; jasne: `shift_l( menu_bg, +0.03 )` | - |
| `--colorify-menu-submenu-background-alt` | menu ciemne: `shift_l( menu_bg, +0.05 )`; jasne: `shift_l( menu_bg, -0.04 )` | - |
| `--colorify-menu-submenu-text` | `seek( mix( menu_text, menu_bg, 0.25 ), cr >= AA_SAFE na submenu_bg ORAZ submenu_bg_alt ORAZ menu_bg, kierunek do menu_text )` | trzy tła 4.5 |
| `--colorify-menu-submenu-focus-text` | `seek( A, cr >= AA_SAFE na submenu_bg ORAZ menu_bg ORAZ submenu_bg_alt, +1 gdy menu ciemne, -1 gdy jasne )` | trzy tła 4.5 |
| `--colorify-menu-submenu-current-text` | `menu_text` | `submenu_bg` 4.5 |
| `--colorify-menu-bubble-background` / `-bubble-text` | `fill` / `on_fill` | 4.5 |
| `--colorify-menu-bubble-current-background` / `-bubble-current-text` | `submenu_bg` / `menu_text` | 4.5 |
| `--colorify-menu-collapse-text`, `--colorify-menu-collapse-icon` | `menu_icon` | `menu_bg` 4.5 |
| `--colorify-menu-collapse-focus-text`, `-collapse-focus-icon` | `on_fill` | `fill` |
| `--colorify-adminbar-avatar-frame`, `--colorify-adminbar-input-background` | menu ciemne: `shift_l( menu_bg, +0.06 )`; jasne: `shift_l( menu_bg, -0.05 )` | `menu_text` na tym tle 4.5 |
| `--colorify-adminbar-recovery-exit-background` / `-text` / `-background-alt` | `fill` / `on_fill` / `shift_l( fill, -0.04 )` (test: `on_fill` na `-alt` 4.5, inaczej `-alt = fill`) | 4.5 |
| `--colorify-base-color` / `--colorify-text-color` | `menu_bg` / `menu_text` | para |
| `--colorify-icon-color` | `menu_icon` | - |
| `--colorify-body-background` | `canvas` | - |

"Menu ciemne" znaczy `luminance( menu_bg ) < 0.2`.

### 4.3 Akcent treści: rodzina koloru motywu

`theme` = `seek( A, cr z WHITE >= AA_SAFE ORAZ cr z canvas_jasny >= AA_SAFE, -1 )`, gdzie `canvas_jasny` to `canvas`
policzony dla trybu jasnego (także gdy liczymy tryb ciemny: wartość `theme` jest taka sama w obu trybach).

| Token CSS | Wartość |
|-----------|---------|
| `--wp-admin-theme-color` | `theme` |
| `--wp-admin-theme-color--rgb` | `rgb_csv( theme )` |
| `--wp-admin-theme-color-darker-10` | `shift_l( theme, -0.04 )` |
| `--wp-admin-theme-color-darker-10--rgb` | jak wyżej, csv |
| `--wp-admin-theme-color-darker-20` | `shift_l( theme, -0.08 )` |
| `--wp-admin-theme-color-darker-20--rgb` | jak wyżej, csv |
| `--colorify-theme-lighter` | `shift_l( theme, +0.07 )` (poświata fokusu, dekoracja) |

W arkuszu schematu zmienne Sass `$link`, `$button-color`, `$highlight-color` wskazują na `var(--wp-admin-theme-color)`,
`$link-focus` na `var(--wp-admin-theme-color-darker-20)`. Osobnych tokenów linku nie ma.

### 4.4 Tryb ciemny: role akcentu dla Dark Readera

Dark Reader zamienia `var(--wp-admin-theme-color)` na `var(--darkreader-text--…)`, `var(--darkreader-bg--…)`
albo `var(--darkreader-border--…)` zależnie od roli. Te zmienne ustawiamy sami, z `!important`:

| Rola | Wyliczenie | Warunek |
|------|-----------|---------|
| `d_fill` (tło przycisku głównego, zaznaczone pole) | `seek( with_l( A, 0.62 ), cr( text, kolor ) >= AA_SAFE, -1 )`, potem `shift_l( wynik, -0.02 )` | `cr( text, d_fill ) >= 4.5`; `d_fill_hover = shift_l( d_fill, +0.02 )` też 4.5 |
| `d_text` (linki, tekst i ramka przycisku drugorzędnego) | `seek( A, cr z surface_high >= 5.0, +1 )` | `cr( d_text, surface_high ) >= 4.5`, `cr( d_text, canvas ) >= 4.5` |
| `d_text_hover` | `shift_l( d_text, +0.05 )` | jak wyżej |

| Zmienna | Wartość |
|---------|---------|
| `--darkreader-bg--wp-admin-theme-color` | `d_fill` |
| `--darkreader-bg--wp-admin-theme-color-darker-10`, `-darker-20` | `d_fill_hover`, `d_fill_hover` |
| `--darkreader-text--wp-admin-theme-color` | `d_text` |
| `--darkreader-text--wp-admin-theme-color-darker-10`, `-darker-20` | `d_text_hover` |
| `--darkreader-border--wp-admin-theme-color` | `d_text` |
| `--darkreader-border--wp-admin-theme-color-darker-10`, `-darker-20` | `d_text_hover` |
| `--darkreader-bg--wp-admin-theme-color--rgb`, `--darkreader-text--…--rgb`, `--darkreader-border--…--rgb` (także `-darker-10--rgb`, `-darker-20--rgb`) | `rgb_csv( d_text )` |

### 4.5 Tokeny ekranów samej wtyczki (arkusze `colorify-ui*.css`, pomijane przez Dark Readera)

| Token CSS | Jasny | Ciemny |
|-----------|-------|--------|
| `--colorify-ui-bg` | `canvas` | `canvas` |
| `--colorify-ui-surface` | `surface` | `surface` |
| `--colorify-ui-surface-2` | `surface2` | `surface2` |
| `--colorify-ui-border` | `border` | `border` |
| `--colorify-ui-text` | `text` | `text` |
| `--colorify-ui-text-muted` | `text_muted` | `text_muted` |
| `--colorify-ui-accent` (tekst akcentowy na powierzchni) | `theme` | `d_text` |
| `--colorify-ui-accent-fill` / `--colorify-ui-on-accent` | `theme` / `#ffffff` | `d_fill` / `text` |
| `--colorify-ui-brand` / `--colorify-ui-on-brand` (jaskrawy akcent marki) | `fill` / `on_fill` | `fill` / `on_fill` |
| `--colorify-ui-focus` | `theme` | `d_text` |

### 4.6 Pozostałe

- Ikony SVG menu (trzeci argument `icons` w `wp_admin_css_color()`): `base = menu_icon`, `focus = on_fill`, `current = on_fill`.
- Podgląd schematu (4 kolory): `[ menu_bg, canvas, fill, theme ]`.
- `<meta name="theme-color">`: `menu_bg`.

## 5. API (`includes/colorify-tokens.php`)

```php
/**
 * @param array<string,mixed> $def  Schemat po dostrojeniu.
 * @param string              $mode dark|light.
 * @return array{
 *   mode: string,
 *   css: array<string,string>,          // wszystkie zmienne z 4.2, 4.3, 4.5 (klucz = nazwa zmiennej)
 *   dark: array{bg:string,text:string,vars:array<string,string>}|null, // tylko tryb ciemny: bieguny + zmienne z 4.4
 *   icons: array{base:string,focus:string,current:string},
 *   preview: array{0:string,1:string,2:string,3:string},
 *   meta_theme_color: string
 * }
 */
function colorify_build_tokens( array $def, string $mode ): array;

/** Tokeny dla użytkownika (zakres per użytkownik / globalny rozstrzyga istniejący kod w colorify-scope.php). */
function colorify_current_tokens( int $user_id = 0, ?string $mode = null ): array;

/** Tokeny strony logowania: zawsze ustawienia globalne. */
function colorify_login_tokens(): array;
```

`colorify_current_tokens()` używa: `colorify_get_effective_admin_color()`, `colorify_get_effective_appearance_mode()`,
`colorify_get_effective_custom_tuning()`, `colorify_admin_get_resolved_scheme()`,
`colorify_admin_apply_user_tuning_to_scheme()`. Wynik jest zapamiętany w zmiennej statycznej na czas żądania
(klucz: użytkownik + tryb).

## 6. Test (`tools/test-tokens.php`)

Uruchamiany z CLI wewnątrz WordPressa: `wp eval-file wp-content/plugins/colorify-by-inyfinn/tools/test-tokens.php`
albo samodzielnie (plik sam dołącza `colorify-color-math.php`, `colorify-tokens.php` i dla listy schematów
`colorify-scheme-definitions.php` z atrapami kilku funkcji WordPressa). Kod wyjścia 0 tylko gdy wszystko przeszło.

1. Wszystkie schematy z `colorify_admin_scheme_definitions()` (w tym `colorify-custom` z wartościami domyślnymi)
   × `dark`, `light`.
2. 2000 losowych palet (ziarno stałe): 4 losowe kolory `#rrggbb` jako `bg_dark`/`bg_light`, `bg2_dark`/`bg2_light`,
   `accent`, `accent_soft` × oba tryby. Palety usera mogą być dowolne, więc test musi przejść dla każdej.
3. Dla każdego zestawu sprawdź każdą parę z tabel 4.1 do 4.5 (kolumny "Musi być czytelny na" i "Warunek"),
   poprawność formatu `#rrggbb` wszystkich wartości oraz luminancję `canvas` w trybie jasnym (co najmniej 0.86).
4. Wypisz: liczbę zestawów, liczbę sprawdzonych par, listę porażek (schemat, tryb, para, kontrast), najniższy
   kontrast dla każdego rodzaju pary.
5. Test odwrotny `oklch`: dla 500 losowych kolorów `from_oklch( to_oklch( x ) )` różni się od `x` najwyżej o 1 na kanał.
