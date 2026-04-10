<?php
/**
 * Plugin Name: TableQR Translation
 * Plugin URI:  https://tableqr.com
 * Description: Lightweight translation system for TableQR digital menus. Replaces WPGlobus for multisite. Stores per-language values in postmeta/options, provides tabbed admin UI, CSV import/export, and a clean REST API for headless Next.js.
 * Version:     1.0.0
 * Author:      TableQR
 * Network:     true
 * Text Domain: tqt
 * Requires PHP: 7.4
 * Requires at least: 6.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'TQT_VERSION', '1.0.0' );
define( 'TQT_DIR',     plugin_dir_path( __FILE__ ) );
define( 'TQT_URL',     plugin_dir_url( __FILE__ ) );

/* ── Core: settings + language helpers ── */
require_once TQT_DIR . 'includes/settings.php';

/* ── Translation storage & retrieval API ── */
require_once TQT_DIR . 'includes/storage.php';

/* ── Front: URL language prefix (/es/…), cookies, localize helpers ── */
require_once TQT_DIR . 'includes/request-language.php';
require_once TQT_DIR . 'includes/locale-theme.php';
require_once TQT_DIR . 'includes/frontend-hooks.php';
require_once TQT_DIR . 'includes/frontend-helpers.php';

/* ── Admin: settings page UI ── */
require_once TQT_DIR . 'includes/admin-settings.php';

/* ── Admin: tabbed editor UI on post edit screens ── */
require_once TQT_DIR . 'includes/admin-tabs.php';

/* ── Admin: translation status columns ── */
require_once TQT_DIR . 'includes/admin-columns.php';

/* ── CSV Import ── */
require_once TQT_DIR . 'includes/csv-import.php';

/* ── CSV Export ── */
require_once TQT_DIR . 'includes/csv-export.php';

/* ── REST API ── */
require_once TQT_DIR . 'includes/rest-api.php';

/* ── WP-CLI commands ── */
if ( defined( 'WP_CLI' ) && WP_CLI ) {
    require_once TQT_DIR . 'includes/cli.php';
}
