jQuery( function( $ ) {

    var data       = ( typeof uwgsAttachData !== 'undefined' ) ? uwgsAttachData : {};
    var i18n       = data.i18n         || {};
    var shouldCopy = data.shouldCopy   || false;
    var capVal     = data.captionValue || '';
    var suggestion = data.suggestion   || null;
    var altIsBlank = data.altIsBlank   || false;
    var warned     = false;

    // Filename sanitization and classification delegated to UWGSAltUtils
    // (uwgs-alt-utils.js), which is the canonical source for all scoring logic.
    var Utils = window.UWGSAltUtils;

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
            var sanitized  = Utils.sanitizeFilename( suggestion.value );
            var confidence = Utils.classifyFilename( suggestion.value );
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
