/**
 * TableQR Translations — Tabbed Language Editor UI
 *
 * Wraps ACF repeater rows into language tabs on the post edit screen.
 * Depends on TQT.languages, TQT.defaultLanguage, TQT.rtlLanguages
 * being localized via wp_localize_script.
 */
(function ($) {
    'use strict';

    if (typeof TQT === 'undefined') return;

    var languages      = TQT.languages;       // { en: 'English', ar: 'Arabic', ... }
    var defaultLang    = TQT.defaultLanguage;  // 'en'
    var rtlLanguages   = TQT.rtlLanguages;     // ['ar', 'fa', 'ur']
    var langCodes      = Object.keys(languages);

    if (langCodes.length < 2) return; // No tabs needed for single language

    /**
     * Initialize tabs once ACF fields are ready.
     */
    function initTabs() {
        // Find the ACF repeater field for translations
        var $repeater = $('.acf-field[data-name="tqt_translations"]');
        if (!$repeater.length) return;

        // Hide the native ACF repeater chrome (add row button, row handles)
        // We still use the repeater for storage but present it differently
        var $repeaterWrap = $repeater.find('.acf-repeater');

        // Build tab UI
        var $wrapper = $('<div class="tqt-tabs-wrapper"></div>');
        var $nav     = $('<ul class="tqt-tabs-nav"></ul>');
        var $panels  = $('<div class="tqt-tabs-panels"></div>');

        // Completion bar
        var $completionBar = $(
            '<div class="tqt-completion-bar">' +
            '<span class="tqt-completion-label">Translation progress:</span>' +
            '<div class="tqt-completion-track"><div class="tqt-completion-fill"></div></div>' +
            '<span class="tqt-completion-text">0 / ' + langCodes.length + '</span>' +
            '</div>'
        );

        // Create a tab for each enabled language
        langCodes.forEach(function (code, index) {
            var label   = languages[code];
            var isDefault = code === defaultLang;
            var isRtl     = rtlLanguages.indexOf(code) !== -1;

            // Tab button
            var badgeHtml = '';
            if (isDefault) {
                badgeHtml = '<span class="tqt-tab-badge tqt-tab-badge-default">Default</span>';
            }
            if (isRtl) {
                badgeHtml += '<span class="tqt-tab-badge tqt-tab-badge-rtl">RTL</span>';
            }

            var $tab = $(
                '<li><button type="button" class="tqt-tab-btn" data-lang="' + code + '">' +
                label + ' ' + badgeHtml +
                '</button></li>'
            );
            $nav.append($tab);

            // Tab panel
            var $panel = $(
                '<div class="tqt-tab-panel" data-lang="' + code + '"' +
                (isRtl ? ' dir="rtl"' : '') + '></div>'
            );

            // Copy from default button (for non-default tabs)
            if (!isDefault) {
                var $copyBtn = $(
                    '<button type="button" class="tqt-copy-default" data-lang="' + code + '">' +
                    '📋 Copy from ' + languages[defaultLang] +
                    '</button>'
                );
                $panel.append($copyBtn);
            }

            $panels.append($panel);
        });

        $wrapper.append($nav).append($completionBar).append($panels);

        // Insert tabs before the repeater
        $repeater.before($wrapper);

        // Now we need to map ACF repeater rows to tab panels.
        // Each repeater row has a sub-field 'tqt_lang_code' that tells us which language it belongs to.
        distributeRowsToPanels($repeater, $panels);

        // Ensure all languages have a row (create missing ones)
        ensureAllLanguageRows($repeater, $panels);

        // Hide the original repeater visually but keep it in DOM for ACF saving
        $repeater.css({
            position: 'absolute',
            left: '-9999px',
            height: '0',
            overflow: 'hidden',
        });

        // Tab click handler
        $nav.on('click', '.tqt-tab-btn', function () {
            var lang = $(this).data('lang');
            $nav.find('.tqt-tab-btn').removeClass('tqt-tab-active');
            $(this).addClass('tqt-tab-active');
            $panels.find('.tqt-tab-panel').removeClass('tqt-tab-panel-active');
            $panels.find('.tqt-tab-panel[data-lang="' + lang + '"]').addClass('tqt-tab-panel-active');
        });

        // Activate default language tab
        $nav.find('.tqt-tab-btn[data-lang="' + defaultLang + '"]').addClass('tqt-tab-active');
        $panels.find('.tqt-tab-panel[data-lang="' + defaultLang + '"]').addClass('tqt-tab-panel-active');

        // Copy from default handler
        $panels.on('click', '.tqt-copy-default', function () {
            var targetLang = $(this).data('lang');
            if (confirm('Copy all field values from ' + languages[defaultLang] + ' to ' + languages[targetLang] + '? This will overwrite existing values.')) {
                copyFieldValues(defaultLang, targetLang);
            }
        });

        // Update completion on field changes
        $panels.on('input change', 'input, textarea, select', function () {
            updateCompletionBar($panels, $completionBar, $nav);
        });

        // Initial completion update
        updateCompletionBar($panels, $completionBar, $nav);
    }

    /**
     * Move ACF repeater row fields into the matching language panel.
     */
    function distributeRowsToPanels($repeater, $panels) {
        $repeater.find('.acf-row:not(.acf-clone)').each(function () {
            var $row     = $(this);
            var langCode = $row.find('[data-name="tqt_lang_code"] input, [data-name="tqt_lang_code"] select').val();

            if (!langCode) return;

            var $panel = $panels.find('.tqt-tab-panel[data-lang="' + langCode + '"]');
            if ($panel.length) {
                // Clone the visible field elements into the panel for display
                // But keep originals in place for ACF to save properly
                var $fields = $row.find('.acf-field').not('[data-name="tqt_lang_code"]');

                $fields.each(function () {
                    var $field   = $(this);
                    var $cloned  = $('<div class="acf-field-display"></div>');
                    var fieldName = $field.data('name');
                    var $label   = $field.find('> .acf-label label').first().text();

                    $cloned.html(
                        '<label style="display:block;font-weight:600;margin-bottom:4px;">' + $label + '</label>' +
                        '<div class="acf-field-input-mirror" data-source-name="' + fieldName + '" data-source-row="' + $row.index() + '"></div>'
                    );
                    $panel.append($cloned);
                });

                // Mirror inputs: bind the real ACF inputs to visible copies
                mirrorFields($row, $panel);
            }
        });
    }

    /**
     * Create two-way binding between hidden ACF fields and visible tab fields.
     */
    function mirrorFields($row, $panel) {
        $row.find('.acf-field').not('[data-name="tqt_lang_code"]').each(function () {
            var $field = $(this);
            var name   = $field.data('name');
            var $input = $field.find('input, textarea, select').first();

            if (!$input.length) return;

            var $mirror = $panel.find('[data-source-name="' + name + '"]');
            if (!$mirror.length) return;

            // Create a matching input in the mirror
            var tagName = $input.prop('tagName').toLowerCase();
            var type    = $input.attr('type') || '';
            var $clone;

            if (tagName === 'textarea') {
                $clone = $('<textarea style="width:100%;min-height:80px;"></textarea>');
            } else if (tagName === 'select') {
                $clone = $input.clone();
            } else {
                $clone = $('<input type="' + type + '" style="width:100%;">');
            }

            $clone.val($input.val());
            $mirror.html($clone);

            // Two-way sync
            $clone.on('input change', function () {
                $input.val($(this).val()).trigger('change');
            });
            $input.on('input change', function () {
                $clone.val($(this).val());
            });
        });
    }

    /**
     * Ensure every enabled language has a repeater row.
     */
    function ensureAllLanguageRows($repeater, $panels) {
        var existingLangs = [];
        $repeater.find('.acf-row:not(.acf-clone)').each(function () {
            var val = $(this).find('[data-name="tqt_lang_code"] input, [data-name="tqt_lang_code"] select').val();
            if (val) existingLangs.push(val);
        });

        langCodes.forEach(function (code) {
            if (existingLangs.indexOf(code) === -1) {
                // Click the add row button to create a new repeater row
                var $addBtn = $repeater.find('.acf-actions .acf-button[data-event="add-row"]');
                if ($addBtn.length) {
                    $addBtn.trigger('click');

                    // Set the language code on the newly created row
                    var $newRow = $repeater.find('.acf-row:not(.acf-clone)').last();
                    $newRow.find('[data-name="tqt_lang_code"] input, [data-name="tqt_lang_code"] select').val(code).trigger('change');

                    // Distribute new row to panel
                    distributeRowsToPanels($repeater, $panels);
                }
            }
        });
    }

    /**
     * Copy field values from one language to another.
     */
    function copyFieldValues(fromLang, toLang) {
        var $fromPanel = $('.tqt-tab-panel[data-lang="' + fromLang + '"]');
        var $toPanel   = $('.tqt-tab-panel[data-lang="' + toLang + '"]');

        $fromPanel.find('input, textarea, select').each(function (i) {
            var $source = $(this);
            var $target = $toPanel.find('input, textarea, select').eq(i);
            if ($target.length) {
                $target.val($source.val()).trigger('input').trigger('change');
            }
        });
    }

    /**
     * Update the translation completion bar.
     */
    function updateCompletionBar($panels, $completionBar, $nav) {
        var filled = 0;
        var total  = langCodes.length;

        langCodes.forEach(function (code) {
            var $panel  = $panels.find('.tqt-tab-panel[data-lang="' + code + '"]');
            var $inputs = $panel.find('input[type="text"], textarea');
            var hasContent = false;

            $inputs.each(function () {
                if ($.trim($(this).val())) {
                    hasContent = true;
                    return false;
                }
            });

            // Update tab badge
            var $tab = $nav.find('.tqt-tab-btn[data-lang="' + code + '"]');
            $tab.find('.tqt-tab-badge-filled, .tqt-tab-badge-empty').remove();

            if (code !== defaultLang) {
                if (hasContent) {
                    $tab.append('<span class="tqt-tab-badge tqt-tab-badge-filled">✓</span>');
                } else {
                    $tab.append('<span class="tqt-tab-badge tqt-tab-badge-empty">✗</span>');
                }
            }

            if (hasContent) filled++;
        });

        var pct = Math.round((filled / total) * 100);
        var $fill = $completionBar.find('.tqt-completion-fill');

        $fill
            .css('width', pct + '%')
            .removeClass('tqt-completion-fill-complete tqt-completion-fill-partial tqt-completion-fill-empty');

        if (pct === 100) {
            $fill.addClass('tqt-completion-fill-complete');
        } else if (pct > 0) {
            $fill.addClass('tqt-completion-fill-partial');
        } else {
            $fill.addClass('tqt-completion-fill-empty');
        }

        $completionBar.find('.tqt-completion-text').text(filled + ' / ' + total);
    }

    // Initialize when ACF is ready
    if (typeof acf !== 'undefined') {
        acf.addAction('ready', initTabs);
    } else {
        $(document).ready(initTabs);
    }

})(jQuery);
