<?php
/**
 * TQT — Request language resolution for WordPress front (e.g. /es/page) + URL helpers.
 *
 * Default language has NO URL prefix. Non-default languages use /{lang}/… after the site path.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** @var string|null Request language from URL prefix (set before WP routing). */
$GLOBALS['tqt_request_lang'] = null;

/**
 * True when the request path had no /{lang}/ prefix (canonical default-language URL).
 * In that case we use the site default language — not the tqt_lang cookie (fixes switch back to English).
 */
$GLOBALS['tqt_url_is_unprefixed'] = false;

/**
 * Site path prefix e.g. /sub — from raw home URL (not via home_url() filter).
 */
function tqt_raw_home_path(): string {
	$home = get_option( 'home' );
	if ( ! $home ) {
		return '';
	}
	$p = wp_parse_url( $home, PHP_URL_PATH );
	if ( ! $p || $p === '/' ) {
		return '';
	}
	return '/' . trim( $p, '/' );
}

/**
 * Match a raw code (from URL/cookie) to a canonical enabled language key (case-safe).
 */
function tqt_normalize_lang_code( string $raw ): ?string {
	$raw = preg_replace( '/[^a-zA-Z0-9_-]/', '', $raw );
	if ( $raw === '' ) {
		return null;
	}
	foreach ( array_keys( tqt_active_languages() ) as $code ) {
		if ( strcasecmp( (string) $code, $raw ) === 0 ) {
			return (string) $code;
		}
	}
	return null;
}

/**
 * Whether a language code is enabled in TQT settings.
 */
function tqt_is_active_lang_code( string $code ): bool {
	return tqt_normalize_lang_code( $code ) !== null;
}

/**
 * Skip language parsing for admin, cron, AJAX, REST, CLI, and static endpoints.
 */
function tqt_should_skip_language_bootstrap(): bool {
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		return true;
	}
	if ( is_admin() ) {
		return true;
	}
	if ( wp_doing_ajax() ) {
		return true;
	}
	if ( wp_doing_cron() ) {
		return true;
	}
	if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
		return true;
	}
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return true;
	}
	$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
	if ( $uri !== '' && preg_match( '#/(wp-admin|wp-json|wp-login\.php|wp-cron\.php|xmlrpc\.php)(/|$)#', $uri ) ) {
		return true;
	}
	return (bool) apply_filters( 'tqt_skip_language_bootstrap', false );
}

/**
 * Strip leading /{lang}/ from REQUEST_URI so WordPress routes correctly.
 * Sets $GLOBALS['tqt_request_lang'] when a language prefix is found.
 */
function tqt_bootstrap_language_from_request(): void {
	// Allow tqt_get_current_language() to re-resolve after URL globals are set (fixes /ar/ stripped from links
	// when locale filters ran earlier and memoized the default language).
	unset( $GLOBALS['tqt_current_language_memo'] );

	if ( tqt_should_skip_language_bootstrap() ) {
		return;
	}

	$GLOBALS['tqt_request_lang']       = null;
	$GLOBALS['tqt_url_is_unprefixed'] = false;

	$raw       = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '/';
	$parsed    = wp_parse_url( $raw );
	$path      = isset( $parsed['path'] ) ? $parsed['path'] : '/';
	$query_str = isset( $parsed['query'] ) && $parsed['query'] !== '' ? '?' . $parsed['query'] : '';

	$hp          = tqt_raw_home_path();
	$home_prefix = $hp === '' ? '' : $hp;

	$rel = $path;
	if ( $home_prefix !== '' && strpos( $path, $home_prefix ) === 0 ) {
		$rel = substr( $path, strlen( $home_prefix ) );
		if ( $rel === false || $rel === '' ) {
			$rel = '/';
		} elseif ( $rel[0] !== '/' ) {
			$rel = '/' . $rel;
		}
	}

	$rel     = '/' . ltrim( $rel, '/' );
	$parts   = array_values( array_filter( explode( '/', trim( $rel, '/' ) ), 'strlen' ) );
	if ( empty( $parts ) ) {
		// e.g. homepage "/" — no language segment → default language (ignore cookie).
		$GLOBALS['tqt_url_is_unprefixed'] = true;
		return;
	}

	$canonical = tqt_normalize_lang_code( $parts[0] );
	if ( $canonical === null ) {
		// First segment is not a language code (e.g. /menu/, /blog/hello) → default language.
		$GLOBALS['tqt_url_is_unprefixed'] = true;
		return;
	}

	$GLOBALS['tqt_request_lang']       = $canonical;
	$GLOBALS['tqt_url_is_unprefixed'] = false;
	array_shift( $parts );

	$new_rel = '/' . implode( '/', $parts );
	if ( $new_rel === '//' ) {
		$new_rel = '/';
	}
	if ( $new_rel !== '/' && $new_rel !== '' ) {
		$new_rel = '/' . trim( $new_rel, '/' );
		if ( substr( $path, -1 ) === '/' && strlen( $new_rel ) > 1 ) {
			$new_rel = trailingslashit( $new_rel );
		}
	}

	$new_path = ( $home_prefix === '' ? '' : $home_prefix ) . ( $new_rel === '' ? '/' : $new_rel );

	$_SERVER['REQUEST_URI'] = $new_path . $query_str;
}

