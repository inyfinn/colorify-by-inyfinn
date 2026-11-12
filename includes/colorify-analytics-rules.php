<?php
/**
 * Reguły analityki Colorify wspólne dla kolektora PHP (colorify-analytics.php) i odbiornika dziennika odsłon
 * (colorify-beacon.php, działa bez WordPressa): bot czy przeglądarka, klasa urządzenia, klasa źródła z domeny.
 * Same stałe i czyste funkcje, bez efektów ubocznych (wywołany bezpośrednio niczego nie robi).
 *
 * @package ColorifyByInyfinn
 */

if ( class_exists( 'Colorify_Analytics_Rules', false ) ) {
	return;
}

final class Colorify_Analytics_Rules {

	/**
	 * Znaki botów, podglądów linków, narzędzi pomiarowych i bibliotek HTTP. (?<!cu)bot: telefony CUBOT to ludzie.
	 * Adres albo e-mail w UA = bot (przeglądarki ich nie mają).
	 */
	const BOT_RE = '~(?<!cu)bot|crawl|spider|slurp|scrap|fetch|preview|headless|phantom|selenium|puppeteer|playwright|lighthouse|pagespeed|gtmetrix|pingdom|uptime|monitor|validator|scan|check|facebookexternalhit|facebookcatalog|meta-external|embedly|iframely|google-|googleother|mediapartners|chatgpt|perplexity|claude|anthropic|cohere|ccbot|diffbot|bytespider|qwantify|ahrefs|semrush|mj12|screaming|ia_archiver|archive\.org|curl|wget|python|java/|okhttp|go-http|httpclient|libwww|axios|node-fetch|undici|https?://|www\.|@~i';

	/** Wyszukiwarki i asystenci AI z wyszukiwaniem (klasa „search”). ponytail: mail.google.com też trafia tu; rzadkie. */
	const SEARCH_RE = '~(^|\.)(google\.[a-z]{2,3}(\.[a-z]{2})?|bing\.com|duckduckgo\.com|search\.yahoo\.com|yandex\.[a-z]{2,3}|ya\.ru|baidu\.com|ecosia\.org|search\.brave\.com|startpage\.com|qwant\.com|seznam\.cz|ask\.com|naver\.com|sogou\.com|szukaj\.onet\.pl|szukaj\.wp\.pl|szukaj\.interia\.pl|chatgpt\.com|chat\.openai\.com|perplexity\.ai|copilot\.microsoft\.com|claude\.ai)$~';

	/** Media społecznościowe i komunikatory (klasa „social”). */
	const SOCIAL_RE = '~(^|\.)(facebook\.com|fb\.com|fb\.me|messenger\.com|instagram\.com|threads\.(net|com)|t\.co|twitter\.com|x\.com|linkedin\.com|lnkd\.in|pinterest\.[a-z]{2,3}(\.[a-z]{2})?|pin\.it|tiktok\.com|youtube\.com|youtu\.be|reddit\.com|wykop\.pl|whatsapp\.com|wa\.me|t\.me|telegram\.org|snapchat\.com|vk\.com|tumblr\.com|bsky\.app|discord\.com|nk\.pl)$~';

	/** Referer aplikacji Androida (android-app://pakiet): początek nazwy pakietu => [klasa, domena]. */
	const APPS = array(
		'com.google.android.googlequicksearchbox' => array( 'search', 'google.com' ),
		'com.google.android.gm'                   => array( 'referral', 'mail.google.com' ),
		'com.facebook.'                           => array( 'social', 'facebook.com' ),
		'com.instagram.'                          => array( 'social', 'instagram.com' ),
		'com.linkedin.'                           => array( 'social', 'linkedin.com' ),
		'com.twitter.'                            => array( 'social', 'x.com' ),
		'com.pinterest'                           => array( 'social', 'pinterest.com' ),
		'com.zhiliaoapp.musically'                => array( 'social', 'tiktok.com' ),
		'com.whatsapp'                            => array( 'social', 'whatsapp.com' ),
		'org.telegram.'                           => array( 'social', 't.me' ),
	);

