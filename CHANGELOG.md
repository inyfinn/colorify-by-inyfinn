# Historia zmian

## 2.15.4

Moduł „Cena i decyzje” czytelnie.

- Na górze jedno zdanie „co z tego wynika” (progi 30/100, nigdy „ceny są za wysokie”) i cztery liczby: wyceny, odpowiedzi o budżecie, zgłoszenia, rezygnacje.
- Karty parami (2 kolumny od 1000 px, 1 na telefonie) z przełącznikiem Wykres/Tabela: odpowiedzi o budżecie według pakietu (słupki składane), według pakietu, ceny i odległości, termin w wycenie, od zgłoszenia do umowy, rezygnacje z powodami („para napisała” / „przypuszczenie”).
- Bez danych karta „Co tu zobaczysz” z przykładowym wykresem; na kokpicie przycisk „Zobacz szczegóły” (podstrona z zakresem dat i wersjami cennika).
- Liczby po polsku („1 200 zł”), twarde spacje w zdaniach, tryb ciemny, szerokie tabele na telefonie przewijane w ramce z gasnącą krawędzią.
- Naprawiony brak odstępów: moduł jest teraz wstawiany w opakowaniu panelu.

## 2.15.3

Moduł „Cena i decyzje” w kokpicie: czy ceny odstraszają.

- Widoki Zapytania i Rezerwacje mają nowy blok „Cena i decyzje”, a Kalendarz zleceń ten sam blok zwinięty (obok „Ruch na stronie”). Dane podaje wtyczka zleceń filtrem `colorify_insights_pricing`; bez niego blok się nie pokazuje.
- Zainteresowanie osobno, bez procentów między liczbami (to nie są te same osoby): odsłony strony z ofertą, obliczenia kalkulatora wyceny („obliczenia, nie osoby”), odpowiedzi na pytanie o budżet.
- Prawdziwy lejek dopiero od zgłoszenia: zgłoszenie → odpowiedź → umowa lub zadatek → realizacja, z umowami przypisanymi do miesiąca zgłoszenia.
- Przekroje osobno: pakiet z cennika (pakiety spoza cennika jako „Pakiet indywidualny”), przedział ceny, odległość, wolny lub zajęty termin (wyceny na zajęty termin nie wchodzą do oceny ceny); koszt usługi i dojazdu osobno; rozkład odpowiedzi o budżecie wg pakietu; powody rezygnacji z rozróżnieniem „para napisała” i „przypuszczenie”; zmiany wersji cennika jako osobne okresy.
- Progi: poniżej 30 porównywalnych spraw tylko liczby i „Za mało danych do oceny”; 30–99 powtarzający się sygnał; od 100 udziały z przedziałem Wilsona. Moduł nigdy nie pisze „ceny są za wysokie”.
- Bez listy „kto porzucił”: para nie zostawia danych, dopóki nie wyśle zgłoszenia, więc moduł mówi to wprost.

## 2.15.2

- Analityka: migracja starego licznika (`wp colorify-analytics migrate` i przebieg cronu) pomija dni już ostateczne, więc nie cofa ręcznych korekt (np. usunięcia odsłon testowych).
- Kokpit: panel ruchu dolicza dzień bieżący, gdy nowa metoda pomiaru nie ma jeszcze pełnego dnia; teksty stanów pustych bez nazw filtrów i wtyczek.

## 2.15.1

- Moduł analityki ładuje się z pliku głównego wtyczki (w 2.15.0 brakowało `require_once` i bez tymczasowego mu-pluginu kolektor oraz `wp colorify-analytics` nie działały).

## 2.15.0

Kokpit z prawdziwą analityką i zarządzaniem stroną. Wydanie zbiera 2.14.1, 2.14.2 i nowy moduł analityki.

- **Analityka bez ciasteczek (nowy moduł `includes/colorify-analytics*.php`):** własne tabele `{prefix}colorify_*` z atomowym przyrostem (`INSERT … ON DUPLICATE KEY UPDATE`), wersją schematu, retencją 800 dni (cron, małe partie) i sprzątaniem przy odinstalowaniu (`uninstall.php`). Kolektor po stronie serwera pomija boty (lista UA, HEAD, prerender, prefetch z wyników wyszukiwarki), zalogowanych, podglądy, REST i AJAX, a boty liczy osobno. Odwiedzający to dzienny szacunek (HMAC z losowym sekretem na dzień, skróty żyją do ok. 25 h, do statystyk trafia tylko liczba), nigdy „unikalni w miesiącu”. Zapisuje klasę i domenę źródła, klasę urządzenia, godziny, wejścia z zewnątrz i adresy 404 ze stroną z błędnym linkiem. Bez IP, pełnego UA, pełnych adresów i parametrów (poza UTM z listy).
- **Dziennik odsłon dla stron z cache całych stron:** gdy cache omija PHP, mały skrypt (bez ciasteczek i pamięci przeglądarki, jedno wysłanie na odsłonę) wysyła ścieżkę do lekkiego odbiornika bez WordPressa (`includes/colorify-beacon.php`); pliki godzinowe są chronione i agregowane cronem. Na stronach bez cache skrypt się nie ładuje. Zawsze jedno źródło odsłon na instalację.
- **Migracja:** historia starego licznika (`colorify_visits`) przeniesiona jako metoda „legacy”; każdy dzień ma zapisaną metodę, raporty nigdy nie sumują dwóch metod. WP-CLI: `wp colorify-analytics status | migrate | purge [--dry-run] | beacon <auto|on|off>`.
- **Panel ruchu i szczegóły (`includes/colorify-dashboard-insights.php`):** zakresy 7 / 30 / 90 / 365 dni i własne daty, wykres odsłon (dni / tygodnie, tabela dla czytnika), wszystkie strony z udziałem i zmianą, źródła, urządzenia, siatka dzień × godzina, adresy 404 z podpowiedzią naprawy, karta jakości danych („za mało danych” poniżej 50 odsłon). Klik w liczbę albo stronę otwiera szczegóły (`admin.php?page=colorify-ruch`). Gdy nowa metoda nie ma jeszcze pełnego dnia, bloki doliczają dzień bieżący z podpisem.
- **Moduły widoków:** każdy widok zaczyna się własnym modułem z danych, które WordPress już ma. Firma: aktualizacje, kondycja witryny, ostatnia kopia zapasowa, dane kontaktowe, uszkodzone linki. Zapytania: sprawy i czas oczekiwania, lejek zgłoszenie → odpowiedź → umowa → realizacja (z wtyczki zleceń przez `colorify_insights_*` albo automatycznie z zapisanych zgłoszeń formularzy). Redakcja, Portfolio, Sprzedaż: własne zestawy.
- **Zasada interfejsu:** kokpit nie pokazuje użytkownikowi nazw filtrów, funkcji ani wtyczek do skonfigurowania. Bez danych: zwykłe zdanie albo brak modułu; informacje techniczne tylko dla właściciela, zwinięte.
- **Telefon i tryb ciemny:** panel ruchu bez poziomego przewijania od 320 px, cele dotyku 44 px, tabela stron w dwóch kolumnach na telefonie; w trybie ciemnym cienka siatka wykresu, widoczne pola i puste karty, neutralne obrysy przycisków planera zamiast zielonych.

