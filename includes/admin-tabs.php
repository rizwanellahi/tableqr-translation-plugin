<?php
/**
 * TQT Inline Modal UI — Translation button inside primary ACF fields.
 *
 * This replaces the separate translations metabox with a per-field translate
 * button rendered inline in translatable ACF fields.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Current post type only on supported post edit screens.
 */
function tqt_inline_modal_post_type(): ?string {
    if ( ! is_admin() ) {
        return null;
    }

    $post_type = '';
    $post_id = tqt_inline_modal_post_id();
    if ( $post_id > 0 ) {
        $post_type = (string) get_post_type( $post_id );
    }
    if ( $post_type === '' && isset( $_GET['post_type'] ) ) {
        $post_type = sanitize_key( (string) $_GET['post_type'] );
    }
    if ( $post_type === '' && isset( $_POST['post_type'] ) ) {
        $post_type = sanitize_key( (string) $_POST['post_type'] );
    }
    if ( $post_type === '' ) {
        return null;
    }

    $all = tqt_translatable_fields();
    if ( ! isset( $all[ $post_type ] ) ) {
        return null;
    }

    return $post_type;
}

/**
 * Resolve post ID from ACF field context first.
 *
 * @param array $field ACF field array.
 */
function tqt_inline_modal_post_id_from_field( array $field ): int {
    $raw = $field['post_id'] ?? null;
    if ( is_numeric( $raw ) ) {
        return (int) $raw;
    }
    if ( is_string( $raw ) ) {
        if ( preg_match( '/^post_(\d+)$/', $raw, $m ) ) {
            return (int) $m[1];
        }
        if ( ctype_digit( $raw ) ) {
            return (int) $raw;
        }
    }
    return tqt_inline_modal_post_id();
}

/**
 * Resolve post ID reliably on wp-admin edit screens.
 */
function tqt_inline_modal_post_id(): int {
    if ( isset( $_GET['post'] ) ) {
        return (int) $_GET['post'];
    }
    if ( isset( $_POST['post_ID'] ) ) {
        return (int) $_POST['post_ID'];
    }
    if ( function_exists( 'get_the_ID' ) ) {
        return (int) get_the_ID();
    }
    return 0;
}

/**
 * Map of top-level translatable ACF fields for the current post type.
 * (Post title is excluded because it's not an ACF field input.)
 */
function tqt_inline_modal_field_map( string $post_type ): array {
    $defs = tqt_get_fields_for( $post_type );
    if ( ! $defs ) {
        return [];
    }

    $map = [];
    foreach ( ( $defs['meta_fields'] ?? [] ) as $field ) {
        if ( empty( $field['name'] ) ) {
            continue;
        }
        $map[ (string) $field['name'] ] = [
            'label' => (string) ( $field['label'] ?? $field['name'] ),
            'type'  => (string) ( $field['type'] ?? 'text' ),
        ];
    }

    return $map;
}

/**
 * Build repeater translation payloads keyed by row base meta key.
 * Example base key: prices_0_price_name
 */
function tqt_inline_modal_repeater_payload( int $post_id, string $post_type, array $trans_langs ): array {
    $defs = tqt_get_fields_for( $post_type );
    if ( ! $defs || empty( $defs['repeater_fields'] ) || ! is_array( $defs['repeater_fields'] ) ) {
        return [];
    }

    $payload = [];
    foreach ( $defs['repeater_fields'] as $rep ) {
        $repeater_name = (string) ( $rep['repeater'] ?? '' );
        if ( $repeater_name === '' || empty( $rep['sub_fields'] ) || ! is_array( $rep['sub_fields'] ) ) {
            continue;
        }

        $rows = function_exists( 'get_field' ) ? get_field( $repeater_name, $post_id ) : [];
        if ( ! is_array( $rows ) || empty( $rows ) ) {
            continue;
        }

        foreach ( $rep['sub_fields'] as $sf ) {
            $sub_name = (string) ( $sf['name'] ?? '' );
            if ( $sub_name === '' ) {
                continue;
            }

            foreach ( $rows as $i => $row ) {
                $base_key = "{$repeater_name}_{$i}_{$sub_name}";
                $translations = [];
                foreach ( $trans_langs as $code => $info ) {
                    $val = (string) get_post_meta( $post_id, $base_key . '_' . $code, true );
                    if ( $val === '' ) {
                        $legacy_key = "{$repeater_name}_" . ( $i + 1 ) . "_{$sub_name}_{$code}";
                        $val = (string) get_post_meta( $post_id, $legacy_key, true );
                    }
                    $translations[ $code ] = [
                        'label' => (string) $info['label'],
                        'rtl'   => ! empty( $info['rtl'] ),
                        'value' => $val,
                    ];
                }

                $payload[ $base_key ] = [
                    'fieldName' => $sub_name,
                    'rowIndex' => (int) $i,
                    'label' => (string) ( ( $sf['label'] ?? $sub_name ) . ' #' . ( $i + 1 ) ),
                    'type' => (string) ( $sf['type'] ?? 'text' ),
                    'default' => (string) get_post_meta( $post_id, $base_key, true ),
                    'translations' => $translations,
                ];
            }
        }
    }

    return $payload;
}

