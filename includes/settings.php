<?php
/**
 * TQT Settings — Language config + translatable field registry.
 *
 * STORAGE APPROACH:
 * Instead of WPGlobus's {:en}text{:}{:ar}نص{:} inline format,
 * we store each translation in a SEPARATE meta key:
 *   description    → English (default)
 *   description_ar → Arabic
 *   description_tr → Turkish
 *
 * This means:
 * - Existing code that reads get_field('description') still works (gets default lang)
 * - Translations are just meta keys with the lang suffix
 * - No data migration needed — new sites start clean
 * - CSV columns map 1:1 to meta keys (description, description_ar, description_tr)
 * - REST API can return the correct language by reading the suffixed key
 * - ACF get_field() works for both: get_field('description') and get_field('description_ar')
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Polyfill for PHP 8 str_ends_with (plugin declares PHP 7.4+).
 */
function tqt_str_ends_with( string $haystack, string $needle ): bool {
    if ( $needle === '' ) {
        return true;
    }
    $len = strlen( $needle );
    return strlen( $haystack ) >= $len && substr( $haystack, -$len ) === $needle;
}

/* ------------------------------------------------------------------ */
/*  BUILT-IN LANGUAGE LIST                                             */
/* ------------------------------------------------------------------ */
function tqt_builtin_languages(): array {
    return [
        'en'    => [ 'label' => 'English',              'native' => 'English',              'rtl' => false ],
        'ar'    => [ 'label' => 'Arabic',               'native' => 'العربية',              'rtl' => true  ],
        'zh'    => [ 'label' => 'Chinese (Simplified)',  'native' => '简体中文',              'rtl' => false ],
        'zh_TW' => [ 'label' => 'Chinese (Traditional)', 'native' => '繁體中文',             'rtl' => false ],
        'nl'    => [ 'label' => 'Dutch',                'native' => 'Nederlands',           'rtl' => false ],
        'fil'   => [ 'label' => 'Filipino',             'native' => 'Filipino',             'rtl' => false ],
        'fr'    => [ 'label' => 'French',               'native' => 'Français',             'rtl' => false ],
        'de'    => [ 'label' => 'German',               'native' => 'Deutsch',              'rtl' => false ],
        'el'    => [ 'label' => 'Greek',                'native' => 'Ελληνικά',             'rtl' => false ],
        'hi'    => [ 'label' => 'Hindi',                'native' => 'हिन्दी',                 'rtl' => false ],
        'id'    => [ 'label' => 'Indonesian',           'native' => 'Bahasa Indonesia',     'rtl' => false ],
        'it'    => [ 'label' => 'Italian',              'native' => 'Italiano',             'rtl' => false ],
        'ja'    => [ 'label' => 'Japanese',             'native' => '日本語',                'rtl' => false ],
        'ko'    => [ 'label' => 'Korean',               'native' => '한국어',                'rtl' => false ],
        'ms'    => [ 'label' => 'Malay',                'native' => 'Bahasa Melayu',        'rtl' => false ],
        'fa'    => [ 'label' => 'Persian (Farsi)',      'native' => 'فارسی',                'rtl' => true  ],
        'pl'    => [ 'label' => 'Polish',               'native' => 'Polski',               'rtl' => false ],
        'pt'    => [ 'label' => 'Portuguese',           'native' => 'Português',            'rtl' => false ],
        'ro'    => [ 'label' => 'Romanian',             'native' => 'Română',               'rtl' => false ],
        'ru'    => [ 'label' => 'Russian',              'native' => 'Русский',              'rtl' => false ],
        'es'    => [ 'label' => 'Spanish',              'native' => 'Español',              'rtl' => false ],
        'sv'    => [ 'label' => 'Swedish',              'native' => 'Svenska',              'rtl' => false ],
        'th'    => [ 'label' => 'Thai',                 'native' => 'ไทย',                   'rtl' => false ],
        'tr'    => [ 'label' => 'Turkish',              'native' => 'Türkçe',               'rtl' => false ],
        'uk'    => [ 'label' => 'Ukrainian',            'native' => 'Українська',           'rtl' => false ],
        'ur'    => [ 'label' => 'Urdu',                 'native' => 'اردو',                 'rtl' => true  ],
        'vi'    => [ 'label' => 'Vietnamese',           'native' => 'Tiếng Việt',           'rtl' => false ],
    ];
}

/* ------------------------------------------------------------------ */
/*  SETTINGS CRUD                                                      */
/* ------------------------------------------------------------------ */
function tqt_get_settings(): array {
    $defaults = [
        'enabled_languages'  => [ 'en' ],
        'default_language'    => 'en',
        'custom_languages'    => [],  // [ ['code'=>'tl','label'=>'Tagalog','native'=>'Tagalog','rtl'=>false], ... ]
        'fallback_behaviour'  => 'default',  // 'default' | 'hide' | 'empty'
        'csv_delimiter'       => ',',
        'show_language_badges' => true,
    ];
    return wp_parse_args( get_option( 'tqt_settings', [] ), $defaults );
}

function tqt_save_settings( array $data ): bool {
    return update_option( 'tqt_settings', $data );
}

/** All languages (builtin + custom), keyed by code. */
function tqt_all_languages(): array {
    $all      = tqt_builtin_languages();
    $settings = tqt_get_settings();
    foreach ( $settings['custom_languages'] as $c ) {
        if ( ! empty( $c['code'] ) && ! empty( $c['label'] ) ) {
            $all[ sanitize_key( $c['code'] ) ] = [
                'label'  => sanitize_text_field( $c['label'] ),
                'native' => sanitize_text_field( $c['native'] ?? $c['label'] ),
                'rtl'    => ! empty( $c['rtl'] ),
            ];
        }
    }
    return $all;
}