## 2.14.2

Kalendarz kokpitu: zaznaczanie dni i blokowanie kilku naraz.

- Klik w dzień zaznacza go, Ctrl (Cmd) dodaje i usuwa kolejne, Shift bierze zakres od ostatnio klikniętego. Zaznaczony dzień ma gruby obrys, mocniejsze tło i ptaszek w rogu, więc nie myli się z „dziś” (cienki obrys) ani z zablokowanym (skośne paski na całej komórce). Pod siatką pojawia się pasek „Zaznaczono: 3 dni” z akcjami i „Wyczyść”; Esc czyści zaznaczenie.
- Prawy przycisk na dniu (albo Shift+F10 / klawisz menu na dniu z fokusem) otwiera menu: „Zablokuj dni (N)”, „Odblokuj dni (N)” (gdy w zaznaczeniu są blokady), „Otwórz terminarz”. Menu staje pod klikniętym dniem i zawsze mieści się na ekranie. Siatka ma obsługę klawiatury: strzałki chodzą po dniach, z Shift zaznaczają zakres, Spacja zaznacza.
- Telefon i tablet: nad listą dni jest mała siatka miesiąca (numer dnia i kropki wydarzeń). Dotknięcie dnia przewija listę do niego, przytrzymanie zaczyna zaznaczanie, a akcje są w pasku u dołu ekranu.
- Zapis: jedno żądanie na wszystkie dni, po potwierdzeniu z listą dat; przy dniu, w którym już coś jest (zlecenie, kontakt), potwierdzenie to pokazuje. Po zapisie kokpit wraca w tym samym miesiącu. Błąd zapisu zostaje w oknie potwierdzenia.
- Blokowanie włącza wtyczka zleceń filtrem `colorify_calendar_block` (adres admin-ajax, akcja, nonce, adres terminarza); bez niego kalendarz działa jak dotąd. Opis żądania i odpowiedzi: nagłówek `includes/colorify-dashboard-planner.php`.
- Karta zablokowanego dnia pokazuje `info` z podziałem na linie (np. druga linia „Zablokowane przez: …”), a jej drugi przycisk ma całą szerokość.
- Telefon 320–390 px: kafle skrótów nie wychodzą poza ekran (poniżej 360 px jedna kolumna), długie etykiety liczb łamią się zamiast rozpychać siatkę, przycisk „Widok” stoi w jednym rzędzie z podtytułem, a zakres 7 / 30 / 90 dni pod spodem na całą szerokość; cele dotyku 44 px.
- Moduł analityki (`includes/colorify-dashboard-insights.php`) w widokach: każdy z widoków Zapytania, Firma, Redakcja, Portfolio i Sprzedaż zaczyna się własnym modułem (`colorify_insights_module()`), a stary pasek ruchu i karty „Popularne podstrony” / „Najczęściej czytane” zastąpił panel ruchu (`colorify_insights_traffic_panel()`). W „Firmie” moduł niesie kondycję, aktualizacje i listę rzeczy do zrobienia, więc dawny pasek liczb i karta „Wymaga uwagi” wypadły. „Kalendarz zleceń” i „Rezerwacje” mają panel ruchu na dole, zwinięty pod nagłówkiem „Ruch na stronie”.
- „Zapytania” na stronie z wtyczką zleceń: najbliższe zlecenie i liczba nadchodzących liczą się z całego okna kalendarza, nie z najbliższych 180 dni (przy weselach planowanych z rocznym wyprzedzeniem widok mówił „0” i „Brak nadchodzących zleceń”).
- `colorify-ui.css` i `colorify-modern.css` mają wersję z czasem pliku, jak arkusz planera.

## 2.14.1

Kokpit: zlecenia zwinięte do jednego wiersza, zrozumiały zablokowany dzień, widoczny główny skrót i przełącznik widoku na kokpicie.

- Lista „Najbliższe” (styl „Kalendarz zleceń”): jeden wiersz na zlecenie zamiast osobnego wiersza na każde zadanie pary. Wiersz zlecenia jest mocniejszy od reszty (blok w tonie karty, data na pełnym akcencie, większa nazwa) i pokazuje datę, ile dni zostało, liczbę zadań i najbliższe z nich. Klik albo Enter rozwija zadania zlecenia po kolei, z samym zleceniem na swoim miejscu w czasie; status („Zadatek wpłacony”) stoi raz, w nagłówku rozwinięcia, obok „Otwórz zlecenie”. Wydarzenia bez zlecenia (zablokowany dzień, luźny kontakt) zostają zwykłymi wierszami. Grupowanie idzie po polu `id` wydarzenia; wtyczka, która go nie podaje, dostaje listę jak dotąd.
- Zablokowany dzień: karta mówi, co to jest („Tego dnia nie przyjmujesz zleceń”), zamiast „Skopiuj informacje” i „Otwórz zlecenie” ma „Otwórz terminarz”, a legenda i listy nazywają go „Dzień zablokowany”. Nowe opcjonalne pola wydarzenia w filtrze `colorify_calendar_events`: `short` (krótka nazwa na chip w siatce miesiąca), `info` (zdanie wyjaśnienia w karcie) i `actions` (własne przyciski karty: odnośnik albo formularz POST z ukrytymi polami i pytaniem przed wysłaniem, tylko na adres panelu tej strony). Najwęższa siatka miesiąca (1024–1099 px) ma węższe marginesy, żeby „Zablokowany” mieścił się bez wielokropka.
- Pierwszy kafel paska skrótów (np. „Wiadomości” w stylu „Zapytania”) jest wypełniony kolorem akcentu. Wcześniej miał 10% akcentu na bieli, czyli prawie kolor tła strony, i znikał.
- Przełącznik widoku: przycisk „Widok: …” w prawym górnym rogu kokpitu (na klasycznym kokpicie WordPressa obok tytułu „Kokpit”) z listą widoków i jednym zdaniem, co każdy daje. Klik zmienia widok tylko klikającemu (meta użytkownika, odnośnik z nonce); zaraz po przełączeniu obok przycisku jest cichy odnośnik powrotu do poprzedniego widoku. Widok, którego strona nie obsłuży (Sprzedaż bez WooCommerce, Kalendarz i Rezerwacje bez wtyczki zleceń), jest widoczny, wyszarzony, z wyjaśnieniem.
- Ustawienia, Colorify, „Styl kokpitu”: karty zamiast zakładek (nazwa, co daje, „Zobaczysz: …”, plakietki „Domyślny” i „Niedostępny tutaj”); klik karty ustawia styl domyślny strony, czyli ten dla kont bez własnego wyboru.
- Arkusz i skrypt planera mają wersję z czasem pliku, jak panel Treści strony.

## 2.14.0

Edytor treści: przełączniki tylko dla właściciela Colorify i klik w podglądzie prowadzi prosto do pola.

