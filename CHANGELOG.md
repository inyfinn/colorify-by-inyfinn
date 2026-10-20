# Historia zmian

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
