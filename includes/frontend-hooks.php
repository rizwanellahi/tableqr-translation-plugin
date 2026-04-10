<?php
/**
 * TQT — Front-end permalink localization (no home_url() recursion: helpers use raw home path).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @return bool
 */
function tqt_frontend_hooks_apply(): bool {
	if ( is_admin() ) {
		return false;
	}
	if ( wp_doing_ajax() ) {
		return false;
	}
	if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
		return false;
	}
	if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
		return false;
	}
	return (bool) apply_filters( 'tqt_apply_frontend_link_hooks', true );
}

add_filter(
	'home_url',
	static function ( $url, $path, $scheme, $blog_id ) {
		if ( ! tqt_frontend_hooks_apply() ) {
			return $url;
		}
		return tqt_localize_url( (string) $url );
	},
	999,
	4
);

add_filter(
	'post_link',
	static function ( $permalink, $post, $leavename ) {
		if ( ! tqt_frontend_hooks_apply() ) {
			return $permalink;
		}
		return tqt_localize_url( (string) $permalink );
	},
	999,
	3
);

add_filter(
	'page_link',
	static function ( $permalink, $post_id ) {
		if ( ! tqt_frontend_hooks_apply() ) {
			return $permalink;
		}
		return tqt_localize_url( (string) $permalink );
	},
	999,
	2
);

add_filter(
	'post_type_link',
	static function ( $post_link, $post, $leavename, $sample ) {
		if ( ! tqt_frontend_hooks_apply() ) {
			return $post_link;
		}
		return tqt_localize_url( (string) $post_link );
	},
	999,
	4
);

add_filter(
	'term_link',
	static function ( $termlink, $term, $taxonomy ) {
		if ( ! tqt_frontend_hooks_apply() ) {
			return $termlink;
		}
		return tqt_localize_url( (string) $termlink );
	},
	999,
	3
);

/**
 * Keep WordPress canonical redirects aligned with the active TQT language prefix.
 * Without this, redirect_canonical can send users to unprefixed URLs while the language memo was wrong.
 */
add_filter(
	'redirect_canonical',
	static function ( $redirect_url, $requested_url ) {
		if ( ! $redirect_url || ! tqt_frontend_hooks_apply() || ! function_exists( 'tqt_get_current_language' ) || ! function_exists( 'tqt_localize_url' ) || ! function_exists( 'tqt_delocalize_url' ) ) {
			return $redirect_url;
		}
		$default = tqt_get_settings()['default_language'];
		$lang    = tqt_get_current_language();
		if ( $lang === $default ) {
			return tqt_delocalize_url( (string) $redirect_url );
		}
		return tqt_localize_url( (string) $redirect_url, $lang );
	},
	1000,
	2
);