- „Edytor treści” i nowa „Zaawansowana edycja” (Wygląd, akapity jako bloki, Układ strony, nowe sekcje) widzi i przełącza tylko właściciel: konta ze stałej `COLORIFY_WLASCICIEL` w wp-config.php (loginy albo e-maile po przecinku), bez stałej konto o ID 1. Inni administratorzy nie widzą tych wierszy, a zapis od nich jest odrzucany po stronie serwera (`pre_update_option`, każda droga: formularz, options.php, REST, `wp option update` bez `--user=<właściciel>`).
- Domyślnie oba wyłączone. Wyłączona „Zaawansowana edycja” = panel jak w 2.12 (zwykłe pola akapitów, bez Wyglądu i układu, WP-CLI `styl`/`uklad`/`sekcja` odmawia, POST z kluczami `__style`/`__uklad`/`__sekcje` jest ignorowany); to, co już zapisano, strona dalej pokazuje, żeby wyłączenie niczego nie rozjechało.
- Klik w element podglądu: otwiera właściwą kartę (pozostałe się zwijają), trafia w konkretny akapit bloku albo wiersz listy, przewija panel tak, że pole stoi w pionowym środku widoku (pod paskiem admina, nad paskiem zapisu), daje fokus i krótkie podświetlenie. Przyczyna dawnego „przesuwa się, ale nie tam”: `scrollIntoView()` w ramce podglądu przewijał też stronę panelu; teraz ramka przewija tylko siebie.
- Panel „Wygląd” zawsze pokazuje, jak tekst wygląda TERAZ: stan odczytany z podglądu (styl obliczony elementu przy szerokości wybranego ekranu) zamieniony na najbliższy token, przerywana złota ramka = „z projektu” z podpisem i faktyczną wartością (np. „z projektu: 23 px”), pełne zaznaczenie + kropka = zmiana. Bez podglądu czytelny komunikat zamiast pustego panelu.
- „Kopiuj wygląd” / „Wklej” (schowek panelu) i „Jak powyżej” / „Jak poniżej” (sąsiedni tekst w karcie): wklejenie zapisuje tylko różnice względem projektu danego pola.
- Naprawa: nowa sekcja „Tekst + zdjęcie” znikała przy zapisie (pole „po której stronie zdjęcie” nazywało się `strona`, jak strona sekcji); teraz `zdjecie_po`.
- Test w WordPressie `wp eval-file tools/test-tresci.php` sprawdza też allowlistę stylu, akapity, normalizację układu i odrzucenie przełącznika od nie-właściciela.

## 2.13.0

Edytor treści: wygląd tekstu, akapity, układ stron i nowe sekcje (twarde reguły edytora: `docs/edytor-tresci.md` 8e, `CLAUDE.md`).

- „Wygląd” przy polach tekstowych: krój, rozmiar XS–XXL (clamp), grubość, kolor z palety, wyrównanie, odstęp pod/nad, interlinia, odstęp liter, szerokość, wielkie litery, kursywa; osobno komputer i telefon; podgląd na żywo bez przeładowania; reset każdego ustawienia, „Tylko zmienione”, „Przywróć cały wygląd”. Na stronę trafiają tylko zmienne CSS z allowlisty tokenów, arkusz reguł (ok. 1,5 KB) tylko gdy coś jest ustawione.
- Pole `akapity`: dodaj, usuń, przesuń, duplikuj, rodzaj akapitu. Grupa zbiera stare pola (`z_pol`), więc zapisane treści i WP-CLI działają jak dotąd, a HTML bez zmian w panelu jest bajt w bajt ten sam.
- Układ stron (rejestr `uklad` + `colorify_render_page()`): kolejność, ukrywanie i odstępy sekcji, biblioteka nowych sekcji (Tekst, Tekst + zdjęcie, Cytat, Przycisk z zachętą, Odstęp) z klasami motywu; nawigator „Układ strony” i pasek sekcji w karcie, ikony z dymkami.
- Dane w tej samej opcji pod kluczami `__style`, `__uklad`, `__sekcje` (jedna bramka wersji, jedna historia, przywracanie obejmuje wszystko); zapis pod blokadą (`INSERT IGNORE`), panel i WP-CLI nie zapiszą w tej samej chwili.
- WP-CLI: `tokeny`, `styl`, `uklad`, `sekcja list|dodaj|set|usun`, `get __style|__uklad|__sekcje`.
- Naprawa: zmiany list (FAQ, menu, Instagram, punkty) z panelu nie zapisywały się od 2.11.0 (zgubiony ukośnik w regexie numerowania zmieniał nazwy pól na `ej[...]`); usunięcie wszystkich wierszy listy też nie dochodziło do zapisu. Test: `node tools/test-tresci.mjs`.
- Assety panelu mają wersję z czasem pliku (każda poprawka omija pamięć przeglądarki).

## 2.12.4

- Kokpit "Kalendarz zleceń" w trybie ciemnym: komórki kalendarza i obrysy przycisków są znowu widoczne. Silnik trybu ciemnego przelicza kolor tekstu użyty jako tło albo ramka na ciemny, więc stopnie "tekst 4–8% na karcie" wychodziły identyczne z kartą: dni miesiąca zlewały się w jedną płaszczyznę, a przyciski (strzałki miesiąca, "Dziś", "Otwórz zlecenie", "Zadzwoń", "Trasa", "Pokaż wszystkie") wyglądały jak zwykły tekst. Pod `body.colorify-dark` zmienne `--line`, `--cfb-soft` i `--cfb-neutral-bg` oraz tła dni weekendowych i spoza miesiąca liczą się od `currentColor`, który po przeliczeniu zostaje kolorem tekstu. Tryb jasny bez zmian.

## 2.12.3

Kokpit "Kalendarz zleceń" i ekrany panelu na telefonie i tablecie: nic nie nachodzi na siebie w zakresie 320–1920 px.

- Karta szczegółów na tablecie (768–1023 px) otwiera się przy wydarzeniu. Skrypt uznawał za telefon wszystko do 1023 px i nie pozycjonował karty, a arkusz dolny w CSS działa tylko do 767 px, więc karta lądowała w lewym górnym rogu, na pasku panelu. Karta nie wchodzi też już na pasek panelu na żadnej szerokości.
- Poniżej 1100 px kafle kokpitu są w jednej kolumnie (liczby, najbliższe wesele, do zrobienia, kalendarz, najbliższe, zadatki). Dwa kafle z listami obok siebie miały na tablecie ok. 230 px na wiersz: plakietka statusu spadała pod datę, ikona zostawała sama w linii, a pusty kafel rozciągał się do wysokości sąsiada.
- Wiersz listy to siatka (znacznik, treść, plakietka albo kwota); w wąskim kaflu plakietka i kwota schodzą pod treść, w jej kolumnie (zapytanie o kontener zamiast sztywnego marginesu 62 px).
- Szczegóły (etykieta / wartość): dwie kolumny z zawijaniem. Wiersz z długim słowem, np. adresem e-mail, sam bierze całą szerokość, zamiast łamać się w środku słowa.
- Pasek kalendarza: nazwa miesiąca nie łamie się i nie wchodzi pod strzałki; poniżej 480 px strzałki i "Dziś" są w drugim rzędzie na całą szerokość.
- Karta szczegółów poniżej 480 px: plakietka statusu pod etykietą rodzaju (wcześniej na nią nachodziła), lista statusów wyrównana do lewej.
- "Najbliższe wesele": trzeci przycisk akcji zajmuje cały rząd zamiast połowy.
- "za 205 dni" i separator "·" mają twarde spacje: wiersz nie kończy się na "za 204" ani nie zaczyna od kropki.
- Pasek panelu przy 320 px: pozycja Colorify bez strzałki (pasek miał 325 px, więc Colorify i awatar spadały pod pasek, na zakładkę "Pomoc" / "Opcje ekranu").
- Kafle na listach: komunikat "ekran jest za wąski, poszerz okno" ukryty poniżej 783 px (suwak rozmiaru jest tam ukryty, więc komunikat nic nie mówił).
- Kreator e-maili: w "Wyślij test" etykieta jest nad polem; pole adresu miało ok. 125 px i ucinało adres.

