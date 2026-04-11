<?php
/**
 * TQT CSV Export
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Send CSV download before any admin output (avoids "headers already sent").
 */
add_action(
	'admin_init',
	static function () {
		if ( ! isset( $_POST['tqt_export'] ) ) {
			return;
		}
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! check_admin_referer( 'tqt_csv_export_nonce', '_wpnonce', false ) ) {
			return;
		}

		$post_type = sanitize_key( wp_unslash( $_POST['tqt_export_cpt'] ?? 'menu_item' ) );
		tqt_process_csv_export( $post_type );
	},
	1
);

function tqt_render_csv_export_page() {
    if ( ! current_user_can( 'manage_options' ) ) return;

    ?>
    <div class="wrap">
        <h1>CSV Export</h1>
        <p>Export posts with all translations as a CSV file. The exported file uses the same column format as the importer.</p>
        <form method="post">
            <?php wp_nonce_field( 'tqt_csv_export_nonce' ); ?>
            <table class="form-table">
                <tr>
                    <th>Post Type</th>
                    <td>
                        <select name="tqt_export_cpt">
                            <option value="menu_item">Menu Items</option>
                            <option value="branch">Branches</option>
                            <option value="menu_promo">Promotions</option>
                        </select>
                    </td>
                </tr>
            </table>
            <?php submit_button( 'Export CSV', 'primary', 'tqt_export' ); ?>
        </form>
    </div>
    <?php
}

function tqt_process_csv_export( string $post_type ) {
    $settings  = tqt_get_settings();
    $delimiter = $settings['csv_delimiter'] ?: ',';
    $default   = $settings['default_language'];
    $trans     = tqt_translation_languages();
    $trans_codes_longest_first = array_keys( $trans );
    usort( $trans_codes_longest_first, static function ( $a, $b ) {
        return strlen( $b ) <=> strlen( $a );
    } );
    $fields    = tqt_get_fields_for( $post_type );

    $posts = get_posts([
        'post_type'      => $post_type,
        'posts_per_page' => -1,
        'post_status'    => 'publish',
        'orderby'        => 'menu_order title',
        'order'          => 'ASC',
    ]);

    // Build headers
    $headers = [ 'title' ];
    if ( $post_type === 'menu_item' ) {
        $headers[] = 'menu_category';
        $headers[] = 'menu_section';
    }

    // Add translated columns for title and taxonomies
    foreach ( $trans as $code => $info ) {
        $headers[] = 'title_' . $code;
    }
    if ( $post_type === 'menu_item' ) {
        foreach ( $trans as $code => $info ) {
            $headers[] = 'menu_category_' . $code;
        }
        foreach ( $trans as $code => $info ) {
            $headers[] = 'menu_section_' . $code;
        }
    }

    // Meta fields + their translations
    foreach ( ( $fields['meta_fields'] ?? [] ) as $f ) {
        $headers[] = $f['name'];
        foreach ( $trans as $code => $info ) {
            $headers[] = $f['name'] . '_' . $code;
        }
    }

    // Non-translatable fields for menu_item
    if ( $post_type === 'menu_item' ) {
        $headers[] = 'price';
        $headers[] = 'calorie';

        // Determine max variant count across all posts
        $max_variants = 0;
        foreach ( $posts as $p ) {
            $count = (int) get_post_meta( $p->ID, 'prices', true );
            $max_variants = max( $max_variants, $count );
        }

        for ( $v = 1; $v <= $max_variants; $v++ ) {
            $headers[] = "prices_{$v}_price_name";
            foreach ( $trans as $code => $info ) {
                $headers[] = "prices_{$v}_price_name_{$code}";
            }
            $headers[] = "prices_{$v}_price";
            $headers[] = "prices_{$v}_calorie";
        }

        $headers[] = 'item_labels';
        $headers[] = 'ingredient_warnings';
    }

    // Output
    $filename = $post_type . '-export-' . date( 'Ymd-His' ) . '.csv';
    header( 'Content-Type: text/csv; charset=utf-8' );
    header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

    $output = fopen( 'php://output', 'w' );
    // BOM for Excel
    fprintf( $output, chr(0xEF) . chr(0xBB) . chr(0xBF) );
    fputcsv( $output, $headers, $delimiter );

    foreach ( $posts as $p ) {
        $row = [];

        foreach ( $headers as $col ) {
            // Detect language suffix
            $lang     = $default;
            $base_col = $col;
            foreach ( $trans_codes_longest_first as $code ) {
                $suffix = '_' . $code;
                if ( ! tqt_str_ends_with( $col, $suffix ) ) {
                    continue;
                }
                $candidate = substr( $col, 0, -strlen( $suffix ) );
                // Make sure it's actually a lang suffix not part of field name
                if ( in_array( $candidate, [ 'title', 'menu_category', 'menu_section', 'description', 'custom_label' ], true )
                    || preg_match( '/^prices_\d+_price_name$/', $candidate ) ) {
                    $lang     = $code;
                    $base_col = $candidate;
                    break;
                }
            }

            if ( $base_col === 'title' ) {
                $row[] = tqt_get_translation( $p->ID, 'post_title', $lang );
            } elseif ( $base_col === 'menu_category' ) {
                $terms = wp_get_object_terms( $p->ID, 'menu_category' );
                if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
                    $row[] = $lang === $default ? $terms[0]->name : tqt_get_term_translation( $terms[0]->term_id, $lang );
                } else {
                    $row[] = '';
                }
            } elseif ( $base_col === 'menu_section' ) {
                $terms = wp_get_object_terms( $p->ID, 'menu_section' );
                if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
                    $row[] = $lang === $default ? $terms[0]->name : tqt_get_term_translation( $terms[0]->term_id, $lang );
                } else {
                    $row[] = '';
                }
            } elseif ( preg_match( '/^prices_(\d+)_(.+)$/', $base_col, $m ) ) {
                $acf_idx = (int) $m[1] - 1;
                $sub     = $m[2];
                $key     = "prices_{$acf_idx}_{$sub}";
                if ( $lang !== $default ) {
                    $row[] = (string) get_post_meta( $p->ID, $key . '_' . $lang, true );
                } else {
                    $row[] = (string) get_post_meta( $p->ID, $key, true );
                }
            } elseif ( in_array( $base_col, [ 'price', 'calorie', 'item_labels', 'ingredient_warnings' ], true ) ) {
                $val = get_post_meta( $p->ID, $base_col, true );
                if ( $base_col === 'item_labels' || $base_col === 'ingredient_warnings' ) {
                    // These might be arrays
                    if ( is_array( $val ) ) $val = implode( ', ', $val );
                }
                $row[] = (string) $val;
            } else {
                // Regular translatable meta field
                $row[] = tqt_get_translation( $p->ID, $base_col, $lang );
            }
        }

        fputcsv( $output, $row, $delimiter );
    }

    fclose( $output );
    exit;
}
