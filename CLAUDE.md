# Colorify by Inyfinn: zasady dla każdej sesji (ludzie i agenci AI)

Wtyczka WordPressa: wygląd panelu (schematy kolorów, tryb ciemny, kokpit), moduły „Edytor treści” (panel Treści strony),
„Kreator e-maili”. Dokumentacja modułów: `docs/edytor-tresci.md`, `docs/kreator-maili.md`, tokeny: `docs/TOKENS.md`.

## Edytor treści: twarde reguły (decyzja usera 11.11.2026)

Pełna lista: `docs/edytor-tresci.md`, rozdział „Twarde reguły edytora”. Skrót, który obowiązuje przy KAŻDEJ zmianie modułu
i przy każdej nowej stronie, która go używa:

1. Właścicielka strony zmienia sama, bez programisty: typografię (krój, rozmiar, grubość, kolor, wyrównanie, interlinia,
   odstęp liter, wielkie litery, kursywa), odstępy akapitów i sekcji (komputer i telefon), akapity (dodaj, usuń, przesuń,
   duplikuj), sekcje (przenieś, ukryj/przywróć szablonową, dodaj nową z biblioteki, edytuj, duplikuj, usuń dodaną).
   Nowa potrzeba = nowy token albo nowy typ sekcji w bibliotece, nigdy „nie da się”.
2. Panel czytelny: ikony (SVG w kodzie, bez CDN) + dymek na hover i fokus + `aria-label`, cel dotykowy 44 px,
   wyszarzony przycisk zawsze z wyjaśnieniem.
3. Podgląd na żywo, historia z przywracaniem całości (treść + wygląd + układ), bramka wersji dla panelu i WP-CLI.
4. Tylko tokeny marki, zero dowolnego CSS i HTML od użytkownika. Jedna walidacja (`colorify_tresci_z_formularza()`)
   i jeden zapis (`colorify_tresci_zapisz()`) dla panelu, WP-CLI i agenta AI.
5. Refaktor szablonu do rendererów sekcji = identyczny HTML (diff przed/po na tym samym stanie treści) przed wdrożeniem.
6. RWD 320–1920 bez poziomego przewijania; zero nowych bibliotek JS na froncie.
7. Dane: tylko addytywnie. Stare klucze opcji czytane jak dotąd; nowe dane pod kluczami zastrzeżonymi `__style`,
   `__uklad`, `__sekcje` w tej samej opcji. Testy na żywej stronie: kopia opcji przed, przywrócenie z bramką wersji po.

## Przełączniki właściciela (od 2.14.0, decyzja usera)

- „Edytor treści” i „Zaawansowana edycja” w Ustawieniach Colorify widzi i zmienia tylko właściciel: `COLORIFY_WLASCICIEL`
  w wp-config.php (loginy/e-maile po przecinku), bez stałej konto ID 1. Nigdy nie wpisuj e-maili ani loginów usera
  w kod tego repozytorium (jest publiczne). Ochrona po stronie serwera (`pre_update_option_*`), nie tylko w UI.
- Nowe funkcje „premium” modułu chowaj za `colorify_tresci_zaawansowana()` (panel, WP-CLI, zapis), a stronę publiczną
  zostaw renderującą to, co już zapisano.

## Praca w repo

- Wersja: `colorify-by-inyfinn.php` (nagłówek `Version:` i `COLORIFY_PLUGIN_VERSION`) + wpis w `CHANGELOG.md`, przy każdej
  zmianie. W repozytorium `$colorify_manifest` jest pusty (paczkę z manifestem robi `tools/build-zip.mjs`); na serwerach
  wgrywanych ręcznie też musi zostać pusty, inaczej zmiana rozmiaru pliku zablokuje panel.
- Kilka sesji naraz w jednym katalogu: commit tylko po ścieżkach swoich plików (`git commit -- <pliki>`), nigdy `-a`.
- Testy: `node tools/test-tresci.mjs` (numerowanie wierszy list w panelu), `php -l` każdego zmienionego pliku PHP.
- Wyrażenia regularne w JS/PHP pisane przez narzędzia agenta: po zapisie sprawdź, czy ukośniki przeżyły (`node --check`,
  `php -l`, test). W 2.11–2.12 zgubiony ukośnik w regexie listy sprawił, że zmiany list z panelu nie zapisywały się.