/** Only the enabled languages for this site. Returns [ code => {label, native, rtl} ]. */
function tqt_active_languages(): array {
    $settings = tqt_get_settings();
    $all      = tqt_all_languages();
    $active   = [];
    foreach ( $settings['enabled_languages'] as $code ) {
        if ( isset( $all[ $code ] ) ) {
            $active[ $code ] = $all[ $code ];
        }
    }
    return $active;
}

/** Non-default languages only. */
function tqt_translation_languages(): array {
    $settings = tqt_get_settings();
    $active   = tqt_active_languages();
    unset( $active[ $settings['default_language'] ] );
    return $active;
}

/** Is a language code RTL? */
function tqt_is_rtl( string $code ): bool {
    $all = tqt_all_languages();
    return ! empty( $all[ $code ]['rtl'] );
}

/* ------------------------------------------------------------------ */
/*  TRANSLATABLE FIELDS REGISTRY                                       */
/*  This is the core config: which fields on which CPT/context need    */
/*  per-language copies. The plugin reads this to build tabs, CSV       */
/*  columns, and REST output.                                          */
/*                                                                     */
/*  Each entry: [ 'name' => field_name, 'type' => acf_type ]          */
/*  For repeater sub-fields: 'parent' => repeater_name                 */
/* ------------------------------------------------------------------ */
function tqt_translatable_fields(): array {
    return apply_filters( 'tqt_translatable_fields', [

        // ── menu_item CPT ──
        'menu_item' => [
            'post_fields' => [
                [ 'name' => 'post_title', 'type' => 'text', 'label' => 'Title' ],
            ],
            'meta_fields' => [
                [ 'name' => 'description',   'type' => 'textarea', 'label' => 'Description' ],
                [ 'name' => 'custom_label',  'type' => 'text',     'label' => 'Custom Label' ],
            ],
            'repeater_fields' => [
                [
                    'repeater' => 'prices',
                    'sub_fields' => [
                        [ 'name' => 'price_name', 'type' => 'text', 'label' => 'Price Name' ],
                    ],
                ],
            ],
            'taxonomy_fields' => [
                [ 'taxonomy' => 'menu_category', 'label' => 'Menu Category' ],
                [ 'taxonomy' => 'menu_section',  'label' => 'Menu Section' ],
            ],
        ],

        // ── branch CPT ──
        'branch' => [
            'post_fields' => [
                [ 'name' => 'post_title', 'type' => 'text', 'label' => 'Branch Name' ],
            ],
            'meta_fields' => [
                [ 'name' => 'branch_card_description', 'type' => 'textarea', 'label' => 'Card Description' ],
            ],
        ],

        // ── menu_promo CPT ──
        'menu_promo' => [
            'post_fields' => [
                [ 'name' => 'post_title', 'type' => 'text', 'label' => 'Promo Title' ],
            ],
        ],

        // ── Options pages (branding, business, advanced) ──
        'options' => [
            'meta_fields' => [
                // Branding & Identity
                [ 'name' => 'name',                 'type' => 'text',     'label' => 'Restaurant Name',       'option_page' => 'menu-design-branding' ],
                [ 'name' => 'business_name',         'type' => 'text',     'label' => 'Business Name',         'option_page' => 'menu-design-branding' ],
                [ 'name' => 'business_description',  'type' => 'textarea', 'label' => 'Business Description',  'option_page' => 'menu-design-branding' ],
                [ 'name' => 'servescuisine',         'type' => 'text',     'label' => 'Serves Cuisine',        'option_page' => 'menu-design-branding' ],
                [ 'name' => 'address',               'type' => 'text',     'label' => 'Address',               'option_page' => 'menu-design-branding' ],
                [ 'name' => 'open_days',             'type' => 'text',     'label' => 'Open Days',             'option_page' => 'menu-design-branding' ],
                [ 'name' => 'street_address',        'type' => 'text',     'label' => 'Street Address',        'option_page' => 'menu-design-branding' ],
                [ 'name' => 'address_city',          'type' => 'text',     'label' => 'City',                  'option_page' => 'menu-design-branding' ],
                [ 'name' => 'address_region',        'type' => 'text',     'label' => 'Region',                'option_page' => 'menu-design-branding' ],

                // Advanced
                [ 'name' => 'tax_and_service_notice', 'type' => 'text',    'label' => 'Tax Notice',            'option_page' => 'menu-design-advanced' ],
                [ 'name' => 'feedback_form_ID',       'type' => 'text',    'label' => 'Google Feedback ID',    'option_page' => 'menu-design-advanced' ],

                // Link in Bio
                [ 'name' => 'links_title',       'type' => 'text',     'label' => 'Link in Bio Title',       'option_page' => 'smart-links' ],
                [ 'name' => 'links_description', 'type' => 'text',     'label' => 'Link in Bio Description', 'option_page' => 'smart-links' ],
            ],
        ],
    ]);
}

/**
 * Get translatable field definitions for a specific post type.
 * Returns null if the post type has no translatable fields.
 */
function tqt_get_fields_for( string $post_type ): ?array {
    $all = tqt_translatable_fields();
    return $all[ $post_type ] ?? null;
}

/**
 * Build the suffixed meta key for a language.
 * e.g. tqt_meta_key('description', 'ar') → 'description_ar'
 *      tqt_meta_key('description', 'en') → 'description' (default lang, no suffix)
 */
function tqt_meta_key( string $base_name, string $lang ): string {
    $settings = tqt_get_settings();
    if ( $lang === $settings['default_language'] ) {
        return $base_name;
    }
    return $base_name . '_' . $lang;
}
