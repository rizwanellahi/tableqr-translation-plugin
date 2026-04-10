<?php
/**
 * Plugin Name: TableQR Translations
 * Plugin URI:  https://example.com
 * Description: Lightweight multilingual translation system for Digital Menu headless WordPress. Provides per-site language config, tabbed ACF editor UI, CSV import/export, and a clean REST API for Next.js frontends.
 * Version:     1.0.0
 * Author:      Your Team
 * Network:     true
 * Text Domain: tableqr-translations
 * Requires PHP: 7.4
 * Requires at least: 6.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'TQT_VERSION', '1.0.0' );
define( 'TQT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'TQT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/* Load sub-modules */
require_once TQT_PLUGIN_DIR . 'includes/cpt-registration.php';
require_once TQT_PLUGIN_DIR . 'includes/acf-fields.php';

/* ------------------------------------------------------------------ */
/*  1. BUILT-IN LANGUAGE REGISTRY                                      */
/* ------------------------------------------------------------------ */
function tqt_get_all_languages(): array {
    return [
        'en' => 'English',
        'ar' => 'Arabic',
        'zh' => 'Chinese (Simplified)',
        'zh_TW' => 'Chinese (Traditional)',
        'nl' => 'Dutch',
        'fil' => 'Filipino',
        'fr' => 'French',
        'de' => 'German',
        'el' => 'Greek',
        'hi' => 'Hindi',
        'id' => 'Indonesian',
        'it' => 'Italian',
        'ja' => 'Japanese',
        'ko' => 'Korean',
        'ms' => 'Malay',
        'fa' => 'Persian (Farsi)',
        'pl' => 'Polish',
        'pt' => 'Portuguese',
        'ro' => 'Romanian',
        'ru' => 'Russian',
        'es' => 'Spanish',
        'sv' => 'Swedish',
        'th' => 'Thai',
        'tr' => 'Turkish',
        'uk' => 'Ukrainian',
        'ur' => 'Urdu',
        'vi' => 'Vietnamese',
    ];
}

/* ------------------------------------------------------------------ */
/*  2. SETTINGS — Per-site language configuration                      */
/* ------------------------------------------------------------------ */
function tqt_get_settings(): array {
    $defaults = [
        'enabled_languages'  => [ 'en' ],
        'default_language'    => 'en',
        'custom_languages'    => [],       // [ ['code'=>'tl','label'=>'Tagalog'], ... ]
        'fallback_behaviour'  => 'default', // 'default' | 'hide' | 'empty'
        'rtl_languages'       => [ 'ar', 'fa', 'ur' ],
        'show_language_badge' => true,
        'csv_delimiter'       => ',',
    ];
    $saved = get_option( 'tqt_settings', [] );
    return wp_parse_args( $saved, $defaults );
}

function tqt_save_settings( array $data ): bool {
    return update_option( 'tqt_settings', $data );
}

/** Merge built-in + custom languages, return only enabled ones. */
function tqt_get_active_languages(): array {
    $settings  = tqt_get_settings();
    $all       = tqt_get_all_languages();

    // Merge custom languages
    foreach ( $settings['custom_languages'] as $custom ) {
        if ( ! empty( $custom['code'] ) && ! empty( $custom['label'] ) ) {
            $all[ sanitize_key( $custom['code'] ) ] = sanitize_text_field( $custom['label'] );
        }
    }

    $active = [];
    foreach ( $settings['enabled_languages'] as $code ) {
        if ( isset( $all[ $code ] ) ) {
            $active[ $code ] = $all[ $code ];
        }
    }
    return $active;
}

/* ------------------------------------------------------------------ */
/*  3. ADMIN MENU & SETTINGS PAGE                                      */
/* ------------------------------------------------------------------ */
add_action( 'admin_menu', function () {
    add_menu_page(
        'TableQR Translations',
        'TableQR Translations',
        'manage_options',
        'tqt-settings',
        'tqt_render_settings_page',
        'dashicons-translation',
        80
    );
    add_submenu_page(
        'tqt-settings',
        'CSV Import',
        'CSV Import',
        'manage_options',
        'tqt-csv-import',
        'tqt_render_csv_import_page'
    );
    add_submenu_page(
        'tqt-settings',
        'CSV Export',
        'CSV Export',
        'manage_options',
        'tqt-csv-export',
        'tqt_render_csv_export_page'
    );
});