## 2.12.2

- Kreator e-maili: brak opcji szablonu jest rozpoznawany poprawnie (`get_option()` zwraca `false`, nie `null`), więc zapis niezmienionych domyślnych naprawdę nic nie zapisuje; 2.12.1 łapało to tylko dla szablonów pod kluczem wspólnej opcji.

## 2.12.1

- Kreator e-maili: zapis formularza równego domyślnym nie tworzy kopii domyślnych w opcji (komunikat "nic się nie zmieniło"); wtyczka dalej czyta swoje domyślne.

## 2.12.0

- Nowy moduł **Kreator e-maili** (Ustawienia, Colorify, przełącznik; domyślnie WYŁĄCZONY). Ekran "Wiadomości do par": szablony maili zarejestrowane przez wtyczkę filtrem `colorify_maile_rejestr` (temat, nagłówek, akapity z B / I, punkty, przycisk, podpis, drobny druk), chipy zmiennych wstawiane w miejscu kursora, wersje wielokrotne (np. 6 wersji na przycisk; losowanie robi wtyczka) z zakładkami, dodawaniem i usuwaniem, podgląd na żywo w iframe (600 / 375 px, dane przykładowe albo wybrany rekord), "Wyślij test" na własny adres (domyślnie z rejestru albo e-mail zalogowanej osoby), włącz / wyłącz, "Przywróć domyślne", historia 8 wersji z przywracaniem i bramka wersji ("ktoś zapisał w międzyczasie" = odmowa). Dane zostają w opcjach wtyczki (także wspólna opcja z kluczami i format pozycyjny), więc nie ma migracji; wtyczka dostarcza dane rekordu, wartości zmiennych, renderer HTML i wysyłkę, a bez renderera moduł rysuje prosty mail w stylu kremowo-złotym. Stary ekran wtyczki (`zastepuje`) przekierowuje na moduł, menu zostaje. Dokumentacja: docs/kreator-maili.md.

## 2.11.3

- Edytor treści: kafelki "Resztę strony edytujesz tutaj" większe (min. 200 px szerokości, 72 px wysokości, siatka auto-fill) z ikoną w kremowym kółku po lewej (22 px, złoto, linia 1,5 px), tytułem 15 px i podpisem 12 px. Rejestr motywu podaje ikonę jako czwarty element wpisu `gdzie`: nazwa z zestawu modułu (`metka`, `serce`, `koperta`, `podpis`, `ksiazka`, `tarcza`, `ciastko`, `dymek`) albo z zestawu kokpitu (`mail`, `cash`, `calendar`, `shield`, `file`, `comment`...), albo gotowy `<svg>`; bez ikony strzałka. `Colorify_Dashboard::ikona_sciezki()` udostępnia ścieżki ikon kokpitu innym modułom.

## 2.11.2

- Kokpit, kafle liczb: wartość nigdy nie łamie się w środku słowa (`overflow-wrap: normal; word-break: keep-all`); wartość ze słowem (np. "14 listopada") dostaje rozmiar tekstu (21 px), liczby z jednostką ("4 690 zł") zostają duże; przy 6 kaflach siatka 3×2 poniżej 1600 px, od 1600 px sześć kolumn z ciaśniejszym kaflem i fontem `clamp(20px, 1.5vw, 28px)`.

## 2.11.1

- Kokpit, kafle liczb (filtr `colorify_dashboard_kpis`): trzy nowe, opcjonalne pola elementu: `tone` (znane: `warning`, ciepły bursztyn: jasny `#fdf1dc` / obrys `#e3b56b` / tekst `#4a2e05`, ciemny `#3a2a12` / `#d79a3e` / `#f6e2bf`), `note` (uwaga pod wartością, np. zbliżanie się do limitu działalności nierejestrowanej) i `action` (`label` + `url`: mały link w kafelku, np. "Ukryj"). Kafel z `action` jest blokiem, a jego `url` przechodzi na wartość (link w linku jest niedozwolony). Bez tych pól kafel bez zmian.
- Strażnik kontrastu: gradient tła z przezroczystą stopą (np. poświata na ciemnym kafelku) nie jest już uśredniany do jednego koloru, który fałszował tło i wymuszał ciemny tekst na ciemnym kafelku; takie tło jest traktowane jak nieznane (jak `url()`), tekst zostaje.

## 2.11.0

- Nowy moduł **Edytor treści** (Ustawienia, Colorify, przełącznik; domyślnie WYŁĄCZONY, więc istniejące strony nie zauważą aktualizacji). Panel "Treści strony": teksty, zdjęcia, filmy i listy strony w jednym miejscu, z podglądem na żywo prawdziwej strony obok formularza (iframe tej samej domeny: pisanie i kadr podmieniane w DOM od razu, fokus pola przewija i podświetla element, kliknięcie w podglądzie otwiera pole; Komputer 1440 / Duży ekran 1920 / Telefon 390; poniżej 1200 px podgląd wysuwany), kadrowaniem zdjęć w proporcjach dokładnie takich jak na stronie (punkt ostrości, przybliżenie 1-2,5×, zapis inline `object-position` + `scale` + `clip-path`, bez wrapperów), edytorem akapitów bez surowego HTML (B / I / Enter, znaczki "↵ tylko komputer" i "w jednej linii"), listami z dodawaniem, usuwaniem i przesuwaniem, kaflami sekcji z żywą miniaturą, historią 10 wersji z przywracaniem i bramką "nie nadpisuj, czego nie widziałeś" (wersja opcji w formularzu, odmowa z listą pól zmienionych w międzyczasie i scalanie; pasek ostrzeżenia co 60 s). Motyw rejestruje schemat filtrem `colorify_tresci_rejestr` (pola text/area/url/email/media z kadrem, listy faq/ig/punkty/menu i własne, strony do podglądu). WP-CLI `wp colorify-tresci list|get|set|reset|wersja` (plus alias z rejestru) zapisuje tą samą funkcją co panel, z autorem "AI/WP-CLI" i `--wersja`. Dokumentacja: docs/edytor-tresci.md; test: `wp eval-file tools/test-tresci.php`.

## 2.10.2

