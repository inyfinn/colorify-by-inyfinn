# Moduł „Kreator e-maili” (od 2.12.0)

Ekran **Wiadomości do par** w wp-admin: szablony maili wtyczki w jednym miejscu, z chipami zmiennych, wersjami
do losowania, podglądem na żywo, testem na własny adres, włącz / wyłącz, „Przywróć domyślne”, historią i bramką
„nie nadpisuj, czego nie widziałeś”. Moduł jest **domyślnie wyłączony** (Ustawienia, Colorify, „Kreator e-maili”)
i działa tylko z wtyczką, która zarejestruje szablony filtrem `colorify_maile_rejestr`.

Podział ról: **Colorify daje ekran, wtyczka daje dane.** Treść szablonów zostaje w opcjach wtyczki (zero migracji),
wtyczka dostarcza dane rekordu, wartości zmiennych, renderer HTML i wysyłkę; moduł tylko edytuje, pokazuje i testuje.

## 1. Rejestracja w wtyczce (10 minut)

```php
add_filter( 'colorify_maile_rejestr', function ( $r ) {
	return array(
		'slug'       => 'moja-maile',                                   // adres ekranu: ...&page=moja-maile
		'tytul'      => 'Wiadomości do klientów',
		'cap'        => 'manage_options',
		'menu'       => array( 'rodzic' => 'edit.php?post_type=zlecenie', 'etykieta' => 'Wiadomości' ), // rodzic '' = menu główne
		'zastepuje'  => 'moje-wiadomosci',                              // slug starego ekranu wtyczki: przekierowuje na moduł
		'test_adres' => 'ja@example.com',                               // domyślny adres testów (puste = e-mail zalogowanej osoby)
		'fonty'      => 'https://fonts.googleapis.com/css2?family=Poppins&display=swap', // opcjonalnie
		'szablony'   => array(
			'potwierdzenie' => array(                                     // rozdział 2
				'nazwa'    => 'Potwierdzenie zgłoszenia',
				'opis'     => 'Klient dostaje go od razu po wysłaniu formularza.',
				'opcja'    => 'moja_potwierdzenie',                       // opcja z zapisanym szablonem (mapa pole => tekst)
				'wlacznik' => 'wlaczony',                                 // klucz w opcji z włącz / wyłącz ('' = brak przełącznika)
				'pola'     => array(
					'temat'    => array( 'text', 'Temat', '', 'req' => true ),
					'naglowek' => array( 'text', 'Nagłówek' ),
					'wstep'    => array( 'area', 'Wstęp', 'Pusta linia = akapit. Zaznacz i kliknij B / I.' ),
					'co_dalej' => array( 'lista', 'Co dalej', 'Jedna linia = jeden punkt.' ),
					'cta'      => array( 'text', 'Przycisk: tekst' ),
					'cta_url'  => array( 'url', 'Przycisk: adres' ),
					'po'       => array( 'area', 'Drobny druk' ),
					'podpis'   => array( 'plain', 'Podpis' ),
				),
				'domyslne' => moja_potwierdzenie_domyslne(),             // mapa pole => tekst (z 'wlaczony' => 1)
				'zmienne'  => array( 'imiona' => 'imiona klienta', 'data' => 'data, np. 20 czerwca 2026' ),
				'dane'     => 'moja_dane_rekordu',                        // callable( int $id ): array; 0 = dane przykładowe
				'rekordy'  => 'moja_lista_rekordow',                      // callable(): array( id => etykieta ) do wyboru w podglądzie
				'wartosci' => 'moja_wartosci_zmiennych',                  // callable( array $z ): array( zmienna => wartość )
				'wstaw'    => 'moja_wstaw',                               // callable( string $tekst, array $w ): string (domyślnie strtr)
				'temat'    => 'moja_temat',                               // callable( array $sz, array $z ): string (domyślnie wstaw( sz[temat] ))
				'html'     => 'moja_html',                                // callable( array $sz, array $z ): string (brak = wbudowany prosty mail)
				'wyslij'   => 'moja_wyslij',                              // callable( array $sz, array $z, string $adres ): '' albo opis błędu (brak = wp_mail z html)
			),
		),
	) + (array) $r;
} );
```

