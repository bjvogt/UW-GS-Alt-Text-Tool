( function() {
    'use strict';

    // =========================================================================
    // SAVE-FLOW ARCHITECTURE (Classic editor / non-block post types) — v2.5.2
    // =========================================================================
    // Single delegated `submit` listener on form#post, capture phase, covers
    // all save paths (click, keyboard Enter, programmatic requestSubmit).
    // When alt text is missing we preventDefault(), run an async batch scan,
    // then either show the warning panel or resubmit cleanly.
    // bypassValidation short-circuits the listener after "Save anyway" or a
    // clean-scan resubmit, preventing recursive validation loops.
    // =========================================================================

    var data = ( typeof uwgsPresaveData !== 'undefined' ) ? uwgsPresaveData : {};
    var ajaxUrl = data.ajaxUrl || ''; var nonce = data.altCheckNonce || ''; var i18n = data.i18n || {};
    var warningEl = null, noticeEl = null; var noticeDismissed = false; var preFocusEl = null;

    // Hard bypass flag — once set, our submit listener returns immediately.
    // Set by "Save anyway" and by the clean-scan resubmit path.
    var bypassValidation = false;

    // Re-entrancy guard for the async featured-image scan: prevents the user
    // from triggering parallel scans by mashing Enter while we await AJAX.
    var scanInFlight = false;

    // The submit button that triggered the current scan; held at module scope
    // so hideWarning() can re-enable it on any dismiss path.
    var activeSubmitter = null;

    function contentHasMissingAlt() {
        var allContent = [];
        if ( typeof window.tinyMCE !== 'undefined' && tinyMCE.editors && tinyMCE.editors.length ) {
            tinyMCE.editors.forEach( function( editor ) { if ( editor && editor.getContent ) { try { allContent.push( editor.getContent() ); } catch(e) {} } } );
        }
        var textarea = document.getElementById( 'content' );
        if ( textarea && textarea.value ) { allContent.push( textarea.value ); }
        if ( ! allContent.length ) { return false; }
        for ( var c = 0; c < allContent.length; c++ ) {
            if ( ! allContent[c] || allContent[c].indexOf( '<img' ) === -1 ) { continue; }
            var tmp = document.createElement( 'div' ); tmp.innerHTML = allContent[c];
            var imgs = tmp.querySelectorAll( 'img' );
            for ( var i = 0; i < imgs.length; i++ ) {
                if ( imgs[i].getAttribute( 'data-mce-bogus' ) ) { continue; }
                if ( ( imgs[i].getAttribute( 'alt' ) || '' ).trim() === '' ) { return true; }
            }
        }
        return false;
    }

    function getFeaturedImageId() {
        var el = document.getElementById( '_thumbnail_id' );
        if ( el ) { var val = parseInt( el.value, 10 ); if ( val && val > 0 ) { return val; } }
        var candidates = document.querySelectorAll( 'input[type="hidden"][name*="thumbnail_id"],input[type="hidden"][id*="thumbnail_id"],input[type="hidden"][name*="featured_image"],input[type="hidden"][id*="featured_image"],input[type="hidden"][name*="featured_media"],input[type="hidden"][id*="featured_media"]' );
        for ( var i = 0; i < candidates.length; i++ ) { var id = parseInt( candidates[i].value, 10 ); if ( id && id > 0 ) { return id; } }
        return 0;
    }

    // Single batched request: sends all IDs at once, returns per-ID results.
    // Replaces the former single-ID uwgs_get_attachment_alt call.
    function featuredImageMissingAlt() {
        return new Promise( function( resolve ) {
            var thumbnailId = getFeaturedImageId();
            if ( ! thumbnailId ) { resolve( false ); return; }
            var formData = new FormData();
            formData.append( 'action', 'uwgs_check_attachments_alt_batch' );
            formData.append( 'nonce', nonce );
            formData.append( 'ids[]', thumbnailId );
            fetch( ajaxUrl, { method: 'POST', body: formData } )
                .then( function( r ) { return r.json(); } )
                .then( function( response ) {
                    if ( ! response.success ) { resolve( false ); return; }
                    var result = response.data[ String( thumbnailId ) ];
                    resolve( !! ( result && result.needs_attention ) );
                } )
                .catch( function() { resolve( false ); } );
        } );
    }

    function buildNoticeBar() {
        noticeEl = document.createElement( 'div' ); noticeEl.id = 'uwgs-inline-notice';
        noticeEl.setAttribute( 'role', 'status' ); noticeEl.setAttribute( 'aria-live', 'polite' );
        var textSpan = document.createElement( 'span' ); textSpan.className = 'uwgs-notice-text'; noticeEl.appendChild( textSpan );
        var dismissBtn = document.createElement( 'button' ); dismissBtn.type = 'button'; dismissBtn.className = 'uwgs-notice-dismiss';
        dismissBtn.setAttribute( 'aria-label', i18n.dismiss || 'Dismiss' ); dismissBtn.textContent = '✕';
        dismissBtn.addEventListener( 'click', function() { noticeEl.classList.remove( 'visible' ); noticeDismissed = true; } );
        noticeEl.appendChild( dismissBtn );
        var anchor = document.getElementById( 'titlediv' ) || document.getElementById( 'post-body-content' );
        if ( anchor && anchor.parentNode ) { anchor.parentNode.insertBefore( noticeEl, anchor.nextSibling ); } else { document.body.appendChild( noticeEl ); }
    }

    function updateNoticeBar( hasContent, hasFeatured ) {
        if ( ! noticeEl ) { return; }
        if ( ! hasContent && ! hasFeatured ) { noticeEl.classList.remove( 'visible' ); noticeDismissed = false; return; }
        if ( noticeDismissed ) { return; }
        var textSpan = noticeEl.querySelector( '.uwgs-notice-text' ); if ( ! textSpan ) { return; }
        textSpan.textContent = hasContent && hasFeatured ? i18n.noticeBoth : hasFeatured ? i18n.noticeFeatured : i18n.noticeContent;
        noticeEl.classList.add( 'visible' );
    }

    function refreshNoticeBar( afterMediaInsert ) {
        if ( afterMediaInsert ) { noticeDismissed = false; }
        var hasContent = contentHasMissingAlt();
        featuredImageMissingAlt().then( function( hasFeatured ) { updateNoticeBar( hasContent, hasFeatured ); } );
    }

    function buildWarningPanel() {
        warningEl = document.createElement( 'div' ); warningEl.id = 'uwgs-presave-warning';
        warningEl.setAttribute( 'role', 'alertdialog' ); warningEl.setAttribute( 'aria-live', 'assertive' );
        warningEl.setAttribute( 'aria-modal', 'true' ); warningEl.setAttribute( 'tabindex', '-1' );
        warningEl.setAttribute( 'aria-labelledby', 'uwgs-presave-warning-title' );
        warningEl.setAttribute( 'aria-describedby', 'uwgs-presave-warning-body' );
        // Focus trap: cycle Tab within the dialog's focusable buttons.
        warningEl.addEventListener( 'keydown', function( e ) {
            if ( e.key !== 'Tab' ) { return; }
            var focusable = warningEl.querySelectorAll( 'button' );
            if ( ! focusable.length ) { return; }
            var first = focusable[0], last = focusable[ focusable.length - 1 ];
            if ( e.shiftKey ) {
                if ( document.activeElement === first ) { e.preventDefault(); last.focus(); }
            } else {
                if ( document.activeElement === last ) { e.preventDefault(); first.focus(); }
            }
        } );
        document.body.appendChild( warningEl );
    }

    // Programmatically resubmit form#post while preserving the originating
    // submitter (so WordPress sees Save Draft vs. Update vs. Publish via the
    // button's name=value pair). Prefers requestSubmit(submitter) so that
    // WordPress / ACF / any other submit handlers still run; falls back to
    // form.submit() with a hidden input on browsers without requestSubmit.
    function resubmitForm( form, submitter, useDirectSubmit ) {
        if ( ! form ) { return; }
        if ( ! useDirectSubmit && typeof form.requestSubmit === 'function' ) {
            try { form.requestSubmit( submitter || undefined ); return; } catch ( err ) { /* fall through */ }
        }
        // Direct native submission. Does not fire the submit event — neither
        // our listener nor any other submit handler runs again. Inject a
        // hidden input to preserve the originating button's name=value pair
        // (browsers normally include it only for user-initiated submits).
        if ( submitter && submitter.name ) {
            var hidden = document.createElement( 'input' );
            hidden.type = 'hidden';
            hidden.name = submitter.name;
            hidden.value = submitter.value || '';
            form.appendChild( hidden );
        }
        // Sync TinyMCE editors to their textareas before native submission,
        // because form.submit() does not fire the submit event that WordPress
        // normally uses to trigger tinyMCE.triggerSave().
        if ( typeof window.tinyMCE !== 'undefined' ) {
            try { tinyMCE.triggerSave(); } catch ( err ) {}
        }
        form.submit();
    }

    function showWarning( hasContent, hasFeatured, submitter ) {
        preFocusEl = document.activeElement;
        warningEl.innerHTML = '';
        var title = document.createElement( 'strong' ); title.id = 'uwgs-presave-warning-title'; title.textContent = i18n.warningTitle || '⚠ Accessibility: Images missing alt text';
        var body = document.createElement( 'p' ); body.id = 'uwgs-presave-warning-body'; body.textContent = hasContent && hasFeatured ? i18n.warningBodyBoth : hasFeatured ? i18n.warningBodyFeatured : i18n.warningBodyContent;
        var actions = document.createElement( 'div' ); actions.className = 'uwgs-warning-actions';
        var goBack = document.createElement( 'button' ); goBack.type = 'button'; goBack.className = 'button button-primary'; goBack.textContent = i18n.goBack || 'Go back and fix';
        goBack.addEventListener( 'click', function() { hideWarning(); if ( typeof window.tinyMCE !== 'undefined' && tinyMCE.activeEditor ) { tinyMCE.activeEditor.focus(); } } );
        var saveAnyway = document.createElement( 'button' ); saveAnyway.type = 'button'; saveAnyway.className = 'button'; saveAnyway.textContent = i18n.saveAnyway || 'Save anyway';
        saveAnyway.addEventListener( 'click', function() {
            hideWarning();
            // Hard bypass before the resubmit so even if requestSubmit fires
            // our listener again, it returns immediately. Then perform a
            // direct form submission — no callback to resume, no stale state.
            bypassValidation = true;
            resubmitForm( document.getElementById( 'post' ), submitter, /* useDirectSubmit */ true );
        } );
        actions.appendChild( goBack ); actions.appendChild( saveAnyway );
        warningEl.appendChild( title ); warningEl.appendChild( body ); warningEl.appendChild( actions );
        // Prevent background scroll while dialog is open.
        document.body.style.overflow = 'hidden';
        warningEl.classList.add( 'visible' ); goBack.focus();
    }

    function hideWarning() {
        warningEl.classList.remove( 'visible' );
        warningEl.innerHTML = '';
        document.body.style.overflow = '';
        // Re-enable the button that triggered the scan so the user can retry.
        if ( activeSubmitter ) { activeSubmitter.disabled = false; activeSubmitter = null; }
        if ( preFocusEl && typeof preFocusEl.focus === 'function' ) { try { preFocusEl.focus(); } catch(e) {} }
        preFocusEl = null;
    }

    // Single delegated submit interception on form#post. Capture phase so we
    // run before WordPress's own bubble-phase handlers and before jQuery
    // delegated listeners. This naturally covers click, keyboard, and any
    // programmatic form submission.
    function interceptFormSubmit() {
        var form = document.getElementById( 'post' );
        if ( ! form ) { return; } // not a post-edit screen with form#post

        form.addEventListener( 'submit', function( e ) {
            // Hard bypass after "Save anyway" or a clean-scan resubmit.
            if ( bypassValidation ) { return; }

            // Re-entrancy guard while an async scan is mid-flight.
            if ( scanInFlight ) { e.preventDefault(); e.stopImmediatePropagation(); return; }

            // Snapshot the originating button (event.submitter is supported in
            // all modern browsers; null means keyboard or programmatic submit).
            var submitter = e.submitter || null;

            var hasContent = contentHasMissingAlt();
            var thumbnailId = getFeaturedImageId();

            // Fast path: nothing to scan synchronously and no featured image
            // to fetch — let the submit continue uninterrupted.
            if ( ! hasContent && ! thumbnailId ) { return; }

            // Halt this submit while we run the async featured-image check.
            // We will either show the warning OR programmatically resubmit
            // if the scan comes back clean (so other submit handlers run).
            e.preventDefault();
            e.stopImmediatePropagation();

            // Disable the triggering button to block double-clicks / autosave
            // races during the async scan; restored in hideWarning() or below.
            activeSubmitter = submitter;
            if ( submitter ) { submitter.disabled = true; }

            scanInFlight = true;
            featuredImageMissingAlt().then( function( hasFeatured ) {
                scanInFlight = false;
                if ( hasContent || hasFeatured ) {
                    showWarning( hasContent, hasFeatured, submitter );
                    // button stays disabled until hideWarning() re-enables it
                } else {
                    // Clean scan — re-enable before resubmit so WP submit
                    // handlers see the button in its normal state.
                    if ( activeSubmitter ) { activeSubmitter.disabled = false; activeSubmitter = null; }
                    bypassValidation = true;
                    resubmitForm( form, submitter, /* useDirectSubmit */ false );
                }
            } );
        }, true );
    }

    function watchForModalClose() {
        new MutationObserver( function( mutations ) {
            mutations.forEach( function( mutation ) {
                mutation.removedNodes.forEach( function( node ) {
                    if ( node.nodeType === 1 && node.classList && node.classList.contains( 'media-modal' ) ) { setTimeout( function() { refreshNoticeBar( true ); }, 400 ); }
                } );
            } );
        } ).observe( document.body, { childList: true } );
    }

    function waitForTinyMCEThenScan() {
        var attempts = 0;
        ( function attempt() {
            attempts++;
            var ready = typeof window.tinyMCE !== 'undefined' && tinyMCE.editors && tinyMCE.editors.length > 0 && tinyMCE.editors[0].initialized;
            if ( ready ) { setTimeout( function() { refreshNoticeBar( false ); }, 200 ); return; }
            if ( attempts === 1 ) { refreshNoticeBar( false ); }
            if ( attempts < 100 ) { setTimeout( attempt, 100 ); }
        } )();
    }

    document.addEventListener( 'keydown', function( e ) { if ( e.key === 'Escape' && warningEl && warningEl.classList.contains( 'visible' ) ) { hideWarning(); } } );

    function bootstrap() {
        buildNoticeBar();
        buildWarningPanel();
        interceptFormSubmit();
        watchForModalClose();
        waitForTinyMCEThenScan();
    }

    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', bootstrap );
    } else { bootstrap(); }
} )();
