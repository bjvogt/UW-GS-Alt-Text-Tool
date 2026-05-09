( function( $ ) {
    'use strict';

    // =========================================================================
    // SAVE-FLOW ARCHITECTURE (uw_stories — ACF + TinyMCE) — v2.5.4
    // =========================================================================
    // ACF's validation fires on the form SUBMIT event (not the click):
    //   1. User clicks #publish
    //   2. ACF's onClickSubmit (jQuery, document bubble) just stores the event
    //   3. Browser default click action fires the form submit event
    //   4. ACF's onSubmit (jQuery, document bubble) runs async validation
    //      — it prevents the submit, validates, then on success calls
    //      jQuery $(form).submit() which fires a 2nd submit event
    //   5. On the 2nd submit ACF sees validation already passed → allows it
    //   6. Browser default of the 2nd submit → form POSTs to server
    //
    // Complications:
    //   a. WP publish/update buttons use form="post" attribute and may sit
    //      OUTSIDE the <form id="post"> element in the DOM, so a listener
    //      on the form never receives their clicks.
    //   b. ACF's onSubmit checks e.isDefaultPrevented(): if we prevented a
    //      submit event, ACF just calls allowSubmit() (no-op) and does nothing —
    //      the form never sends. So intercepting the submit event breaks saves.
    //
    // Fix: intercept CLICK events at the DOCUMENT level in capture phase —
    // document is always an ancestor of every button regardless of DOM position.
    //
    //   1. Document capture listener filters for submit-type buttons that target
    //      form#post (via btn.form or the form="post" attribute).
    //
    //   2. We stop the click, run our async alt-text scan, then:
    //      — Issues found → show warning panel.
    //      — Clean scan   → set a one-shot bypass, call btn.click().
    //        The bypass is consumed immediately on the re-click so the next
    //        user save attempt always re-scans.
    //
    //   3. "Save anyway" sets bypassOnce and re-clicks the original button so
    //      ACF's full save flow runs (nonces, TinyMCE sync, featured image).
    // =========================================================================

    var data    = ( typeof uwgsStoriesData !== 'undefined' ) ? uwgsStoriesData : {};
    var ajaxUrl = data.ajaxUrl        || '';
    var nonce   = data.altCheckNonce  || '';
    var i18n    = data.i18n           || {};

    // One-shot flag: consumed the moment the re-click is handled, so the very
    // next user-initiated click always re-runs the alt-text scan.
    var bypassOnce = false;

    // The button that triggered the current warning — used by "Save anyway".
    var capturedBtn = null;

    // Re-entrancy guard while async AJAX scan is in-flight.
    var scanInFlight = false;

    // -------------------------------------------------------------------------
    // Warning panel (built once, reused across saves)
    // -------------------------------------------------------------------------

    var $warning    = $( '<div id="uwgs-stories-warning" role="alertdialog" aria-live="assertive" aria-modal="true" aria-labelledby="uwgs-stories-warning-title" aria-describedby="uwgs-stories-warning-body" tabindex="-1">' );
    var preFocusEl  = null;
    $( function() {
        $( 'body' ).append( $warning );
        // Focus trap: cycle Tab within the dialog's focusable buttons.
        $warning[0].addEventListener( 'keydown', function( e ) {
            if ( e.key !== 'Tab' ) { return; }
            var focusable = $warning[0].querySelectorAll( 'button' );
            if ( ! focusable.length ) { return; }
            var first = focusable[0], last = focusable[ focusable.length - 1 ];
            if ( e.shiftKey ) {
                if ( document.activeElement === first ) { e.preventDefault(); last.focus(); }
            } else {
                if ( document.activeElement === last ) { e.preventDefault(); first.focus(); }
            }
        } );
    } );

    function showWarning( hasContent, hasFeatured ) {
        preFocusEl = document.activeElement;
        $warning.empty();
        var bodyText = ( hasContent && hasFeatured ) ? i18n.warningBodyBoth
                     : hasFeatured                  ? i18n.warningBodyFeatured
                                                    : i18n.warningBodyContent;
        var $title   = $( '<strong id="uwgs-stories-warning-title">' ).text( i18n.warningTitle || '⚠ Accessibility: Images missing alt text' );
        var $body    = $( '<p id="uwgs-stories-warning-body">' ).text( bodyText );
        var $actions = $( '<div class="uwgs-stories-warning-actions">' );

        var $goBack = $( '<button type="button" class="button button-primary">' )
            .text( i18n.goBack || 'Go back and fix' )
            .on( 'click', function() { hideWarning(); } );

        var $saveAnyway = $( '<button type="button" class="button">' )
            .text( i18n.saveAnyway || 'Save anyway' )
            .on( 'click', function() {
                hideWarning();
                // Use requestSubmit so ACF's full save flow runs normally from
                // this async context. Fall back to click() for old browsers.
                var form = document.getElementById( 'post' );
                if ( form && typeof form.requestSubmit === 'function' && capturedBtn ) {
                    try { form.requestSubmit( capturedBtn ); return; } catch ( err ) {}
                }
                bypassOnce = true;
                if ( capturedBtn ) { capturedBtn.click(); }
            } );

        $actions.append( $goBack ).append( $saveAnyway );
        $warning.append( $title ).append( $body ).append( $actions );
        // Prevent background scroll while dialog is open.
        document.body.style.overflow = 'hidden';
        $warning.addClass( 'visible' );
        $goBack[0].focus();
    }

    function hideWarning() {
        $warning.removeClass( 'visible' ).empty();
        document.body.style.overflow = '';
        // Re-enable the save button so the user can retry after Go Back / Escape.
        if ( capturedBtn ) { capturedBtn.disabled = false; }
        if ( preFocusEl && typeof preFocusEl.focus === 'function' ) { try { preFocusEl.focus(); } catch(e) {} }
        preFocusEl = null;
    }

    $( document ).on( 'keydown', function( e ) {
        if ( e.key === 'Escape' && $warning.hasClass( 'visible' ) ) { hideWarning(); }
    } );

    // -------------------------------------------------------------------------
    // Alt-text scan helpers
    // -------------------------------------------------------------------------

    function tinyMCEHasMissingAlt() {
        if ( typeof window.tinymce === 'undefined' ) { return false; }
        var found = false;
        tinymce.editors.forEach( function( editor ) {
            if ( found || ! editor || ! editor.getContent ) { return; }
            try {
                var content = editor.getContent();
                if ( ! content || content.indexOf( '<img' ) === -1 ) { return; }
                var tmp = document.createElement( 'div' );
                tmp.innerHTML = content;
                tmp.querySelectorAll( 'img' ).forEach( function( img ) {
                    if ( img.getAttribute( 'data-mce-bogus' ) ) { return; }
                    if ( ( img.getAttribute( 'alt' ) || '' ).trim() === '' ) { found = true; }
                } );
            } catch ( e ) {}
        } );
        return found;
    }

    function getAcfImageIds() {
        var ids = [];
        $( '.acf-field[data-type="image"] input[type="hidden"].acf-image-value,' +
           '.acf-image-uploader input[type="hidden"]' ).each( function() {
            var val = parseInt( $( this ).val(), 10 );
            if ( val && val > 0 ) { ids.push( val ); }
        } );
        // Also include the WP native featured image.
        var thumb = document.getElementById( '_thumbnail_id' );
        if ( thumb ) {
            var thumbId = parseInt( thumb.value, 10 );
            if ( thumbId && thumbId > 0 ) { ids.push( thumbId ); }
        }
        return ids.filter( function( v, i, a ) { return a.indexOf( v ) === i; } );
    }

    function checkAcfImagesMissingAlt( ids, callback ) {
        if ( ! ids.length ) { callback( false ); return; }
        var fd = new FormData();
        fd.append( 'action', 'uwgs_check_attachments_alt_batch' );
        fd.append( 'nonce', nonce );
        ids.forEach( function( id ) { fd.append( 'ids[]', id ); } );
        fetch( ajaxUrl, { method: 'POST', body: fd } )
            .then( function( r ) { return r.json(); } )
            .then( function( resp ) {
                if ( ! resp.success ) { callback( false ); return; }
                var missing = Object.keys( resp.data ).some( function( k ) {
                    return resp.data[ k ] && resp.data[ k ].needs_attention;
                } );
                callback( missing );
            } )
            .catch( function() { callback( false ); } );
    }

    // -------------------------------------------------------------------------
    // DOCUMENT-LEVEL CLICK INTERCEPT (capture phase — fires before everything).
    // We bind to document so buttons outside <form id="post"> are also caught.
    // -------------------------------------------------------------------------

    function isSubmitForPostForm( btn ) {
        // Must be a submit-type button or a known WP publish/save button.
        if ( btn.type !== 'submit' && btn.id !== 'publish' && btn.id !== 'save-post' && btn.id !== 'save' ) {
            return false;
        }
        // Resolve the associated form: native .form property or the form="…" attribute.
        var form = btn.form || ( btn.getAttribute( 'form' ) ? document.getElementById( btn.getAttribute( 'form' ) ) : null );
        if ( ! form && typeof btn.closest === 'function' ) { form = btn.closest( 'form' ); }
        return ( form && form.id === 'post' );
    }

    function interceptSaveClicks() {
        document.addEventListener( 'click', function( e ) {
            // Consume the one-shot bypass immediately so the next user click always re-scans.
            if ( bypassOnce ) { bypassOnce = false; return; }

            // Re-entrancy guard while async scan is in-flight.
            if ( scanInFlight ) { e.preventDefault(); e.stopImmediatePropagation(); return; }

            // Walk up from e.target in case the user clicked a child node (e.g. a
            // <span> inside the button), then verify this targets form#post.
            var btn = e.target;
            if ( btn.nodeType !== 1 ) { return; } // ignore text nodes
            if ( ! isSubmitForPostForm( btn ) ) {
                // Try the closest ancestor button/input in case of inner elements.
                if ( typeof btn.closest === 'function' ) {
                    btn = btn.closest( 'input[type="submit"], button[type="submit"]' );
                    if ( ! btn || ! isSubmitForPostForm( btn ) ) { return; }
                } else { return; }
            }

            var hasContent = tinyMCEHasMissingAlt();
            var acfIds     = getAcfImageIds();

            // Fast path: nothing to warn about — let the click through normally.
            if ( ! hasContent && acfIds.length === 0 ) { return; }

            // Halt this click (prevents form submit AND ACF's jQuery click handler).
            e.preventDefault();
            e.stopImmediatePropagation();

            capturedBtn = btn; // store at module scope for "Save anyway"
            // Disable the button to block double-clicks / autosave races;
            // restored in hideWarning() on dismiss or below on clean scan.
            btn.disabled = true;
            scanInFlight = true;
            checkAcfImagesMissingAlt( acfIds, function( hasFeatured ) {
                scanInFlight = false;
                if ( hasContent || hasFeatured ) {
                    showWarning( hasContent, hasFeatured );
                    // button stays disabled until hideWarning() re-enables it
                } else {
                    // Clean scan: re-enable before resubmit so ACF sees the
                    // button in its normal state during the save flow.
                    capturedBtn.disabled = false;
                    var form = document.getElementById( 'post' );
                    if ( form && typeof form.requestSubmit === 'function' && capturedBtn ) {
                        try { form.requestSubmit( capturedBtn ); return; } catch ( err ) {}
                    }
                    bypassOnce = true;
                    if ( capturedBtn ) { capturedBtn.click(); }
                }
            } );
        }, true ); // capture phase
    }

    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', interceptSaveClicks );
    } else {
        interceptSaveClicks();
    }

} )( jQuery );