function tqt_render_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) return;

    // Handle save
    if ( isset( $_POST['tqt_save_settings'] ) && check_admin_referer( 'tqt_settings_nonce' ) ) {
        $settings = tqt_get_settings();

        $settings['enabled_languages']  = array_map( 'sanitize_key', $_POST['tqt_enabled_langs'] ?? [] );
        $settings['default_language']    = sanitize_key( $_POST['tqt_default_lang'] ?? 'en' );
        $settings['fallback_behaviour']  = sanitize_key( $_POST['tqt_fallback'] ?? 'default' );
        $settings['show_language_badge'] = ! empty( $_POST['tqt_show_badge'] );
        $settings['csv_delimiter']       = sanitize_text_field( $_POST['tqt_csv_delimiter'] ?? ',' );

        // Custom languages
        $custom = [];
        if ( ! empty( $_POST['tqt_custom_code'] ) ) {
            foreach ( $_POST['tqt_custom_code'] as $i => $code ) {
                $code  = sanitize_key( $code );
                $label = sanitize_text_field( $_POST['tqt_custom_label'][ $i ] ?? '' );
                if ( $code && $label ) {
                    $custom[] = [ 'code' => $code, 'label' => $label ];
                }
            }
        }
        $settings['custom_languages'] = $custom;

        // Ensure default language is in enabled list
        if ( ! in_array( $settings['default_language'], $settings['enabled_languages'], true ) ) {
            array_unshift( $settings['enabled_languages'], $settings['default_language'] );
        }

        tqt_save_settings( $settings );
        echo '<div class="notice notice-success"><p>Settings saved.</p></div>';
    }

    $settings   = tqt_get_settings();
    $all_langs  = tqt_get_all_languages();

    // Merge custom into display list
    foreach ( $settings['custom_languages'] as $c ) {
        $all_langs[ $c['code'] ] = $c['label'] . ' (custom)';
    }

    ?>
    <div class="wrap">
        <h1>TableQR Translations — Settings</h1>
        <form method="post">
            <?php wp_nonce_field( 'tqt_settings_nonce' ); ?>

            <!-- ── Default language ── -->
            <h2>Default Language</h2>
            <p class="description">The language shown first in the editor tabs and used as the primary display language for the menu frontend.</p>
            <select name="tqt_default_lang">
                <?php foreach ( $all_langs as $code => $label ) : ?>
                    <option value="<?php echo esc_attr( $code ); ?>" <?php selected( $settings['default_language'], $code ); ?>><?php echo esc_html( $label ); ?></option>
                <?php endforeach; ?>
            </select>

            <!-- ── Enabled languages ── -->
            <h2>Enabled Languages</h2>
            <p class="description">Check the languages available for this site. Only checked languages appear as tabs in the editor.</p>
            <fieldset style="max-height:300px;overflow-y:auto;border:1px solid #ccc;padding:12px;background:#fff;">
                <?php foreach ( $all_langs as $code => $label ) : ?>
                    <label style="display:block;margin-bottom:6px;">
                        <input type="checkbox" name="tqt_enabled_langs[]" value="<?php echo esc_attr( $code ); ?>"
                            <?php checked( in_array( $code, $settings['enabled_languages'], true ) ); ?>>
                        <?php echo esc_html( $label ); ?> <code>(<?php echo esc_html( $code ); ?>)</code>
                    </label>
                <?php endforeach; ?>
            </fieldset>

            <!-- ── Custom languages ── -->
            <h2>Custom Languages</h2>
            <p class="description">Add languages not in the built-in list.</p>
            <div id="tqt-custom-langs">
                <?php foreach ( $settings['custom_languages'] as $c ) : ?>
                    <div class="tqt-custom-row" style="margin-bottom:6px;">
                        <input type="text" name="tqt_custom_code[]" value="<?php echo esc_attr( $c['code'] ); ?>" placeholder="Code (e.g. tl)" style="width:100px;">
                        <input type="text" name="tqt_custom_label[]" value="<?php echo esc_attr( $c['label'] ); ?>" placeholder="Label (e.g. Tagalog)" style="width:200px;">
                        <button type="button" class="button tqt-remove-custom">✕</button>
                    </div>
                <?php endforeach; ?>
            </div>
            <button type="button" class="button" id="tqt-add-custom-lang">+ Add Custom Language</button>

            <!-- ── Fallback behaviour ── -->
            <h2>Fallback Behaviour</h2>
            <p class="description">When a translation is missing for a requested language:</p>
            <select name="tqt_fallback">
                <option value="default" <?php selected( $settings['fallback_behaviour'], 'default' ); ?>>Show default language content</option>
                <option value="hide" <?php selected( $settings['fallback_behaviour'], 'hide' ); ?>>Hide the item entirely</option>
                <option value="empty" <?php selected( $settings['fallback_behaviour'], 'empty' ); ?>>Show empty / blank fields</option>
            </select>

            <!-- ── Display options ── -->
            <h2>Display Options</h2>
            <label>
                <input type="checkbox" name="tqt_show_badge" <?php checked( $settings['show_language_badge'] ); ?>>
                Show language badge/count on post list table
            </label>

            <!-- ── CSV options ── -->
            <h2>CSV Settings</h2>
            <label>
                Delimiter:
                <select name="tqt_csv_delimiter">
                    <option value="," <?php selected( $settings['csv_delimiter'], ',' ); ?>>Comma (,)</option>
                    <option value=";" <?php selected( $settings['csv_delimiter'], ';' ); ?>>Semicolon (;)</option>
                    <option value="\t" <?php selected( $settings['csv_delimiter'], "\t" ); ?>>Tab</option>
                </select>
            </label>

            <br><br>
            <button type="submit" name="tqt_save_settings" class="button button-primary">Save Settings</button>
        </form>
    </div>

    <script>
    jQuery(function($){
        $('#tqt-add-custom-lang').on('click', function(){
            $('#tqt-custom-langs').append(
                '<div class="tqt-custom-row" style="margin-bottom:6px;">' +
                '<input type="text" name="tqt_custom_code[]" placeholder="Code (e.g. tl)" style="width:100px;"> ' +
                '<input type="text" name="tqt_custom_label[]" placeholder="Label (e.g. Tagalog)" style="width:200px;"> ' +
                '<button type="button" class="button tqt-remove-custom">✕</button>' +
                '</div>'
            );
        });
        $(document).on('click', '.tqt-remove-custom', function(){ $(this).parent().remove(); });
    });
    </script>
    <?php
}

