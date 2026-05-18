/**
 * uwgs-ga-settings.js — Google Analytics settings page interactions.
 * Handles: save settings (property ID, window, service account JSON), sync GA4 data.
 */
( function( $ ) {
    'use strict';

    var data    = ( typeof uwgsGaSettingsData !== 'undefined' ) ? uwgsGaSettingsData : {};
    var ajaxUrl = data.ajaxUrl || '';
    var i18n    = data.i18n   || {};

    // -------------------------------------------------------------------------
    // Save settings
    // -------------------------------------------------------------------------

    var removeSaPending = false;

    $( '#uwgs-ga-remove-sa' ).on( 'click', function() {
        if ( ! confirm( i18n.confirmRemove || 'Remove service account credentials?' ) ) { return; }
        removeSaPending = true;
        $( this ).prop( 'disabled', true ).text( '(' + ( i18n.willRemove || 'will remove on Save' ) + ')' );
    } );

    $( '#uwgs-ga-save-btn' ).on( 'click', function() {
        var $btn    = $( this );
        var saJson  = ( $( '#uwgs-ga-service-account' ).val() || '' ).trim();
        var propId  = ( $( '#uwgs-ga-property-id' ).val() || '' ).trim();
        var window_ = $( 'input[name="uwgs_ga_window"]:checked' ).val() || '90';

        $btn.prop( 'disabled', true ).text( i18n.saving || 'Saving…' );
        showFeedback( '', '' );

        var fd = new FormData();
        fd.append( 'action',          'uwgs_save_ga_settings' );
        fd.append( 'nonce',           data.saveNonce );
        fd.append( 'property_id',     propId );
        fd.append( 'window',          window_ );
        fd.append( 'service_account', saJson );
        if ( removeSaPending ) { fd.append( 'remove_sa', '1' ); }

        fetch( ajaxUrl, { method: 'POST', body: fd } )
            .then( function( r ) { return r.json(); } )
            .then( function( resp ) {
                $btn.prop( 'disabled', false ).text( 'Save Settings' );
                if ( resp.success ) {
                    showFeedback( 'success', i18n.saved || 'Settings saved.' );
                    $( '#uwgs-ga-service-account' ).val( '' );
                    removeSaPending = false;
                    if ( resp.data && resp.data.sa_email ) {
                        $( '#uwgs-ga-sa-status' ).text( resp.data.sa_email );
                    }
                    if ( resp.data && resp.data.ready ) {
                        $( '#uwgs-ga-sync-section' ).show();
                    }
                } else {
                    showFeedback( 'error', resp.data || ( i18n.saveError || 'Error saving settings.' ) );
                }
            } )
            .catch( function() {
                $btn.prop( 'disabled', false ).text( 'Save Settings' );
                showFeedback( 'error', i18n.saveError || 'Error saving settings.' );
            } );
    } );

    // -------------------------------------------------------------------------
    // Sync GA4 data
    // -------------------------------------------------------------------------

    $( '#uwgs-ga-sync-btn' ).on( 'click', function() {
        var $btn    = $( this );
        var $status = $( '#uwgs-ga-sync-status' );

        $btn.prop( 'disabled', true );
        $status.css( 'color', '#555' ).text( i18n.syncing || 'Syncing GA4 data…' );

        var fd = new FormData();
        fd.append( 'action', 'uwgs_sync_ga4' );
        fd.append( 'nonce',  data.syncNonce );

        fetch( ajaxUrl, { method: 'POST', body: fd } )
            .then( function( r ) { return r.json(); } )
            .then( function( resp ) {
                $btn.prop( 'disabled', false );
                if ( resp.success ) {
                    var msg = ( i18n.syncDone || 'Sync complete — %1$d attachments updated, %2$d with traffic.' )
                        .replace( '%1$d', resp.data.synced )
                        .replace( '%2$d', resp.data.with_views );
                    $status.css( 'color', '#2e7d32' ).text( msg );
                } else {
                    $status.css( 'color', '#c62828' ).text( ( i18n.syncError || 'Sync failed: ' ) + ( resp.data || '' ) );
                }
            } )
            .catch( function() {
                $btn.prop( 'disabled', false );
                $status.css( 'color', '#c62828' ).text( i18n.syncError || 'Sync failed.' );
            } );
    } );

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    function showFeedback( type, msg ) {
        var $el = $( '#uwgs-ga-save-feedback' );
        if ( ! type ) { $el.hide(); return; }
        var cls = type === 'success' ? 'notice notice-success inline' : 'notice notice-error inline';
        $el.attr( 'class', cls ).html( '<p>' + $( '<span>' ).text( msg ).html() + '</p>' ).show();
    }

} )( jQuery );