/**
 * Render inline button + modal on each supported ACF field.
 */
add_action( 'acf/render_field', function( $field ) {
    $post_id = tqt_inline_modal_post_id_from_field( (array) $field );
    if ( ! $post_id ) {
        return;
    }

    $post_type = (string) get_post_type( $post_id );
    if ( $post_type === '' ) {
        $post_type = tqt_inline_modal_post_type() ?? '';
    }
    if ( $post_type === '' ) {
        return;
    }

    $trans_langs = tqt_translation_languages();
    if ( empty( $trans_langs ) ) {
        return;
    }

    $map = tqt_inline_modal_field_map( $post_type );
    $field_name = (string) ( $field['name'] ?? '' );
    $field_key  = (string) ( $field['key'] ?? '' );

    // Hard fallback for known Description field key in menu_item.
    if ( $field_name === '' && $field_key === 'field_68dbd9964115e' ) {
        $field_name = 'description';
    }

    if ( $field_name === '' || ! isset( $map[ $field_name ] ) ) {
        return;
    }

    $settings = tqt_get_settings();
    $default_lang = (string) $settings['default_language'];
    $post_status = (string) get_post_status( $post_id );
    $save_label = ( $post_status === 'publish' ) ? 'Update' : 'Save Draft';
    $field_meta = $map[ $field_name ];
    $is_textarea = ( $field_meta['type'] === 'textarea' || (string) ( $field['type'] ?? '' ) === 'textarea' );
    $modal_id = 'tqt-inline-modal-' . sanitize_html_class( $field_name ) . '-' . (int) $post_id;
    $default_val = tqt_get_translation( (int) $post_id, $field_name, $default_lang );
    ?>
    <div class="tqt-inline-wrap" data-tqt-inline-wrap>
        <button type="button" class="tqt-inline-btn" data-tqt-open="<?php echo esc_attr( $modal_id ); ?>" title="Translate">
            <span class="dashicons dashicons-translation"></span>
        </button>
    </div>

    <div class="tqt-inline-backdrop" data-tqt-backdrop="<?php echo esc_attr( $modal_id ); ?>"></div>
    <div class="tqt-inline-modal" id="<?php echo esc_attr( $modal_id ); ?>" role="dialog" aria-modal="true" aria-labelledby="<?php echo esc_attr( $modal_id ); ?>-title">
        <div class="tqt-inline-modal__header">
            <h3 id="<?php echo esc_attr( $modal_id ); ?>-title"><?php echo esc_html( $field_meta['label'] ); ?> translations</h3>
            <button type="button" class="tqt-inline-close" data-tqt-close="<?php echo esc_attr( $modal_id ); ?>">
                <span class="dashicons dashicons-no-alt"></span>
            </button>
        </div>
        <div class="tqt-inline-modal__body">
            <div class="tqt-inline-default">
                <label>Default (<?php echo esc_html( strtoupper( $default_lang ) ); ?>)</label>
                <?php if ( $is_textarea ) : ?>
                    <textarea readonly><?php echo esc_textarea( (string) $default_val ); ?></textarea>
                <?php else : ?>
                    <input type="text" readonly value="<?php echo esc_attr( (string) $default_val ); ?>">
                <?php endif; ?>
            </div>

            <div class="tqt-inline-grid">
                <?php foreach ( $trans_langs as $code => $info ) :
                    $val = tqt_get_translation( (int) $post_id, $field_name, (string) $code );
                    $input_name = "tqt[{$code}][{$field_name}]";
                    $rtl = ! empty( $info['rtl'] );
                ?>
                    <div class="tqt-inline-field">
                        <label>
                            <?php echo esc_html( (string) $info['label'] ); ?>
                            <span>(<?php echo esc_html( strtoupper( (string) $code ) ); ?>)</span>
                        </label>
                        <?php if ( $is_textarea ) : ?>
                            <textarea name="<?php echo esc_attr( $input_name ); ?>" style="<?php echo $rtl ? 'direction:rtl;text-align:right;' : ''; ?>"><?php echo esc_textarea( (string) $val ); ?></textarea>
                        <?php else : ?>
                            <input type="text" name="<?php echo esc_attr( $input_name ); ?>" value="<?php echo esc_attr( (string) $val ); ?>" style="<?php echo $rtl ? 'direction:rtl;text-align:right;' : ''; ?>">
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="tqt-inline-modal__footer">
            <button type="button" class="button button-secondary" data-tqt-close="<?php echo esc_attr( $modal_id ); ?>">Close</button>
        </div>
    </div>
    <?php
}, 30 );

