/**
 * uwgs-unused.js — Unused Media admin page interactions.
 * Handles: batch scan progress, exclude/unexclude, bulk trash with confirm dialog.
 */
( function( $ ) {
    'use strict';

    var data        = ( typeof uwgsUnusedData !== 'undefined' ) ? uwgsUnusedData : {};
    var ajaxUrl     = data.ajaxUrl     || '';
    var scanNonce   = data.scanNonce   || '';
    var actionNonce = data.actionNonce || '';
    var i18n        = data.i18n        || {};

    // -------------------------------------------------------------------------
    // Batch scanner
    // -------------------------------------------------------------------------

    var scanInProgress = false;

    function runScanBatch( offset ) {
        var fd = new FormData();
        fd.append( 'action', 'uwgs_scan_batch' );
        fd.append( 'nonce', scanNonce );
        fd.append( 'offset', offset );

        fetch( ajaxUrl, { method: 'POST', body: fd } )
            .then( function( r ) { return r.json(); } )
            .then( function( response ) {
                if ( ! response.success ) {
                    setScanStatus( i18n.scanError || 'Scan error. Please try again.' );
                    setScanProgress( 0 );
                    scanInProgress = false;
                    $( '#uwgs-rescan-btn' ).prop( 'disabled', false );
                    return;
                }

                var d = response.data;
                var pct = d.total > 0 ? Math.round( ( d.processed / d.total ) * 100 ) : 100;
                setScanProgress( pct );
                setScanStatus( ( i18n.scanning || 'Scanning…' ) + ' ' + d.processed + ' / ' + d.total );

                if ( d.done ) {
                    setScanStatus( i18n.scanComplete || 'Scan complete. Refreshing results…' );
                    scanInProgress = false;
                    // Reload page to show fresh results
                    setTimeout( function() { window.location.reload(); }, 800 );
                } else {
                    runScanBatch( d.offset );
                }
            } )
            .catch( function() {
                setScanStatus( i18n.scanError || 'Scan error. Please try again.' );
                setScanProgress( 0 );
                scanInProgress = false;
                $( '#uwgs-rescan-btn' ).prop( 'disabled', false );
            } );
    }

    function setScanStatus( msg ) {
        $( '#uwgs-scan-status' ).text( msg );
    }

    function setScanProgress( pct ) {
        $( '#uwgs-scan-bar' ).css( 'width', pct + '%' );
    }

    $( '#uwgs-rescan-btn' ).on( 'click', function() {
        if ( scanInProgress ) { return; }
        scanInProgress = true;
        $( this ).prop( 'disabled', true );
        $( '#uwgs-scan-progress' ).show();
        setScanProgress( 0 );
        setScanStatus( i18n.scanning || 'Scanning…' );
        runScanBatch( 0 );
    } );

    // -------------------------------------------------------------------------
    // Select all checkbox
    // -------------------------------------------------------------------------

    $( '#uwgs-select-all' ).on( 'change', function() {
        $( 'input[name="attachment_ids[]"]' ).prop( 'checked', $( this ).prop( 'checked' ) );
    } );

    $( '#uwgs-unused-table' ).on( 'change', 'input[name="attachment_ids[]"]', function() {
        var total   = $( 'input[name="attachment_ids[]"]' ).length;
        var checked = $( 'input[name="attachment_ids[]"]:checked' ).length;
        $( '#uwgs-select-all' ).prop( 'indeterminate', checked > 0 && checked < total );
        $( '#uwgs-select-all' ).prop( 'checked', checked === total );
    } );

    // -------------------------------------------------------------------------
    // Bulk trash (with confirm dialog)
    // -------------------------------------------------------------------------

    var pendingTrashIds = [];

    function getSelectedIds() {
        return $( 'input[name="attachment_ids[]"]:checked' ).map( function() {
            return $( this ).val();
        } ).get();
    }

    function showTrashConfirm( ids ) {
        pendingTrashIds = ids;
        var msg = ( i18n.confirmTrash || 'Move %d item(s) to Trash? You have 30 days to restore them.' )
            .replace( '%d', ids.length );
        $( '#uwgs-trash-confirm-msg' ).text( msg );
        $( '#uwgs-trash-confirm-dialog' ).css( 'display', 'flex' );
        $( '#uwgs-trash-confirm-yes' ).trigger( 'focus' );
    }

    function hideTrashConfirm() {
        $( '#uwgs-trash-confirm-dialog' ).hide();
        pendingTrashIds = [];
    }

    $( document ).on( 'click', '.uwgs-bulk-apply-btn', function() {
        var action = $( this ).closest( '.tablenav' ).find( '.uwgs-bulk-action-sel' ).val();
        if ( action !== 'trash' ) { return; }
        var ids = getSelectedIds();
        if ( ids.length === 0 ) {
            $( '.uwgs-bulk-feedback' ).text( i18n.selectItems || 'Please select at least one item.' );
            return;
        }
        showTrashConfirm( ids );
    } );

    $( '#uwgs-trash-confirm-no' ).on( 'click', hideTrashConfirm );

    $( '#uwgs-trash-confirm-dialog' ).on( 'keydown', function( e ) {
        if ( e.key === 'Escape' ) { hideTrashConfirm(); }
    } );

    $( '#uwgs-trash-confirm-yes' ).on( 'click', function() {
        hideTrashConfirm();
        doTrash( pendingTrashIds );
    } );

    function doTrash( ids ) {
        $( '.uwgs-bulk-feedback' ).text( i18n.trashing || 'Moving to Trash…' );
        $( '.uwgs-bulk-apply-btn' ).prop( 'disabled', true );

        var fd = new FormData();
        fd.append( 'action', 'uwgs_bulk_trash_unused' );
        fd.append( 'nonce', actionNonce );
        ids.forEach( function( id ) { fd.append( 'ids[]', id ); } );

        fetch( ajaxUrl, { method: 'POST', body: fd } )
            .then( function( r ) { return r.json(); } )
            .then( function( response ) {
                $( '.uwgs-bulk-apply-btn' ).prop( 'disabled', false );
                if ( ! response.success ) {
                    $( '.uwgs-bulk-feedback' ).text( i18n.trashError || 'Some items could not be trashed.' );
                    return;
                }
                var trashed = response.data.trashed || [];
                trashed.forEach( function( id ) {
                    $( 'tr[data-id="' + id + '"]' ).fadeOut( 300, function() { $( this ).remove(); } );
                } );
                var msg = ( i18n.trashDone || '%d items moved to Trash.' ).replace( '%d', trashed.length );
                if ( response.data.failed && response.data.failed.length ) {
                    msg += ' ' + ( i18n.trashError || 'Some items could not be trashed.' );
                }
                $( '.uwgs-bulk-feedback' ).text( msg );
                $( '#uwgs-select-all' ).prop( 'checked', false );
            } )
            .catch( function() {
                $( '.uwgs-bulk-apply-btn' ).prop( 'disabled', false );
                $( '.uwgs-bulk-feedback' ).text( i18n.trashError || 'Some items could not be trashed.' );
            } );
    }

    // -------------------------------------------------------------------------
    // Single-row trash button
    // -------------------------------------------------------------------------

    $( '#uwgs-unused-table' ).on( 'click', '.uwgs-trash-single-btn', function() {
        var btn = $( this );
        var id  = btn.data( 'id' );
        showTrashConfirm( [ String( id ) ] );
    } );

    // -------------------------------------------------------------------------
    // Exclude / unexclude
    // -------------------------------------------------------------------------

    function doExclude( id, nonce, action, btn ) {
        btn.prop( 'disabled', true ).text( i18n.excluding || 'Excluding…' );

        var fd = new FormData();
        fd.append( 'action', 'uwgs_exclude_attachment' );
        fd.append( 'nonce', nonce );
        fd.append( 'id', id );
        fd.append( 'exclude_action', action );

        fetch( ajaxUrl, { method: 'POST', body: fd } )
            .then( function( r ) { return r.json(); } )
            .then( function( response ) {
                if ( response.success ) {
                    if ( action === 'exclude' ) {
                        // Remove from current results view
                        $( 'tr[data-id="' + id + '"]' ).fadeOut( 300, function() { $( this ).remove(); } );
                    } else {
                        btn.prop( 'disabled', false ).text( i18n.unexcluded || 'Exclusion removed.' );
                    }
                } else {
                    btn.prop( 'disabled', false ).text( 'Error' );
                }
            } )
            .catch( function() {
                btn.prop( 'disabled', false ).text( 'Error' );
            } );
    }

    // Exclude from main unused media table
    $( '#uwgs-unused-table' ).on( 'click', '.uwgs-exclude-btn', function() {
        var btn   = $( this );
        var id    = btn.data( 'id' );
        var nonce = btn.data( 'nonce' );
        doExclude( id, nonce, 'exclude', btn );
    } );

    // Unexclude from settings page excluded items table
    $( document ).on( 'click', '.uwgs-unexclude', function() {
        var btn   = $( this );
        var id    = btn.data( 'id' );
        var nonce = btn.data( 'nonce' );
        doExclude( id, nonce, 'unexclude', btn );
    } );

} )( jQuery );
