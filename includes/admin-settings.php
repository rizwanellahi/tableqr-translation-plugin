<?php
/**
 * TQT Admin Settings Page
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'admin_menu', function () {
    add_menu_page(
        'TableQR Translation',
        'Translations',
        'manage_options',
        'tqt-settings',
        'tqt_render_settings_page',
        'dashicons-translation',
        80
    );
    add_submenu_page( 'tqt-settings', 'Settings',   'Settings',   'manage_options', 'tqt-settings',    'tqt_render_settings_page' );
    add_submenu_page( 'tqt-settings', 'CSV Import',  'CSV Import',  'manage_options', 'tqt-csv-import',  'tqt_render_csv_import_page' );
    add_submenu_page( 'tqt-settings', 'CSV Export',  'CSV Export',  'manage_options', 'tqt-csv-export',  'tqt_render_csv_export_page' );
    add_submenu_page( 'tqt-settings', 'Term Translations', 'Term Translations', 'manage_options', 'tqt-terms', 'tqt_render_term_translations_page' );
});

function tqt_render_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) return;

    // Handle save
    if ( isset( $_POST['tqt_save'] ) && check_admin_referer( 'tqt_settings_nonce' ) ) {
        $settings = tqt_get_settings();

        $settings['enabled_languages']   = array_map( 'sanitize_key', $_POST['tqt_enabled_langs'] ?? [] );
        $settings['default_language']     = sanitize_key( $_POST['tqt_default_lang'] ?? 'en' );
        $settings['fallback_behaviour']   = sanitize_key( $_POST['tqt_fallback'] ?? 'default' );
        $settings['show_language_badges'] = ! empty( $_POST['tqt_show_badges'] );
        $settings['csv_delimiter']        = sanitize_text_field( $_POST['tqt_csv_delimiter'] ?? ',' );

        // Custom languages
        $custom = [];
        if ( ! empty( $_POST['tqt_custom_code'] ) ) {
            foreach ( $_POST['tqt_custom_code'] as $i => $code ) {
                $code   = sanitize_key( $code );
                $label  = sanitize_text_field( $_POST['tqt_custom_label'][ $i ] ?? '' );
                $native = sanitize_text_field( $_POST['tqt_custom_native'][ $i ] ?? $label );
                $rtl    = ! empty( $_POST['tqt_custom_rtl'][ $i ] );
                if ( $code && $label ) {
                    $custom[] = compact( 'code', 'label', 'native', 'rtl' );
                }
            }
        }
        $settings['custom_languages'] = $custom;

        // Ensure default is in enabled list
        if ( ! in_array( $settings['default_language'], $settings['enabled_languages'], true ) ) {
            array_unshift( $settings['enabled_languages'], $settings['default_language'] );
        }

        tqt_save_settings( $settings );
        echo '<div class="notice notice-success"><p>Settings saved.</p></div>';
    }

    $settings  = tqt_get_settings();
    $all_langs = tqt_all_languages();
    ?>
    <div class="wrap">
        <h1>TableQR Translation — Settings</h1>
        <form method="post">
            <?php wp_nonce_field( 'tqt_settings_nonce' ); ?>

            <table class="form-table">
                <tr>
                    <th>Default Language</th>
                    <td>
                        <select name="tqt_default_lang">
                            <?php foreach ( $all_langs as $code => $info ) : ?>
                                <option value="<?php echo esc_attr( $code ); ?>" <?php selected( $settings['default_language'], $code ); ?>>
                                    <?php echo esc_html( $info['label'] . ' (' . $info['native'] . ')' ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">Content in this language is stored in the original ACF fields. Other languages get suffixed meta keys.</p>
                    </td>
                </tr>

                <tr>
                    <th>Enabled Languages</th>
                    <td>
                        <fieldset style="max-height:300px;overflow-y:auto;border:1px solid #ccc;padding:12px;background:#fff;">
                            <?php foreach ( $all_langs as $code => $info ) : ?>
                                <label style="display:block;margin-bottom:6px;">
                                    <input type="checkbox" name="tqt_enabled_langs[]" value="<?php echo esc_attr( $code ); ?>"
                                        <?php checked( in_array( $code, $settings['enabled_languages'], true ) ); ?>>
                                    <?php echo esc_html( $info['label'] ); ?>
                                    <span style="color:#666;">(<?php echo esc_html( $info['native'] ); ?>)</span>
                                    <code><?php echo esc_html( $code ); ?></code>
                                    <?php if ( $info['rtl'] ) : ?>
                                        <span style="background:#dba617;color:#fff;padding:1px 6px;border-radius:3px;font-size:11px;">RTL</span>
                                    <?php endif; ?>
                                </label>
                            <?php endforeach; ?>
                        </fieldset>
                    </td>
                </tr>

                <tr>
                    <th>Custom Languages</th>
                    <td>
                        <div id="tqt-custom-langs">
                            <?php foreach ( $settings['custom_languages'] as $c ) : ?>
                                <div class="tqt-custom-row" style="margin-bottom:6px;display:flex;gap:6px;align-items:center;">
                                    <input type="text" name="tqt_custom_code[]" value="<?php echo esc_attr( $c['code'] ); ?>" placeholder="Code" style="width:80px;">
                                    <input type="text" name="tqt_custom_label[]" value="<?php echo esc_attr( $c['label'] ); ?>" placeholder="Label" style="width:150px;">
                                    <input type="text" name="tqt_custom_native[]" value="<?php echo esc_attr( $c['native'] ?? '' ); ?>" placeholder="Native name" style="width:150px;">
                                    <label><input type="checkbox" name="tqt_custom_rtl[]" value="1" <?php checked( ! empty( $c['rtl'] ) ); ?>> RTL</label>
                                    <button type="button" class="button tqt-remove-custom">&times;</button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" class="button" id="tqt-add-custom">+ Add Custom Language</button>
                    </td>
                </tr>

                <tr>
                    <th>Fallback Behaviour</th>
                    <td>
                        <select name="tqt_fallback">
                            <option value="default" <?php selected( $settings['fallback_behaviour'], 'default' ); ?>>Show default language content</option>
                            <option value="hide" <?php selected( $settings['fallback_behaviour'], 'hide' ); ?>>Hide item entirely</option>
                            <option value="empty" <?php selected( $settings['fallback_behaviour'], 'empty' ); ?>>Show empty / blank</option>
                        </select>
                        <p class="description">What happens when a translation is missing for a requested language in the REST API.</p>
                    </td>
                </tr>

                <tr>
                    <th>CSV Delimiter</th>
                    <td>
                        <select name="tqt_csv_delimiter">
                            <option value="," <?php selected( $settings['csv_delimiter'], ',' ); ?>>Comma (,)</option>
                            <option value=";" <?php selected( $settings['csv_delimiter'], ';' ); ?>>Semicolon (;)</option>
                            <option value="&#9;" <?php selected( $settings['csv_delimiter'], "\t" ); ?>>Tab</option>
                        </select>
                    </td>
                </tr>

                <tr>
                    <th>Post List</th>
                    <td>
                        <label>
                            <input type="checkbox" name="tqt_show_badges" <?php checked( $settings['show_language_badges'] ); ?>>
                            Show language completion badges on post list tables
                        </label>
                    </td>
                </tr>
            </table>

            <?php submit_button( 'Save Settings', 'primary', 'tqt_save' ); ?>
        </form>
    </div>

    <script>
    jQuery(function($){
        $('#tqt-add-custom').on('click', function(){
            $('#tqt-custom-langs').append(
                '<div class="tqt-custom-row" style="margin-bottom:6px;display:flex;gap:6px;align-items:center;">' +
                '<input type="text" name="tqt_custom_code[]" placeholder="Code" style="width:80px;"> ' +
                '<input type="text" name="tqt_custom_label[]" placeholder="Label" style="width:150px;"> ' +
                '<input type="text" name="tqt_custom_native[]" placeholder="Native name" style="width:150px;"> ' +
                '<label><input type="checkbox" name="tqt_custom_rtl[]" value="1"> RTL</label> ' +
                '<button type="button" class="button tqt-remove-custom">&times;</button>' +
                '</div>'
            );
        });
        $(document).on('click', '.tqt-remove-custom', function(){ $(this).closest('.tqt-custom-row').remove(); });
    });
    </script>
    <?php
}

/* ── Term Translations page ── */
function tqt_render_term_translations_page() {
    if ( ! current_user_can( 'manage_options' ) ) return;

    $trans_langs = tqt_translation_languages();
    $taxonomies  = [ 'menu_category' => 'Menu Categories', 'menu_section' => 'Menu Sections' ];

    // Handle save
    if ( isset( $_POST['tqt_save_terms'] ) && check_admin_referer( 'tqt_terms_nonce' ) ) {
        foreach ( $taxonomies as $tax => $label ) {
            $terms = get_terms( [ 'taxonomy' => $tax, 'hide_empty' => false ] );
            foreach ( $terms as $term ) {
                foreach ( $trans_langs as $code => $info ) {
                    $key = "term_{$term->term_id}_{$code}";
                    $val = sanitize_text_field( $_POST[ $key ] ?? '' );
                    tqt_set_term_translation( $term->term_id, $code, $val );
                }
            }
        }
        echo '<div class="notice notice-success"><p>Term translations saved.</p></div>';
    }

    ?>
    <div class="wrap">
        <h1>Term Translations</h1>
        <p>Translate taxonomy term names (Menu Categories, Menu Sections) used in the digital menu.</p>
        <form method="post">
            <?php wp_nonce_field( 'tqt_terms_nonce' ); ?>

            <?php foreach ( $taxonomies as $tax => $tax_label ) :
                $terms = get_terms( [ 'taxonomy' => $tax, 'hide_empty' => false, 'orderby' => 'name' ] );
                if ( empty( $terms ) || is_wp_error( $terms ) ) continue;
            ?>
                <h2><?php echo esc_html( $tax_label ); ?></h2>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th>Default (<?php echo esc_html( tqt_get_settings()['default_language'] ); ?>)</th>
                            <?php foreach ( $trans_langs as $code => $info ) : ?>
                                <th><?php echo esc_html( $info['label'] ); ?> (<?php echo esc_html( $code ); ?>)</th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $terms as $term ) : ?>
                            <tr>
                                <td><strong><?php echo esc_html( $term->name ); ?></strong></td>
                                <?php foreach ( $trans_langs as $code => $info ) :
                                    $val = tqt_get_term_translation( $term->term_id, $code );
                                ?>
                                    <td>
                                        <input type="text" name="term_<?php echo $term->term_id; ?>_<?php echo esc_attr( $code ); ?>"
                                            value="<?php echo esc_attr( $val ); ?>"
                                            style="width:100%;<?php echo tqt_is_rtl( $code ) ? 'direction:rtl;text-align:right;' : ''; ?>"
                                            placeholder="<?php echo esc_attr( $term->name ); ?>">
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endforeach; ?>

            <?php submit_button( 'Save Term Translations', 'primary', 'tqt_save_terms' ); ?>
        </form>
    </div>
    <?php
}
