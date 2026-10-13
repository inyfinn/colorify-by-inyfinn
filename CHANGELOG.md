# Historia zmian

## 2.0.0

Nowy silnik kolorów, nowy wygląd wtyczki i aktualizacje jak w zwykłych wtyczkach.

- Nowy silnik kolorów: tryb jasny przez mechanizm WordPressa (własny schemat `wp_admin_css_color`
  i zmienne `--wp-admin-theme-color*`), bez tysięcy reguł `!important` przykrywających panel.
- Tryb ciemny przez dołączony silnik Dark Reader (MIT), ładowany tylko w trybie ciemnym. Gdy silnik się nie uruchomi,
  panel zostaje czytelny: ciemna rama, jasna treść.
- Strażnik kontrastu: poprawia teksty poniżej progu czytelności w stanie spoczynku (cały panel w trybie ciemnym,
  menu i górny pasek w jasnym). Filtr `colorify_contrast_guard` go wyłącza.
- Schemat domyślny: Grafit i złoto (ciemne menu i górny pasek, jasna treść, złoty akcent).
  Osoby z zapisanym własnym wyborem zachowują go.
- Nowe ekrany wtyczki: górny pasek z przełącznikiem trybu i wyborem schematu, wybór w profilu, Ustawienia, Colorify.
  Przepisana strona logowania (bez zewnętrznych czcionek).
- Czcionki EB Garamond i IBM Plex Sans dołączone lokalnie (licencja OFL), bez ładowania z zewnętrznego serwera.
- Aktualizacje z GitHub Releases przez mechanizm WordPressa (`Update URI`): pasek aktualizacji na ekranie Wtyczki,
  "Zaktualizuj teraz", `wp plugin update`. Paczka tylko z assetu wydania `colorify-by-inyfinn.zip`, weryfikacja
  sumy SHA-256 podanej przez GitHub, wstrzymanie wydania wymagającego nowszego PHP lub WordPressa niż na stronie,
  cache wydania 11 godzin, "Sprawdź ponownie" na ekranie Aktualizacje czyści go od razu.
  Stała `COLORIFY_GITHUB_REPO` w `wp-config.php` zmienia repozytorium.
- Z wersji 1.x: raz kliknij link "Aktualizuj" w wierszu wtyczki. Pliki, których 2.0 już nie ma (stare arkusze,
  `colorify-admin-schemes.php`, `colorify-compat.php`, instalator paczek), są usuwane po aktualizacji pojedynczo,
  wraz z opcjami i transjentami starego updatera.
- Usunięte pliki nadpisań 1.x (kilkanaście arkuszy CSS i skryptów), usunięty własny instalator, usunięty plik
  tłumaczeń (interfejs jest po polsku).
- Kontrola środowiska na starcie: PHP poniżej 7.4 albo WordPress poniżej 7.0 dają komunikat zamiast ładowania wtyczki.
  Kontrola kompletności plików: podczas kopiowania plików wtyczka nic nie ładuje i pokazuje komunikat.
- Wymagania: WordPress 7.0, PHP 7.4.
- Narzędzia wydawnicze: `tools/build-zip.mjs`, `tools/release.sh`, atrapa GitHuba `tools/mock-release/`.