add_action( 'plugins_loaded', 'tqt_bootstrap_language_from_request', -9999 );

/**
 * Resolve current language:
 * 1) Stripped /{lang}/ prefix from this request
 * 2) Unprefixed canonical URL → site default (never use stale cookie here)
 * 3) Cookie (only when bootstrap did not classify the URL, e.g. skipped contexts)
 * 4) Site default
 */
function tqt_get_current_language(): string {
	if ( isset( $GLOBALS['tqt_current_language_memo'] ) ) {
		return $GLOBALS['tqt_current_language_memo'];
	}

	$default = tqt_get_settings()['default_language'];

	if ( isset( $GLOBALS['tqt_request_lang'] ) && $GLOBALS['tqt_request_lang'] !== null ) {
		$code = tqt_normalize_lang_code( (string) $GLOBALS['tqt_request_lang'] );
		if ( $code !== null ) {
			$GLOBALS['tqt_current_language_memo'] = apply_filters( 'tqt_current_language', $code );
			return $GLOBALS['tqt_current_language_memo'];
		}
	}

	// No /lang/ segment in URL → user is on default-language URLs (/page not /es/page). Cookie must not override.
	if ( ! empty( $GLOBALS['tqt_url_is_unprefixed'] ) ) {
		$GLOBALS['tqt_current_language_memo'] = apply_filters( 'tqt_current_language', $default );
		return $GLOBALS['tqt_current_language_memo'];
	}

	if ( ! empty( $_COOKIE['tqt_lang'] ) ) {
		$c = tqt_normalize_lang_code( (string) $_COOKIE['tqt_lang'] );
		if ( $c !== null ) {
			$GLOBALS['tqt_current_language_memo'] = apply_filters( 'tqt_current_language', $c );
			return $GLOBALS['tqt_current_language_memo'];
		}
	}

	$GLOBALS['tqt_current_language_memo'] = apply_filters( 'tqt_current_language', $default );
	return $GLOBALS['tqt_current_language_memo'];
}

/**
 * Persist language cookie on front-end responses.
 */
function tqt_maybe_set_language_cookie(): void {
	if ( tqt_should_skip_language_bootstrap() ) {
		return;
	}
	if ( headers_sent() ) {
		return;
	}

	$lang = tqt_get_current_language();
	$path = COOKIEPATH ?: '/';
	setcookie(
		'tqt_lang',
		$lang,
		time() + YEAR_IN_SECONDS,
		$path,
		COOKIE_DOMAIN,
		is_ssl(),
		true
	);
	$_COOKIE['tqt_lang'] = $lang;
}

add_action( 'template_redirect', 'tqt_maybe_set_language_cookie', 0 );

/**
 * Remove any leading /{lang}/ segment (enabled TQT codes) from URL path after home path.
 */