/* ------------------------------------------------------------------ */
/*  4. TABBED LANGUAGE UI ON POST EDIT SCREENS                         */
/* ------------------------------------------------------------------ */
/**
 * Registers ACF field groups programmatically for any CPT that opts in.
 *
 * HOW TO USE:
 * In your theme or another plugin, hook into 'tqt_translatable_fields'
 * and return the ACF sub-fields you want translated per language.
 *
 * Example:
 *   add_filter('tqt_translatable_fields', function( $fields, $post_type ) {
 *       if ( $post_type === 'digital_menu' ) {
 *           $fields[] = [ 'key' => 'item_name',   'label' => 'Item Name',        'type' => 'text' ];
 *           $fields[] = [ 'key' => 'item_desc',   'label' => 'Item Description', 'type' => 'textarea' ];
 *           $fields[] = [ 'key' => 'item_note',   'label' => 'Dietary Note',     'type' => 'text' ];
 *       }
 *       return $fields;
 *   }, 10, 2);
 */

add_action( 'admin_enqueue_scripts', function ( $hook ) {
    if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) return;

    $languages = tqt_get_active_languages();
    if ( count( $languages ) < 2 ) return;

    $settings = tqt_get_settings();
    $default  = $settings['default_language'];
    $rtl_list = $settings['rtl_languages'] ?? [];

    wp_enqueue_style( 'tqt-tabs', TQT_PLUGIN_URL . 'assets/css/tqt-tabs.css', [], TQT_VERSION );
    wp_enqueue_script( 'tqt-tabs', TQT_PLUGIN_URL . 'assets/js/tqt-tabs.js', [ 'jquery' ], TQT_VERSION, true );
    wp_localize_script( 'tqt-tabs', 'TQT', [
        'languages'       => $languages,
        'defaultLanguage' => $default,
        'rtlLanguages'    => $rtl_list,
    ]);
});