- Kokpit "Kalendarz zleceń": kalendarz miesiąca na komputerze ma najwyżej 600 px wysokości (6 tygodni po 65 px). W dniu do dwóch wydarzeń w jednej linii (jedno wydarzenie: dwie linie), reszta pod "+N więcej"; pełna nazwa jest w karcie szczegółów. Telefon i tablet bez zmian (lista dni). Od 1600 px kafel "Najbliższe wesele" schodzi obok listy "Najbliższe", więc pod krótszym kalendarzem nie ma pustego miejsca.
- Karta szczegółów: status zlecenia zmienia się z karty. Gdy wtyczka poda przy wydarzeniu `status_options` (lista `[ wartość, etykieta ]`) i `status_action` (`[ 'action' => nazwa akcji wp_ajax_*, 'nonce' => nonce ]`), plakietka statusu jest listą do wyboru; wybór od razu wysyła `action`, `nonce`, `id` i `status` do admin-ajax.php, a karta, kalendarz i plakietki na listach odświeżają się bez przeładowania. Bez tych pól status jest tylko do odczytu jak dotąd. Klawiatura: strzałki, Enter, Escape (zamyka listę, karta zostaje).
- Tryb ciemny: kremowe i beżowe tła (ciepła biel, np. `#F6EFE0`, `#FFFCF4`) nie wychodzą już oliwkowo-musztardowe. Silnik (Dark Reader 4.9.133, dołączony) uznawał za neutralne tylko kolory o nasyceniu HSL poniżej 12%, a krem ma 58-100%, więc zachowywał jego odcień przy jasności ok. 10%. Poprawka w `assets/vendor/darkreader.js` (oznaczona "COLORIFY PATCH"): ciepła biel (jasność > 80%, odcień 20-65°, chroma < 12%) przyjmuje barwę bieguna tła z palety (np. czekoladę), a ciepłe odcienie w ciemnej wersji mają nasycenie najwyżej 40%.
- Nowy filtr `colorify_dark_ignored_stylesheets`: wtyczka strony z własnymi, ręcznie dobranymi kolorami trybu ciemnego wyłącza swój arkusz z przeliczania (fragment adresu URL).

## 2.10.1

- Kafle na listach (Zlecenia, Opinie, Wpisy...): przyciski, pigułki, przełącznik Kafle/Lista, suwak i znaczniki stanu dziedziczą kształt z ustawień Colorify (kanciasty kształt strony = kanciaste wszędzie), główne przyciski (Edytuj, kosz, aktywny widok) mają kolor akcentu palety zamiast czerni tekstu, pierścień fokusu i obrys dodawania 1 px, padding wewnątrz kafli i panelu pól o 8 px większy.

## 2.10.0

- Kokpit "Kalendarz zleceń" przebudowany jako bento (CSS grid z nazwanymi obszarami, 100% szerokości obszaru treści, układ zależny od szerokości: ≥1600, ≥1100, tablet, telefon). Liczby w równych, osobnych kafelkach; kalendarz miesiąca na całą szerokość o stałej wysokości (zawsze 6 tygodni, nic nie skacze); duży kafel "Najbliższe wesele" z kompletem szczegółów (para, data, dzień tygodnia, za ile dni, miejsce i sala, dojazd, nocleg, pakiet i jego zawartość, kwota, status, telefon, e-mail, wiadomość, przypomnienia przy zleceniu) i przyciskami Skopiuj informacje, Otwórz zlecenie, Zadzwoń, Trasa; "Do zrobienia w tym tygodniu", "Najbliższe", "Zadatki do wpłaty". Listy pokazują 6 pozycji i "Pokaż wszystkie", w kafelkach nie ma przewijania.
- Wydarzenia w kalendarzu: ikony rodzaju (wesele, kontakt, oddanie, przygotowanie, blokada), kolor statusu zlecenia, pełne nazwy (zawijane, bez natywnego tooltipa `title`), "+N więcej" rozwija dzień, dzień z wydarzeniem wyraźnie oznaczony.
- Karta szczegółów zamiast tooltipa: pokazuje się po najechaniu, fokusie albo dotyku; znika po 0,5 s, gdy kursor jej opuści, i nie znika, gdy kursor wróci na wydarzenie albo wejdzie na kartę; klik, tap albo Enter przypina. Przyciski Skopiuj informacje (wielowierszowy tekst do Messengera, bez notatek wewnętrznych), Otwórz zlecenie, Zadzwoń, Trasa oraz Poprzednie/Następne (po wydarzeniach chronologicznie, także między miesiącami). Przy każdej wartości (miejsce, sala, dojazd, pakiet, kwota, telefon, e-mail...) pokazuje się ikonka kopiowania; klik kopiuje wartość i pokazuje ptaszek.
- Wybór miesiąca i roku: klik w nagłówek (siatka 12 miesięcy, pole roku ze strzałkami do wpisania, "Dziś"), skróty ←/→ (miesiąc) i Shift+←/→ (rok); adres zapamiętuje miesiąc. Na telefonie zamiast siatki 7 kolumn lista dni z wydarzeniami; cele dotykowe co najmniej 44 px, przyciski na pełną szerokość, karta jako dolny arkusz.
- Dane wydarzeń z filtra `colorify_calendar_events` mogą mieć pola `id`, `phone`, `email`, `route_url`, `status_label`, `reminder_done` i `details`; bez nich karta pokazuje to, co jest. Nowy filtr `colorify_calendar_capability` (domyślnie `edit_posts`) chroni dane osobowe w kokpicie. Nowy rodzaj `przygotowanie`.
- Ekran powitalny WordPressa (about.php, credits.php, freedoms.php, contribute.php) przekierowuje na kokpit. Domyślnie włączone (nowości po aktualizacji WordPressa nie są nikomu potrzebne, a kokpit Colorify ma być pierwszy); wyłączenie: Ustawienia, Colorify, "Ekran powitalny WordPressa". Filtr `colorify_about_redirect_pages` zmienia listę ekranów.
- "Dopasuj do mojej strony" odczytuje kształt przycisków strony (najczęstszy róg z do 12 przycisków, także obrysowych; pigułka albo kanciasty) i, jako osobna opcja "Zastosuj kształt przycisków strony w panelu", ustawia rogi przycisków, pigułek, pól i kart w panelu (przyciski kanciaste = wszystko kanciaste). Opcja jest niezależna od czcionek i kolorów (paleta nie jest zmieniana, gdy odznaczysz "Zastosuj kolory strony").
- Styl Nowoczesny: padding wewnątrz okien (karty, metaboksy, tabele ustawień, welcome, kafle kokpitu) o 8 px większy; obrys przycisków w panelu najwyżej 1 px (także pierścień fokusu z klawiatury); cienki pasek przewijania w całym panelu (4 px, przezroczysty tor, bez strzałek, uchwyt w kolorze akcentu; filtr `colorify_scrollbar_color` albo zmienna `--colorify-scrollbar-thumb` zmienia kolor; Firefox przez `scrollbar-width` tylko tam, gdzie nie ma `::-webkit-scrollbar`).
- Kokpit Redakcja i licznik w pasku admina: gdy komentarze są wyłączone (brak menu Komentarze, wpisy ich nie obsługują albo są zamknięte i nie ma żadnego), nie ma kafla, skrótu ani karty komentarzy i znika licznik komentarzy z paska.

## 2.9.0

