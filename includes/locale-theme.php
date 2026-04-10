<?php
/**
 * TQT — WordPress locale, gettext/Loco, RTL, and HTML lang/dir for the active TQT language.
 *
 * Theme strings (Loco, load_theme_textdomain) and core is_rtl() / language_attributes()
 * follow determine_locale() and get_locale(). We align those with tqt_get_current_language().
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Map TQT language codes to WordPress locale strings (used for .mo filenames and core i18n).
 * Override per site with filter {@see 'tqt_wp_locale_for_lang'} if your Loco files use a variant (e.g. ar_SA).
 *
 * @return array<string, string>
 */
function tqt_default_wp_locale_map(): array {
	return [
		'en'    => 'en_US',
		'ar'    => 'ar',
		'zh'    => 'zh_CN',
		'zh_TW' => 'zh_TW',
		'nl'    => 'nl_NL',
		'fil'   => 'fil',
		'fr'    => 'fr_FR',
		'de'    => 'de_DE',
		'el'    => 'el',
		'hi'    => 'hi_IN',
		'id'    => 'id_ID',
		'it'    => 'it_IT',
		'ja'    => 'ja',
		'ko'    => 'ko_KR',
		'ms'    => 'ms_MY',
		'fa'    => 'fa_IR',
		'pl'    => 'pl_PL',
		'pt'    => 'pt_PT',
		'ro'    => 'ro_RO',
		'ru'    => 'ru_RU',
		'es'    => 'es_ES',
		'sv'    => 'sv_SE',
		'th'    => 'th',
		'tr'    => 'tr_TR',
		'uk'    => 'uk',
		'ur'    => 'ur',
		'vi'    => 'vi',
	];
}

/**
 * WordPress locale string for gettext / Loco (.mo names), from a TQT language code.
 */
function tqt_lang_to_wp_locale( string $code ): string {
	$norm = tqt_normalize_lang_code( $code );
	if ( $norm === null ) {
		$norm = tqt_get_settings()['default_language'];
	}

	$map = tqt_default_wp_locale_map();
	if ( isset( $map[ $norm ] ) ) {
		$locale = $map[ $norm ];
	} else {
		// Custom languages: keep underscore for regional variants (e.g. tl_PH).
		$locale = str_replace( '-', '_', $norm );
	}

	return apply_filters( 'tqt_wp_locale_for_lang', $locale, $norm );
}

/**
 * Whether TQT should drive WP locale / gettext on this request (same scope as URL bootstrap).
 */
function tqt_should_sync_wp_locale(): bool {
	return ! tqt_should_skip_language_bootstrap();
}

/**
 * @param string $locale Current candidate locale.
 */
function tqt_filter_determine_locale( string $locale ): string {
	if ( ! tqt_should_sync_wp_locale() || ! function_exists( 'tqt_get_current_language' ) ) {
		return $locale;
	}

	return tqt_lang_to_wp_locale( tqt_get_current_language() );
}

/**
 * Keep get_locale() in sync so code using get_locale() (not only determine_locale) matches TQT.
 *
 * @param string $locale Site locale from options.
 */
function tqt_filter_locale( string $locale ): string {
	if ( ! tqt_should_sync_wp_locale() || ! function_exists( 'tqt_get_current_language' ) ) {
		return $locale;
	}

	return tqt_lang_to_wp_locale( tqt_get_current_language() );
}

/**
 * BCP 47-ish tag for the HTML lang attribute (matches core: str_replace( '_', '-', determine_locale() )).
 */
function tqt_current_html_lang(): string {
	if ( ! function_exists( 'tqt_get_current_language' ) ) {
		return '';
	}
	$wp = tqt_lang_to_wp_locale( tqt_get_current_language() );
	$wp = strtolower( str_replace( '_', '-', $wp ) );
	// Match common WordPress output for regional locales (e.g. en-us → en-US for first segment only is optional; HTML accepts lowercase.)
	return $wp;
}

/**
 * Ensure dir= and lang= match TQT (core can miss if html_lang_attribute or bloginfo paths differ).
 *
 * @param string $output  Space-separated attributes.
 * @param string $doctype html|xhtml
 */
function tqt_filter_language_attributes( string $output, string $doctype ): string {
	if ( ! tqt_should_sync_wp_locale() || ! function_exists( 'tqt_get_current_language' ) || ! function_exists( 'tqt_is_rtl' ) ) {
		return $output;
	}

	$lang = tqt_get_current_language();
	$rtl  = tqt_is_rtl( $lang );
	$html = tqt_current_html_lang();

	$parts = [ $rtl ? 'dir="rtl"' : 'dir="ltr"' ];

	if ( $html !== '' ) {
		if ( 'text/html' === get_option( 'html_type' ) || 'html' === $doctype ) {
			$parts[] = 'lang="' . esc_attr( $html ) . '"';
		}
		if ( 'text/html' !== get_option( 'html_type' ) || 'xhtml' === $doctype ) {
			$parts[] = 'xml:lang="' . esc_attr( $html ) . '"';
		}
	}

	return implode( ' ', $parts );
}

add_filter( 'determine_locale', 'tqt_filter_determine_locale', 999 );
add_filter( 'locale', 'tqt_filter_locale', 999 );
add_filter( 'language_attributes', 'tqt_filter_language_attributes', 999, 2 );
