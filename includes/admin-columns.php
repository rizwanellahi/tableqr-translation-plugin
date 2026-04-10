<?php
/**
 * TQT Admin Columns — Show translation status badges on post list tables.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'admin_init', function () {
    $settings = tqt_get_settings();
    if ( ! $settings['show_language_badges'] ) return;

    $all_fields = tqt_translatable_fields();
    $cpt_keys   = array_diff( array_keys( $all_fields ), [ 'options' ] );

    foreach ( $cpt_keys as $cpt ) {
        if ( ! post_type_exists( $cpt ) ) continue;

        add_filter( "manage_{$cpt}_posts_columns", function ( $cols ) {
            $new = [];
            foreach ( $cols as $k => $v ) {
                $new[ $k ] = $v;
                if ( $k === 'title' ) {
                    $new['tqt_langs'] = 'Languages';
                }
            }
            return $new;
        });

        add_action( "manage_{$cpt}_posts_custom_column", function ( $col, $post_id ) use ( $cpt ) {
            if ( $col !== 'tqt_langs' ) return;

            $active = tqt_active_languages();
            $trans  = tqt_translation_languages();

            if ( empty( $trans ) ) {
                echo '<span style="color:#999;">—</span>';
                return;
            }

            $settings    = tqt_get_settings();
            $default     = $settings['default_language'];
            $fields_def  = tqt_get_fields_for( $cpt );

            foreach ( $active as $code => $info ) {
                $has = false;
                if ( $code === $default ) {
                    $has = true; // default always has content
                } else {
                    foreach ( ( $fields_def['post_fields'] ?? [] ) as $f ) {
                        if ( tqt_get_translation( $post_id, $f['name'], $code ) !== '' ) { $has = true; break; }
                    }
                    if ( ! $has ) {
                        foreach ( ( $fields_def['meta_fields'] ?? [] ) as $f ) {
                            if ( tqt_get_translation( $post_id, $f['name'], $code ) !== '' ) { $has = true; break; }
                        }
                    }
                }
                $bg = $has ? '#00a32a' : '#ccc';
                echo '<span title="' . esc_attr( $info['label'] ) . '" style="display:inline-block;padding:2px 6px;margin:1px;border-radius:3px;font-size:11px;color:#fff;background:' . $bg . ';">' . esc_html( strtoupper( $code ) ) . '</span> ';
            }
        }, 10, 2 );
    }
});
