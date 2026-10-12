/**
 * TinyMCE 插件：插入 [at8_mluc_hide type="X"]...[/at8_mluc_hide] 短代码。
 *
 * 经典 API（addButton / addMenuItem）兼容 TinyMCE 4/6，WP 6+ 默认 TinyMCE 6。
 * 按钮渲染为下拉菜单，type 与 Free 内置类型（reply / logged）一一对应；
 * Pro 安装后会通过各自的按钮补充会员/付费两型。
 */
(function () {
    'use strict';

    if (typeof window.tinymce === 'undefined') {
        return;
    }

    /**
     * 类型与本地化文案来自服务器注入的 at8_mluc_tinymce_i18n；
     * 若未注入则使用兜底，避免编辑器完全空白。
     */
    var i18n = (window.at8_mluc_tinymce_i18n && typeof window.at8_mluc_tinymce_i18n === 'object')
        ? window.at8_mluc_tinymce_i18n
        : {};

    var TYPES = (Array.isArray(i18n.types) && i18n.types.length > 0)
        ? i18n.types
        : [
            { value: 'reply',  label: 'Comment to View' },
            { value: 'logged', label: 'Log in to View' }
        ];

    var BUTTON_LABEL = i18n.button_label || 'Hidden Content';

    tinymce.PluginManager.add('at8_mluc_hidecontent', function (editor) {
        var menu = TYPES.map(function (t) {
            return {
                text: t.label,
                onclick: function () {
                    insertShortcode(editor, t.value, t.label);
                }
            };
        });

        // 工具栏下拉按钮
        editor.addButton('at8_mluc_hidecontent', {
            title: BUTTON_LABEL,
            text: BUTTON_LABEL,
            type: 'menubutton',
            menu: menu
        });

        // 同时注册菜单项（部分主题/插件可能屏蔽工具栏，但仍可在「格式」下拉找到）
        editor.addMenuItem('at8_mluc_hidecontent', {
            text: BUTTON_LABEL,
            context: 'insert',
            menu: menu
        });
    });

    /**
     * 在编辑器光标处插入 [at8_mluc_hide type="X"]...[/at8_mluc_hide]。
     * 插入后光标自动落在短代码之后，便于继续输入。
     */
    function insertShortcode(editor, type, label) {
        var openTag  = '[at8_mluc_hide type="' + type + '"]';
        var closeTag = '[/at8_mluc_hide]';
        var placeholder = 'Enter content visible only to: ' + label + '…';
        var snippet = openTag + placeholder + closeTag;

        editor.focus();

        // 兼容 TinyMCE 4（WordPress 自带，无 collapseToEnd 方法）与 TinyMCE 6：
        // 安全地把选区折叠到末尾，再插入短代码。mceInsertContent 会把光标落在插入内容之后。
        if (typeof editor.selection.collapseToEnd === 'function') {
            editor.selection.collapseToEnd();
        } else if (typeof editor.selection.collapse === 'function') {
            editor.selection.collapse(false);
        }

        editor.execCommand('mceInsertContent', false, snippet);
    }
})();
