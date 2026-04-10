<?php
/**
 * TQT REST API — Headless endpoints for Next.js.
 *
 * Endpoints:
 *   GET /wp-json/tqt/v1/languages          → available languages
 *   GET /wp-json/tqt/v1/menu?lang=ar       → menu items in a language
 *   GET /wp-json/tqt/v1/menu/{id}?lang=ar  → single menu item
 *   GET /wp-json/tqt/v1/terms?lang=ar      → translated taxonomy terms
 *   GET /wp-json/tqt/v1/options?lang=ar    → translated options (branding, etc.)
 *   GET /wp-json/tqt/v1/branches?lang=ar   → branches
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'rest_api_init', function () {

    $ns = 'tqt/v1';

    // ── Languages ──
    register_rest_route( $ns, '/languages', [
        'methods'             => 'GET',
        'callback'            => 'tqt_rest_languages',
        'permission_callback' => '__return_true',
    ]);

    // ── Menu items ──
    register_rest_route( $ns, '/menu', [
        'methods'             => 'GET',
        'callback'            => 'tqt_rest_menu_items',
        'permission_callback' => '__return_true',
        'args' => [
            'lang'     => [ 'sanitize_callback' => 'sanitize_key' ],
            'category' => [ 'sanitize_callback' => 'sanitize_text_field' ],
            'per_page' => [ 'default' => 100, 'sanitize_callback' => 'absint' ],
            'page'     => [ 'default' => 1, 'sanitize_callback' => 'absint' ],
        ],
    ]);

    register_rest_route( $ns, '/menu/(?P<id>\d+)', [
        'methods'             => 'GET',
        'callback'            => 'tqt_rest_menu_single',
        'permission_callback' => '__return_true',
        'args' => [
            'lang' => [ 'sanitize_callback' => 'sanitize_key' ],
        ],
    ]);

    // ── Terms ──
    register_rest_route( $ns, '/terms', [
        'methods'             => 'GET',
        'callback'            => 'tqt_rest_terms',
        'permission_callback' => '__return_true',
        'args' => [
            'lang'     => [ 'sanitize_callback' => 'sanitize_key' ],
            'taxonomy' => [ 'default' => 'menu_category', 'sanitize_callback' => 'sanitize_key' ],
        ],
    ]);

    // ── Options ──
    register_rest_route( $ns, '/options', [
        'methods'             => 'GET',
        'callback'            => 'tqt_rest_options',
        'permission_callback' => '__return_true',
        'args' => [
            'lang' => [ 'sanitize_callback' => 'sanitize_key' ],
        ],
    ]);

    // ── Branches ──
    register_rest_route( $ns, '/branches', [
        'methods'             => 'GET',
        'callback'            => 'tqt_rest_branches',
        'permission_callback' => '__return_true',
        'args' => [
            'lang' => [ 'sanitize_callback' => 'sanitize_key' ],
        ],
    ]);
});

/* ── Languages ── */
function tqt_rest_languages(): WP_REST_Response {
    $settings = tqt_get_settings();
    $active   = tqt_active_languages();
    $out      = [];
    foreach ( $active as $code => $info ) {
        $out[] = [
            'code'       => $code,
            'label'      => $info['label'],
            'native'     => $info['native'],
            'is_default' => $code === $settings['default_language'],
            'is_rtl'     => $info['rtl'],
        ];
    }
    return new WP_REST_Response( $out, 200 );
}