- "Dopasuj do mojej strony" odczytuje teraz kolory motywu. Wcześniej wykrywacz pomijał kolory ciemne i stonowane (brąz `#301E0E`, granat) jako "za mało nasycone", a zmienne CSS strony (`:root { --color-primary: ... }`) w ogóle nie były czytane, więc strona z brązową marką nie dawała żadnej palety. Teraz: czyta zmienne CSS z `:root`, `html`, `body` i zestawu Elementora (waży nazwy: primary, brand, main, accent, secondary), liczy użycie kolorów w elementach z nasyceniem HSV (ciemny brąz jest barwny), przy czerni i bieli bierze najczęstsze ciemne tło przycisku, wykrywa jasne tło strony (krem). Ciemny akcent staje się kolorem menu, a krem tła strony kolorem podświetlenia na menu (jasna pigułka z ciemnym tekstem).
- Podgląd dopasowania ma dwa przełączniki: "Zastosuj kolory strony" i "Zastosuj też czcionki i rogi strony". Czcionki, których strona nie udostępnia lokalnie, są domyślnie odznaczone, a panel zostaje przy czcionkach WordPressa (skóra "neutral"). Pod podglądem widać, skąd pochodzą kolory (zmienne CSS motywu albo wygląd elementów).
- Tokeny: gdy akcent zlewa się z menu (brąz na brązie), podświetlenie menu bierze drugi kolor palety, jeśli się wyróżnia (kontrast co najmniej 3:1); przyciski w treści zostają w kolorze akcentu.
- Pasek administratora: jedno logo. Logo WordPressa po lewej jest ukryte (filtr `colorify_hide_wp_logo`, domyślnie `true`), ikona witryny przy nazwie pojawia się tylko, gdy witryna ma własną ikonę (wcześniej bez niej dokładany był znak INYFINN, który dublował znak w menu Colorify i nakładał się na ikonę domu). Gdy witryna ma ikonę, menu Colorify dostaje neutralną ikonę palety zamiast logo INYFINN.
- Kokpit: trzy nowe style. **Kalendarz zleceń** (miesiąc z wydarzeniami, lista najbliższych, liczby), **Rezerwacje** (etapy zleceń z liczbą i wartością, najbliższe zlecenie, lista) i **Portfolio** (ostatnio dodane zdjęcia, liczba zdjęć i filmów, strony, wejścia). Kalendarz i Rezerwacje czytają dane przez filtry `colorify_calendar_events( array(), $from_ymd, $to_ymd )` (elementy: `date`, `title`, `url`, `type`, `status`, `amount`, `meta`) i `colorify_dashboard_kpis( array() )` (elementy: `label`, `value`, `hint`, `url`). Bez danych jest pusty kalendarz z podpowiedzią, nie błąd. Na telefonie siatka miesiąca pokazuje kropki, a szczegóły są na liście pod spodem.
- Kokpit Zapytania: gdy formularze niczego nie zapisują, ale wtyczka podaje zlecenia przez powyższe filtry, kafel nie straszy "Formularze nie zapisują wiadomości", tylko pokazuje liczbę zleceń w terminarzu i najbliższe zlecenie.

## 2.8.3

- Logowanie: logo strony na górze tylko z filtra `colorify_login_logo`. Wersja 2.8.2 brała też logo z Tożsamości witryny, które jest zwykle ciemne i znikało na ciemnym tle ekranu logowania (kancelaria, ETA). Strony bez filtra mają znów nazwę z ikoną na górze; logo INYFINN zostaje na dole.

## 2.8.2

- Logowanie: na górze logo strony (obraz), na dole logo INYFINN. Logo strony pochodzi z filtra `colorify_login_logo( $url, $mode )` (wtyczka strony podaje osobny obraz dla trybu ciemnego i jasnego) albo z Wygląd > Personalizuj > Tożsamość witryny. Bez logo zostaje nazwa strony z ikoną jak dotąd. Znak INYFINN przeniesiony z góry do stopki, nad podpis "Colorify · inyfinn.art".

## 2.8.1

- Logowanie: logo z motywu nie nakłada się już na znak i nazwę Colorify. Motyw (np. Realexa) drukuje w nagłówku `#login h1 a { background-image: ...; width: 250px; height: 50px }`; selektor z id był silniejszy od arkusza Colorify, więc obraz leżał pod nazwą. Teraz Colorify odbiera temu elementowi tło, wymiary i elementy pseudo.

## 2.8.0

- Kafle na listach wpisów, stron i własnych rodzajów treści (Wpisy, Strony itd.). Nad tabelą przełącznik "Kafle / Lista" (każde konto pamięta swój wybór, domyślnie lista) i suwak "Rozmiar kafelków" od 3 do 10 w rzędzie. Na węższym ekranie kafli w rzędzie jest mniej: licznik pokazuje prawdziwą liczbę i jest komunikat, że widok się zmienił.
- Pierwszy kafel to zawsze "Dodaj nowy...". Kafel ma obrazek, tytuł i wybrane pola; na dole Edytuj, Duplikuj, Zobacz i kosz (akcje innych wtyczek z wiersza tabeli też, np. Duplicate Page). Gdy kafel jest wąski, przyciski tracą podpisy, a potem chowają się pod "..."; nigdy nie znikają.
- Tryb edycji ("Dostosuj kafelki", tylko administrator): zaznaczasz, co pokazuje kafel danego rodzaju treści (obrazek, status, data, autor, zajawka, kategorie i tagi, pola własne). Ustawienie obowiązuje wszystkich.
- Dla wtyczek stron: filtry `colorify_list_tile_fields` (własne pola, np. adres i godziny sklepu), `colorify_list_tile_image`, `colorify_list_tile_title`, `colorify_list_tiles_default_view`, `colorify_list_tiles_excluded_post_types` i funkcja `colorify_lt_active()` (czy lista jest teraz w kaflach). Klasy `cfl-badge` (`is-ok`, `is-bad`) i `cfl-table` do pól z plakietką i tabelką.

## 2.7.4

- Repozytorium: nowa wtyczka strony `site-plugins/eta-przekierowania` 1.0.0 (ETA Innovations: przekierowania 301 ze starych adresów zamiast strony 404). Wydanie dołącza `eta-przekierowania.zip`. Sam Colorify bez zmian.

## 2.7.3

- Licznik wejść: adres, którego nie ma (404), nie liczy się już jako wejście. Wcześniej motywy, które przy 404 pokazują własną stronę (np. "Page 404" w motywie Pharma), sprawiały, że strona błędu wychodziła jako "Najczęściej oglądana".
- Kokpit: nowa karta "Adresy, które nie istnieją (404)" pod paskiem ruchu: ścieżka, ile razy i skąd ludzie przyszli. Pojawia się tylko, gdy są takie wejścia. Skanery szukające plików (.php, .env, kopie) i boty są pomijane.

## 2.7.2

- Widok kafli (po przeglądzie projektowym): kliknięcie w skrót zwiniętego kafla otwiera go; kafle zwinięte od startu także dla kont, które nigdy nie zwijały skrzynek; wymagane pole w zwiniętym kaflu otwiera kafel zamiast cicho blokować zapis; przeciąganie skrzynek wyłączone przy kaflach (kolejność strzałkami, także z klawiatury); po otwarciu kafla cudze skrypty dostają `resize`; skrót podpięty pod przycisk zwijania (`aria-describedby`); grupy pól ACF mają skrót (pierwsze pole i "wypełnione X z Y"); pusty kafel mówi, co zrobić; ostatni nieparzysty kafel na całą szerokość.

