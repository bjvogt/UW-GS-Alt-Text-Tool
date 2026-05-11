/**
 * Attachment Details — Image Accessibility Plugin.
 *
 * Enhances wp.media.view.Attachment.Details (media modal + upload.php grid) with:
 *
 *   1. Inline warning — amber banner below the alt field when alt is blank or weak.
 *      Cleared when the user starts typing or accepts a suggestion.
 *
 *   2. "Use suggestion" button — when alt is blank, offers a caption- or filename-
 *      derived suggestion. The user clicks to fill the field; this triggers WP's
 *      auto-save-on-blur via a synthetic 'change' event (no explicit Save button).
 *
 *   3. Navigation warning — amber inline banner when the user clicks prev/next
 *      (TwoColumn layout) while the current attachment has blank alt. Two buttons:
 *      "Continue anyway" and "Add alt text".
 *
 *   4. Close warning — same inline banner when the user tries to close the Add
 *      Media modal (MediaFrame.Post) while the visible attachment has blank alt.
 *      A second close click proceeds (two-click bypass via frame._uwgsCloseWarned).
 *
 * Data injected before this script (via wp_add_inline_script '...before'):
 *   window.uwgsDetailsData = { i18n: { … } }
 */
( function( wp ) {
    'use strict';

    if ( typeof wp === 'undefined' || ! wp.media || ! wp.media.view ) { return; }

    var Utils = window.UWGSAltUtils;
    if ( ! Utils ) { return; }

    var $    = window.jQuery;
    var data = ( typeof uwgsDetailsData !== 'undefined' ) ? uwgsDetailsData : {};
    var i18n = data.i18n || {};

    // -------------------------------------------------------------------------
    // Suggestion helper — prefers caption, falls back to filename.
    // Returns { text, source } or null.
    // -------------------------------------------------------------------------

    function getSuggestion( model ) {
        var caption = ( model.get( 'caption' ) || '' ).trim();
        if ( caption ) { return { text: caption, source: 'caption' }; }

        var filename = ( model.get( 'filename' ) || '' ).trim();
        if ( filename ) {
            var sanitized = Utils.sanitizeFilename( filename );
            if ( sanitized && Utils.classifyFilename( filename ) !== 'invalid' ) {
                return { text: sanitized, source: 'filename' };
            }
        }
        return null;
    }

    // -------------------------------------------------------------------------
    // Inject inline warning and suggestion button into a Details view.
    // Safe to call repeatedly — removes any prior injection first.
    // Called in a setTimeout(0) after the original render so WP's own
    // post-render processing completes before we append.
    // -------------------------------------------------------------------------

    function injectDetailsUI( view ) {
        if ( ! view || ! view.$el || ! view.model ) { return; }
        if ( view.model.get( 'type' ) !== 'image' ) { return; }

        view.$el.find( '.uwgs-details-warning, .uwgs-details-suggestion' ).remove();

        var alt = ( view.model.get( 'alt' ) || '' ).trim();
        var needsAttention = ( view.model.get( 'uwgsNeedsAttention' ) !== undefined )
            ? view.model.get( 'uwgsNeedsAttention' )
            : Utils.needsAttention( alt );

        if ( ! needsAttention ) { return; }

        var $altSetting = view.$el.find( '[data-setting="alt"]' );
        if ( ! $altSetting.length ) { return; }

        var $altInput = $altSetting.find( 'textarea, input' ).first();
        if ( ! $altInput.length ) { return; }

        // Inline warning banner
        var warnMsg = ( alt === '' )
            ? ( i18n.blankWarning  || 'This image has no alt text. Please add a description.' )
            : ( i18n.weakWarning   || 'Alt text may need improvement. Please review the description.' );

        var $warning = $( '<p>' )
            .addClass( 'uwgs-details-warning' )
            .attr( 'role', 'alert' )
            .text( warnMsg );

        $altSetting.append( $warning );

        // "Use suggestion" button — only when alt is blank and a suggestion exists
        if ( alt === '' ) {
            var suggestion = getSuggestion( view.model );
            if ( suggestion ) {
                var label = ( suggestion.source === 'caption' )
                    ? ( i18n.useSuggestionCaption  || 'Use caption as alt text' )
                    : ( i18n.useSuggestionFilename || 'Use filename as alt text' );

                var $btn = $( '<button type="button" class="button button-small uwgs-details-suggestion">' )
                    .text( label + ': “' + suggestion.text + '”' );

                $btn.on( 'click', function() {
                    $altInput.val( suggestion.text );
                    // Synthetic 'change' triggers WP's updateSetting → model sync → auto-save on blur.
                    $altInput.trigger( 'change' );
                    $btn.remove();
                    $warning.text( i18n.suggestionApplied || 'Suggestion applied — click elsewhere to save.' );
                } );

                // Clear warning when user starts typing independently
                $altInput.one( 'input', function() { $warning.remove(); $btn.remove(); } );

                $altSetting.append( $btn );
            }
        }
    }

    // -------------------------------------------------------------------------
    // 1. Patch Attachment.Details.prototype.render
    // -------------------------------------------------------------------------

    var Details = wp.media.view.Attachment && wp.media.view.Attachment.Details;
    if ( Details ) {
        var _originalRender = Details.prototype.render;
        Details.prototype.render = function() {
            _originalRender.apply( this, arguments );
            var self = this;
            setTimeout( function() { injectDetailsUI( self ); }, 0 );
            return this;
        };
    }

    // -------------------------------------------------------------------------
    // Shared helpers for navigation and close warnings
    // -------------------------------------------------------------------------

    function altIsBlankForView( view ) {
        if ( ! view || ! view.model ) { return false; }
        if ( view.model.get( 'type' ) !== 'image' ) { return false; }
        return Utils.needsAttention( view.model.get( 'alt' ) || '' );
    }

    function altIsBlankInModal( $modalEl ) {
        var $altInput = $modalEl.find( '[data-setting="alt"] textarea, [data-setting="alt"] input' ).first();
        if ( ! $altInput.length ) { return false; }
        var altEl = $modalEl.find( '.attachment-details' );
        if ( ! altEl.length ) { return false; }
        return Utils.needsAttention( $altInput.val() || '' );
    }

    function showInlineWarning( $container, warnMsg, proceedLabel, proceedCb, stayLabel ) {
        $container.find( '.uwgs-nav-warning, .uwgs-close-warning' ).remove();

        var $w = $( '<div>' ).css( {
            padding: '8px 12px', marginBottom: '8px',
            background: '#fff3cd', borderLeft: '4px solid #ffc107',
            color: '#856404', fontSize: '12px', lineHeight: '1.5',
            borderRadius: '0 3px 3px 0',
        } ).attr( 'role', 'alert' );

        var $msg  = $( '<span>' ).text( warnMsg + '  ' );
        var $go   = $( '<button type="button" class="button button-small">' ).text( proceedLabel );
        var $stay = $( '<button type="button" class="button button-small">' )
            .css( 'marginLeft', '6px' )
            .text( stayLabel || ( i18n.addAlt || 'Add alt text' ) );

        $go.on(   'click', function() { $w.remove(); if ( proceedCb ) { proceedCb(); } } );
        $stay.on( 'click', function() { $w.remove(); } );

        $w.append( $msg ).append( $go ).append( $stay );
        $container.prepend( $w );
    }

    // -------------------------------------------------------------------------
    // 2. Patch TwoColumn prev/next for navigation warning
    // -------------------------------------------------------------------------

    var TwoColumn = Details && Details.TwoColumn;
    if ( TwoColumn ) {
        [ 'previousMediaItem', 'nextMediaItem' ].forEach( function( method ) {
            var _original = TwoColumn.prototype[ method ];
            if ( ! _original ) { return; }

            TwoColumn.prototype[ method ] = function() {
                var self = this, args = arguments;
                if ( altIsBlankForView( this ) ) {
                    var $panel = this.$el.find( '.attachment-details' );
                    if ( ! $panel.length ) { $panel = this.$el; }
                    showInlineWarning(
                        $panel,
                        i18n.navWarning  || 'This image still has no alt text.',
                        i18n.navProceed  || 'Continue anyway',
                        function() { _original.apply( self, args ); },
                        i18n.navStay     || 'Add alt text'
                    );
                } else {
                    _original.apply( this, args );
                }
            };
        } );
    }

    // -------------------------------------------------------------------------
    // 3. Patch MediaFrame.Post.prototype.close for close warning
    // -------------------------------------------------------------------------

    var MediaFramePost = wp.media.view.MediaFrame && wp.media.view.MediaFrame.Post;
    if ( MediaFramePost ) {
        var _originalClose = MediaFramePost.prototype.close;

        MediaFramePost.prototype.close = function() {
            var frame = this;
            var args  = arguments;

            if ( ! frame._uwgsCloseWarned && altIsBlankInModal( frame.$el ) ) {
                frame._uwgsCloseWarned = true;

                var $content = frame.$el.find( '.media-frame-content' );
                if ( ! $content.length ) { $content = frame.$el; }

                showInlineWarning(
                    $content,
                    i18n.closeWarning || 'This image has no alt text.',
                    i18n.closeAnyway  || 'Close without alt text',
                    function() {
                        frame._uwgsCloseWarned = false;
                        _originalClose.apply( frame, args );
                    },
                    i18n.addAlt || 'Add alt text'
                );
                return;
            }

            frame._uwgsCloseWarned = false;
            _originalClose.apply( this, args );
        };
    }

} )( window.wp );
