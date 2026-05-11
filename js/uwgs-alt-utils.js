/**
 * UWGSAltUtils — shared alt-text quality utilities.
 *
 * Mirrors UWGS_Alt_Quality (PHP) exactly so browser-side scoring always matches
 * server-side scoring. When a rule changes, update both files and the settings
 * page rule descriptions in UWGS_Alt_Quality::get_rules_description().
 *
 * Registered as WordPress script handle 'uwgs-alt-utils' with no WP
 * dependencies; plugin scripts that need quality evaluation list it as a dep.
 */
window.UWGSAltUtils = ( function() {
    'use strict';

    // Must stay in sync with UWGS_Alt_Quality::IMAGE_EXTENSIONS in PHP.
    var IMAGE_EXTENSIONS = /\.(jpg|jpeg|png|gif|webp|svg|bmp|tiff?|avif|heic|heif)$/i;

    // Must stay in sync with UWGS_Alt_Quality::LOW_QUALITY_WORDS in PHP.
    var LOW_QUALITY_WORDS = [
        'image', 'photo', 'img', 'picture', 'screenshot',
        'graphic', 'thumbnail', 'banner', 'logo', 'icon',
    ];

    // -------------------------------------------------------------------------
    // Filename sanitization — strips extensions, date tokens, dimension strings,
    // and capitalizes words. Used to make filename-derived suggestions readable.
    // -------------------------------------------------------------------------

    function sanitizeFilename( raw ) {
        var s = raw;
        s = s.replace( /&[#a-zA-Z0-9]+;/g, ' ' );
        s = s.replace( /\.[a-zA-Z0-9]+$/, '' );
        // Split CamelCase: "ThreeMinuteThesis" → "Three Minute Thesis",
        // "GSEERoom" → "GSEE Room" (acronym boundary handled by second pass).
        s = s.replace( /([a-z])([A-Z])/g, '$1 $2' );
        s = s.replace( /([A-Z]+)([A-Z][a-z])/g, '$1 $2' );
        s = s.replace( /[-_]+/g, ' ' );
        s = s.replace( /\b\d+x\d+\b/gi, '' );
        s = s.replace( /\b(19|20)\d{6}\b/g, '' );
        s = s.replace( /\b(19|20)\d{2}(?![a-zA-Z0-9])/g, '' );
        s = s.replace( /\bscaled\b/gi, '' );
        s = s.replace( /\b\d{1,2}\b/g, '' );
        s = s.replace( /\s{2,}/g, ' ' ).trim();
        s = s.replace( /\b[a-zA-Z]/g, function( c ) { return c.toUpperCase(); } );
        return s;
    }

    // -------------------------------------------------------------------------
    // Suggestion / filename classification
    //
    // classifyFilename( rawString ) — classify a raw filename string directly
    // classifySuggestion( obj )     — classify a {type, value} suggestion object
    //
    // Both return 'good' | 'weak' | 'invalid' based on heuristics that match
    // the suggestion pre-population logic in uwgs-list-view and uwgs-attachment-edit.
    // -------------------------------------------------------------------------

    function _classifyFilenameString( value ) {
        var sanitized = sanitizeFilename( value );
        if ( /^https?:\/\//i.test( value ) || /^www\./i.test( value ) ) { return 'invalid'; }
        if ( IMAGE_EXTENSIONS.test( value ) || IMAGE_EXTENSIONS.test( sanitized ) ) { return 'invalid'; }
        if ( sanitized.length < 5 ) { return 'invalid'; }
        if ( /^(IMG|DSC|DSCN|MVI|MOV|P\d)[_\-\s]/i.test( value ) ) { return 'invalid'; }
        var tokens = sanitized.split( /\s+/ ).filter( function( t ) { return t.length > 0; } );
        var meaningfulWords = tokens.filter( function( t ) {
            if ( t.length <= 1 ) { return false; }
            if ( /^\d+$/.test( t ) ) { return false; }
            if ( /^[A-Z0-9]+$/.test( t ) && ! /[AEIOU]/i.test( t ) ) { return false; }
            return true;
        } );
        if ( meaningfulWords.length === 0 ) { return 'invalid'; }
        var junkTokens = tokens.filter( function( t ) { return /^\d+$/.test( t ) || ( t.length <= 3 && /^[A-Z0-9]+$/i.test( t ) ); } );
        if ( junkTokens.length > tokens.length / 2 ) { return 'invalid'; }
        if ( meaningfulWords.length === 1 && tokens.length === 1 ) { return 'weak'; }
        return 'good';
    }

    function classifyFilename( value ) {
        return _classifyFilenameString( value );
    }

    function classifySuggestion( suggestion ) {
        if ( ! suggestion ) { return 'invalid'; }
        if ( suggestion.type === 'caption' ) { return 'good'; }
        return _classifyFilenameString( suggestion.value );
    }

    // -------------------------------------------------------------------------
    // Alt-text quality evaluation — must match UWGS_Alt_Quality (PHP) exactly.
    //
    // needsAttention( alt ) → true when the value needs editorial attention.
    // classify( alt )       → 'good' | 'weak' | 'invalid'
    //
    // Rule changes must be reflected in PHP, JS, and the settings page docs.
    // -------------------------------------------------------------------------

    function needsAttention( alt ) {
        var trimmed = ( alt || '' ).trim();
        if ( trimmed === '' ) { return true; }
        if ( trimmed.length < 3 ) { return true; }
        if ( /^https?:\/\//i.test( trimmed ) ) { return true; }
        if ( /^www\./i.test( trimmed ) ) { return true; }
        if ( IMAGE_EXTENSIONS.test( trimmed ) ) { return true; }
        if ( /^\d+$/.test( trimmed.replace( /\s/g, '' ) ) ) { return true; }
        if ( LOW_QUALITY_WORDS.indexOf( trimmed.toLowerCase() ) !== -1 ) { return true; }
        if ( /^[a-z]{1,6}[_\-]\d+$/i.test( trimmed ) ) { return true; }
        return false;
    }

    function classify( alt ) {
        var trimmed = ( alt || '' ).trim();
        if ( trimmed === '' ) { return 'invalid'; }
        if ( /^https?:\/\//i.test( trimmed ) ) { return 'invalid'; }
        if ( /^www\./i.test( trimmed ) ) { return 'invalid'; }
        if ( IMAGE_EXTENSIONS.test( trimmed ) ) { return 'invalid'; }
        if ( trimmed.length < 3 ) { return 'invalid'; }
        if ( /^\d+$/.test( trimmed.replace( /\s/g, '' ) ) ) { return 'invalid'; }
        if ( LOW_QUALITY_WORDS.indexOf( trimmed.toLowerCase() ) !== -1 ) { return 'invalid'; }
        if ( /^[a-z]{1,6}[_\-]\d+$/i.test( trimmed ) ) { return 'invalid'; }
        var words = trimmed.split( /\s+/ );
        if ( words.length === 1 ) { return 'weak'; }
        return 'good';
    }

    // -------------------------------------------------------------------------
    // Save-time input validation — returns an i18n error string or null.
    // Subset of needsAttention() rules enforced at the moment of save.
    // -------------------------------------------------------------------------

    function validateAltText( value, i18n ) {
        i18n = i18n || {};
        var trimmed = ( value || '' ).trim();
        if ( trimmed === '' ) { return i18n.emptyBlocked || 'Please enter a description before saving.'; }
        if ( /^https?:\/\//i.test( trimmed ) || /^www\./i.test( trimmed ) ) { return i18n.urlRejected || 'URLs cannot be used as alt text.'; }
        if ( IMAGE_EXTENSIONS.test( trimmed ) ) { return i18n.filenameRejected || 'Filenames cannot be used as alt text.'; }
        return null;
    }

    // -------------------------------------------------------------------------
    // REST API helpers
    //
    // checkBatch( ids, opts, callback ) — batch alt-text status check.
    //
    // Tries the WP REST API endpoint first (GET /uwgs-alt-text/v1/attachments/check);
    // falls back to admin-ajax.php (uwgs_check_attachments_alt_batch) on any
    // network or auth error, for compatibility with aggressive caching or custom
    // admin configurations.
    //
    // opts     = { restUrl, restNonce, ajaxUrl, ajaxNonce }
    // callback = function( hasMissing, resultsMap )
    //   hasMissing  — true if any attachment has needs_attention === true
    //   resultsMap  — object keyed by attachment ID string
    // -------------------------------------------------------------------------

    function restCheckBatch( ids, opts, callback ) {
        if ( ! ids || ! ids.length ) { callback( false, {} ); return; }

        if ( opts.restUrl && opts.restNonce ) {
            fetch( opts.restUrl + 'attachments/check', {
                method:  'POST',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': opts.restNonce },
                body:    JSON.stringify( { ids: ids } ),
            } )
                .then( function( r ) {
                    if ( ! r.ok ) { throw new Error( 'REST ' + r.status ); }
                    return r.json();
                } )
                .then( function( data ) {
                    var missing = Object.keys( data ).some( function( k ) {
                        return data[ k ] && data[ k ].needs_attention;
                    } );
                    callback( missing, data );
                } )
                .catch( function() { ajaxCheckBatch( ids, opts, callback ); } );
            return;
        }
        ajaxCheckBatch( ids, opts, callback );
    }

    function ajaxCheckBatch( ids, opts, callback ) {
        var fd = new FormData();
        fd.append( 'action', 'uwgs_check_attachments_alt_batch' );
        fd.append( 'nonce', opts.ajaxNonce );
        ids.forEach( function( id ) { fd.append( 'ids[]', id ); } );
        fetch( opts.ajaxUrl, { method: 'POST', body: fd } )
            .then( function( r ) { return r.json(); } )
            .then( function( resp ) {
                if ( ! resp.success ) { callback( false, {} ); return; }
                var missing = Object.keys( resp.data ).some( function( k ) {
                    return resp.data[ k ] && resp.data[ k ].needs_attention;
                } );
                callback( missing, resp.data );
            } )
            .catch( function() { callback( false, {} ); } );
    }

    return {
        IMAGE_EXTENSIONS:   IMAGE_EXTENSIONS,
        sanitizeFilename:   sanitizeFilename,
        classifyFilename:   classifyFilename,
        classifySuggestion: classifySuggestion,
        needsAttention:     needsAttention,
        classify:           classify,
        validateAltText:    validateAltText,
        rest: {
            checkBatch: restCheckBatch,
        },
    };
} )();