`$sz` w callable to mapa `pole => tekst` jednej wersji (po sanitizacji), `$z` to to, co zwróciło `dane`.

## 2. Szablony: pola, typy, wersje

Typy pól: `text` (jedna linia), `area` (akapity, lekki HTML `b`/`strong`/`i`/`em`/`br`/`a`; pusta linia = akapit; w panelu B / I),
`plain` (czysty tekst wieloliniowy), `lista` (jedna linia = jeden punkt), `url`, `email`. `'req' => true`: puste pole
dostaje wartość domyślną. Pola `text`, `area`, `plain` i `lista` przyjmują chipy zmiennych.

**Wersje wielokrotne.** `'wersje' => 6` = szablon trzyma listę do 6 wersji (zakładki, dodaj, usuń); opcja wtyczki
jest wtedy listą wersji. Losowanie przy użyciu robi wtyczka (moduł tylko edytuje). `'pozycyjne' => true` = wersja
w opcji jest tablicą pozycyjną w kolejności `pola` (np. `[ temat, treść ]`), inaczej mapą `pole => tekst`.

**Wspólna opcja.** `'klucz' => 'odp'` = szablon żyje pod kluczem w opcji (np. `ejr_wiadomosci['odp']`); reszta opcji
zostaje nietknięta. Historia: `'historia'` (domyślnie `<opcja>_hist` albo `<opcja>_<klucz>_hist`), 8 wpisów
`t, u, l, d, w`; moduł czyta też starszy format wtyczek (`kto`, `dane`).

**Zapis.** Klucze opcji spoza `pola` (np. `test_adres`) zostają. `wlacznik` zapisuje się jako `1` / `0`.
„Przywróć domyślne” usuwa opcję (albo klucz), a wtyczka wraca do swoich domyślnych.

## 3. Bramka wersji

Formularz niesie skrót surowej opcji z chwili otwarcia (`colorify_maile_wersja()`); zapis przy innym skrócie jest
odrzucony („ktoś zapisał w międzyczasie, odśwież”). Poprzednia wartość trafia do historii przy każdym zapisie.

## 4. Podgląd i test

Podgląd: AJAX `colorify_maile_podglad` wysyła formularz, moduł sanituje pola, bierze aktywną wersję i dane rekordu
(`dane( id )`, `0` = przykład), renderuje `html` (albo wbudowany prosty mail: temat, akapity, punkty; krem, złoto, brąz)
i wstawia w iframe (600 / 375 px). Test: `colorify_maile_test` wysyła tę samą wersję przez `wyslij` (albo `wp_mail`)
na wpisany adres.

## 5. Co dzieje się na innych stronach

Moduł wyłączony (domyślnie) albo brak rejestru: `includes/colorify-maile.php` definiuje funkcje i wiersz w ustawieniach,
żadnego hooka ekranu (menu, admin-post, AJAX, przekierowanie ładują się w `init` tylko, gdy
`colorify_maile_dostepny()` jest prawdą). Aktualizacja Colorify niczego więc nie zmienia bez włączenia.

## 6. Przykład: ej-rezerwacje

`wp-content/plugins/ej-rezerwacje/includes/colorify-maile.php`: potwierdzenie zgłoszenia (opcja `ejr_potwierdzenie`,
renderer `ejr_potw_html()`, wysyłka `ejr_potw_wyslij()` z plikiem .ics) i trzy szablony przycisków zlecenia
(wspólna opcja `ejr_wiadomosci`, klucze `odp` / `konsu` / `film`, 6 wersji pozycyjnych `[temat, treść]`, bez renderera).
Stary ekran `ejr-wiadomosci` przekierowuje na moduł, gdy jest włączony; po wyłączeniu działa jak dotąd.