	/** Parametr kliknięcia z reklamy albo aplikacji (bez wartości, liczy się sama obecność), gdy Referera brak. */
	const CLICK_IDS = array(
		'fbclid'  => array( 'social', 'facebook.com' ),
		'igshid'  => array( 'social', 'instagram.com' ),
		'gclid'   => array( 'search', 'google.com' ),
		'gbraid'  => array( 'search', 'google.com' ),
		'wbraid'  => array( 'search', 'google.com' ),
		'msclkid' => array( 'search', 'bing.com' ),
	);

	/** Bot albo program, nie przeglądarka: pusty/krótki UA, UA spoza rodziny Mozilla/Opera, znak z listy. */
	public static function is_bot_ua( string $ua ): bool {
		return strlen( $ua ) < 30 || ! preg_match( '~^(Mozilla|Opera)/~', $ua ) || (bool) preg_match( self::BOT_RE, $ua );
	}

	/** mobile | tablet | desktop | unknown, z UA w chwili żądania (UA nie jest zapisywany). iPadOS 13+ podaje się jako Mac: desktop. */
	public static function device( string $ua ): string {
		if ( preg_match( '~ipad|tablet|kindle|silk/|playbook|sm-t\d~i', $ua ) || preg_match( '~^(?!.*mobi).*android~i', $ua ) ) {
			return 'tablet';
		}
		if ( preg_match( '~mobi|iphone|ipod|android|windows phone|blackberry|opera mini|iemobile~i', $ua ) ) {
			return 'mobile';
		}
		if ( preg_match( '~windows nt|macintosh|mac os x|x11|linux|cros~i', $ua ) ) {
			return 'desktop';
		}
		return 'unknown';
	}

	/** Domena bez www., m., l., lm., mobile., amp. i bez kropki na końcu. */
	public static function bare_host( string $h ): string {
		return (string) preg_replace( '~^(www\d?|m|l|lm|mobile|amp)\.~', '', strtolower( rtrim( trim( $h ), '.' ) ) );
	}

	/**
	 * Klasa źródła i domena odsyłająca (bez ścieżki).
	 *
	 * @param string $ref_host  Host z Referera (albo nazwa pakietu z android-app://), '' gdy brak.
	 * @param string $site_host Host tej strony.
	 * @param string $click     Nazwa parametru kliknięcia z adresu (fbclid, gclid...), '' gdy brak.
	 * @return array{0:string,1:string} [search|social|referral|internal|direct_unknown, domena albo '']
	 */
	public static function source( string $ref_host, string $site_host, string $click = '' ): array {
		$h = strtolower( trim( $ref_host ) );
		if ( '' !== $h ) {
			foreach ( self::APPS as $pkg => $hit ) {
				if ( 0 === strpos( $h, $pkg ) ) {
					return $hit;
				}
			}
			$h = self::bare_host( $h );
			if ( $h === self::bare_host( $site_host ) ) {
				return array( 'internal', '' );
			}
			if ( preg_match( '~^[a-z0-9.-]{1,100}$~', $h ) && false !== strpos( $h, '.' ) ) {
				return array( self::domain_class( $h ), $h );
			}
		}
		return self::CLICK_IDS[ $click ] ?? array( 'direct_unknown', '' );
	}

	public static function domain_class( string $h ): string {
		if ( preg_match( self::SEARCH_RE, $h ) ) {
			return 'search';
		}
		return preg_match( self::SOCIAL_RE, $h ) ? 'social' : 'referral';
	}

	/** Wartość utm_* po oczyszczeniu (małe litery, cyfry, . _ -, do 40 znaków) albo ''. */
	public static function utm_value( string $v ): string {
		$v = strtolower( trim( $v ) );
		return preg_match( '~^[a-z0-9._-]{1,40}$~', $v ) ? $v : '';
	}
}
