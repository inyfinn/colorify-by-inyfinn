<?php
/**
 * Dane startowe cennika: kategoria => array( nazwa, kolejnosc, uslugi ), usluga pod kluczem = slug.
 * Zrodlo: cennik biura na rok 2026 (ceny netto, progi wedlug liczby dokumentow w miesiacu) i teksty strony z 12.10.2026.
 * Uzywane przy pierwszej aktywacji (gdy nie ma zadnej uslugi) oraz przez skrypt wdrozeniowy.
 * Potem jedynym zrodlem prawdy jest panel: menu "Uslugi".
 *
 * Prog: array( etykieta, cena, czy "od" ).
 *
 * @package kw-uslugi
 */

defined( 'ABSPATH' ) || exit;

$kw_progi = static function ( array $ceny, $ksiegi = false ) {
	$etykiety = $ksiegi
		? array( '1-10', '11-30', '31-60', '61-99', '100-150', '151-200', '201 i więcej' ) // ksiegi handlowe: progi z cennika 2026
		: array( '1-10', '11-20', '21-40', '41-80', '81-140', '141 i więcej' );
	$progi    = array();
	foreach ( $etykiety as $nr => $etykieta ) {
		$progi[] = array(
			'etykieta' => $etykieta,
			'cena'     => (string) $ceny[ $nr ],
			'od'       => $nr === count( $etykiety ) - 1 ? 1 : 0, // ostatni prog to cena "od"
		);
	}
	return $progi;
};

$kw_ksiegi_dodatkowo = array( 'roczne sprawozdanie finansowe', 'kadry i płace (50 zł netto za pracownika)', 'zestawienia do banku', 'raporty do GUS' );
$kw_ksiegi_czynniki  = array( 'liczba dokumentów w miesiącu', 'transakcje zagraniczne i walutowe', 'liczba rachunków bankowych', 'nietypowe umowy i rozliczenia' );
$kw_kpir_dodatkowo   = array( 'deklaracja roczna PIT (30-60 zł netto)', 'kadry i płace (50 zł netto za pracownika)', 'zestawienia do banku' );
$kw_ryczalt_dod      = array( 'deklaracja roczna PIT (30-60 zł netto)', 'kadry i płace (50 zł netto za pracownika)' );
// Dwie pozycje "W cenie" kazdej uslugi miesiecznej (ksiegi, KPiR, ryczalt), zaraz po pierwszej: karta pokazuje trzy pierwsze
// pozycje listy, wiec obie widac bez rozwijania szczegolow (decyzja wlasciciela z 12.10.2026: to glowny argument ceny).
$kw_stale            = array( 'przegląd rozliczeń na początku współpracy', 'comiesięczna lista operacji do wyjaśnienia' );
$kw_z_argumentami    = static function ( array $lista ) use ( $kw_stale ) {
	array_splice( $lista, 1, 0, $kw_stale );
	return $lista;
};

