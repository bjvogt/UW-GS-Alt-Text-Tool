jQuery( function( $ ) {

    var data         = ( typeof uwgsAltData !== 'undefined' ) ? uwgsAltData : {};
    var ajaxUrl      = data.ajaxUrl     || '';
    var suggestions  = data.suggestions || {};
    var instructions = data.instructions || '';
    var i18n         = data.i18n        || {};

    // Quality functions and suggestion classification delegated to UWGSAltUtils
    // (uwgs-alt-utils.js), which is the canonical source for all scoring logic.
    var Utils = window.UWGSAltUtils;

    // -------------------------------------------------------------------------
    // Pre-classify suggestions on page load so we can decide which rows to
    // pre-populate without re-evaluating on every access.
    // -------------------------------------------------------------------------

    var classified = {};
    Object.keys( suggestions ).forEach( function( k ) {
        classified[ String( k ) ] = Utils.classifySuggestion( suggestions[ k ] );
    } );

    // -------------------------------------------------------------------------
    // Info bar — settings-driven instructions message
    // -------------------------------------------------------------------------

    if ( instructions ) {
        var $bar = $( '<div id="uwgs-info-bar">' ).html( instructions );
        var $tablenav = $( '.tablenav.top' );
        if ( $tablenav.length ) { $tablenav.before( $bar ); }
        else { $( '#wpbody-content .wrap' ).prepend( $bar ); }
    }

    // -------------------------------------------------------------------------
    // On page load: pre-populate textareas that have a "good" suggestion
    // -------------------------------------------------------------------------

    $( '.uwgs-alt-input' ).each( function() {
        var $ta        = $( this );
        var postId     = String( $ta.data( 'post-id' ) );
        var confidence = classified[ postId ] || null;
        var suggestion = suggestions[ postId ] || null;

        if ( ! suggestion || confidence !== 'good' ) { return; }

        var altText = suggestion.type === 'caption'
            ? suggestion.value
            : Utils.sanitizeFilename( suggestion.value );

        if ( ! altText || Utils.validateAltText( altText, i18n ) ) { return; }

        $ta.val( altText );

        // Show hint only for pre-populated rows
        var $hint = $ta.siblings( '.uwgs-alt-hint' );
        $hint.text( i18n.suggestionHint || 'Alt text suggestion from image metadata. Please review and save.' ).show();
        $ta.one( 'input', function() { $hint.hide(); } );
    } );

    // -------------------------------------------------------------------------
    // Clear save-state indicator when the user starts editing
    // -------------------------------------------------------------------------

    $( document ).on( 'input', '.uwgs-alt-input', function() {
        var $wrap = $( this ).closest( '.uwgs-alt-wrap' );
        $wrap.removeAttr( 'data-uwgs-state' );
        $wrap.find( '.uwgs-alt-feedback' ).text( '' ).removeClass( 'success warning error' );
    } );

    // -------------------------------------------------------------------------
    // Keyboard navigation: Enter saves, Tab/Cmd+Enter saves and advances
    // -------------------------------------------------------------------------

    $( document ).on( 'keydown', '.uwgs-alt-input', function( e ) {
        var $wrap = $( this ).closest( '.uwgs-alt-wrap' );
        if ( e.which === 13 && ! e.shiftKey && ! e.metaKey && ! e.ctrlKey ) {
            e.preventDefault();
            $wrap.find( '.uwgs-alt-save-btn' ).trigger( 'click' );
            return;
        }
        if ( ( e.which === 13 && ( e.metaKey || e.ctrlKey ) ) ||
             ( e.which === 9  && ! e.shiftKey ) ) {
            e.preventDefault();
            saveAndAdvance( $wrap, 1 );
            return;
        }
        if ( e.which === 9 && e.shiftKey ) {
            e.preventDefault();
            saveAndAdvance( $wrap, -1 );
            return;
        }
    } );

    // Tab navigation across visible textareas
    function saveAndAdvance( $currentWrap, direction ) {
        var $allInputs = $( '.uwgs-alt-editor .uwgs-alt-input' );
        var $thisInput = $currentWrap.find( '.uwgs-alt-input' );
        var idx        = $allInputs.index( $thisInput );
        var $target    = $allInputs.eq( idx + direction );

        var nonce   = $currentWrap.find( '.uwgs-alt-save-btn' ).data( 'nonce' );
        var postId  = String( $currentWrap.data( 'post-id' ) );
        var altText = $thisInput.val().trim();
        var err     = Utils.validateAltText( altText, i18n );

        if ( err ) {
            $currentWrap.attr( 'data-uwgs-state', 'invalid' );
            $currentWrap.find( '.uwgs-alt-feedback' ).text( '✗ ' + err ).removeClass( 'success warning' ).addClass( 'error' );
            return;
        }

        $.ajax( {
            url: ajaxUrl, type: 'POST',
            data: { action: 'uwgs_save_alt_text', post_id: parseInt( postId, 10 ), alt_text: altText, nonce: nonce },
            success: function( r ) {
                if ( r.success ) { applyColumnSave( $currentWrap, postId, r.data.alt_text || altText, r.data.needs_attention ); }
            },
            complete: function() {
                if ( $target.length ) { $target.trigger( 'focus' ); }
            }
        } );
    }

    // -------------------------------------------------------------------------
    // Per-row Save button
    // -------------------------------------------------------------------------

    $( document ).on( 'click', '.uwgs-alt-save-btn', function() {
        var $btn     = $( this );
        var $wrap    = $btn.closest( '.uwgs-alt-wrap' );
        var postId   = String( $wrap.data( 'post-id' ) );
        var nonce    = $btn.data( 'nonce' );
        var altText  = $wrap.find( '.uwgs-alt-input' ).val().trim();
        var $spinner = $wrap.find( '.uwgs-alt-spinner' );
        var $fb      = $wrap.find( '.uwgs-alt-feedback' );

        var err = Utils.validateAltText( altText, i18n );
        if ( err ) {
            $wrap.attr( 'data-uwgs-state', 'invalid' );
            $fb.text( '✗ ' + err ).removeClass( 'success warning' ).addClass( 'error' );
            return;
        }

        $btn.prop( 'disabled', true );
        $spinner.addClass( 'is-active' ).attr( 'aria-hidden', 'true' );
        $fb.text( '' ).removeClass( 'success warning error' );

        $.ajax( {
            url: ajaxUrl, type: 'POST',
            data: { action: 'uwgs_save_alt_text', post_id: parseInt( postId, 10 ), alt_text: altText, nonce: nonce },
            success: function( r ) {
                if ( r.success ) {
                    applyColumnSave( $wrap, postId, r.data.alt_text || altText, r.data.needs_attention );
                } else {
                    $wrap.attr( 'data-uwgs-state', 'invalid' );
                    $fb.text( '✗ ' + ( r.data || i18n.saveFailed || 'Save failed.' ) ).addClass( 'error' );
                }
            },
            error: function() {
                $wrap.attr( 'data-uwgs-state', 'invalid' );
                $fb.text( '✗ ' + ( i18n.requestFailed || 'Request failed.' ) ).addClass( 'error' );
            },
            complete: function() {
                $btn.prop( 'disabled', false );
                $spinner.removeClass( 'is-active' ).attr( 'aria-hidden', 'true' );
            }
        } );
    } );

    // -------------------------------------------------------------------------
    // After a successful save:
    //   - Good quality → collapse editor, update badge to green, set "saved" state
    //   - Still low quality → keep editor, update badge, set persistent "warning" state
    //   - Empty (shouldn't happen) → blank badge, set "invalid" state
    // -------------------------------------------------------------------------

    function applyColumnSave( $wrap, postId, savedAlt, needsAttention ) {
        var key     = String( postId );
        var $editor = $wrap.find( '.uwgs-alt-editor' );
        var $badge  = $wrap.find( '.uwgs-alt-value' );
        var $fb     = $wrap.find( '.uwgs-alt-feedback' );
        var $hint   = $wrap.find( '.uwgs-alt-hint' );

        // Update data so future keyboard nav reads correctly
        $wrap.find( '.uwgs-alt-input' ).attr( 'data-saved-alt', savedAlt );
        delete classified[ key ];
        delete suggestions[ key ];

        if ( savedAlt && ! needsAttention ) {
            // ✓ Good — collapse editor, update badge to green, mark wrap as saved
            $wrap.attr( 'data-uwgs-state', 'saved' );
            $badge.text( savedAlt )
                  .removeClass( 'uwgs-alt-blank uwgs-low-quality' )
                  .addClass( 'uwgs-has-alt' )
                  .removeAttr( 'aria-label' );
            $wrap.find( '.uwgs-alt-new-flag' ).remove();
            $hint.hide();
            $editor.find( '.uwgs-alt-input' ).prop( 'disabled', true );
            $editor.find( '.uwgs-alt-save-btn' ).prop( 'disabled', true );
            $editor.hide();
        } else if ( savedAlt && needsAttention ) {
            // ⚠ Saved but still low quality — keep editor open, mark wrap as warning
            // Feedback message persists (no timeout) so the state is visible at a glance
            $wrap.attr( 'data-uwgs-state', 'warning' );
            $badge.text( '⚠ ' + savedAlt )
                  .removeClass( 'uwgs-alt-blank uwgs-has-alt' )
                  .addClass( 'uwgs-low-quality' );
            $hint.hide();
            $fb.text( '⚠ ' + ( i18n.saved || 'Saved' ) + ' — alt text may need improvement' )
               .removeClass( 'error' )
               .addClass( 'warning' );
        } else {
            // Empty saved (shouldn't happen due to validation)
            $wrap.attr( 'data-uwgs-state', 'invalid' );
            $badge.text( i18n.blank || '(blank)' )
                  .removeClass( 'uwgs-has-alt uwgs-low-quality' )
                  .addClass( 'uwgs-alt-blank' )
                  .attr( 'aria-label', 'Alt text is blank' );
        }
    }

} );
