<?php
/**
 * TableQR Translations — Custom Post Type Registration
 *
 * Registers the 'digital_menu' CPT.
 * If you already have this CPT registered elsewhere, you can skip this file
 * and just update the CPT slug references in the plugin.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'init', function () {

    $labels = [
        'name'               => 'Menu Items',
        'singular_name'      => 'Menu Item',
        'add_new'            => 'Add Menu Item',
        'add_new_item'       => 'Add New Menu Item',
        'edit_item'          => 'Edit Menu Item',
        'view_item'          => 'View Menu Item',
        'all_items'          => 'All Menu Items',
        'search_items'       => 'Search Menu Items',
        'not_found'          => 'No menu items found.',
        'not_found_in_trash' => 'No menu items found in Trash.',
    ];

    register_post_type( 'digital_menu', [
        'labels'              => $labels,
        'public'              => false,   // Headless — no frontend needed in WP
        'show_ui'             => true,
        'show_in_menu'        => true,
        'show_in_rest'        => true,    // Required for REST API & Gutenberg
        'rest_base'           => 'digital-menu',
        'menu_icon'           => 'dashicons-food',
        'supports'            => [ 'title', 'page-attributes' ], // page-attributes for menu_order
        'has_archive'         => false,
        'rewrite'             => false,
        'hierarchical'        => false,
        'capability_type'     => 'post',
        'menu_position'       => 25,
    ]);
});
