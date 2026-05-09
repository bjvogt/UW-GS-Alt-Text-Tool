( function() {
    'use strict';
    var i18n = ( typeof uwgsUploadI18n !== 'undefined' ) ? uwgsUploadI18n : {};
    var promptText = i18n.editPrompt || 'Upload complete — click here to edit and add alt text';
    function processRow( row ) {
        if ( ! row ) { return; }
        var editLink = row.querySelector( 'a[href*="action=edit"]' ) || row.querySelector( '.edit-attachment a' ) || row.querySelector( '.media-item-info a' );
        if ( ! editLink || editLink.getAttribute( 'data-uwgs-updated' ) ) { return; }
        if ( row.querySelector( '.upload-error' ) ) { return; }
        editLink.textContent = promptText; editLink.setAttribute( 'data-uwgs-updated', '1' );
        editLink.style.fontWeight = '600'; editLink.style.color = '#856404';
    }
    function observeUploadList() {
        var container = document.getElementById( 'media-items' );
        if ( ! container ) { return; }
        new MutationObserver( function( mutations ) {
            mutations.forEach( function( mutation ) {
                mutation.addedNodes.forEach( function( node ) {
                    if ( node.nodeType !== 1 ) { return; }
                    if ( node.id && node.id.indexOf( 'media-item-' ) === 0 ) { processRow( node ); }
                    var rows = node.querySelectorAll ? node.querySelectorAll( '[id^="media-item-"]' ) : [];
                    rows.forEach( processRow );
                } );
                if ( mutation.type === 'childList' && mutation.target && mutation.target.closest ) {
                    var row = mutation.target.closest( '[id^="media-item-"]' );
                    if ( row ) { processRow( row ); }
                }
            } );
        } ).observe( container, { childList: true, subtree: true } );
    }
    if ( document.readyState === 'loading' ) { document.addEventListener( 'DOMContentLoaded', observeUploadList ); }
    else { observeUploadList(); }
} )();
