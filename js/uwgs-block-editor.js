/**
 * Block editor (Gutenberg) integration — Image Accessibility Plugin.
 *
 * Architecture:
 *
 *   1. Block canvas warning — an inline banner rendered inside each core/image
 *      block that lacks alt text (uses addFilter 'editor.BlockEdit'). Gives
 *      immediate, per-block feedback without blocking any save action.
 *
 *   2. Document sidebar panel — always-visible "Image Accessibility" panel in
 *      the Document tab sidebar (PluginDocumentSettingPanel). Shows green status
 *      when all images are covered, amber when any are missing. Non-disruptive:
 *      never blocks saves or interrupts the editor flow.
 *
 *   3. Pre-publish panel — expanded automatically in the publish sidebar when
 *      issues exist (PluginPrePublishPanel). Gives a final reminder before the
 *      post goes live.
 *
 *   4. Save notification — a dismissible wp.data notice when saving with issues.
 *      Fires once per save cycle via isSavingPost subscription.
 *
 * Save-flow note:
 *   Gutenberg saves go through wp.data / the REST API, NOT a traditional
 *   form#post submit event. The classic-editor form-submit intercept in
 *   uwgs-classic-presave.js does not apply here. Components (2) and (3) surface
 *   alt-text state reactively from the block editor store without blocking saves,
 *   preserving the Gutenberg UX contract.
 *
 * Data injected before this script (via wp_add_inline_script '...before'):
 *   window.uwgsBlockEditorData = { restUrl, restNonce, i18n: { … } }
 */
