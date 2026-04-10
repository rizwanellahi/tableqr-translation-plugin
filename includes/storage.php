<?php
/**
 * TQT Storage — Read/write translation values.
 *
 * Uses suffixed meta keys: description_ar, description_tr, etc.
 * Default language uses the original key (no suffix).
 *
 * For post fields (post_title), translations are stored in postmeta
 * as tqt_post_title_{lang} since we can't have multiple post_title columns.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* ------------------------------------------------------------------ */
/*  POST META translations                                             */
/* ------------------------------------------------------------------ */

/**
 * Get a translated meta value for a post.
 *
 * @param int    $post_id
 * @param string $field_name  Base field name (e.g. 'description')
 * @param string $lang        Language code
 * @return string
 */
function tqt_get_translation( int $post_id, string $field_name, string $lang ): string {
    $settings = tqt_get_settings();

    if ( $lang === $settings['default_language'] ) {
        // Default language: read from original field
        $val = get_post_meta( $post_id, $field_name, true );
        // For post_title, read from the post itself
        if ( $field_name === 'post_title' ) {
            $val = get_the_title( $post_id );
        }
        return (string) $val;
    }

    // Translation: read suffixed key
    if ( $field_name === 'post_title' ) {
        return (string) get_post_meta( $post_id, 'tqt_post_title_' . $lang, true );
    }

    return (string) get_post_meta( $post_id, $field_name . '_' . $lang, true );
}

/**
 * Set a translated meta value for a post.
 */
function tqt_set_translation( int $post_id, string $field_name, string $lang, string $value ): void {
    $settings = tqt_get_settings();

    if ( $lang === $settings['default_language'] ) {
        if ( $field_name === 'post_title' ) {
            wp_update_post( [ 'ID' => $post_id, 'post_title' => $value ] );
        } else {
            update_post_meta( $post_id, $field_name, $value );
            // Also update via ACF if available, to keep ACF's internal refs in sync
            if ( function_exists( 'update_field' ) ) {
                update_field( $field_name, $value, $post_id );
            }
        }
        return;
    }

    if ( $field_name === 'post_title' ) {
        update_post_meta( $post_id, 'tqt_post_title_' . $lang, $value );
    } else {
        update_post_meta( $post_id, $field_name . '_' . $lang, $value );
    }
}

/* ------------------------------------------------------------------ */
/*  OPTIONS translations (for ACF options pages)                       */
/* ------------------------------------------------------------------ */

function tqt_get_option_translation( string $field_name, string $lang ): string {
    $settings = tqt_get_settings();
    if ( $lang === $settings['default_language'] ) {
        return (string) get_option( 'options_' . $field_name, '' );
    }
    return (string) get_option( 'options_' . $field_name . '_' . $lang, '' );
}

function tqt_set_option_translation( string $field_name, string $lang, string $value ): void {
    $settings = tqt_get_settings();
    if ( $lang === $settings['default_language'] ) {
        update_option( 'options_' . $field_name, $value );
    } else {
        update_option( 'options_' . $field_name . '_' . $lang, $value );
    }
}

/* ------------------------------------------------------------------ */
/*  TAXONOMY TERM translations                                         */
/* ------------------------------------------------------------------ */

function tqt_get_term_translation( int $term_id, string $lang ): string {
    $settings = tqt_get_settings();
    if ( $lang === $settings['default_language'] ) {
        $term = get_term( $term_id );
        return $term ? $term->name : '';
    }
    return (string) get_term_meta( $term_id, 'tqt_name_' . $lang, true );
}

function tqt_set_term_translation( int $term_id, string $lang, string $value ): void {
    $settings = tqt_get_settings();
    if ( $lang === $settings['default_language'] ) {
        wp_update_term( $term_id, '', [ 'name' => $value ] );
    } else {
        update_term_meta( $term_id, 'tqt_name_' . $lang, $value );
    }
}

/**
 * Term name for front-end display: TQT translation for the active (or given) language, else default WP term name.
 *
 * @param WP_Term|int $term Term object or term ID.
 */
function tqt_term_display_name( $term, ?string $lang = null ): string {
    if ( is_numeric( $term ) ) {
        $term = get_term( (int) $term );
    }
    if ( ! $term instanceof WP_Term || is_wp_error( $term ) ) {
        return '';
    }
    if ( ! function_exists( 'tqt_get_current_language' ) || ! function_exists( 'tqt_get_term_translation' ) ) {
        return $term->name;
    }
    $lang = $lang ?? tqt_get_current_language();
    $tr   = tqt_get_term_translation( (int) $term->term_id, $lang );
    return $tr !== '' ? $tr : $term->name;
}

/* ------------------------------------------------------------------ */
/*  BULK: Get all translations for a post (all fields, all languages)  */
/* ------------------------------------------------------------------ */

/**
 * Returns [ field_name => [ lang_code => value, ... ], ... ]
 */
function tqt_get_all_translations( int $post_id, string $post_type ): array {
    $fields   = tqt_get_fields_for( $post_type );
    $langs    = tqt_active_languages();
    $result   = [];

    if ( ! $fields ) return $result;

    // Post fields (title)
    foreach ( ( $fields['post_fields'] ?? [] ) as $f ) {
        foreach ( $langs as $code => $info ) {
            $result[ $f['name'] ][ $code ] = tqt_get_translation( $post_id, $f['name'], $code );
        }
    }

    // Meta fields
    foreach ( ( $fields['meta_fields'] ?? [] ) as $f ) {
        foreach ( $langs as $code => $info ) {
            $result[ $f['name'] ][ $code ] = tqt_get_translation( $post_id, $f['name'], $code );
        }
    }

    return $result;
}

/**
 * Count how many non-default languages have content for a post.
 * Returns [ 'filled' => N, 'total' => M ]
 */
function tqt_translation_completeness( int $post_id, string $post_type ): array {
    $trans_langs = tqt_translation_languages();
    $fields      = tqt_get_fields_for( $post_type );
    if ( ! $fields || empty( $trans_langs ) ) {
        return [ 'filled' => 0, 'total' => 0 ];
    }

    $total  = count( $trans_langs );
    $filled = 0;

    foreach ( $trans_langs as $code => $info ) {
        $has_content = false;

        // Check post fields
        foreach ( ( $fields['post_fields'] ?? [] ) as $f ) {
            if ( tqt_get_translation( $post_id, $f['name'], $code ) !== '' ) {
                $has_content = true;
                break;
            }
        }
        if ( ! $has_content ) {
            foreach ( ( $fields['meta_fields'] ?? [] ) as $f ) {
                if ( tqt_get_translation( $post_id, $f['name'], $code ) !== '' ) {
                    $has_content = true;
                    break;
                }
            }
        }

        if ( $has_content ) $filled++;
    }

    return [ 'filled' => $filled, 'total' => $total ];
}