/* ------------------------------------------------------------------ */
/*  5. REST API — /wp-json/tableqr/v1/menu                                  */
/* ------------------------------------------------------------------ */
add_action( 'rest_api_init', function () {

    // GET /wp-json/tableqr/v1/menu?lang=zh&category=mains
    register_rest_route( 'tableqr/v1', '/menu', [
        'methods'             => 'GET',
        'callback'            => 'tqt_rest_get_menu',
        'permission_callback' => '__return_true',
        'args'                => [
            'lang' => [
                'required'          => false,
                'sanitize_callback' => 'sanitize_key',
            ],
            'category' => [
                'required'          => false,
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'per_page' => [
                'required'          => false,
                'default'           => 100,
                'sanitize_callback' => 'absint',
            ],
            'page' => [
                'required'          => false,
                'default'           => 1,
                'sanitize_callback' => 'absint',
            ],
        ],
    ]);

    // GET /wp-json/tableqr/v1/languages  — available languages for this site
    register_rest_route( 'tableqr/v1', '/languages', [
        'methods'             => 'GET',
        'callback'            => 'tqt_rest_get_languages',
        'permission_callback' => '__return_true',
    ]);

    // GET /wp-json/tableqr/v1/menu/<id>?lang=zh  — single item
    register_rest_route( 'tableqr/v1', '/menu/(?P<id>\d+)', [
        'methods'             => 'GET',
        'callback'            => 'tqt_rest_get_single_item',
        'permission_callback' => '__return_true',
        'args'                => [
            'lang' => [
                'required'          => false,
                'sanitize_callback' => 'sanitize_key',
            ],
        ],
    ]);
});

function tqt_rest_get_languages( WP_REST_Request $request ): WP_REST_Response {
    $settings  = tqt_get_settings();
    $active    = tqt_get_active_languages();
    $default   = $settings['default_language'];
    $rtl       = $settings['rtl_languages'] ?? [];

    $out = [];
    foreach ( $active as $code => $label ) {
        $out[] = [
            'code'       => $code,
            'label'      => $label,
            'is_default' => $code === $default,
            'is_rtl'     => in_array( $code, $rtl, true ),
        ];
    }
    return new WP_REST_Response( $out, 200 );
}

function tqt_rest_get_menu( WP_REST_Request $request ): WP_REST_Response {
    $settings  = tqt_get_settings();
    $lang      = $request->get_param( 'lang' ) ?: $settings['default_language'];
    $category  = $request->get_param( 'category' );
    $per_page  = min( $request->get_param( 'per_page' ), 500 );
    $page      = $request->get_param( 'page' );

    $args = [
        'post_type'      => 'digital_menu', // Adjust to your CPT slug
        'posts_per_page' => $per_page,
        'paged'          => $page,
        'post_status'    => 'publish',
        'orderby'        => 'menu_order',
        'order'          => 'ASC',
    ];

    if ( $category ) {
        $args['meta_query'] = [[
            'key'   => 'category',
            'value' => $category,
        ]];
    }

    $query = new WP_Query( $args );
    $items = [];

    foreach ( $query->posts as $post ) {
        $item = tqt_build_item_response( $post, $lang, $settings );
        if ( $item !== null ) {
            $items[] = $item;
        }
    }

    $response = new WP_REST_Response( $items, 200 );
    $response->header( 'X-WP-Total', $query->found_posts );
    $response->header( 'X-WP-TotalPages', $query->max_num_pages );
    $response->header( 'X-TQT-Language', $lang );

    return $response;
}

function tqt_rest_get_single_item( WP_REST_Request $request ): WP_REST_Response {
    $settings = tqt_get_settings();
    $lang     = $request->get_param( 'lang' ) ?: $settings['default_language'];
    $post     = get_post( $request->get_param( 'id' ) );

    if ( ! $post || $post->post_status !== 'publish' ) {
        return new WP_REST_Response( [ 'error' => 'Item not found' ], 404 );
    }

    $item = tqt_build_item_response( $post, $lang, $settings );
    if ( $item === null ) {
        return new WP_REST_Response( [ 'error' => 'No translation available' ], 404 );
    }

    return new WP_REST_Response( $item, 200 );
}

function tqt_build_item_response( WP_Post $post, string $lang, array $settings ): ?array {
    $translations = get_field( 'tqt_translations', $post->ID );
    $fallback     = $settings['fallback_behaviour'];
    $default_lang = $settings['default_language'];

    $matched    = null;
    $default_tr = null;

    if ( is_array( $translations ) ) {
        foreach ( $translations as $tr ) {
            if ( ( $tr['tqt_lang_code'] ?? '' ) === $lang ) {
                $matched = $tr;
                break;
            }
            if ( ( $tr['tqt_lang_code'] ?? '' ) === $default_lang ) {
                $default_tr = $tr;
            }
        }
    }

    // Fallback logic
    if ( ! $matched ) {
        if ( $fallback === 'hide' ) return null;
        if ( $fallback === 'default' && $default_tr ) {
            $matched = $default_tr;
        }
    }

    // Build non-translatable fields (price, image, category, etc.)
    $image_id  = get_field( 'item_image', $post->ID );
    $image_url = $image_id ? wp_get_attachment_url( $image_id ) : null;

    $item = [
        'id'            => $post->ID,
        'slug'          => $post->post_name,
        'item_id'       => get_field( 'item_id', $post->ID ) ?: (string) $post->ID,
        'category'      => get_field( 'category', $post->ID ),
        'price'         => get_field( 'price', $post->ID ),
        'image'         => $image_url,
        'is_available'  => get_field( 'is_available', $post->ID ) ?? true,
        'sort_order'    => $post->menu_order,
        'language'      => $lang,
    ];

    // Merge translated fields
    if ( $matched ) {
        // Dynamically include all translated sub-fields except the lang code
        foreach ( $matched as $key => $value ) {
            if ( $key === 'tqt_lang_code' ) continue;
            // Strip the tqt_ prefix for cleaner API output
            $clean_key = preg_replace( '/^tqt_/', '', $key );
            $item[ $clean_key ] = $value;
        }
    }

    return $item;
}

/* ------------------------------------------------------------------ */
/*  6. CSV IMPORT                                                       */
/* ------------------------------------------------------------------ */
function tqt_render_csv_import_page() {
    if ( ! current_user_can( 'manage_options' ) ) return;

    $message = '';
    $errors  = [];

    if ( isset( $_POST['tqt_import_csv'] ) && check_admin_referer( 'tqt_csv_import_nonce' ) ) {
        if ( ! empty( $_FILES['tqt_csv_file']['tmp_name'] ) ) {
            $result = tqt_process_csv_import( $_FILES['tqt_csv_file']['tmp_name'] );
            $message = $result['message'];
            $errors  = $result['errors'];
        } else {
            $message = 'No file uploaded.';
        }
    }

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

        <h2>CSV Format</h2>
        <p>Your CSV should have these columns:</p>
        <code>item_id, category, price, image, is_available, sort_order, lang, name, description, [any_other_translated_field]</code>
        <br><br>
        <p><strong>Rules:</strong></p>
        <ul style="list-style:disc;padding-left:20px;">
            <li>Columns <code>item_id</code> through <code>sort_order</code> are non-translatable — same value across all language rows for the same item.</li>
            <li>Columns from <code>lang</code> onward are per-language. Include one row per language per item.</li>
            <li>Use the <strong>item_id</strong> column as your unique identifier. If a post with that item_id already exists, it will be updated. Otherwise a new post is created.</li>
            <li>Add any extra translated columns you need (e.g. <code>dietary_note</code>, <code>allergens</code>). They'll be stored automatically in the translations repeater.</li>
        </ul>

        <h2>Example CSV</h2>
        <pre style="background:#f0f0f0;padding:12px;overflow-x:auto;">
item_id,category,price,image,is_available,sort_order,lang,name,description
001,mains,25.00,chicken.jpg,1,10,en,Grilled Chicken,Juicy grilled chicken breast
001,mains,25.00,chicken.jpg,1,10,ar,دجاج مشوي,صدر دجاج مشوي طري
001,mains,25.00,chicken.jpg,1,10,zh,烤鸡,多汁的烤鸡胸肉
002,desserts,12.00,cake.jpg,1,20,en,Chocolate Cake,Rich dark chocolate cake
002,desserts,12.00,cake.jpg,1,20,ar,كيكة الشوكولاتة,كيكة شوكولاتة داكنة غنية</pre>

        <h2>Upload CSV</h2>
        <form method="post" enctype="multipart/form-data">
            <?php wp_nonce_field( 'tqt_csv_import_nonce' ); ?>
            <input type="file" name="tqt_csv_file" accept=".csv,.tsv,.txt">
            <br><br>
            <label>
                <input type="checkbox" name="tqt_dry_run" value="1">
                Dry run (validate only, don't import)
            </label>
            <br><br>
            <button type="submit" name="tqt_import_csv" class="button button-primary">Import</button>
        </form>
    </div>
    <?php
}

function tqt_process_csv_import( string $file_path ): array {
    $settings  = tqt_get_settings();
    $delimiter = $settings['csv_delimiter'] ?: ',';
    $dry_run   = ! empty( $_POST['tqt_dry_run'] );

    $handle = fopen( $file_path, 'r' );
    if ( ! $handle ) {
        return [ 'message' => 'Could not open file.', 'errors' => [] ];
    }

    $headers = fgetcsv( $handle, 0, $delimiter );
    if ( ! $headers ) {
        fclose( $handle );
        return [ 'message' => 'Empty or invalid CSV.', 'errors' => [] ];
    }

    $headers = array_map( 'trim', $headers );
    $headers = array_map( 'strtolower', $headers );

    // Validate required columns
    $required = [ 'item_id', 'lang' ];
    $missing  = array_diff( $required, $headers );
    if ( ! empty( $missing ) ) {
        fclose( $handle );
        return [
            'message' => 'Missing required columns: ' . implode( ', ', $missing ),
            'errors'  => [],
        ];
    }

    // Non-translatable columns (everything before 'lang')
    $lang_index        = array_search( 'lang', $headers, true );
    $non_trans_cols     = array_slice( $headers, 0, $lang_index );
    $trans_cols         = array_slice( $headers, $lang_index ); // includes 'lang'

    // Group rows by item_id
    $grouped = [];
    $row_num = 1;
    $errors  = [];

    while ( ( $row = fgetcsv( $handle, 0, $delimiter ) ) !== false ) {
        $row_num++;
        if ( count( $row ) !== count( $headers ) ) {
            $errors[] = "Row {$row_num}: column count mismatch (expected " . count( $headers ) . ", got " . count( $row ) . ")";
            continue;
        }
        $data    = array_combine( $headers, $row );
        $item_id = trim( $data['item_id'] );
        if ( ! $item_id ) {
            $errors[] = "Row {$row_num}: empty item_id, skipping.";
            continue;
        }
        $grouped[ $item_id ][] = $data;
    }
    fclose( $handle );

    if ( $dry_run ) {
        $item_count = count( $grouped );
        $lang_counts = [];
        foreach ( $grouped as $rows ) {
            foreach ( $rows as $r ) {
                $lang_counts[ $r['lang'] ] = ( $lang_counts[ $r['lang'] ] ?? 0 ) + 1;
            }
        }
        $summary = "Dry run: {$item_count} items found. Languages: ";
        foreach ( $lang_counts as $lc => $cnt ) {
            $summary .= "{$lc}({$cnt}) ";
        }
        return [ 'message' => $summary, 'errors' => $errors ];
    }

    // Process each item
    $created = 0;
    $updated = 0;

    foreach ( $grouped as $item_id => $rows ) {
        $first = $rows[0]; // Use first row for non-translatable data

        // Find existing post by item_id meta
        $existing = get_posts([
            'post_type'      => 'digital_menu',
            'posts_per_page' => 1,
            'meta_key'       => 'item_id',
            'meta_value'     => $item_id,
            'post_status'    => 'any',
        ]);

        // Get default language name for post title
        $default_lang = $settings['default_language'];
        $title = $item_id; // fallback
        foreach ( $rows as $r ) {
            if ( $r['lang'] === $default_lang && ! empty( $r['name'] ) ) {
                $title = $r['name'];
                break;
            }
        }
        // If no default lang row, use first row's name
        if ( $title === $item_id && ! empty( $rows[0]['name'] ) ) {
            $title = $rows[0]['name'];
        }

        $post_data = [
            'post_type'   => 'digital_menu',
            'post_title'  => sanitize_text_field( $title ),
            'post_status' => 'publish',
            'menu_order'  => intval( $first['sort_order'] ?? 0 ),
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
            $errors[] = "Item {$item_id}: failed to create/update post.";
            continue;
        }

        // Update non-translatable fields
        foreach ( $non_trans_cols as $col ) {
            if ( $col === 'item_id' ) {
                update_field( 'item_id', $item_id, $post_id );
                continue;
            }
            $value = $first[ $col ] ?? '';
            if ( $col === 'price' ) $value = floatval( $value );
            if ( $col === 'is_available' ) $value = (bool) $value;
            update_field( $col, $value, $post_id );
        }

        // Build translations repeater
        $translations = [];
        foreach ( $rows as $r ) {
            $tr_row = [ 'tqt_lang_code' => sanitize_key( $r['lang'] ) ];
            foreach ( $trans_cols as $tc ) {
                if ( $tc === 'lang' ) continue;
                $tr_row[ 'tqt_' . $tc ] = sanitize_text_field( $r[ $tc ] ?? '' );
            }
            $translations[] = $tr_row;
        }
        update_field( 'tqt_translations', $translations, $post_id );
    }

    return [
        'message' => "Import complete. Created: {$created}, Updated: {$updated}.",
        'errors'  => $errors,
    ];
}

/* ------------------------------------------------------------------ */
/*  7. CSV EXPORT                                                       */
/* ------------------------------------------------------------------ */
function tqt_render_csv_export_page() {
    if ( ! current_user_can( 'manage_options' ) ) return;

    if ( isset( $_POST['tqt_export_csv'] ) && check_admin_referer( 'tqt_csv_export_nonce' ) ) {
        tqt_process_csv_export();
        return; // headers sent, exit
    }

    ?>
    <div class="wrap">
        <h1>CSV Export</h1>
        <p>Export all digital menu items with all translations as a CSV file. You can edit the file and re-import it.</p>
        <form method="post">
            <?php wp_nonce_field( 'tqt_csv_export_nonce' ); ?>
            <button type="submit" name="tqt_export_csv" class="button button-primary">Export CSV</button>
        </form>
    </div>
    <?php
}

function tqt_process_csv_export() {
    $settings  = tqt_get_settings();
    $delimiter = $settings['csv_delimiter'] ?: ',';

    $posts = get_posts([
        'post_type'      => 'digital_menu',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
        'orderby'        => 'menu_order',
        'order'          => 'ASC',
    ]);

    // Collect all translated field keys from first post to build headers
    $trans_keys = [];
    foreach ( $posts as $post ) {
        $translations = get_field( 'tqt_translations', $post->ID );
        if ( is_array( $translations ) ) {
            foreach ( $translations as $tr ) {
                foreach ( array_keys( $tr ) as $k ) {
                    if ( $k === 'tqt_lang_code' ) continue;
                    $clean = preg_replace( '/^tqt_/', '', $k );
                    $trans_keys[ $clean ] = true;
                }
            }
        }
    }

    $non_trans_headers = [ 'item_id', 'category', 'price', 'image', 'is_available', 'sort_order' ];
    $all_headers       = array_merge( $non_trans_headers, [ 'lang' ], array_keys( $trans_keys ) );

    $filename = 'digital-menu-export-' . date( 'Y-m-d-His' ) . '.csv';

    header( 'Content-Type: text/csv; charset=utf-8' );
    header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

    $output = fopen( 'php://output', 'w' );
    fputcsv( $output, $all_headers, $delimiter );

    foreach ( $posts as $post ) {
        $item_id      = get_field( 'item_id', $post->ID ) ?: $post->ID;
        $category     = get_field( 'category', $post->ID ) ?: '';
        $price        = get_field( 'price', $post->ID ) ?: '';
        $image        = '';
        $image_id     = get_field( 'item_image', $post->ID );
        if ( $image_id ) $image = basename( wp_get_attachment_url( $image_id ) );
        $is_available = get_field( 'is_available', $post->ID ) ? '1' : '0';
        $sort_order   = $post->menu_order;

        $translations = get_field( 'tqt_translations', $post->ID );
        if ( ! is_array( $translations ) || empty( $translations ) ) {
            // Write one row with no translation data
            $row = [ $item_id, $category, $price, $image, $is_available, $sort_order, '', ];
            foreach ( array_keys( $trans_keys ) as $tk ) {
                $row[] = '';
            }
            fputcsv( $output, $row, $delimiter );
            continue;
        }

        foreach ( $translations as $tr ) {
            $row = [
                $item_id,
                $category,
                $price,
                $image,
                $is_available,
                $sort_order,
                $tr['tqt_lang_code'] ?? '',
            ];
            foreach ( array_keys( $trans_keys ) as $tk ) {
                $row[] = $tr[ 'tqt_' . $tk ] ?? '';
            }
            fputcsv( $output, $row, $delimiter );
        }
    }

    fclose( $output );
    exit;
}

/* ------------------------------------------------------------------ */
/*  8. ADMIN COLUMNS — show translation status in post list            */
/* ------------------------------------------------------------------ */
add_filter( 'manage_digital_menu_posts_columns', function ( $columns ) {
    $settings = tqt_get_settings();
    if ( $settings['show_language_badge'] ) {
        $columns['tqt_languages'] = 'Languages';
    }
    return $columns;
});

add_action( 'manage_digital_menu_posts_custom_column', function ( $column, $post_id ) {
    if ( $column !== 'tqt_languages' ) return;

    $translations = get_field( 'tqt_translations', $post_id );
    $active       = tqt_get_active_languages();

    if ( ! is_array( $translations ) ) {
        echo '<span style="color:#999;">—</span>';
        return;
    }

    $translated_langs = array_column( $translations, 'tqt_lang_code' );

    foreach ( $active as $code => $label ) {
        $has  = in_array( $code, $translated_langs, true );
        $bg   = $has ? '#00a32a' : '#ccc';
        echo '<span title="' . esc_attr( $label ) . '" style="display:inline-block;padding:2px 6px;margin:1px;border-radius:3px;font-size:11px;color:#fff;background:' . $bg . ';">' . esc_html( strtoupper( $code ) ) . '</span> ';
    }
}, 10, 2 );

/* ------------------------------------------------------------------ */
/*  9. WP-CLI COMMANDS (optional but recommended for bulk ops)         */
/* ------------------------------------------------------------------ */
if ( defined( 'WP_CLI' ) && WP_CLI ) {
    WP_CLI::add_command( 'tqt import', function ( $args ) {
        if ( empty( $args[0] ) ) {
            WP_CLI::error( 'Please provide the CSV file path.' );
        }
        $file = $args[0];
        if ( ! file_exists( $file ) ) {
            WP_CLI::error( "File not found: {$file}" );
        }

        // Simulate POST context for dry_run
        $_POST['tqt_dry_run'] = false;

        $result = tqt_process_csv_import( $file );
        WP_CLI::success( $result['message'] );
        foreach ( $result['errors'] as $e ) {
            WP_CLI::warning( $e );
        }
    });

    WP_CLI::add_command( 'tqt list-languages', function () {
        $active = tqt_get_active_languages();
        $settings = tqt_get_settings();
        foreach ( $active as $code => $label ) {
            $default = $code === $settings['default_language'] ? ' (default)' : '';
            WP_CLI::log( "{$code} — {$label}{$default}" );
        }
    });
}