return array(
	'spolki'           => array(
		'nazwa'     => 'Spółki',
		'kolejnosc' => 10,
		'uslugi'    => array(
			'ksiegi-handlowe-bez-vat'       => array(
				'tytul'     => 'Księgi handlowe bez VAT',
				'zajawka'   => 'Pełna księgowość dla spółek zwolnionych z VAT.',
				'ikona'     => 'far fa-folder-open',
				'typ'       => 'od',
				'min'       => '400',
				'jednostka' => 'miesiac',
				'progi'     => $kw_progi( array( 400, 650, 800, 1120, 1550, 2200, 2600 ), true ),
				'w_cenie'   => $kw_z_argumentami( array( 'prowadzenie ksiąg rachunkowych', 'zaliczki na podatek dochodowy (CIT)', 'ewidencja środków trwałych', 'dwa przeksięgowania w miesiącu', 'comiesięczna informacja o kwotach podatków do zapłaty' ) ),
				'dodatkowo' => $kw_ksiegi_dodatkowo,
				'czynniki'  => $kw_ksiegi_czynniki,
			),
			'ksiegi-handlowe'               => array(
				'tytul'     => 'Księgi handlowe z VAT',
				'zajawka'   => 'Dla spółek z o.o. i innych podmiotów prowadzących pełne księgi, czynnych podatników VAT.',
				'ikona'     => 'far fa-folder-open',
				'typ'       => 'od',
				'min'       => '450',
				'jednostka' => 'miesiac',
				'progi'     => $kw_progi( array( 450, 750, 1100, 1400, 1800, 2400, 2950 ), true ),
				'w_cenie'   => $kw_z_argumentami( array( 'prowadzenie ksiąg rachunkowych', 'rejestry VAT i plik JPK_V7', 'zaliczki na podatek dochodowy (CIT)', 'ewidencja środków trwałych', 'dwa przeksięgowania w miesiącu', 'comiesięczna informacja o kwotach podatków do zapłaty' ) ),
				'dodatkowo' => $kw_ksiegi_dodatkowo,
				'czynniki'  => $kw_ksiegi_czynniki,
			),
			'roczne-sprawozdanie-finansowe' => array(
				'tytul'     => 'Roczne sprawozdanie finansowe',
				'zajawka'   => 'Bilans, rachunek zysków i strat oraz informacja dodatkowa na koniec roku obrotowego. Cena to równowartość ostatniej faktury miesięcznej albo aktualnej wyceny zgodnej z cennikiem.',
				'ikona'     => 'far fa-calendar-check',
				'typ'       => 'opis',
				'opis_ceny' => 'Równowartość jednej faktury miesięcznej',
				'jednostka' => 'rok',
			),
		),
	),
	'jdg'              => array(
		'nazwa'     => 'JDG',
		'kolejnosc' => 20,
		'uslugi'    => array(
			'ryczalt-bez-vat'          => array(
				'tytul'     => 'Ryczałt bez VAT',
				'zajawka'   => 'Najprostsza forma: ewidencja przychodów bez VAT.',
				'ikona'     => 'far fa-file-alt',
				'typ'       => 'od',
				'min'       => '75',
				'jednostka' => 'miesiac',
				'progi'     => $kw_progi( array( 75, 100, 140, 185, 260, 360 ) ),
				'w_cenie'   => $kw_z_argumentami( array( 'ewidencja przychodów', 'wyliczenie ryczałtu', 'comiesięczna informacja o kwocie podatku do zapłaty' ) ),
				'dodatkowo' => $kw_ryczalt_dod,
			),
			'ryczalt'                  => array(
				'tytul'     => 'Ryczałt',
				'zajawka'   => 'Ewidencja przychodów dla działalności na ryczałcie, z VAT.',
				'ikona'     => 'far fa-file-alt',
				'typ'       => 'od',
				'min'       => '100',
				'jednostka' => 'miesiac',
				'progi'     => $kw_progi( array( 100, 130, 170, 250, 350, 450 ) ),
				'w_cenie'   => $kw_z_argumentami( array( 'ewidencja przychodów', 'rejestry VAT i plik JPK_V7', 'wyliczenie ryczałtu', 'comiesięczna informacja o kwotach podatków do zapłaty' ) ),
				'dodatkowo' => $kw_ryczalt_dod,
			),
			'kpir-bez-vat'             => array(
				'tytul'     => 'KPiR bez VAT',
				'zajawka'   => 'Księga przychodów i rozchodów dla działalności zwolnionej z VAT.',
				'ikona'     => 'far fa-list-alt',
				'typ'       => 'od',
				'min'       => '170',
				'jednostka' => 'miesiac',
				'progi'     => $kw_progi( array( 170, 200, 250, 300, 400, 500 ) ),
				'w_cenie'   => $kw_z_argumentami( array( 'prowadzenie księgi przychodów i rozchodów', 'zaliczki na podatek dochodowy', 'ewidencja środków trwałych', 'comiesięczna informacja o kwotach podatków do zapłaty' ) ),
				'dodatkowo' => $kw_kpir_dodatkowo,
				'czynniki'  => array( 'liczba dokumentów w miesiącu', 'transakcje zagraniczne', 'samochody i leasingi w firmie' ),
			),
			'kpir'                     => array(
				'tytul'     => 'KPiR',
				'zajawka'   => 'Księga przychodów i rozchodów dla działalności na skali podatkowej lub podatku liniowym, z VAT.',
				'ikona'     => 'far fa-list-alt',
				'typ'       => 'od',
				'min'       => '200',
				'jednostka' => 'miesiac',
				'progi'     => $kw_progi( array( 200, 250, 300, 350, 420, 550 ) ), // 1-10 = 200 zl: korekta wlasciciela z 13.10.2026 (w pliku cennika bylo 250)
				'w_cenie'   => $kw_z_argumentami( array( 'prowadzenie księgi przychodów i rozchodów', 'rejestry VAT i plik JPK_V7', 'zaliczki na podatek dochodowy', 'ewidencja środków trwałych', 'comiesięczna informacja o kwotach podatków do zapłaty' ) ),
				'dodatkowo' => $kw_kpir_dodatkowo,
				'czynniki'  => array( 'liczba dokumentów w miesiącu', 'VAT', 'transakcje zagraniczne', 'samochody i leasingi w firmie' ),
			),
			'deklaracje-podatkowe-pit' => array(
				'tytul'     => 'Deklaracje podatkowe PIT',
				'zajawka'   => 'Roczne zeznanie podatkowe przedsiębiorcy lub osoby prywatnej.',
				'ikona'     => 'far fa-clipboard',
				'typ'       => 'przedzial',
				'min'       => '30',
				'max'       => '60',
				'jednostka' => 'deklaracja',
			),
		),
	),
	'kadry-i-place'    => array(
		'nazwa'     => 'Kadry i płace',
		'kolejnosc' => 30,
		'uslugi'    => array(
			'obsluga-kadrowo-placowa-pracownika' => array(
				'tytul'     => 'Obsługa kadrowo-płacowa pracownika',
				'zajawka'   => 'Lista płac, składki ZUS i zaliczka na PIT za każdego zatrudnionego.',
				'ikona'     => 'far fa-money-bill-alt',
				'typ'       => 'stala',
				'min'       => '50',
				'jednostka' => 'pracownik-miesiac',
				'w_cenie'   => array( 'lista płac', 'deklaracje ZUS', 'zaliczki na PIT pracownika', 'ewidencja urlopów i zwolnień' ),
				'dodatkowo' => array( 'przygotowanie umowy o pracę (50 zł netto)', 'umowa cywilnoprawna (25 zł netto)', 'rachunek do umowy cywilnoprawnej (10 zł netto)' ),
			),
			'umowa-o-prace'                      => array(
				'tytul'     => 'Umowa o pracę',
				'zajawka'   => 'Przygotowanie umowy o pracę dla nowego pracownika.',
				'ikona'     => 'far fa-edit',
				'typ'       => 'stala',
				'min'       => '50',
				'jednostka' => 'dokument',
			),
			'umowa-cywilnoprawna'                => array(
				'tytul'     => 'Umowa cywilnoprawna',
				'zajawka'   => 'Umowa zlecenie albo umowa o dzieło.',
				'ikona'     => 'far fa-edit',
				'typ'       => 'stala',
				'min'       => '25',
				'jednostka' => 'dokument',
			),
			'rachunki-do-umow-cywilnoprawnych'   => array(
				'tytul'     => 'Rachunki do umów cywilnoprawnych',
				'zajawka'   => 'Rachunek do umowy zlecenie lub umowy o dzieło z wyliczeniem składek i podatku.',
				'ikona'     => 'far fa-copy',
				'typ'       => 'stala',
				'min'       => '10',
				'jednostka' => 'dokument',
			),
		),
	),
	'uslugi-dodatkowe' => array(
		'nazwa'     => 'Usługi dodatkowe',
		'kolejnosc' => 40,
		'uslugi'    => array(
			'zestawienia-do-banku'  => array(
				'tytul'     => 'Zestawienia do banku',
				'zajawka'   => 'Rachunek zysków i strat oraz bilans na potrzeby kredytu albo leasingu.',
				'ikona'     => 'far fa-credit-card',
				'typ'       => 'przedzial',
				'min'       => '50',
				'max'       => '100',
				'jednostka' => 'zestawienie',
			),
			'raporty-do-gus'        => array(
				'tytul'     => 'Raporty do GUS',
				'zajawka'   => 'Sprawozdania statystyczne składane do Głównego Urzędu Statystycznego.',
				'ikona'     => 'far fa-chart-bar',
				'typ'       => 'stala',
				'min'       => '50',
				'jednostka' => 'raport',
			),
			'zakladanie-firmy'      => array(
				'tytul'   => 'Zakładanie firmy',
				'zajawka' => 'Rejestracja działalności w CEIDG albo spółki w KRS wraz ze zgłoszeniami do urzędu skarbowego i ZUS.',
				'ikona'   => 'far fa-flag',
				'typ'     => 'indywidualna',
			),
			'przejecie-ksiegowosci-z-innego-biura' => array(
				'tytul'   => 'Przejęcie księgowości z innego biura',
				'zajawka' => 'Zmiana biura w trakcie roku albo od nowego roku, z przeglądem przekazanych dokumentów.',
				'ikona'   => 'far fa-handshake',
				'typ'     => 'indywidualna',
			),
			'wsparcie-w-dotacjach'  => array(
				'tytul'   => 'Wsparcie w dotacjach',
				'zajawka' => 'Pomoc w przygotowaniu części finansowej wniosku i rozliczeniu dotacji.',
				'ikona'   => 'far fa-handshake',
				'typ'     => 'indywidualna',
			),
		),
	),
);
