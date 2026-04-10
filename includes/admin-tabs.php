<?php
/**
 * TQT Admin Tabs — Language-tabbed metabox on post edit screens.
 *
 * Shows a metabox "Translations" with tabs for each non-default language.
 * Each tab contains input fields for the translatable fields defined in tqt_translatable_fields().
 * Saves on post save. Does NOT touch the original ACF fields — those remain yours.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* ── Register the metabox for all translatable CPTs ── */
add_action( 'add_meta_boxes', function () {
    $all_fields = tqt_translatable_fields();
    $cpt_keys   = array_diff( array_keys( $all_fields ), [ 'options' ] ); // skip options pages

    $trans_langs = tqt_translation_languages();
    if ( empty( $trans_langs ) ) return; // Only 1 language, no tabs needed

    foreach ( $cpt_keys as $cpt ) {
        if ( ! post_type_exists( $cpt ) ) continue;
        add_meta_box(
            'tqt-translations',
            'Translations',
            'tqt_render_translation_metabox',
            $cpt,
            'normal',
            'high'
        );
    }
});

function tqt_render_translation_metabox( $post ) {
    $post_type   = $post->post_type;
    $fields      = tqt_get_fields_for( $post_type );
    $trans_langs = tqt_translation_languages();
    $settings    = tqt_get_settings();

    if ( ! $fields || empty( $trans_langs ) ) return;

    wp_nonce_field( 'tqt_save_translations', 'tqt_translations_nonce' );

    $completeness = tqt_translation_completeness( $post->ID, $post_type );

    ?>
    <style>
        .tqt-tabs-nav { display:flex; gap:0; border-bottom:2px solid #2271b1; background:#f0f0f1; margin:-6px -12px 0; padding:0; }
        .tqt-tabs-nav button { padding:10px 16px; border:none; background:transparent; color:#50575e; font-size:13px; font-weight:500; cursor:pointer; border-bottom:2px solid transparent; margin-bottom:-2px; }
        .tqt-tabs-nav button:hover { color:#2271b1; }
        .tqt-tabs-nav button.active { color:#1d2327; background:#fff; border-bottom-color:#2271b1; font-weight:600; }
        .tqt-tabs-nav .tqt-badge { display:inline-block; padding:0 5px; border-radius:10px; font-size:10px; line-height:16px; font-weight:600; margin-left:4px; }
        .tqt-badge-rtl { background:#dba617; color:#fff; }
        .tqt-badge-filled { background:#00a32a; color:#fff; }
        .tqt-badge-empty { background:#d63638; color:#fff; }
        .tqt-tab-panel { display:none; padding:12px 0; }
        .tqt-tab-panel.active { display:block; }
        .tqt-field { margin-bottom:12px; }
        .tqt-field label { display:block; font-weight:600; margin-bottom:4px; font-size:13px; color:#1d2327; }
        .tqt-field input[type="text"], .tqt-field textarea { width:100%; }
        .tqt-field textarea { min-height:80px; }
        .tqt-progress { display:flex; align-items:center; gap:8px; padding:8px 0; font-size:12px; color:#50575e; margin:-6px -12px 0; padding:6px 12px; background:#f6f7f7; border-bottom:1px solid #e0e0e0; }
        .tqt-progress-track { flex:0 0 140px; height:5px; background:#e0e0e0; border-radius:3px; overflow:hidden; }
        .tqt-progress-fill { height:100%; border-radius:3px; }
        .tqt-copy-btn { font-size:12px; padding:2px 8px; margin-bottom:8px; cursor:pointer; }
    </style>

    <!-- Progress bar -->
    <?php
    $pct = $completeness['total'] > 0 ? round( ( $completeness['filled'] / $completeness['total'] ) * 100 ) : 0;
    $fill_color = $pct === 100 ? '#00a32a' : ( $pct > 0 ? '#dba617' : '#d63638' );
    ?>
    <div class="tqt-progress">
        <span>Translation progress:</span>
        <div class="tqt-progress-track"><div class="tqt-progress-fill" style="width:<?php echo $pct; ?>%;background:<?php echo $fill_color; ?>;"></div></div>
        <span><?php echo $completeness['filled']; ?> / <?php echo $completeness['total']; ?> languages</span>
    </div>

    <!-- Tabs nav -->
    <div class="tqt-tabs-nav">
        <?php $first = true; foreach ( $trans_langs as $code => $info ) :
            $has_content = false;
            foreach ( ( $fields['post_fields'] ?? [] ) as $f ) {
                if ( tqt_get_translation( $post->ID, $f['name'], $code ) !== '' ) { $has_content = true; break; }
            }
            if ( ! $has_content ) {
                foreach ( ( $fields['meta_fields'] ?? [] ) as $f ) {
                    if ( tqt_get_translation( $post->ID, $f['name'], $code ) !== '' ) { $has_content = true; break; }
                }
            }
        ?>
            <button type="button" class="tqt-tab-btn <?php echo $first ? 'active' : ''; ?>" data-lang="<?php echo esc_attr( $code ); ?>">
                <?php echo esc_html( $info['label'] ); ?>
                <?php if ( $info['rtl'] ) : ?><span class="tqt-badge tqt-badge-rtl">RTL</span><?php endif; ?>
                <span class="tqt-badge <?php echo $has_content ? 'tqt-badge-filled' : 'tqt-badge-empty'; ?>"><?php echo $has_content ? '&#10003;' : '&#10007;'; ?></span>
            </button>
        <?php $first = false; endforeach; ?>
    </div>

    <!-- Tab panels -->
    <?php $first = true; foreach ( $trans_langs as $code => $info ) : ?>
        <div class="tqt-tab-panel <?php echo $first ? 'active' : ''; ?>" data-lang="<?php echo esc_attr( $code ); ?>"
             <?php echo $info['rtl'] ? 'dir="rtl"' : ''; ?>>

            <!-- Post fields -->
            <?php foreach ( ( $fields['post_fields'] ?? [] ) as $f ) :
                $val = tqt_get_translation( $post->ID, $f['name'], $code );
                $input_name = "tqt[{$code}][{$f['name']}]";
            ?>
                <div class="tqt-field">
                    <label><?php echo esc_html( $f['label'] ); ?></label>
                    <input type="text" name="<?php echo esc_attr( $input_name ); ?>" value="<?php echo esc_attr( $val ); ?>"
                        placeholder="<?php echo esc_attr( tqt_get_translation( $post->ID, $f['name'], $settings['default_language'] ) ); ?>">
                </div>
            <?php endforeach; ?>

            <!-- Meta fields -->
            <?php foreach ( ( $fields['meta_fields'] ?? [] ) as $f ) :
                $val = tqt_get_translation( $post->ID, $f['name'], $code );
                $input_name = "tqt[{$code}][{$f['name']}]";
                $default_val = tqt_get_translation( $post->ID, $f['name'], $settings['default_language'] );
            ?>
                <div class="tqt-field">
                    <label><?php echo esc_html( $f['label'] ); ?></label>
                    <?php if ( $f['type'] === 'textarea' ) : ?>
                        <textarea name="<?php echo esc_attr( $input_name ); ?>"
                            placeholder="<?php echo esc_attr( wp_strip_all_tags( $default_val ) ); ?>"
                            style="<?php echo $info['rtl'] ? 'direction:rtl;text-align:right;' : ''; ?>"
                        ><?php echo esc_textarea( $val ); ?></textarea>
                    <?php else : ?>
                        <input type="text" name="<?php echo esc_attr( $input_name ); ?>" value="<?php echo esc_attr( $val ); ?>"
                            placeholder="<?php echo esc_attr( $default_val ); ?>"
                            style="<?php echo $info['rtl'] ? 'direction:rtl;text-align:right;' : ''; ?>">
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>

            <!-- Repeater sub-fields -->
            <?php foreach ( ( $fields['repeater_fields'] ?? [] ) as $rep ) :
                $repeater_name = $rep['repeater'];
                $rows = get_field( $repeater_name, $post->ID );
                if ( ! is_array( $rows ) || empty( $rows ) ) continue;

                foreach ( $rep['sub_fields'] as $sf ) :
                    foreach ( $rows as $i => $row ) :
                        // ACF stores rows as repeater_0_field, repeater_1_field (0-based)
                        $base_key   = "{$repeater_name}_{$i}_{$sf['name']}";
                        $val        = (string) get_post_meta( $post->ID, $base_key . '_' . $code, true );
                        // Legacy: older UI wrote 1-based keys (prices_1_*); read if present
                        if ( $val === '' ) {
                            $legacy_key = "{$repeater_name}_" . ( $i + 1 ) . "_{$sf['name']}_{$code}";
                            $val        = (string) get_post_meta( $post->ID, $legacy_key, true );
                        }
                        $default_val = (string) get_post_meta( $post->ID, $base_key, true );
                        $default_val2 = (string) get_post_meta( $post->ID, "{$repeater_name}_{$i}_{$sf['name']}", true );
                        if ( $default_val2 !== '' ) {
                            $default_val = $default_val2;
                        }

                        $input_name = "tqt_rep[{$code}][{$base_key}]";
                        $label_row    = $i + 1;
            ?>
                        <div class="tqt-field">
                            <label><?php echo esc_html( $sf['label'] . ' #' . $label_row ); ?></label>
                            <input type="text" name="<?php echo esc_attr( $input_name ); ?>" value="<?php echo esc_attr( $val ); ?>"
                                placeholder="<?php echo esc_attr( $default_val ); ?>"
                                style="<?php echo $info['rtl'] ? 'direction:rtl;text-align:right;' : ''; ?>">
                        </div>
            <?php
                    endforeach;
                endforeach;
            endforeach; ?>

        </div>
    <?php $first = false; endforeach; ?>

    <script>
    jQuery(function($){
        $('.tqt-tab-btn').on('click', function(){
            var lang = $(this).data('lang');
            $('.tqt-tab-btn').removeClass('active');
            $(this).addClass('active');
            $('.tqt-tab-panel').removeClass('active');
            $('.tqt-tab-panel[data-lang="'+lang+'"]').addClass('active');
        });
    });
    </script>
    <?php
}

/* ── Save translations on post save ── */
add_action( 'save_post', function ( $post_id ) {
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
    if ( wp_is_post_revision( $post_id ) ) return;
    if ( ! isset( $_POST['tqt_translations_nonce'] ) ) return;
    if ( ! wp_verify_nonce( $_POST['tqt_translations_nonce'], 'tqt_save_translations' ) ) return;
    if ( ! current_user_can( 'edit_post', $post_id ) ) return;

    // Standard fields
    if ( ! empty( $_POST['tqt'] ) && is_array( $_POST['tqt'] ) ) {
        foreach ( $_POST['tqt'] as $lang => $fields ) {
            $lang = sanitize_key( $lang );
            foreach ( $fields as $field_name => $value ) {
                $field_name = sanitize_key( $field_name );
                $value      = wp_kses_post( $value );
                tqt_set_translation( $post_id, $field_name, $lang, $value );
            }
        }
    }

    // Repeater sub-fields
    if ( ! empty( $_POST['tqt_rep'] ) && is_array( $_POST['tqt_rep'] ) ) {
        foreach ( $_POST['tqt_rep'] as $lang => $fields ) {
            $lang = sanitize_key( $lang );
            foreach ( $fields as $meta_key => $value ) {
                $meta_key = sanitize_key( $meta_key );
                $value    = sanitize_text_field( $value );
                update_post_meta( $post_id, $meta_key . '_' . $lang, $value );
            }
        }
    }
}, 20 ); // priority 20 to run after ACF saves
