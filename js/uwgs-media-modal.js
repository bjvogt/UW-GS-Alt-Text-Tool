( function() {
    'use strict';
    var i18n = ( typeof uwgsModalCapI18n !== 'undefined' ) ? uwgsModalCapI18n : {};
    function applyCaptionToAlt( panel ) {
        if ( ! panel ) { return; }
        var altField = panel.querySelector( '#attachment-details-alt-text' ) || panel.querySelector( 'textarea[id*="alt-text"]' ) || panel.querySelector( '.setting.alt-text textarea' ) || panel.querySelector( '[data-setting="alt"] textarea' ) || panel.querySelector( '[data-setting="alt"] input' );
        var capField = panel.querySelector( '#attachment-details-caption' ) || panel.querySelector( 'textarea[id*="caption"]' ) || panel.querySelector( '[data-setting="caption"] textarea' ) || panel.querySelector( '[data-setting="caption"] input' );
        if ( ! altField || ! capField ) { return; }
        var altVal = altField.value.trim(), capVal = capField.value.trim();
        if ( altVal !== '' || capVal === '' ) { return; }
        if ( altField.getAttribute( 'data-uwgs-cap-applied' ) ) { return; }
        altField.setAttribute( 'data-uwgs-cap-applied', '1' ); altField.value = capVal;
        altField.dispatchEvent( new Event( 'change', { bubbles: true } ) ); altField.dispatchEvent( new Event( 'input', { bubbles: true } ) );
        var existing = panel.querySelector( '.uwgs-modal-cap-notice' ); if ( existing ) { existing.remove(); }
        var notice = document.createElement( 'div' ); notice.className = 'uwgs-modal-cap-notice'; notice.setAttribute( 'role', 'note' );
        notice.style.cssText = ['margin-top:4px','padding:5px 8px','background:#fff3cd','border-left:3px solid #ffc107','color:#856404','font-size:11px','line-height:1.4','border-radius:0 2px 2px 0','display:flex','align-items:center','justify-content:space-between','gap:6px'].join(';');
        var msg = document.createElement( 'span' ); msg.textContent = i18n.captionCopied || 'Copied from caption — please review before inserting.';
        var dismiss = document.createElement( 'button' ); dismiss.type = 'button'; dismiss.textContent = '✕'; dismiss.setAttribute( 'aria-label', 'Dismiss' );
        dismiss.style.cssText = 'background:none;border:none;cursor:pointer;color:#856404;font-size:12px;padding:0;flex-shrink:0;';
        dismiss.addEventListener( 'click', function() { notice.remove(); } );
        notice.appendChild( msg ); notice.appendChild( dismiss );
        var altSetting = altField.closest( '.setting' );
        if ( altSetting && altSetting.parentNode ) { altSetting.parentNode.insertBefore( notice, altSetting.nextSibling ); } else { altField.parentNode.insertBefore( notice, altField.nextSibling ); }
        altField.addEventListener( 'input', function() { notice.remove(); altField.removeAttribute( 'data-uwgs-cap-applied' ); }, { once: true } );
    }
    function processPanels( container ) {
        if ( ! container ) { return; }
        container.querySelectorAll( '.attachment-details.save-ready,.attachment-details' ).forEach( function( panel ) { setTimeout( function() { applyCaptionToAlt( panel ); }, 200 ); } );
    }
    function observeModalContent( modal ) {
        if ( ! modal || modal._uwgsObserved ) { return; }
        modal._uwgsObserved = true; processPanels( modal );
        new MutationObserver( function( mutations ) {
            mutations.forEach( function( mutation ) {
                mutation.addedNodes.forEach( function( node ) {
                    if ( node.nodeType !== 1 ) { return; }
                    if ( node.classList && node.classList.contains( 'attachment-details' ) ) { setTimeout( function() { applyCaptionToAlt( node ); }, 200 ); return; }
                    processPanels( node );
                } );
                if ( mutation.type === 'childList' && mutation.target ) {
                    var panel = mutation.target.closest ? mutation.target.closest( '.attachment-details' ) : null;
                    if ( panel ) { setTimeout( function() { applyCaptionToAlt( panel ); }, 200 ); }
                }
            } );
        } ).observe( modal, { childList: true, subtree: true } );
    }
    function observeForModal() {
        document.querySelectorAll( '.media-modal,.media-frame' ).forEach( observeModalContent );
        new MutationObserver( function( mutations ) {
            mutations.forEach( function( mutation ) {
                mutation.addedNodes.forEach( function( node ) {
                    if ( node.nodeType !== 1 ) { return; }
                    if ( node.classList && ( node.classList.contains( 'media-modal' ) || node.classList.contains( 'media-frame' ) ) ) { observeModalContent( node ); return; }
                    node.querySelectorAll && node.querySelectorAll( '.media-modal,.media-frame' ).forEach( observeModalContent );
                } );
            } );
        } ).observe( document.body, { childList: true, subtree: false } );
    }
    if ( document.readyState === 'loading' ) { document.addEventListener( 'DOMContentLoaded', observeForModal ); }
    else { observeForModal(); }
} )();
