"use strict";
window.cffmetatrans = false;
(function () {
    var _wp = wp,
        _wp$serverSideRender = _wp.serverSideRender,
        createElement = wp.element.createElement,
        ServerSideRender = _wp$serverSideRender === void 0 ? wp.components.ServerSideRender : _wp$serverSideRender,
        _ref = wp.blockEditor || wp.editor,
        InspectorControls = _ref.InspectorControls,
        useBlockProps = _ref.useBlockProps,
        _wp$components = wp.components,
        TextareaControl = _wp$components.TextareaControl,
        Button = _wp$components.Button,
        PanelBody = _wp$components.PanelBody,
        Placeholder = _wp$components.Placeholder,
        registerBlockType = wp.blocks.registerBlockType;

    // Locate the WP 7.0 block editor canvas iframe. Returns null if the
    // iframe doesn't exist yet or its contentDocument isn't reachable.
    function getEditorIframe() {
        var selectors = [
            'iframe[name="editor-canvas"]',
            'iframe.edit-post-visual-editor__content-area',
            'iframe.editor-canvas'
        ];
        for (var i = 0; i < selectors.length; i++) {
            var el = document.querySelector(selectors[i]);
            if (el && el.contentDocument && el.contentDocument.head) {
                return el;
            }
        }
        return null;
    }

    // Inject cff-scripts.min.js (and jQuery if needed) into the editor
    // iframe's <head>, so window.cff_init exists inside the iframe and can
    // find the feed DOM that ServerSideRender mounts there.
    var iframeAssetsPromise = null;
    function ensureIframeFeedAssets() {
        if (iframeAssetsPromise) {
            return iframeAssetsPromise;
        }
        iframeAssetsPromise = new Promise(function (resolve, reject) {
            var attempts = 0;
            var tryInject = function () {
                attempts++;
                var iframe = getEditorIframe();
                if (!iframe) {
                    if (attempts > 40) {
                        reject(new Error('cff: editor iframe not found'));
                        return;
                    }
                    setTimeout(tryInject, 250);
                    return;
                }
                var doc = iframe.contentDocument;
                if (doc.documentElement.getAttribute('data-cff-feed-assets-injected')) {
                    resolve(iframe);
                    return;
                }
                doc.documentElement.setAttribute('data-cff-feed-assets-injected', '1');

                var loadScript = function (src) {
                    return new Promise(function (res, rej) {
                        var s = doc.createElement('script');
                        s.src = src;
                        s.onload = function () { res(); };
                        s.onerror = function () { rej(new Error('cff: failed to load ' + src)); };
                        doc.head.appendChild(s);
                    });
                };

                var chain = Promise.resolve();
                if (!iframe.contentWindow.jQuery && cff_block_editor.jqueryUrl) {
                    chain = chain.then(function () { return loadScript(cff_block_editor.jqueryUrl); });
                }
                if (cff_block_editor.iframeScriptUrl) {
                    chain = chain.then(function () { return loadScript(cff_block_editor.iframeScriptUrl); });
                }
                chain.then(function () { resolve(iframe); }, reject);
            };
            tryInject();
        });
        return iframeAssetsPromise;
    }

    // Call cff_init() inside the iframe (WP 7.0+) or in the outer scope as a
    // fallback for pre-iframe editors.
    function triggerCffInit() {
        var iframe = getEditorIframe();
        if (iframe && iframe.contentWindow && typeof iframe.contentWindow.cff_init === 'function') {
            try { iframe.contentWindow.cff_init(); } catch (e) {}
            return;
        }
        if (!iframe && typeof cff_init !== 'undefined') {
            try { cff_init(); } catch (e) {}
        }
    }

    var cffIcon = createElement('svg', {
        width: 20,
        height: 20,
        viewBox: '0 0 448 512',
        className: 'dashicon'
    }, createElement('path', {
        fill: 'currentColor',
        d: 'M400 32H48A48 48 0 0 0 0 80v352a48 48 0 0 0 48 48h137.25V327.69h-63V256h63v-54.64c0-62.15 37-96.48 93.67-96.48 27.14 0 55.52 4.84 55.52 4.84v61h-31.27c-30.81 0-40.42 19.12-40.42 38.73V256h68.78l-11 71.69h-57.78V480H400a48 48 0 0 0 48-48V80a48 48 0 0 0-48-48z'
    }));

    registerBlockType('cff/cff-feed-block', {
        apiVersion: 3,
        title: 'Custom Facebook Feed (Deprecated)',
        icon: cffIcon,
        category: 'widgets',
        attributes: {
            noNewChanges: {
                type: 'boolean',
            },
            shortcodeSettings: {
                type: 'string',
            },
            executed: {
                type: 'boolean'
            }
        },
        edit: function edit(props) {
            var blockProps = typeof useBlockProps === 'function' ? useBlockProps() : {};
            var _props = props,
                setAttributes = _props.setAttributes,
                _props$attributes = _props.attributes,
                _props$attributes$sho = _props$attributes.shortcodeSettings,
                shortcodeSettings = _props$attributes$sho === void 0 ? cff_block_editor.shortcodeSettings : _props$attributes$sho,
                _props$attributes$cli = _props$attributes.noNewChanges,
                noNewChanges = _props$attributes$cli === void 0 ? true : _props$attributes$cli;

            props.attributes.shortcodeSettings = shortcodeSettings;

            function setState(shortcodeSettingsContent) {
                setAttributes({
                    noNewChanges: false,
                    shortcodeSettings: shortcodeSettingsContent
                })
            }

            function previewClick(content) {
                setAttributes({
                    noNewChanges: true,
                })
            }

            function cffGutenbergSizeVisualHeader() {
                jQuery('.cff-visual-header.cff-has-cover').each(function() {
                    var wrapperHeight = jQuery(this).find('.cff-header-hero').innerHeight(),
                        imageHeight = jQuery(this).find('.cff-header-hero img').innerHeight(),
                        wrapperWidth = jQuery(this).find('.cff-header-hero').innerWidth(),
                        imageWidth = jQuery(this).find('.cff-header-hero img').innerWidth(),
                        wrapperAspect = wrapperWidth/wrapperHeight,
                        imageAspect = imageWidth/imageHeight,
                        width = wrapperAspect < imageAspect ? wrapperHeight * imageAspect + 'px' : '100%',
                        difference = imageHeight - wrapperHeight,
                        topMargin = Math.max(0,Math.round(difference/2)),
                        leftMargin = width !== '100%' ? Math.max(0,Math.round(((wrapperHeight * imageAspect)-wrapperWidth)/2)) : 0;
                    jQuery(this).find('.cff-header-hero img').css({
                        'opacity' : 1,
                        'display' : 'block',
                        'visibility' : 'visible',
                        'max-width' : 'none',
                        'max-height' : 'none',
                        'margin-top' : - topMargin + 'px',
                        'margin-left' : - leftMargin + 'px',
                        'width' : width,
                    });
                });
            }

            function afterRender() {
                // Inject cff-scripts into the WP 7.0 iframe (no-op once injected),
                // then poll-trigger cff_init in iframe scope. ServerSideRender
                // doesn't expose an onload callback, so we retry on intervals.
                ensureIframeFeedAssets().catch(function () {});
                setTimeout(triggerCffInit, 1000);
                setTimeout(triggerCffInit, 2000);
                setTimeout(triggerCffInit, 3000);
                setTimeout(triggerCffInit, 5000);
                setTimeout(triggerCffInit, 10000);
                jQuery(window).resize(function () {
                    setTimeout(function(){
                        cffGutenbergSizeVisualHeader();
                    }, 500);
                });
                var executed = false;
                // no way to run a script after AJAX call to get feed so we just try to execute it on a few intervals
                setTimeout(function() { if (typeof cffGutenbergSizeVisualHeader !== 'undefined' && !executed) {cffGutenbergSizeVisualHeader();}},1000);
                setTimeout(function() { if (typeof cffGutenbergSizeVisualHeader !== 'undefined' && !executed) {cffGutenbergSizeVisualHeader();}},2000);
                setTimeout(function() { if (typeof cffGutenbergSizeVisualHeader !== 'undefined' && !executed) {cffGutenbergSizeVisualHeader();}},3000);
                setTimeout(function() { if (typeof cffGutenbergSizeVisualHeader !== 'undefined' && !executed) {cffGutenbergSizeVisualHeader();}},5000);
                setTimeout(function() { if (typeof cffGutenbergSizeVisualHeader !== 'undefined' && !executed) {cffGutenbergSizeVisualHeader();}},10000);
            }

            var jsx = [React.createElement(InspectorControls, {
                key: "cff-gutenberg-setting-selector-inspector-controls"
            }, React.createElement(PanelBody, {
                title: cff_block_editor.i18n.addSettings
            }, React.createElement(TextareaControl, {
                key: "cff-gutenberg-settings",
                className: "cff-gutenberg-settings",
                label: cff_block_editor.i18n.shortcodeSettings,
                help: cff_block_editor.i18n.example + ": 'feed=\"1\"'",
                value: shortcodeSettings,
                onChange: setState
            }), React.createElement(Button, {
                key: "cff-gutenberg-preview",
                className: "cff-gutenberg-preview",
                onClick: previewClick,
                isDefault: true
            }, cff_block_editor.i18n.preview)))];

            if (noNewChanges) {
                afterRender();
                jsx.push(React.createElement(ServerSideRender, {
                    key: "custom-facebook-feeds/custom-facebook-feeds",
                    block: "cff/cff-feed-block",
                    attributes: props.attributes,
                }));
            } else {
                props.attributes.noNewChanges = false;
                jsx.push(React.createElement(Placeholder, {
                    key: "cff-gutenberg-setting-selector-select-wrap",
                    className: "cff-gutenberg-setting-selector-select-wrap"
                }, React.createElement(Button, {
                    key: "cff-gutenberg-preview",
                    className: "cff-gutenberg-preview",
                    onClick: previewClick,
                    isDefault: true
                }, cff_block_editor.i18n.preview)));
            }

            return createElement('div', blockProps, jsx);
        },
        save: function save() {
            return null;
        }
    });
})();