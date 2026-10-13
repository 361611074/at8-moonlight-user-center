/**
 * AT8 Moonlight User Center — admin settings screen interactions.
 *
 * - Wraps every settings section in a card and builds the sticky side
 *   navigation from the section titles (progressive enhancement: the plain
 *   Settings API markup stays intact without JS).
 * - Membership level editor: add / remove rows without a page reload.
 * - Sidebar icon editor: Dashicon class or media-library image per tab.
 * - Upgrade comparison panel: collapse / dismiss without a reload.
 */
(function ($) {
    'use strict';

    var mediaFrame = null;

    function ensureFrame($btn) {
        if (mediaFrame) {
            return mediaFrame;
        }
        mediaFrame = wp.media({
            title: $btn.data('frameTitle') || (window.at8MlucAdmin && window.at8MlucAdmin.frameTitle) || 'Select or upload an icon',
            button: { text: (window.at8MlucAdmin && window.at8MlucAdmin.useIcon) || 'Use this icon' },
            library: { type: 'image' },
            multiple: false
        });
        mediaFrame.on('select', function () {
            var attachment = mediaFrame.state().get('selection').first();
            if (!attachment) {
                return;
            }
            var id = attachment.id;
            var url = attachment.get('url') || '';
            var sizes = attachment.get('sizes');
            if (sizes) {
                if (sizes.thumbnail) {
                    url = sizes.thumbnail.url;
                } else if (sizes.medium) {
                    url = sizes.medium.url;
                }
            }
            var $row = $btn.closest('tr');
            $row.find('.at8-mluc-icon-image-id').val(id);
            $row.find('.at8-mluc-icon-image-preview').empty().append($('<img>').attr('src', url).attr('alt', ''));
            var $big = $row.find('[data-at8-mluc-icon-preview]');
            if ($big.length) {
                $big.removeClass('dashicons').attr('class', 'at8-mluc-preview-img').empty().append($('<img>').attr('src', url).attr('alt', ''));
            }
        });
        return mediaFrame;
    }

    function setType($row, type) {
        var $dash = $row.find('.at8-mluc-icon-input');
        var $img = $row.find('.at8-mluc-icon-image');
        if ('image' === type) {
            $dash.attr('hidden', 'hidden');
            $img.removeAttr('hidden');
        } else {
            $img.attr('hidden', 'hidden');
            $dash.removeAttr('hidden');
        }
    }

    $(function () {
        /* ===== Upgrade comparison panel: collapse / dismiss ===== */
        var $upgrade = $('#at8-mluc-upgrade');

        if ($upgrade.length) {
            $upgrade.on('click', '.at8-mluc-upgrade-dismiss', function () {
                var $panel = $upgrade;
                if ($panel.hasClass('is-collapsed')) {
                    // Already open: this click hides the panel for good.
                    hideUpgrade();
                    return;
                }
                $panel.addClass('is-collapsed');
                $(this).addClass('at8-mluc-upgrade-dismiss-collapsed').attr('aria-expanded', 'false');
            });

            $upgrade.on('click', '[data-at8-mluc-upgrade-hide]', function () {
                hideUpgrade();
            });

            function hideUpgrade() {
                var data = (window.at8MlucAdmin && window.at8MlucAdmin.ajax) || {};
                $upgrade.slideUp(160, function () {
                    $upgrade.remove();
                });
                if (!data.url || !data.nonce) {
                    return;
                }
                $.post(data.url, {
                    action: 'at8_mluc_dismiss_upgrade',
                    nonce: data.nonce
                });
            }
        }

        /* ===== Sidebar icon editor ===== */
        $(document).on('change', '.at8-mluc-icon-type input[type="radio"]', function () {
            setType($(this).closest('tr'), $(this).val());
        });

        $(document).on('input', '.at8-mluc-icon-input', function () {
            var $row = $(this).closest('tr');
            if (!$row.find('.at8-mluc-icon-type input[value="dashicon"]:checked').length) {
                return;
            }
            var $preview = $row.find('[data-at8-mluc-icon-preview]');
            if ($preview.length) {
                $preview.attr('class', 'dashicons ' + ($.trim($(this).val()) || 'dashicons-minus'));
            }
        });

        $(document).on('click', '.at8-mluc-icon-upload', function (e) {
            e.preventDefault();
            ensureFrame($(this)).open();
        });

        $(document).on('click', '.at8-mluc-icon-remove', function (e) {
            e.preventDefault();
            var $row = $(this).closest('tr');
            $row.find('.at8-mluc-icon-image-id').val(0);
            $row.find('.at8-mluc-icon-image-preview').empty();
            var $big = $row.find('[data-at8-mluc-icon-preview]');
            if ($big.length) {
                $big.removeClass('at8-mluc-preview-img').empty().attr('class', 'dashicons dashicons-minus');
            }
        });

        // Initial radio state (no JS round-trip needed on load).
        $('.at8-mluc-icon-type').each(function () {
            var $row = $(this).closest('tr');
            setType($row, $row.find('input[type="radio"]:checked').val());
        });

        /* ===== Membership level editor ===== */
        var $addBtn = $('#at8-mluc-add-level');
        var $tbody = $('#at8-mluc-levels-tbody');
        var $tpl = $('#at8-mluc-level-row-tpl');

        if ($addBtn.length && $tbody.length && $tpl.length) {
            var counter = 0;
            $addBtn.on('click', function (e) {
                e.preventDefault();
                counter++;
                var newKey = '__new__' + counter + '__';
                var row = $tpl[0].content.querySelector('tr').cloneNode(true);
                row.querySelectorAll('[data-name]').forEach(function (el) {
                    el.setAttribute('name', el.getAttribute('data-name').replace('__NEWKEY__', newKey));
                    el.removeAttribute('data-name');
                });
                $tbody.append(row);
            });

            $tbody.on('click', '.at8-mluc-del-level', function (e) {
                e.preventDefault();
                $(this).closest('tr').remove();
            });
        }

        /* ===== Custom field editor ===== */
        var $editor = $('#at8-mluc-fields-editor');

        if ($editor.length) {
            var $rows = $editor.find('.at8-mluc-fields-rows');
            var $tplRow = $editor.find('[data-at8-mluc-fields-template]')[0];
            var rowSeq = $rows.children().length;
            var $status = $editor.find('.at8-mluc-fields-status');

            $editor.on('click', '[data-at8-mluc-fields-add]', function (e) {
                e.preventDefault();
                if (!$tplRow || !$tplRow.content) {
                    return;
                }
                rowSeq++;
                var row = $tplRow.content.querySelector('[data-at8-mluc-field-row]').cloneNode(true);
                row.setAttribute('data-at8-mluc-field-row', 'new-' + rowSeq);
                $rows.append(row);
            });

            $editor.on('click', '[data-at8-mluc-fields-remove]', function (e) {
                e.preventDefault();
                $(this).closest('[data-at8-mluc-field-row]').remove();
            });

            $editor.on('click', '[data-at8-mluc-fields-save]', function (e) {
                e.preventDefault();

                var data = (window.at8MlucAdmin && window.at8MlucAdmin.ajax) || {};
                if (!data.url || !data.nonce) {
                    return;
                }

                var fields = [];
                $rows.children('[data-at8-mluc-field-row]').each(function () {
                    var $row = $(this);
                    var entry = {};

                    $row.find('[data-field]').each(function () {
                        var $input = $(this);
                        var key = $input.data('field');
                        entry[key] = $input.is(':checkbox') ? ($input.is(':checked') ? '1' : '') : $input.val();
                    });

                    if (!$.trim(entry.label || '')) {
                        return;
                    }

                    if (!$.trim(entry.id || '')) {
                        entry.id = slugify(entry.label);
                    }

                    fields.push(entry);
                });

                var $btn = $(this);
                $btn.prop('disabled', true);
                $status.text((window.at8MlucAdmin && window.at8MlucAdmin.saving) || 'Saving…');

                $.post(data.url, {
                    action: 'at8_mluc_save_fields',
                    nonce: data.nonce,
                    fields: fields
                }, function (res) {
                    $btn.prop('disabled', false);
                    $status.text(res && res.success
                        ? ((window.at8MlucAdmin && window.at8MlucAdmin.saved) || 'Saved.')
                        : (res && res.data && res.data.message) || 'Error.');
                }).fail(function () {
                    $btn.prop('disabled', false);
                    $status.text('Error.');
                });
            });

            function slugify(text) {
                return String(text).toLowerCase()
                    .replace(/[^a-z0-9]+/g, '_')
                    .replace(/^_+|_+$/g, '')
                    .substring(0, 40) || 'field';
            }
        }

        /* ===== Settings screen beautify: cards + sticky nav ===== */
        var $wrap = $('.at8-mluc-settings-wrap');
        if (!$wrap.length || $wrap.data('at8-beautified')) {
            return;
        }
        $wrap.data('at8-beautified', 1);

        var $form = $wrap.children('form').eq(0);
        if (!$form.length) {
            return;
        }

        var navTitle = (window.at8MlucAdmin && window.at8MlucAdmin.navTitle) || 'Sections';
        var $nav = $('<nav>', { 'class': 'at8-mluc-settings-nav' })
            .append('<div class="at8-mluc-settings-nav-title">' + navTitle + '</div>')
            .append('<ul class="at8-mluc-settings-nav-list"></ul>');
        var $list = $nav.find('ul');

        var idx = 0;
        $form.children('h2').each(function () {
            idx++;
            var $h = $(this);
            var id = 'at8-mluc-sec-' + idx;
            $h.attr('id', id).addClass('at8-mluc-card-title');
            var $card = $('<section>', { 'class': 'at8-mluc-card' });
            $h.nextUntil('h2').addBack().wrapAll($card);
            var label = $.trim($h.text());
            $('<li>').append($('<a>', { href: '#' + id, text: label })).appendTo($list);
        });

        $wrap.addClass('at8-mluc-settings-layout');
        $form.before($nav);

        var $links = $list.find('a');

        function onScroll() {
            var pos = window.scrollY + 120;
            var current = $links.first();
            $form.children('.at8-mluc-card').each(function () {
                if ($(this).offset().top <= pos) {
                    var cid = $(this).children('.at8-mluc-card-title').attr('id');
                    if (cid) {
                        current = $links.filter('[href="#' + cid + '"]');
                    }
                }
            });
            $links.removeClass('active');
            current.addClass('active');
        }

        $(window).on('scroll', onScroll).trigger('scroll');
    });
})(jQuery);