/* ── Menu items ── */
function tqt_rest_menu_items( WP_REST_Request $req ): WP_REST_Response {
    $settings = tqt_get_settings();
    $lang     = $req->get_param( 'lang' ) ?: $settings['default_language'];
    $category = $req->get_param( 'category' );
    $per_page = min( $req->get_param( 'per_page' ), 500 );
    $page     = $req->get_param( 'page' );

    $args = [
        'post_type'      => 'menu_item',
        'posts_per_page' => $per_page,
        'paged'          => $page,
        'post_status'    => 'publish',
        'orderby'        => 'menu_order title',
        'order'          => 'ASC',
    ];

    if ( $category ) {
        $args['tax_query'] = [[
            'taxonomy' => 'menu_category',
            'field'    => 'slug',
            'terms'    => $category,
        ]];
    }

    $query = new WP_Query( $args );
    $items = [];

    foreach ( $query->posts as $post ) {
        $item = tqt_build_menu_item( $post, $lang, $settings );
        if ( $item !== null ) $items[] = $item;
    }

    $response = new WP_REST_Response( $items, 200 );
    $response->header( 'X-WP-Total', $query->found_posts );
    $response->header( 'X-WP-TotalPages', $query->max_num_pages );
    $response->header( 'X-TQT-Language', $lang );
    return $response;
}

function tqt_rest_menu_single( WP_REST_Request $req ): WP_REST_Response {
    $settings = tqt_get_settings();
    $lang     = $req->get_param( 'lang' ) ?: $settings['default_language'];
    $post     = get_post( $req->get_param( 'id' ) );

    if ( ! $post || $post->post_status !== 'publish' ) {
        return new WP_REST_Response( [ 'error' => 'Not found' ], 404 );
    }

    $item = tqt_build_menu_item( $post, $lang, $settings );
    if ( $item === null ) {
        return new WP_REST_Response( [ 'error' => 'No translation' ], 404 );
    }
    return new WP_REST_Response( $item, 200 );
}

function tqt_build_menu_item( WP_Post $post, string $lang, array $settings ): ?array {
    $default  = $settings['default_language'];
    $fallback = $settings['fallback_behaviour'];

    $title = tqt_get_translation( $post->ID, 'post_title', $lang );
    $desc  = tqt_get_translation( $post->ID, 'description', $lang );

    // Fallback logic
    if ( $lang !== $default && $title === '' ) {
        if ( $fallback === 'hide' ) return null;
        if ( $fallback === 'default' ) {
            $title = tqt_get_translation( $post->ID, 'post_title', $default );
            if ( $desc === '' ) $desc = tqt_get_translation( $post->ID, 'description', $default );
        }
    }

    // Image
    $image_data = get_field( 'image', $post->ID );
    $image_url  = is_array( $image_data ) ? ( $image_data['url'] ?? '' ) : ( $image_data ?: '' );

    // Category & Section
    $cat_terms = wp_get_object_terms( $post->ID, 'menu_category' );
    $sec_terms = wp_get_object_terms( $post->ID, 'menu_section' );

    $category = '';
    $category_slug = '';
    if ( ! empty( $cat_terms ) && ! is_wp_error( $cat_terms ) ) {
        $category      = $lang === $default ? $cat_terms[0]->name : ( tqt_get_term_translation( $cat_terms[0]->term_id, $lang ) ?: $cat_terms[0]->name );
        $category_slug = $cat_terms[0]->slug;
    }

    $section = '';
    $section_slug = '';
    if ( ! empty( $sec_terms ) && ! is_wp_error( $sec_terms ) ) {
        $section      = $lang === $default ? $sec_terms[0]->name : ( tqt_get_term_translation( $sec_terms[0]->term_id, $lang ) ?: $sec_terms[0]->name );
        $section_slug = $sec_terms[0]->slug;
    }

    $item = [
        'id'             => $post->ID,
        'slug'           => $post->post_name,
        'title'          => $title,
        'description'    => $desc,
        'price'          => get_field( 'price', $post->ID ),
        'calorie'        => get_field( 'calorie', $post->ID ),
        'image'          => $image_url,
        'category'       => $category,
        'category_slug'  => $category_slug,
        'section'        => $section,
        'section_slug'   => $section_slug,
        'sort_order'     => $post->menu_order,
        'language'       => $lang,
    ];

    // Variant prices
    $variant_count = (int) get_post_meta( $post->ID, 'prices', true );
    if ( $variant_count > 0 ) {
        $variants = [];
        for ( $i = 0; $i < $variant_count; $i++ ) {
            $name_key = "prices_{$i}_price_name";
            $name     = $lang === $default
                ? (string) get_post_meta( $post->ID, $name_key, true )
                : ( (string) get_post_meta( $post->ID, $name_key . '_' . $lang, true ) ?: (string) get_post_meta( $post->ID, $name_key, true ) );

            $variants[] = [
                'name'    => $name,
                'price'   => (string) get_post_meta( $post->ID, "prices_{$i}_price", true ),
                'calorie' => (string) get_post_meta( $post->ID, "prices_{$i}_calorie", true ),
            ];
        }
        $item['variants'] = $variants;
    }

    // Labels
    $item['item_labels'] = get_field( 'item_labels', $post->ID ) ?: '';
    $item['custom_label'] = tqt_get_translation( $post->ID, 'custom_label', $lang );

    // Nutritional data
    $item['preparation_time'] = get_field( 'preparation_time', $post->ID );
    $item['ingredients']      = get_field( 'ingredients', $post->ID ) ?: [];
    $item['allergens']        = get_field( 'allergens', $post->ID ) ?: [];

    return $item;
}