## 2.7.1

- Widok kafli: znane skrzynki mają skrót jak w panelu Usług. Dane produktu WooCommerce: cena duża (promocyjna z ceną regularną obok), stan magazynu i "Wirtualny" jako plakietki, SKU. Krótki opis: początek tekstu. Komentarze i opinie: liczba. Pola własne: liczba. Pozostałe skrzynki: pierwsze wypełnione pole jak dotąd.

## 2.7.0

- Styl Nowoczesny: tabele list (wpisy, strony, media, użytkownicy, wtyczki, komentarze, zamówienia) w stylu listy usług. Nagłówek kolumn to osobna zaokrąglona belka, wiersze to karta pod nią z cienką linią między wierszami; najechanie lekko przyciemnia ten sam odcień, zebra zostaje. Od 783 px; na telefonie karty wierszy WordPressa bez zmian. Lista usług kancelarii (kw_usluga) ma własny układ.
- Przełączniki WooCommerce w kolorze marki z jasną gałką.
- Repozytorium: folder `site-plugins/` na wtyczki stron klientów (na razie `kw-uslugi` 2.0.4, Kancelaria Wieczorek). Colorify ich nie ładuje; każde wydanie dołącza `kw-uslugi.zip` jako osobny plik.

## 2.6.1

- Styl Nowoczesny: zebra w tabelach list (co drugi wiersz lekko ciemniejszy) jako nakładka zamiast koloru, więc działa też w trybie ciemnym. Wcześniej tryb ciemny przeliczał kolor i paski znikały (kancelaria).

## 2.6.0

- Nowość: "Widok kafli" na klasycznych ekranach edycji (produkty, wpisy, strony). Skrzynki stają się zwijanymi kaflami w stylu panelu Usług; zwinięty kafel pokazuje pierwsze wypełnione pole. Włącza i wyłącza się pigułką przy tytule ekranu, osobno dla każdego rodzaju ekranu (tylko administrator). Domyślnie wszędzie wyłączony; edytor bloków i ekran usług kw-uslugi bez zmian (filtr `colorify_tiles_excluded_post_types`).
- Kokpit: tekst w pasku statystyk (np. "Najczęściej oglądana") ma mniejsze pismo i mieści się w dwóch liniach zamiast rozpychać kafel; poprawna odmiana "wyświetlenie / wyświetlenia / wyświetleń".

## 2.5.2

- Tryb ciemny: karty i kafelki o krok jaśniejsze od tła (lepiej widoczne na kokpicie i ekranach Colorify); kafel skrótu kokpitu jaśnieje przy najechaniu. Tło, karta akcentowa i tryb jasny bez zmian.

## 2.5.1

- Styl Nowoczesny: najechanie na pozycję menu pokazuje kolor schematu tylko na zaokrąglonym polu, bez trójkątów i teł całego wiersza w żadnym stanie (sprawdzone na stronie z ciemnym motywem i wtyczkami z własnymi pozycjami menu).

## 2.5.0

- **Styl kokpitu** (Ustawienia, Colorify): Klasyczny albo jeden z czterech motywów zamiast widżetów WordPressa.
  Sprzedaż (sklep: przychód, zamówienia do realizacji, płatności do sprawdzenia, magazyn, ostatnie zamówienia),
  Zapytania (usługi: wiadomości z formularzy Fluent Forms, WPForms, MetForm, Flamingo, popularne podstrony),
  Redakcja (blog: komentarze, zaplanowane i nieopublikowane o czasie wpisy, szkice, najczęściej czytane),
  Firma (wizytówka: Kondycja witryny, aktualizacje, brakujące tłumaczenia Polylang). Każdy ma pasek 4 skrótów
  i jedną kartę "do zrobienia". Brak danych: kafelek mówi to wprost, nie udaje zera.
- Własny licznik wejść bez ciasteczek (przejmuje historię z kokpitu ETA), także najczęściej oglądane strony.

## 2.4.1

- Styl Nowoczesny: menu boczne bez "czarnego skoku" pod zaokrągloną pozycją przy najechaniu, bez trójkąta przy aktywnej pozycji, spokojne najechanie podmenu.
- Widżety kokpitu, które same są układem kart (kokpit ETA), nie są owijane w drugą białą kartę (tekst nie dotyka krawędzi).
- Dodana zmienna `--colorify-tag-neutral-{bg,fg}`.

## 2.4.0

- **Styl panelu: Klasyczny albo Nowoczesny** (Ustawienia, Colorify). Nowoczesny zmienia cały wp-admin: zaokrąglone
  karty bez ramek z lekkim cieniem, ustawienia jako karta, miękkie pola, przyciski-pigułki, tabele z pasami,
  zakładki-pigułki, aktywne menu w zaokrąglonym polu, jednolite komunikaty bez bocznej kreski. Kolory ze schematu.
  Edytor bloków i Customizer zostają bez zmian. Domyślnie Klasyczny (aktualizacja niczego nie przestawia).
- **Każdy komunikat w panelu da się zamknąć**, także te bez krzyżyka (np. reklamy wtyczek). Zamknięcie zapamiętuje
  się dla konta, więc ten sam komunikat nie wraca. "Pokaż je z powrotem" w Ustawienia, Colorify.
- **Wspólne zmienne stylu** (`--colorify-radius-card/-control/-pill`, `--colorify-shadow-card`,
  `--colorify-tag-{ok,info,warn,bad}-{bg,fg}`) dla innych wtyczek, gdy styl jest Nowoczesny.

## 2.3.2

- Własna paleta: rząd pól kolorów i podgląd odsunięte 70 px od opisu nad nimi.
- Próbniki kolorów zaokrąglone (także wnętrze natywnego pola koloru w Chrome, Edge i Firefox).

## 2.3.1

- Karta "Twoje kolory" (zakres "Każdy osobno") i przyciski w kartach wyglądają jak reszta ekranu: zaokrąglone, bez ramki, zwykłe litery.

## 2.3.0

- **Nowy wygląd ekranu Ustawienia, Colorify.** Jednolite tło, jasne zaokrąglone karty bez ramek i bocznych kresek,
  przełączniki jako pigułki bez wersalików, mniejsze nagłówki, odsunięcie od lewej krawędzi.
- **Własna paleta: cztery pola w jednym rzędzie** (próbnik i kod koloru w jednym polu), podgląd obok.
- **Bez pola "Używaj własnej palety".** Zakładka "Własna paleta" i każda zmiana koloru same ją włączają.
- **Dostrojenie jako lekki pasek** z suwakami w kolorze akcentu.

## 2.2.3

- **Dopasowanie do strony bierze prawdziwe kolory strony.** Akcent to kolor nasycony, którego na stronie widać
  najwięcej, a nie pierwszy przycisk z brzegu. Baner cookies, pasek administratora i okienka czatu są pomijane
  (wcześniej przycisk "Akceptuję" z banera cookies dawał akcent w kolorze tła).
