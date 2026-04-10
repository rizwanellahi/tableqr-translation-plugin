<?php
/**
 * TQT WP-CLI Commands
 *
 * wp tqt import <file> [--post-type=menu_item] [--dry-run]
 * wp tqt languages
 * wp tqt stats [--post-type=menu_item]
 */

if ( ! defined( 'ABSPATH' ) ) exit;

WP_CLI::add_command( 'tqt import', function ( $args, $assoc ) {
    if ( empty( $args[0] ) ) WP_CLI::error( 'Provide a CSV file path.' );
    $file = $args[0];
    if ( ! file_exists( $file ) ) WP_CLI::error( "File not found: {$file}" );

    $post_type = $assoc['post-type'] ?? 'menu_item';
    $dry_run   = ! empty( $assoc['dry-run'] );

    $result = tqt_process_csv_import( $file, $post_type, $dry_run );
    WP_CLI::success( $result['message'] );
    foreach ( $result['errors'] as $e ) WP_CLI::warning( $e );
});

WP_CLI::add_command( 'tqt languages', function () {
    $active   = tqt_active_languages();
    $settings = tqt_get_settings();
    foreach ( $active as $code => $info ) {
        $default = $code === $settings['default_language'] ? ' [DEFAULT]' : '';
        $rtl     = $info['rtl'] ? ' [RTL]' : '';
        WP_CLI::log( "{$code} — {$info['label']} ({$info['native']}){$default}{$rtl}" );
    }
});

WP_CLI::add_command( 'tqt stats', function ( $args, $assoc ) {
    $post_type = $assoc['post-type'] ?? 'menu_item';
    $posts = get_posts([
        'post_type'      => $post_type,
        'posts_per_page' => -1,
        'post_status'    => 'publish',
    ]);

    $trans = tqt_translation_languages();
    $total = count( $posts );
    $fully_translated = 0;

    foreach ( $posts as $p ) {
        $c = tqt_translation_completeness( $p->ID, $post_type );
        if ( $c['filled'] === $c['total'] && $c['total'] > 0 ) $fully_translated++;
    }

    WP_CLI::log( "Post type: {$post_type}" );
    WP_CLI::log( "Total posts: {$total}" );
    WP_CLI::log( "Languages: " . count( $trans ) . " (excluding default)" );
    WP_CLI::log( "Fully translated: {$fully_translated} / {$total}" );
});