/* ── Terms ── */
function tqt_rest_terms( WP_REST_Request $req ): WP_REST_Response {
    $settings = tqt_get_settings();
    $lang     = $req->get_param( 'lang' ) ?: $settings['default_language'];
    $taxonomy = $req->get_param( 'taxonomy' );

    $terms = get_terms( [ 'taxonomy' => $taxonomy, 'hide_empty' => false, 'orderby' => 'name' ] );
    if ( is_wp_error( $terms ) ) {
        return new WP_REST_Response( [], 200 );
    }

    $out = [];
    foreach ( $terms as $t ) {
        $name = $lang === $settings['default_language']
            ? $t->name
            : ( tqt_get_term_translation( $t->term_id, $lang ) ?: $t->name );

        $out[] = [
            'id'    => $t->term_id,
            'slug'  => $t->slug,
            'name'  => $name,
            'count' => $t->count,
        ];
    }
    return new WP_REST_Response( $out, 200 );
}

/* ── Options ── */
function tqt_rest_options( WP_REST_Request $req ): WP_REST_Response {
    $settings = tqt_get_settings();
    $lang     = $req->get_param( 'lang' ) ?: $settings['default_language'];
    $fields   = tqt_translatable_fields()['options']['meta_fields'] ?? [];

    $out = [];
    foreach ( $fields as $f ) {
        $val = tqt_get_option_translation( $f['name'], $lang );
        if ( $val === '' && $lang !== $settings['default_language'] ) {
            $val = tqt_get_option_translation( $f['name'], $settings['default_language'] );
        }
        $out[ $f['name'] ] = $val;
    }
    return new WP_REST_Response( $out, 200 );
}

/* ── Branches ── */
function tqt_rest_branches( WP_REST_Request $req ): WP_REST_Response {
    $settings = tqt_get_settings();
    $lang     = $req->get_param( 'lang' ) ?: $settings['default_language'];

    $posts = get_posts([
        'post_type'      => 'branch',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
    ]);

    $out = [];
    foreach ( $posts as $p ) {
        $title = tqt_get_translation( $p->ID, 'post_title', $lang );
        if ( ! $title ) $title = $p->post_title;

        $desc = tqt_get_translation( $p->ID, 'branch_card_description', $lang );
        if ( ! $desc ) $desc = (string) get_post_meta( $p->ID, 'branch_card_description', true );

        $out[] = [
            'id'          => $p->ID,
            'slug'        => $p->post_name,
            'title'       => $title,
            'description' => $desc,
            'thumbnail'   => get_the_post_thumbnail_url( $p->ID, 'medium' ) ?: '',
            'language'    => $lang,
        ];
    }
    return new WP_REST_Response( $out, 200 );
}