( function( wp, data ) {
    'use strict';

    if ( typeof wp === 'undefined' ) { return; }

    var i18n     = ( data && data.i18n ) || {};
    var el       = wp.element.createElement;
    var Fragment = wp.element.Fragment;

    var registerPlugin             = wp.plugins  ? wp.plugins.registerPlugin  : null;
    var PluginPrePublishPanel      = ( wp.editor  && wp.editor.PluginPrePublishPanel )
                                       ? wp.editor.PluginPrePublishPanel
                                       : ( wp.editPost ? wp.editPost.PluginPrePublishPanel : null );
    var PluginDocumentSettingPanel = wp.editPost  ? wp.editPost.PluginDocumentSettingPanel : null;
    var useSelect                  = wp.data      ? wp.data.useSelect  : null;
    var subscribe                  = wp.data      ? wp.data.subscribe  : null;
    var addFilter                  = wp.hooks     ? wp.hooks.addFilter : null;
    var createHOC                  = wp.compose   ? wp.compose.createHigherOrderComponent : null;

    // -------------------------------------------------------------------------
    // 1. Block canvas warning
    // Wraps core/image blocks; renders an amber banner below the block when
    // it has a URL but no alt text. Cleared immediately when the editor types.
    // -------------------------------------------------------------------------

    if ( addFilter && createHOC ) {
        var withAltWarning = createHOC( function( BlockEdit ) {
            return function( props ) {
                if ( props.name !== 'core/image' ) { return el( BlockEdit, props ); }
                var alt = ( props.attributes && props.attributes.alt ) || '';
                var url = ( props.attributes && props.attributes.url ) || '';
                var bannerStyle = {
                    display: 'flex', alignItems: 'center', gap: '8px',
                    margin: '4px 0 0', padding: '8px 12px',
                    background: '#fff3cd', borderLeft: '4px solid #ffc107',
                    color: '#856404', fontSize: '13px', lineHeight: '1.5',
                    borderRadius: '0 3px 3px 0',
                };
                return el( Fragment, null,
                    el( BlockEdit, props ),
                    ( url !== '' && alt.trim() === '' )
                        ? el( 'div', {
                              style: bannerStyle,
                              role: 'alert',
                              'aria-live': 'polite',
                              className: 'uwgs-block-alt-warning',
                          },
                            el( 'span', { 'aria-hidden': 'true' }, '⚠' ),
                            el( 'span', null, i18n.canvasBanner || 'Missing alt text — add it in the sidebar.' )
                          )
                        : null
                );
            };
        }, 'withAltWarning' );
        addFilter( 'editor.BlockEdit', 'uwgs-alt-text-tool/with-alt-warning', withAltWarning );
    }

    if ( ! registerPlugin || ! useSelect || ! subscribe ) { return; }

    // -------------------------------------------------------------------------
    // Shared: recursive image-block alt-text scan
    // -------------------------------------------------------------------------

    function hasImageBlocksMissingAlt( blocks ) {
        if ( ! blocks || ! blocks.length ) { return false; }
        for ( var i = 0; i < blocks.length; i++ ) {
            var block = blocks[ i ];
            if ( block.name === 'core/image' ) {
                var alt = ( block.attributes && block.attributes.alt ) ? block.attributes.alt.trim() : '';
                if ( alt === '' ) { return true; }
            }
            if ( block.innerBlocks && block.innerBlocks.length ) {
                if ( hasImageBlocksMissingAlt( block.innerBlocks ) ) { return true; }
            }
        }
        return false;
    }

    // One-shot imperative check for use in subscribe() callbacks (which cannot
    // call hooks). Returns true if any image content or featured image is missing.
    function checkAltIssues() {
        var blockEditorStore = wp.data.select( 'core/block-editor' );
        if ( ! blockEditorStore ) { return false; }
        if ( hasImageBlocksMissingAlt( blockEditorStore.getBlocks() ) ) { return true; }
        var featuredId = wp.data.select( 'core/editor' ).getEditedPostAttribute( 'featured_media' );
        if ( featuredId && featuredId > 0 ) {
            var media = wp.data.select( 'core' ).getMedia( featuredId, { context: 'edit' } );
            if ( media && ( media.alt_text || '' ).trim() === '' ) { return true; }
        }
        return false;
    }

    // -------------------------------------------------------------------------
    // 4. Save notification (wp.data subscriber)
    // Fires a single dismissible warning notice once per save cycle when issues
    // exist. wasSaving/hasShownAltWarning prevent notice duplication across
    // multiple store ticks within the same save.
    // -------------------------------------------------------------------------

    var wasSaving = false, hasShownAltWarning = false;
    subscribe( function() {
        var editorSelect = wp.data.select( 'core/editor' );
        if ( ! editorSelect ) { return; }
        var isSaving     = editorSelect.isSavingPost();
        var isAutosaving = editorSelect.isAutosavingPost();
        if ( isSaving && ! isAutosaving && ! wasSaving ) {
            wasSaving = true;
            if ( checkAltIssues() && ! hasShownAltWarning ) {
                hasShownAltWarning = true;
                var noticesDispatch = wp.data.dispatch( 'core/notices' );
                if ( noticesDispatch && noticesDispatch.createNotice ) {
                    noticesDispatch.createNotice(
                        'warning',
                        i18n.draftWarning || 'Some images are missing alt text. You can fix this before publishing.',
                        { id: 'uwgs-alt-text-draft-warning', isDismissible: true }
                    );
                }
            }
        }
        if ( ! isSaving && wasSaving ) { wasSaving = false; hasShownAltWarning = false; }
    } );

    // Auto-expand pre-publish panel when issues exist.
    subscribe( function() {
        var editorStore = wp.data.select( 'core/edit-post' ) || wp.data.select( 'core/editor' );
        if ( ! editorStore ) { return; }
        var sidebarOpen = editorStore.isPublishSidebarOpened ? editorStore.isPublishSidebarOpened() : false;
        if ( ! sidebarOpen ) { return; }
        if ( checkAltIssues() ) {
            var editPostDispatch = wp.data.dispatch( 'core/edit-post' );
            if ( editPostDispatch && editPostDispatch.toggleEditorPanelOpened ) {
                var panelId = 'uwgs-alt-text-panel/uwgs-alt-text-panel';
                var isOpen  = editorStore.isEditorPanelOpened ? editorStore.isEditorPanelOpened( panelId ) : false;
                if ( ! isOpen ) { editPostDispatch.toggleEditorPanelOpened( panelId ); }
            }
        }
    } );

    // -------------------------------------------------------------------------
    // Shared React hook — computes accessibility status once per render.
    // Called by both the sidebar panel (2) and pre-publish panel (3).
    // -------------------------------------------------------------------------

    function useAltStatus() {
        return useSelect( function( select ) {
            var blockStore      = select( 'core/block-editor' );
            var contentMissing  = blockStore ? hasImageBlocksMissingAlt( blockStore.getBlocks() ) : false;
            var featuredId      = select( 'core/editor' ).getEditedPostAttribute( 'featured_media' );
            var featuredMissing = false;
            if ( featuredId && featuredId > 0 ) {
                var media = select( 'core' ).getMedia( featuredId, { context: 'edit' } );
                featuredMissing = media ? ( media.alt_text || '' ).trim() === '' : false;
            }
            var hasIssues = contentMissing || featuredMissing;
            var message   = ( contentMissing && featuredMissing ) ? i18n.warningBoth
                          : featuredMissing                       ? i18n.warningFeatured
                          :                                         i18n.warningContent;
            return {
                contentMissing:  contentMissing,
                featuredMissing: featuredMissing,
                hasIssues:       hasIssues,
                message:         message,
            };
        } );
    }

    // -------------------------------------------------------------------------
    // 2. Document sidebar panel — always visible in the Document tab
    // -------------------------------------------------------------------------

    function UWGSAltStatusPanel() {
        var status = useAltStatus();
        if ( ! PluginDocumentSettingPanel ) { return null; }
        return el( PluginDocumentSettingPanel, {
            name:      'uwgs-alt-status',
            title:     i18n.panelTitle || 'Image Accessibility',
            className: status.hasIssues ? 'uwgs-sidebar-warning' : 'uwgs-sidebar-ok',
        },
            status.hasIssues
                ? el( Fragment, null,
                    el( 'p', { style: { margin: '0 0 8px', color: '#856404', fontSize: '13px', lineHeight: '1.6' } },
                        status.message
                    ),
                    el( 'p', { style: { margin: '0', fontSize: '12px', color: '#555', fontStyle: 'italic' } },
                        i18n.decorativeNote
                    )
                  )
                : el( 'p', { style: { margin: '0', color: '#2e7d32', fontSize: '13px' } },
                    i18n.allGood
                  )
        );
    }

    // -------------------------------------------------------------------------
    // 3. Pre-publish panel — shown in the publish sidebar, auto-expands on issues
    // -------------------------------------------------------------------------

    function UWGSAltTextPanel() {
        var status = useAltStatus();
        if ( ! PluginPrePublishPanel ) { return null; }
        return el( PluginPrePublishPanel, {
            name:        'uwgs-alt-text-panel',
            title:       i18n.panelTitle || 'Image Accessibility',
            initialOpen: status.hasIssues,
            className:   status.hasIssues ? 'uwgs-prepublish-warning' : 'uwgs-prepublish-ok',
        },
            status.hasIssues
                ? el( Fragment, null,
                    el( 'p', { style: { margin: '0 0 10px', color: '#856404', fontSize: '13px', lineHeight: '1.6' } },
                        status.message
                    ),
                    el( 'p', { style: { margin: '0', fontSize: '12px', color: '#555', fontStyle: 'italic' } },
                        i18n.decorativeNote
                    )
                  )
                : el( 'p', { style: { margin: '0', color: '#2e7d32', fontSize: '13px' } },
                    i18n.allGood
                  )
        );
    }

    registerPlugin( 'uwgs-alt-text-tool', {
        render: function() {
            return el( Fragment, null,
                el( UWGSAltStatusPanel, null ),
                el( UWGSAltTextPanel,   null )
            );
        },
    } );

} )( window.wp, ( typeof uwgsBlockEditorData !== 'undefined' ? uwgsBlockEditorData : {} ) );
