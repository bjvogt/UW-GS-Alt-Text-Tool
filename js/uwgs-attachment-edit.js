jQuery( function( $ ) {

    var data       = ( typeof uwgsAttachData !== 'undefined' ) ? uwgsAttachData : {};
    var i18n       = data.i18n         || {};
    var shouldCopy = data.shouldCopy   || false;
    var capVal     = data.captionValue || '';
    var suggestion = data.suggestion   || null;
    var altIsBlank = data.altIsBlank   || false;
    var warned     = false;

    // FIX v2.4.4 (Issue 3): Broadened selector — tries multiple possible
    // field IDs used by WordPress on the attachment edit screen.
    var $altField = $( '#attachment_alt' ).add( $( 'input[name="attachments[' + $( 'input[name^="post_ID"]' ).val() + '][_wp_attachment_image_alt]"]' ) ).first();

    // Fallback: find any input that looks like the alt text field
    if ( ! $altField.length ) {
        $altField = $( 'input[name*="attachment_image_alt"], input[id*="attachment_alt"], textarea[id*="attachment_alt"]' ).first();
    }

    // Last resort: look for the field near the label "Alternative Text"
    if ( ! $altField.length ) {
        $( 'label' ).each( function() {
            if ( $( this ).text().toLowerCase().indexOf( 'alt' ) !== -1 ) {
                var $field = $( this ).next( 'input, textarea' );
                if ( $field.length ) { $altField = $field; return false; }
                var forId = $( this ).attr( 'for' );
                if ( forId ) { $altField = $( '#' + forId ); return false; }
            }
        } );
    }

    if ( ! $altField.length ) { return; }

    var $submitBtn = $( '#publish, #save-post, input[name="save"], input[type="submit"]' );

    var IMAGE_EXTENSIONS = /\.(jpg|jpeg|png|gif|webp|svg|bmp|tiff?|avif|heic|heif)$/i;

    function sanitizeFilename( raw ) {
        var s = raw;
        s = s.replace( /&[#a-zA-Z0-9]+;/g, ' ' );
        s = s.replace( /\.[a-zA-Z0-9]+$/, '' );
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

    function classifyFilename( value ) {
        var sanitized = sanitizeFilename( value );
        var original  = value;
        if ( /^https?:\/\//i.test( original ) || /^www\./i.test( original ) ) { return 'invalid'; }
        if ( IMAGE_EXTENSIONS.test( original ) || IMAGE_EXTENSIONS.test( sanitized ) ) { return 'invalid'; }
        if ( sanitized.length < 5 ) { return 'invalid'; }
        if ( /^(IMG|DSC|DSCN|MVI|MOV|P\d)[_\-\s]/i.test( original ) ) { return 'invalid'; }
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

    // Show inline page notice if alt is blank on load
    if ( altIsBlank ) {
        var $blankNotice = $( '<div>' ).addClass( 'uwgs-attachment-blank-notice' ).text( i18n.blankNotice || '⚠ This image has no alt text. Please add a description below.' );
        $altField.before( $blankNotice );
    }

    // Apply suggestion if alt is currently empty
    if ( suggestion && $altField.val().trim() === '' ) {
        var hintText = '';

        if ( suggestion.type === 'caption' ) {
            $altField.val( suggestion.value );
            hintText = i18n.fromCaption || 'Suggested from caption — please review before saving';
        } else {
            var sanitized  = sanitizeFilename( suggestion.value );
            var confidence = classifyFilename( suggestion.value );
            if ( confidence === 'good' ) {
                $altField.val( sanitized );
                hintText = i18n.fromFilename || 'Suggested from filename — please review before saving';
            } else if ( confidence === 'weak' ) {
                $altField.val( sanitized );
                hintText = i18n.lowConfidence || 'This may be too brief — consider adding more detail';
            } else {
                hintText = i18n.invalidSuggest || 'This looks like a filename or URL — please write a meaningful description';
            }
        }

        if ( hintText ) {
            var $hint = $( '<span>' ).addClass( 'uwgs-attach-suggestion-hint' ).text( hintText );
            $altField.after( $hint );
            $altField.one( 'input', function() { $hint.remove(); } );
        }
    }

    // Save warning — same pattern as classic editor
    var $warning = $( '<div>' ).addClass( 'uwgs-attachment-alt-warning' ).attr( { 'role': 'alert', 'aria-live': 'assertive' } ).text( i18n.warningText || '⚠ This image has no alt text. Please add a description or click Update again to proceed.' );
    $altField.after( $warning );

    $altField.on( 'input', function() {
        if ( $( this ).val().trim().length ) {
            $( this ).removeClass( 'uwgs-alt-field-highlight' );
            $warning.removeClass( 'visible' );
            warned = false;
            // Remove blank notice once editor starts typing
            $( '.uwgs-attachment-blank-notice' ).remove();
        }
    } );

    $submitBtn.on( 'click', function( e ) {
        if ( $altField.val().trim().length ) { warned = false; return true; }
        if ( ! warned ) {
            e.preventDefault();
            warned = true;
            $altField.addClass( 'uwgs-alt-field-highlight' );
            $warning.addClass( 'visible' );
            $altField.trigger( 'focus' );
            return false;
        }
        warned = false;
        return true;
    } );

} );