/**
 * Inline UI assets and modal events.
 */
add_action( 'acf/input/admin_footer', function() {
    $post_id = tqt_inline_modal_post_id();
    if ( ! $post_id ) {
        return;
    }
    $post_type = (string) get_post_type( $post_id );
    if ( $post_type === '' ) {
        $post_type = tqt_inline_modal_post_type() ?? '';
    }
    if ( $post_type === '' ) {
        return;
    }
    $map = tqt_inline_modal_field_map( $post_type );
    $trans_langs = tqt_translation_languages();
    if ( empty( $map ) || empty( $trans_langs ) ) {
        return;
    }
    $settings = tqt_get_settings();
    $default_lang = (string) $settings['default_language'];
    $post_status = (string) get_post_status( $post_id );
    $save_label = ( $post_status === 'publish' ) ? 'Update' : 'Save Draft';

    $field_payload = [];
    foreach ( $map as $name => $meta ) {
        $translations = [];
        foreach ( $trans_langs as $code => $info ) {
            $translations[ $code ] = [
                'label' => (string) $info['label'],
                'rtl'   => ! empty( $info['rtl'] ),
                'value' => (string) tqt_get_translation( (int) $post_id, (string) $name, (string) $code ),
            ];
        }
        $field_payload[ $name ] = [
            'label' => (string) $meta['label'],
            'type' => (string) $meta['type'],
            'default' => (string) tqt_get_translation( (int) $post_id, (string) $name, $default_lang ),
            'translations' => $translations,
        ];
    }
    $repeater_payload = tqt_inline_modal_repeater_payload( (int) $post_id, $post_type, $trans_langs );

    $title_payload = null;
    $defs = tqt_get_fields_for( $post_type );
    if ( ! empty( $defs['post_fields'] ) && is_array( $defs['post_fields'] ) ) {
        foreach ( $defs['post_fields'] as $pf ) {
            if ( (string) ( $pf['name'] ?? '' ) !== 'post_title' ) {
                continue;
            }
            $translations = [];
            foreach ( $trans_langs as $code => $info ) {
                $translations[ $code ] = [
                    'label' => (string) $info['label'],
                    'rtl'   => ! empty( $info['rtl'] ),
                    'value' => (string) tqt_get_translation( (int) $post_id, 'post_title', (string) $code ),
                ];
            }
            $title_payload = [
                'label' => (string) ( $pf['label'] ?? 'Title' ),
                'type' => 'text',
                'default' => (string) tqt_get_translation( (int) $post_id, 'post_title', $default_lang ),
                'translations' => $translations,
            ];
            break;
        }
    }

    wp_nonce_field( 'tqt_save_translations', 'tqt_translations_nonce' );
    ?>
    <style>
        .tqt-inline-wrap { position: absolute; top: 1px; right: 1px; bottom: 1px; margin: 0; z-index: 5; display: flex; }
        .tqt-inline-btn { width: 34px; height: 100%; display: inline-flex; align-items: center; justify-content: center; border: 0; border-left: 1px solid #c3c4c7; border-radius: 0 3px 3px 0; background: #f6f7f7; color: #2271b1; cursor: pointer; padding: 0; margin: 0; line-height: 1; box-sizing: border-box; }
        .tqt-inline-btn:hover { background: #f0f6fc; border-color: #2271b1; }
        .tqt-inline-btn .dashicons { font-size: 16px; width: 16px; height: 16px; line-height: 16px; }
        .acf-field .acf-input.tqt-has-inline-btn { position: relative; line-height: 0; }
        .acf-field .acf-input.tqt-has-inline-btn > input[type="text"],
        .acf-field .acf-input.tqt-has-inline-btn > input[type="number"],
        .acf-field .acf-input.tqt-has-inline-btn > textarea { padding-right: 40px; line-height: normal; }
        #titlewrap.tqt-has-inline-btn { position: relative; line-height: 0; }
        #titlewrap.tqt-has-inline-btn #title { padding-right: 40px; line-height: normal; }

        .tqt-inline-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,.45); z-index: 100000; display: none; }
        .tqt-inline-backdrop.is-open { display: block; }
        .tqt-inline-modal { position: fixed; z-index: 100001; left: 50%; top: 50%; transform: translate(-50%, -50%); width: min(860px, calc(100vw - 40px)); max-height: calc(100vh - 60px); overflow: auto; background: #fff; border-radius: 10px; box-shadow: 0 10px 40px rgba(0,0,0,.22); display: none; }
        .tqt-inline-modal.is-open { display: block; }
        .tqt-inline-modal__header { display: flex; justify-content: space-between; align-items: center; padding: 12px 16px; border-bottom: 1px solid #dcdcde; position: sticky; top: 0; background: #fff; }
        .tqt-inline-modal__header h3 { margin: 0; font-size: 14px; }
        .tqt-inline-close { border: none; background: transparent; color: #646970; cursor: pointer; }
        .tqt-inline-modal__body { padding: 14px 16px; }
        .tqt-inline-default { border: 1px dashed #c3c4c7; border-radius: 8px; padding: 10px; margin-bottom: 14px; background: #fcfcfc; }
        .tqt-inline-default label { display: block; margin-bottom: 6px; font-weight: 600; }
        .tqt-inline-default input, .tqt-inline-default textarea { width: 100%; }
        .tqt-inline-default textarea { min-height: 80px; }
        .tqt-inline-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px 12px; }
        .tqt-inline-field label { display: block; margin-bottom: 4px; font-weight: 600; }
        .tqt-inline-field label span { font-weight: 400; color: #646970; font-size: 11px; margin-left: 4px; }
        .tqt-inline-field input, .tqt-inline-field textarea { width: 100%; }
        .tqt-inline-field textarea { min-height: 80px; }
        .tqt-inline-modal__footer { position: sticky; bottom: 0; background: #fff; border-top: 1px solid #dcdcde; padding: 10px 16px; display: flex; justify-content: flex-end; gap: 8px; }
        @media (max-width: 780px) { .tqt-inline-grid { grid-template-columns: 1fr; } }
    </style>
    <script>
    (function($){
        var tqtConfig = <?php echo wp_json_encode( [
            'defaultLang' => $default_lang,
            'postId' => (int) $post_id,
            'postStatus' => $post_status,
            'saveLabel' => $save_label,
            'titleField' => $title_payload,
            'fields' => $field_payload,
            'repeaterFields' => $repeater_payload,
        ] ); ?>;

        function escHtml(str){
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function buildModal(fieldName, cfg, isRepeater){
            var modalId = 'tqt-inline-modal-' + fieldName + '-' + tqtConfig.postId;
            var isTextarea = cfg.type === 'textarea';
            var body = '';
            Object.keys(cfg.translations || {}).forEach(function(code){
                var lang = cfg.translations[code];
                var inputName = isRepeater ? ('tqt_rep[' + code + '][' + fieldName + ']') : ('tqt[' + code + '][' + fieldName + ']');
                var style = lang.rtl ? 'direction:rtl;text-align:right;' : '';
                body += '<div class="tqt-inline-field">';
                body +=   '<label>' + escHtml(lang.label) + ' <span>(' + escHtml(code.toUpperCase()) + ')</span></label>';
                if (isTextarea) {
                    body += '<textarea name="' + escHtml(inputName) + '" style="' + escHtml(style) + '">' + escHtml(lang.value || '') + '</textarea>';
                } else {
                    body += '<input type="text" name="' + escHtml(inputName) + '" value="' + escHtml(lang.value || '') + '" style="' + escHtml(style) + '">';
                }
                body += '</div>';
            });

            var defaultBlock = isTextarea
                ? '<textarea readonly>' + escHtml(cfg.default || '') + '</textarea>'
                : '<input type="text" readonly value="' + escHtml(cfg.default || '') + '">';

            var html = ''
                + '<div class="tqt-inline-backdrop" data-tqt-backdrop="' + escHtml(modalId) + '"></div>'
                + '<div class="tqt-inline-modal" id="' + escHtml(modalId) + '" role="dialog" aria-modal="true">'
                +   '<div class="tqt-inline-modal__header">'
                +     '<h3>' + escHtml(cfg.label) + ' translations</h3>'
                +     '<button type="button" class="tqt-inline-close" data-tqt-close="' + escHtml(modalId) + '"><span class="dashicons dashicons-no-alt"></span></button>'
                +   '</div>'
                +   '<div class="tqt-inline-modal__body">'
                +     '<div class="tqt-inline-default">'
                +       '<label>Default (' + escHtml((tqtConfig.defaultLang || '').toUpperCase()) + ')</label>'
                +       defaultBlock
                +     '</div>'
                +     '<div class="tqt-inline-grid">' + body + '</div>'
                +   '</div>'
                +   '<div class="tqt-inline-modal__footer">'
                +     '<button type="button" class="button button-primary" data-tqt-save="1">' + escHtml(tqtConfig.saveLabel || 'Save') + '</button>'
                +     '<button type="button" class="button button-secondary" data-tqt-close="' + escHtml(modalId) + '">Close</button>'
                +   '</div>'
                + '</div>';

            var $form = $('#post');
            if ($form.length) {
                $form.append(html);
            } else {
                $('body').append(html);
            }
            return modalId;
        }

        function injectButtons(){
            Object.keys(tqtConfig.fields || {}).forEach(function(fieldName){
                var cfg = tqtConfig.fields[fieldName];
                var $field = $('.acf-field[data-name="' + fieldName + '"]');
                if (!$field.length) return;
                if ($field.find('.tqt-inline-wrap').length) return;

                var modalId = '';
                var $btn = $('<button/>', {
                    type: 'button',
                    class: 'tqt-inline-btn',
                    title: 'Translate',
                    html: '<span class="dashicons dashicons-translation"></span>'
                }).on('click', function(){
                        if (!modalId) modalId = buildModal(fieldName, cfg, false);
                    $('#' + modalId).addClass('is-open');
                    $('[data-tqt-backdrop="' + modalId + '"]').addClass('is-open');
                    $('body').css('overflow', 'hidden');
                });

                var $wrap = $('<div class="tqt-inline-wrap" data-tqt-inline-wrap></div>').append($btn);
                var $inputWrap = $field.find('> .acf-input');
                $inputWrap.addClass('tqt-has-inline-btn').append($wrap);
            });
        }

        function injectRepeaterButtons(){
            $('.acf-field[data-name="price_name"]').each(function(){
                var $field = $(this);
                if ($field.find('.tqt-inline-wrap').length) return;

                // Scope to the intended repeater only (main prices).
                var $repeater = $field.closest('.acf-field-repeater[data-name="prices"]');
                if (!$repeater.length) return;

                var $row = $field.closest('.acf-row');
                if (!$row.length) return;

                var rowId = String($row.attr('data-id') || '');
                var match = rowId.match(/row-(\d+)/);
                if (!match) return;
                var rowIndex = parseInt(match[1], 10);
                if (isNaN(rowIndex)) return;

                var fieldName = 'prices_' + rowIndex + '_price_name';
                var cfg = (tqtConfig.repeaterFields && tqtConfig.repeaterFields[fieldName]) ? tqtConfig.repeaterFields[fieldName] : null;
                if (!cfg) {
                    // Fallback empty payload for new rows added after page load.
                    cfg = {
                        fieldName: 'price_name',
                        rowIndex: rowIndex,
                        label: 'Price Name #' + (rowIndex + 1),
                        type: 'text',
                        default: '',
                        translations: {}
                    };
                    // Seed languages from any existing field config.
                    var seed = null;
                    var repeaterKeys = Object.keys(tqtConfig.repeaterFields || {});
                    if (repeaterKeys.length) seed = tqtConfig.repeaterFields[repeaterKeys[0]];
                    if (seed && seed.translations) {
                        Object.keys(seed.translations).forEach(function(code){
                            cfg.translations[code] = {
                                label: seed.translations[code].label,
                                rtl: !!seed.translations[code].rtl,
                                value: ''
                            };
                        });
                    }
                }

                var modalId = '';
                var $btn = $('<button/>', {
                    type: 'button',
                    class: 'tqt-inline-btn',
                    title: 'Translate',
                    html: '<span class="dashicons dashicons-translation"></span>'
                }).on('click', function(){
                    if (!modalId) modalId = buildModal(fieldName, cfg, true);
                    $('#' + modalId).addClass('is-open');
                    $('[data-tqt-backdrop="' + modalId + '"]').addClass('is-open');
                    $('body').css('overflow', 'hidden');
                });

                var $wrap = $('<div class="tqt-inline-wrap" data-tqt-inline-wrap></div>').append($btn);
                var $inputWrap = $field.find('> .acf-input');
                $inputWrap.addClass('tqt-has-inline-btn').append($wrap);
            });
        }

        function injectTitleButton() {
            var cfg = tqtConfig.titleField;
            if (!cfg) return;

            var $titleWrap = $('#titlediv #titlewrap');
            if (!$titleWrap.length) return;
            if ($('#tqt-title-inline-wrap').length) return;

            var fieldName = 'post_title';
            var modalId = '';
            var $btn = $('<button/>', {
                type: 'button',
                id: 'tqt-title-inline-btn',
                class: 'tqt-inline-btn',
                title: 'Translate title',
                html: '<span class="dashicons dashicons-translation"></span>'
            }).on('click', function(){
                if (!modalId) modalId = buildModal(fieldName, cfg, false);
                $('#' + modalId).addClass('is-open');
                $('[data-tqt-backdrop="' + modalId + '"]').addClass('is-open');
                $('body').css('overflow', 'hidden');
            });

            var $wrap = $('<div id="tqt-title-inline-wrap" class="tqt-inline-wrap" data-tqt-inline-wrap></div>').append($btn);
            $titleWrap.addClass('tqt-has-inline-btn').append($wrap);
        }

        function tqtCloseModal(modalId) {
            $('#' + modalId).removeClass('is-open');
            $('[data-tqt-backdrop="' + modalId + '"]').removeClass('is-open');
            $('body').css('overflow', '');
        }

        $(document).on('click', '[data-tqt-close]', function(){
            tqtCloseModal($(this).attr('data-tqt-close'));
        });

        $(document).on('click', '[data-tqt-save]', function(){
            var $form = $('#post');
            if (!$form.length) return;

            var status = String(tqtConfig.postStatus || '');
            if (status !== 'publish') {
                var $saveDraft = $('#save-post');
                if ($saveDraft.length) {
                    $saveDraft.trigger('click');
                    return;
                }
            }

            var $update = $('#publish');
            if ($update.length) {
                $update.trigger('click');
                return;
            }

            // Final fallback if button selectors differ.
            $form.trigger('submit');
        });

        $(document).on('click', '[data-tqt-backdrop]', function(){
            tqtCloseModal($(this).attr('data-tqt-backdrop'));
        });

        $(document).on('keydown', function(e){
            if (e.key !== 'Escape') return;
            var $open = $('.tqt-inline-modal.is-open').last();
            if (!$open.length) return;
            tqtCloseModal($open.attr('id'));
        });

        injectButtons();
        injectRepeaterButtons();
        injectTitleButton();
        if (window.acf && typeof window.acf.addAction === 'function') {
            window.acf.addAction('append_field/type=textarea', injectButtons);
            window.acf.addAction('append_field/type=text', injectButtons);
            window.acf.addAction('append_field/type=repeater', injectRepeaterButtons);
        }
    })(jQuery);
    </script>
    <?php
});

/* ── Save translations on post save ── */
add_action( 'save_post', function ( $post_id ) {
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
    if ( wp_is_post_revision( $post_id ) ) return;
    if ( ! current_user_can( 'edit_post', $post_id ) ) return;
    if ( empty( $_POST['tqt'] ) && empty( $_POST['tqt_rep'] ) ) return;

    if ( isset( $_POST['tqt_translations_nonce'] ) && ! wp_verify_nonce( $_POST['tqt_translations_nonce'], 'tqt_save_translations' ) ) {
        return;
    }

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
}, 20 );