- **Naprawione: zielony zamiast kolorów strony.** Własna paleta witryny była liczona z pustej palety osoby
  (domyślna limonka). Teraz osoba bez własnego wyglądu dostaje paletę witryny.
- **Dopasowanie nie przełącza trybu.** Kto pracował w jasnym, zostaje w jasnym.
- **Stały panel "Dopasowanie do strony"** pod nagłówkiem, z kolorami, czcionką i rogami, które są teraz w panelu.
- **Dostrojenie w jednym niskim pasku** zamiast pół ekranu.

## 2.2.2

- **Ustawienia, Colorify: jeden ekran zamiast trzech kart.** U góry "Kolory panelu" z przełącznikiem
  "Dla wszystkich / Każdy osobno" i przyciskiem "Dopasuj do mojej strony". Pod spodem schematy w grupach,
  własna paleta i dostrojenie, które jest zawsze rozwinięte.
- **Wybór schematu zeruje dostrojenie.** Przesunięcia jasności i nasycenia z poprzedniego schematu potrafiły
  zmienić nowy schemat nie do poznania (antracyt w ciemnym trybie wychodził brązowy).
- **Jeden przełącznik trybu.** Dostrojenie dotyczy trybu, który widać, bez drugiego przełącznika Ciemny/Jasny.
- **Bez osobnej sekcji czcionek.** Czcionki i rogi przychodzą razem z "Dopasuj do mojej strony", więc ta sama
  decyzja nie pojawia się dwa razy. Zapisany wcześniej styl zostaje bez zmian.
- **Naprawione: "Dopasuj do mojej strony" dawało inne kolory niż strona.** Stare dostrojenie witryny przesuwało
  odczytane kolory, a własny wygląd administratora je przykrywał. Dopasowanie zeruje teraz dostrojenie i zdejmuje
  własny wygląd osoby, która je uruchamia.

## 2.1.3

- **Kolory przełączają się od razu i zapisują się same.** Wybór schematu, trybu, palety i dostrojenia zapisuje się
  automatycznie chwilę po zmianie, bez przycisku "Zapisz ustawienia" (przycisk zostaje tylko bez JavaScriptu).
- **Zmiana wyglądu witryny jest widoczna dla administratora, który ją robi.** Przy zakresie "jeden wygląd" własny
  wygląd administratora przykrywał każdą zmianę w Ustawienia, Colorify. Zapis wyglądu witryny zdejmuje teraz własny
  wygląd tej osoby, a podgląd na żywo idzie od razu na panel.

## 2.1.2

- **Kolor schematu także na stronie publicznej, dla zalogowanych.** Narzędzia pokazywane tam osobom z panelu
  (na przykład zgłaszanie poprawek) dostają akcent schematu zamiast domyślnego niebieskiego WordPressa.

## 2.1.1

- **"Zmień styl" w górnym pasku prowadzi administratora do Ustawienia, Colorify.** Tam są kolory witryny, czcionki
  i przycisk "Dopasuj do strony". Pozostałe osoby nadal trafiają do swojego profilu.

## 2.1.0

Wygląd ekranów Colorify i strony logowania dopasowuje się do strony, na której wtyczka działa.

- **Styl: czcionki i kształt.** Nowa sekcja w Ustawienia, Colorify. Do wyboru: Neutralny (czcionka panelu WordPressa,
  lekko zaokrąglone rogi), Jak strona oraz Kancelaria (EB Garamond i IBM Plex Sans, ostre rogi, wygląd z wersji 2.0).
- **Domyślnie styl neutralny.** Na nowej stronie Colorify nie narzuca już czcionek i ostrych rogów kancelarii.
  Kto chce wygląd z wersji 2.0, wybiera styl Kancelaria.
- **Przycisk "Dopasuj do strony".** Odczytuje ze strony głównej kolor przycisków i odnośników, ciemny kolor stopki albo
  tekstu, czcionki nagłówków i tekstu oraz zaokrąglenie przycisków. Pokazuje wynik i zapisuje go dopiero po zatwierdzeniu:
  kolory jako własna paleta witryny, czcionki i rogi jako styl "Jak strona". Działa z każdym motywem i kreatorem stron.
- **Czcionki tylko z własnego serwera.** Colorify używa plików czcionek strony, gdy leżą na tym samym serwerze.
  Gdy strona ładuje je z zewnątrz, zostaje czcionka systemowa.

## 2.0.0

Nowy wygląd panelu, czytelny na każdym ekranie, i aktualizacje jak w zwykłych wtyczkach.

- **Czytelny panel.** Tekst widać na każdym ekranie WordPressa, także w cudzych wtyczkach (sklep, formularze, kreatory
  stron). Znikają białe napisy na białym tle i szare na szarym, które psuły wersję 1.x.
- **Nowy domyślny wygląd: Grafit i złoto.** Ciemne menu boczne i górny pasek, jasna treść, złoty akcent. Kto wybrał
  własny schemat albo własne kolory, zachowuje je bez zmian.
- **Tryb ciemny i jasny.** Przełącznik trybu i wybór schematu są w górnym pasku panelu. Nie pływają już nad treścią,
  więc nie zasłaniają list ani przycisków, także na telefonie. Wybór zapisuje się osobno dla każdej osoby.
- **Nowe ekrany Colorify.** Wybór wyglądu w Twoim profilu oraz Ustawienia, Colorify: wygląd witryny, strona logowania
  i to, czy każdy wybiera sam, czy wszyscy mają jeden wygląd.
- **Nowa strona logowania** w tym samym stylu, bez czcionek ładowanych z zewnętrznych serwerów.
- **Czcionki dołączone do wtyczki** (EB Garamond i IBM Plex Sans). Wtyczka nie ładuje już czcionek z serwerów Google.
- **Aktualizacje same z GitHuba.** Nowa wersja pokazuje się na ekranie Wtyczki jak w każdej innej wtyczce i instaluje się
  przyciskiem "Zaktualizuj teraz". Pobrany plik jest sprawdzany sumą kontrolną, a wydanie, którego strona nie
  obsłuży (za stary WordPress albo PHP), jest wstrzymywane. Ekran Aktualizacje, "Sprawdź ponownie" pokazuje nową wersję od razu.
- **Instalacja z pliku ZIP działa.** Wgranie paczki przez Wtyczki, Dodaj nową, Wyślij wtyczkę (także jako zamiana
  starszej wersji) kopiuje pliki na właściwe miejsca.
- **Aktualizacja z wersji 1.x: jedno kliknięcie.** Raz użyj linku "Aktualizuj" w wierszu wtyczki. Stare, niepotrzebne pliki
  są usuwane po aktualizacji, a Twoje ustawienia zostają.
- **Na ciężkich ekranach tryb ciemny włącza się z chwilą zwłoki.** W edytorze wpisów na wolnym komputerze przyciemnienie
  może potrwać kilka sekund, a do tego czasu ekran jest czytelny. Okno edycji treści w edytorze zostaje jasne.
- **Wymagania:** WordPress 7.0 lub nowszy, PHP 7.4 lub nowszy. Na starszym środowisku wtyczka pokazuje komunikat
  zamiast się włączyć.
