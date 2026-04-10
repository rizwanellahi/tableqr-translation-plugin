<?php
/**
 * TableQR Translations — ACF Field Groups
 *
 * Registers the translation repeater and common menu item fields.
 * Requires ACF Pro (for repeater field type).
 *
 * CUSTOMIZATION:
 * To add more translated sub-fields, add entries to the $translated_sub_fields array.
 * To add more non-translatable fields, add entries to the $base_fields array.
 * The CSV importer will automatically pick up any new columns that match field names.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'acf/init', function () {

    if ( ! function_exists( 'acf_add_local_field_group' ) ) return;

    $settings  = tqt_get_settings();
    $languages = tqt_get_active_languages();

    // ── Language choices for the repeater sub-field ──
    $lang_choices = [];
    foreach ( $languages as $code => $label ) {
        $lang_choices[ $code ] = $label;
    }

    // ── Translated sub-fields (inside the repeater) ──
    // Add or remove fields here as your menu requires.
    $translated_sub_fields = [
        [
            'key'          => 'field_tqt_lang_code',
            'label'        => 'Language',
            'name'         => 'tqt_lang_code',
            'type'         => 'select',
            'choices'      => $lang_choices,
            'required'     => 1,
            'wrapper'      => [ 'width' => '20' ],
        ],
        [
            'key'          => 'field_tqt_name',
            'label'        => 'Item Name',
            'name'         => 'tqt_name',
            'type'         => 'text',
            'required'     => 1,
            'wrapper'      => [ 'width' => '40' ],
        ],
        [
            'key'          => 'field_tqt_description',
            'label'        => 'Description',
            'name'         => 'tqt_description',
            'type'         => 'textarea',
            'rows'         => 3,
            'wrapper'      => [ 'width' => '40' ],
        ],
        // ── Add more translated fields below ──
        // Example:
        // [
        //     'key'   => 'field_tqt_dietary_note',
        //     'label' => 'Dietary Note',
        //     'name'  => 'tqt_dietary_note',
        //     'type'  => 'text',
        // ],
        // [
        //     'key'   => 'field_tqt_allergens',
        //     'label' => 'Allergens',
        //     'name'  => 'tqt_allergens',
        //     'type'  => 'text',
        // ],
    ];

    // Allow other plugins/themes to add translated fields
    $translated_sub_fields = apply_filters( 'tqt_translated_sub_fields', $translated_sub_fields );

    // ── Translations repeater ──
    acf_add_local_field_group([
        'key'      => 'group_tqt_translations',
        'title'    => 'Translations',
        'fields'   => [
            [
                'key'          => 'field_tqt_translations',
                'label'        => 'Translations',
                'name'         => 'tqt_translations',
                'type'         => 'repeater',
                'layout'       => 'block',
                'button_label' => 'Add Translation',
                'sub_fields'   => $translated_sub_fields,
            ],
        ],
        'location' => [
            [
                [
                    'param'    => 'post_type',
                    'operator' => '==',
                    'value'    => 'digital_menu', // Change to your CPT slug
                ],
            ],
        ],
        'menu_order'            => 10,
        'position'              => 'normal',
        'style'                 => 'default',
        'label_placement'       => 'top',
        'instruction_placement' => 'label',
        'show_in_rest'          => true,
    ]);

    // ── Non-translatable base fields ──
    acf_add_local_field_group([
        'key'    => 'group_tqt_base_fields',
        'title'  => 'Menu Item Details',
        'fields' => [
            [
                'key'       => 'field_tqt_item_id',
                'label'     => 'Item ID',
                'name'      => 'item_id',
                'type'      => 'text',
                'required'  => 1,
                'instructions' => 'Unique identifier for CSV import/export. Must match across all language rows.',
                'wrapper'   => [ 'width' => '25' ],
            ],
            [
                'key'       => 'field_tqt_category',
                'label'     => 'Category',
                'name'      => 'category',
                'type'      => 'text',
                'wrapper'   => [ 'width' => '25' ],
            ],
            [
                'key'       => 'field_tqt_price',
                'label'     => 'Price',
                'name'      => 'price',
                'type'      => 'number',
                'step'      => '0.01',
                'wrapper'   => [ 'width' => '15' ],
            ],
            [
                'key'       => 'field_tqt_item_image',
                'label'     => 'Image',
                'name'      => 'item_image',
                'type'      => 'image',
                'return_format' => 'id',
                'preview_size'  => 'thumbnail',
                'wrapper'   => [ 'width' => '20' ],
            ],
            [
                'key'           => 'field_tqt_is_available',
                'label'         => 'Available',
                'name'          => 'is_available',
                'type'          => 'true_false',
                'default_value' => 1,
                'ui'            => 1,
                'wrapper'       => [ 'width' => '15' ],
            ],
        ],
        'location' => [
            [
                [
                    'param'    => 'post_type',
                    'operator' => '==',
                    'value'    => 'digital_menu',
                ],
            ],
        ],
        'menu_order'      => 5,
        'position'        => 'normal',
        'style'           => 'default',
        'show_in_rest'    => true,
    ]);
});
