# Historia zmian

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
