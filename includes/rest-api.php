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

/**
 * ACF get_field when available; otherwise raw post meta (same field name).
 *
 * @return mixed
 */
function tqt_rest_get_field_value( string $selector, int $post_id ) {
    if ( function_exists( 'get_field' ) ) {
        return get_field( $selector, $post_id );
    }
    return get_post_meta( $post_id, $selector, true );
}

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

    if ( ! $post || $post->post_status !== 'publish' || $post->post_type !== 'menu_item' ) {
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

    // Fallback logic (missing title for non-default language)
    if ( $lang !== $default && $title === '' ) {
        if ( $fallback === 'hide' ) {
            return null;
        }
        if ( $fallback === 'default' ) {
            $title = tqt_get_translation( $post->ID, 'post_title', $default );
            if ( $desc === '' ) {
                $desc = tqt_get_translation( $post->ID, 'description', $default );
            }
        }
        // 'empty': leave title and description blank
    }

    // Image (ACF image field or attachment id)
    $image_data = function_exists( 'get_field' ) ? get_field( 'image', $post->ID ) : null;
    $image_url  = '';
    if ( is_array( $image_data ) ) {
        $image_url = $image_data['url'] ?? '';
    } elseif ( $image_data ) {
        $image_url = is_numeric( $image_data ) && function_exists( 'wp_get_attachment_url' )
            ? (string) wp_get_attachment_url( (int) $image_data )
            : (string) $image_data;
    }

    // Category & Section
    $cat_terms = wp_get_object_terms( $post->ID, 'menu_category' );
    $sec_terms = wp_get_object_terms( $post->ID, 'menu_section' );

    $category = '';
    $category_slug = '';
    if ( ! empty( $cat_terms ) && ! is_wp_error( $cat_terms ) ) {
        $t = $cat_terms[0];
        if ( $lang === $default ) {
            $category = $t->name;
        } else {
            $tr = tqt_get_term_translation( $t->term_id, $lang );
            if ( $tr !== '' ) {
                $category = $tr;
            } elseif ( $fallback === 'empty' ) {
                $category = '';
            } else {
                $category = $t->name;
            }
        }
        $category_slug = $t->slug;
    }

    $section = '';
    $section_slug = '';
    if ( ! empty( $sec_terms ) && ! is_wp_error( $sec_terms ) ) {
        $t = $sec_terms[0];
        if ( $lang === $default ) {
            $section = $t->name;
        } else {
            $tr = tqt_get_term_translation( $t->term_id, $lang );
            if ( $tr !== '' ) {
                $section = $tr;
            } elseif ( $fallback === 'empty' ) {
                $section = '';
            } else {
                $section = $t->name;
            }
        }
        $section_slug = $t->slug;
    }

    $item = [
        'id'             => $post->ID,
        'slug'           => $post->post_name,
        'title'          => $title,
        'description'    => $desc,
        'price'          => tqt_rest_get_field_value( 'price', $post->ID ),
        'calorie'        => tqt_rest_get_field_value( 'calorie', $post->ID ),
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
            if ( $lang === $default ) {
                $name = (string) get_post_meta( $post->ID, $name_key, true );
            } else {
                $tr = (string) get_post_meta( $post->ID, $name_key . '_' . $lang, true );
                if ( $tr !== '' ) {
                    $name = $tr;
                } elseif ( $fallback === 'empty' ) {
                    $name = '';
                } else {
                    $name = (string) get_post_meta( $post->ID, $name_key, true );
                }
            }

            $variants[] = [
                'name'    => $name,
                'price'   => (string) get_post_meta( $post->ID, "prices_{$i}_price", true ),
                'calorie' => (string) get_post_meta( $post->ID, "prices_{$i}_calorie", true ),
            ];
        }
        $item['variants'] = $variants;
    }

    // Labels
    $item['item_labels'] = tqt_rest_get_field_value( 'item_labels', $post->ID ) ?: '';
    $item['custom_label'] = tqt_get_translation( $post->ID, 'custom_label', $lang );

    // Nutritional data
    $item['preparation_time'] = tqt_rest_get_field_value( 'preparation_time', $post->ID );
    $ingredients              = tqt_rest_get_field_value( 'ingredients', $post->ID );
    $allergens                = tqt_rest_get_field_value( 'allergens', $post->ID );
    $item['ingredients']      = is_array( $ingredients ) ? $ingredients : [];
    $item['allergens']        = is_array( $allergens ) ? $allergens : [];

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