function tqt_delocalize_url( string $url ): string {
	$url = trim( $url );
	if ( $url === '' ) {
		return $url;
	}

	$parts = wp_parse_url( $url );
	if ( ! $parts || empty( $parts['host'] ) ) {
		return $url;
	}

	$scheme = isset( $parts['scheme'] ) ? $parts['scheme'] . '://' : '';
	$host   = $parts['host'];
	$port   = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
	$path   = isset( $parts['path'] ) ? $parts['path'] : '/';
	$query  = isset( $parts['query'] ) && $parts['query'] !== '' ? '?' . $parts['query'] : '';
	$frag   = isset( $parts['fragment'] ) && $parts['fragment'] !== '' ? '#' . $parts['fragment'] : '';

	$hp          = tqt_raw_home_path();
	$home_prefix = $hp === '' ? '' : $hp;

	$rel = $path;
	if ( $home_prefix !== '' && strpos( $path, $home_prefix ) === 0 ) {
		$rel = substr( $path, strlen( $home_prefix ) );
		if ( $rel === false || $rel === '' ) {
			$rel = '/';
		} elseif ( $rel[0] !== '/' ) {
			$rel = '/' . $rel;
		}
	}

	$rel    = '/' . ltrim( $rel, '/' );
	$segs   = array_values( array_filter( explode( '/', trim( $rel, '/' ) ), 'strlen' ) );
	$first  = $segs[0] ?? '';
	$norm   = $first !== '' ? tqt_normalize_lang_code( $first ) : null;
	if ( $norm !== null ) {
		array_shift( $segs );
		$rel = '/' . implode( '/', $segs );
		if ( $rel !== '/' ) {
			$rel = '/' . trim( $rel, '/' );
		}
	}


	$new_path = ( $home_prefix === '' ? '' : $home_prefix ) . ( $rel === '' ? '/' : $rel );

	return $scheme . $host . $port . $new_path . $query . $frag;
}

/**
 * Add /{lang}/ after site path when language is non-default.
 *
 * @param string      $url  Full URL.
 * @param string|null $lang Override language (default: current).
 */
function tqt_localize_url( string $url, ?string $lang = null ): string {
	$url = trim( $url );
	if ( $url === '' ) {
		return $url;
	}

	$lang = $lang ?? tqt_get_current_language();
	$norm = tqt_normalize_lang_code( (string) $lang );
	if ( $norm === null ) {
		return tqt_delocalize_url( $url );
	}
	$lang    = $norm;
	$default = tqt_get_settings()['default_language'];

	$url = tqt_delocalize_url( $url );

	if ( $lang === $default ) {
		return $url;
	}

	$parts = wp_parse_url( $url );
	if ( ! $parts || empty( $parts['host'] ) ) {
		return $url;
	}

	$scheme = isset( $parts['scheme'] ) ? $parts['scheme'] . '://' : '';
	$host   = $parts['host'];
	$port   = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
	$path   = isset( $parts['path'] ) ? $parts['path'] : '/';
	$query  = isset( $parts['query'] ) && $parts['query'] !== '' ? '?' . $parts['query'] : '';
	$frag   = isset( $parts['fragment'] ) && $parts['fragment'] !== '' ? '#' . $parts['fragment'] : '';

	$hp          = tqt_raw_home_path();
	$home_prefix = $hp === '' ? '' : $hp;

	$rel = $path;
	if ( $home_prefix !== '' && strpos( $path, $home_prefix ) === 0 ) {
		$rel = substr( $path, strlen( $home_prefix ) );
		if ( $rel === false || $rel === '' ) {
			$rel = '/';
		} elseif ( $rel[0] !== '/' ) {
			$rel = '/' . $rel;
		}
	}

	$rel = '/' . ltrim( $rel, '/' );
	if ( $rel === '//' ) {
		$rel = '/';
	}

	$insert = '/' . $lang . ( $rel === '/' ? '/' : '/' . ltrim( $rel, '/' ) );
	if ( substr( $path, -1 ) === '/' && strlen( $insert ) > 1 ) {
		$insert = trailingslashit( untrailingslashit( $insert ) );
	}

	$new_path = ( $home_prefix === '' ? '' : $home_prefix ) . $insert;

	return $scheme . $host . $port . $new_path . $query . $frag;
}
