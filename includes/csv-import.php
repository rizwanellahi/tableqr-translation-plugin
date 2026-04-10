<?php
/**
 * TQT CSV Import
 *
 * Matches the existing TableQR CSV format:
 *   title, menu_category, menu_section, title_ar, menu_category_ar, menu_section_ar,
 *   title_tr, ..., description, description_ar, description_tr, price, calorie,
 *   prices_1_price_name, prices_1_price_name_ar, ...
 *
 * The importer:
 * 1. Matches rows to existing posts by title (default language)
 * 2. Creates new posts if no match
 * 3. Stores default language values in original fields
 * 4. Stores translations in suffixed meta keys (field_name + _lang)
 * 5. Assigns taxonomy terms (creates if missing), stores term translations
 */

if ( ! defined( 'ABSPATH' ) ) exit;

function tqt_render_csv_import_page() {
    if ( ! current_user_can( 'manage_options' ) ) return;

    $message = '';
    $errors  = [];

    if ( isset( $_POST['tqt_import'] ) && check_admin_referer( 'tqt_csv_import_nonce' ) ) {
        if ( ! empty( $_FILES['tqt_csv_file']['tmp_name'] ) ) {
            $post_type = sanitize_key( $_POST['tqt_import_cpt'] ?? 'menu_item' );
            $dry_run   = ! empty( $_POST['tqt_dry_run'] );
            $result    = tqt_process_csv_import( $_FILES['tqt_csv_file']['tmp_name'], $post_type, $dry_run );
            $message   = $result['message'];
            $errors    = $result['errors'];
        } else {
            $message = 'No file selected.';
        }
    }

    $settings   = tqt_get_settings();
    $active     = tqt_active_languages();
    $default    = $settings['default_language'];
    $trans      = tqt_translation_languages();
    $lang_codes = array_keys( $trans );
    $suffixes   = implode( ', ', array_map( fn($c) => "_{$c}", $lang_codes ) );

    ?>
    <div class="wrap">
        <h1>CSV Import</h1>

        <?php if ( $message ) : ?>
            <div class="notice <?php echo empty( $errors ) ? 'notice-success' : 'notice-warning'; ?>">
                <p><?php echo esc_html( $message ); ?></p>
                <?php foreach ( $errors as $e ) : ?>
                    <p style="color:#a00;"><?php echo esc_html( $e ); ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <h2>How it works</h2>
        <p>Your CSV columns use the <strong>suffix pattern</strong>: the base column is the default language (<?php echo esc_html( $default ); ?>), and translated columns append a language suffix.</p>
        <p>For example with languages <?php echo esc_html( implode( ', ', array_keys( $active ) ) ); ?>:</p>

        <code>title, title_ar, title_tr, description, description_ar, description_tr, price, calorie, ...</code>

        <h3>Supported columns</h3>
        <ul style="list-style:disc;padding-left:20px;">
            <li><strong>title</strong> + title suffixes — post title translations</li>
            <li><strong>menu_category</strong> + suffixes — assigns category, creates if missing, stores term translations</li>
            <li><strong>menu_section</strong> + suffixes — same for sections</li>
            <li><strong>description</strong> + suffixes — item description translations</li>
            <li><strong>price, calorie</strong> — non-translatable, stored as-is</li>
            <li><strong>prices_N_price_name</strong> + suffixes — variant price name translations</li>
            <li><strong>prices_N_price, prices_N_calorie</strong> — variant price/calorie values</li>
            <li><strong>item_labels, ingredient_warnings</strong> — stored as-is (pre-translated WPGlobus format)</li>
            <li><strong>custom_label</strong> + suffixes — custom label translations</li>
            <li>Any other column with a recognized language suffix will be stored as translated meta</li>
        </ul>

        <h2>Upload</h2>
        <form method="post" enctype="multipart/form-data">
            <?php wp_nonce_field( 'tqt_csv_import_nonce' ); ?>

            <table class="form-table">
                <tr>
                    <th>Post Type</th>
                    <td>
                        <select name="tqt_import_cpt">
                            <option value="menu_item">Menu Items</option>
                            <option value="branch">Branches</option>
                            <option value="menu_promo">Promotions</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th>CSV File</th>
                    <td><input type="file" name="tqt_csv_file" accept=".csv,.tsv,.txt"></td>
                </tr>
                <tr>
                    <th>Options</th>
                    <td>
                        <label><input type="checkbox" name="tqt_dry_run" value="1"> Dry run (validate only, don't import)</label>
                    </td>
                </tr>
            </table>

            <?php submit_button( 'Import', 'primary', 'tqt_import' ); ?>
        </form>
    </div>
    <?php
}

function tqt_process_csv_import( string $file, string $post_type, bool $dry_run ): array {
    $settings  = tqt_get_settings();
    $delimiter = $settings['csv_delimiter'] ?: ',';
    $default   = $settings['default_language'];
    $trans     = tqt_translation_languages();

    $handle = fopen( $file, 'r' );
    if ( ! $handle ) return [ 'message' => 'Could not open file.', 'errors' => [] ];

    // Read headers — strip BOM
    $raw_headers = fgetcsv( $handle, 0, $delimiter );
    if ( ! $raw_headers ) { fclose( $handle ); return [ 'message' => 'Empty CSV.', 'errors' => [] ]; }
    $raw_headers[0] = preg_replace( '/^\xEF\xBB\xBF/', '', $raw_headers[0] );
    $headers = array_map( 'trim', $raw_headers );

    if ( ! in_array( 'title', $headers, true ) ) {
        fclose( $handle );
        return [ 'message' => 'Missing required "title" column.', 'errors' => [] ];
    }

    // Build lang suffix list
    $lang_suffixes = [];
    foreach ( $trans as $code => $info ) {
        $lang_suffixes[] = '_' . $code;
    }

    // Parse all rows
    $rows    = [];
    $errors  = [];
    $row_num = 1;

    while ( ( $row = fgetcsv( $handle, 0, $delimiter ) ) !== false ) {
        $row_num++;
        if ( count( $row ) !== count( $headers ) ) {
            $errors[] = "Row {$row_num}: column count mismatch (expected " . count( $headers ) . ", got " . count( $row ) . ")";
            continue;
        }
        $rows[] = array_combine( $headers, $row );
    }
    fclose( $handle );

    if ( $dry_run ) {
        return [
            'message' => "Dry run: " . count( $rows ) . " rows parsed, " . count( $headers ) . " columns. " . count( $errors ) . " errors.",
            'errors'  => $errors,
        ];
    }

    // Process rows
    $created = 0;
    $updated = 0;

    foreach ( $rows as $idx => $data ) {
        $title = trim( $data['title'] ?? '' );
        if ( ! $title ) {
            $errors[] = "Row " . ( $idx + 2 ) . ": empty title, skipping.";
            continue;
        }

        // Find existing post by exact title match
        $existing = get_posts([
            'post_type'      => $post_type,
            'title'          => $title,
            'posts_per_page' => 1,
            'post_status'    => 'any',
        ]);

        $post_data = [
            'post_type'   => $post_type,
            'post_title'  => $title,
            'post_status' => 'publish',
        ];

        if ( ! empty( $existing ) ) {
            $post_id = $existing[0]->ID;
            $post_data['ID'] = $post_id;
            wp_update_post( $post_data );
            $updated++;
        } else {
            $post_id = wp_insert_post( $post_data );
            $created++;
        }

        if ( is_wp_error( $post_id ) ) {
            $errors[] = "Row " . ( $idx + 2 ) . ": failed to create post for '{$title}'.";
            continue;
        }

        // Process each column
        foreach ( $data as $col => $value ) {
            $value = trim( $value );
            if ( $col === 'title' ) continue; // Already handled as post_title

            // Detect if this is a translated column (ends with _ar, _tr, etc.)
            $lang       = $default;
            $base_col   = $col;
            foreach ( $lang_suffixes as $suffix ) {
                if ( str_ends_with( $col, $suffix ) ) {
                    $lang     = substr( $suffix, 1 );
                    $base_col = substr( $col, 0, -strlen( $suffix ) );
                    break;
                }
            }

            // ── Title translations ──
            if ( $base_col === 'title' && $lang !== $default ) {
                if ( $value !== '' ) {
                    tqt_set_translation( $post_id, 'post_title', $lang, $value );
                }
                continue;
            }

            // ── Taxonomy: menu_category ──
            if ( $base_col === 'menu_category' ) {
                if ( $value !== '' && $lang === $default ) {
                    // Assign term
                    $term = term_exists( $value, 'menu_category' );
                    if ( ! $term ) {
                        $term = wp_insert_term( $value, 'menu_category' );
                    }
                    if ( ! is_wp_error( $term ) ) {
                        $term_id = is_array( $term ) ? $term['term_id'] : $term;
                        wp_set_object_terms( $post_id, (int) $term_id, 'menu_category', true );
                    }
                } elseif ( $value !== '' && $lang !== $default ) {
                    // Find the default language term to store translation
                    $default_term_name = trim( $data['menu_category'] ?? '' );
                    if ( $default_term_name ) {
                        $term = get_term_by( 'name', $default_term_name, 'menu_category' );
                        if ( $term ) {
                            tqt_set_term_translation( $term->term_id, $lang, $value );
                        }
                    }
                }
                continue;
            }

            // ── Taxonomy: menu_section ──
            if ( $base_col === 'menu_section' ) {
                if ( $value !== '' && $lang === $default ) {
                    $term = term_exists( $value, 'menu_section' );
                    if ( ! $term ) {
                        $term = wp_insert_term( $value, 'menu_section' );
                    }
                    if ( ! is_wp_error( $term ) ) {
                        $term_id = is_array( $term ) ? $term['term_id'] : $term;
                        wp_set_object_terms( $post_id, (int) $term_id, 'menu_section', true );
                    }
                } elseif ( $value !== '' && $lang !== $default ) {
                    $default_term_name = trim( $data['menu_section'] ?? '' );
                    if ( $default_term_name ) {
                        $term = get_term_by( 'name', $default_term_name, 'menu_section' );
                        if ( $term ) {
                            tqt_set_term_translation( $term->term_id, $lang, $value );
                        }
                    }
                }
                continue;
            }

            // ── Non-translatable fields (store only for default lang) ──
            $non_translatable = [ 'price', 'calorie', 'item_labels', 'ingredient_warnings' ];
            // Check variant price/calorie (prices_N_price, prices_N_calorie)
            $is_variant_value = preg_match( '/^prices_\d+_(price|calorie)$/', $base_col );

            if ( in_array( $base_col, $non_translatable, true ) || $is_variant_value ) {
                if ( $lang === $default && $value !== '' ) {
                    // For ACF variant repeater: prices_1_price_name → ACF stores as prices_0_price_name
                    if ( preg_match( '/^prices_(\d+)_(.+)$/', $col, $m ) ) {
                        $acf_idx = (int) $m[1] - 1; // CSV is 1-indexed, ACF is 0-indexed
                        $acf_key = "prices_{$acf_idx}_{$m[2]}";
                        update_post_meta( $post_id, $acf_key, $value );
                    } else {
                        update_post_meta( $post_id, $base_col, $value );
                        if ( function_exists( 'update_field' ) ) {
                            update_field( $base_col, $value, $post_id );
                        }
                    }
                }
                continue;
            }

            // ── Translatable meta fields ──
            if ( $value !== '' ) {
                // Handle variant price names: prices_1_price_name_ar → prices_0_price_name + _ar
                if ( preg_match( '/^prices_(\d+)_(.+)$/', $base_col, $m ) ) {
                    $acf_idx  = (int) $m[1] - 1;
                    $real_key = "prices_{$acf_idx}_{$m[2]}";

                    if ( $lang === $default ) {
                        update_post_meta( $post_id, $real_key, $value );
                    } else {
                        update_post_meta( $post_id, $real_key . '_' . $lang, $value );
                    }
                } else {
                    // Standard field
                    if ( $lang === $default ) {
                        update_post_meta( $post_id, $base_col, $value );
                        if ( function_exists( 'update_field' ) ) {
                            update_field( $base_col, $value, $post_id );
                        }
                    } else {
                        tqt_set_translation( $post_id, $base_col, $lang, $value );
                    }
                }
            }
        }

        // Handle ACF repeater count for prices
        $max_variant = 0;
        foreach ( $data as $col => $val ) {
            if ( preg_match( '/^prices_(\d+)_/', $col, $m ) ) {
                $max_variant = max( $max_variant, (int) $m[1] );
            }
        }
        if ( $max_variant > 0 ) {
            update_post_meta( $post_id, 'prices', $max_variant );
            if ( $max_variant > 0 ) {
                update_post_meta( $post_id, 'add_variants', '1' );
            }
        }
    }

    return [
        'message' => "Import complete. Created: {$created}, Updated: {$updated}.",
        'errors'  => $errors,
    ];
}
