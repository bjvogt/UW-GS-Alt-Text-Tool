/**
 * Media grid overlay — upload.php grid mode only.
 *
 * Overrides wp.media.view.Attachment.prototype.render to add CSS classes and a
 * data-uwgs-label attribute to tiles whose images have missing or weak alt text.
 * The CSS ::after overlay is defined in enqueue_media_grid_assets() (PHP).
 *
 * Data supplied before this script via wp_add_inline_script 'before':
 *   window.uwgsMediaGridData = { i18n: { missingAlt, weakAlt } }
 *
 * The extra JSON fields (uwgsNeedsAttention, uwgsClassification) are added to
 * every image attachment's wp.media model via the wp_prepare_attachment_for_js
 * filter in the plugin PHP.
 */
( function( data ) {
    'use strict';

    var i18n = ( data && data.i18n ) || {};

    function applyOverride() {
        if ( typeof wp === 'undefined' || ! wp.media || ! wp.media.view || ! wp.media.view.Attachment ) { return; }

        var originalRender = wp.media.view.Attachment.prototype.render;

        wp.media.view.Attachment.prototype.render = function() {
            originalRender.apply( this, arguments );

            if ( this.model.get( 'type' ) !== 'image' ) { return this; }

            var needsAttention = this.model.get( 'uwgsNeedsAttention' );
            var classification = this.model.get( 'uwgsClassification' ) || 'invalid';
            // Target .attachment-preview (position:relative, overflow:visible) so the
            // ::after badge is not clipped by .thumbnail's overflow:hidden.
            var $preview = this.$el.find( '.attachment-preview' );

            this.$el.removeClass( 'uwgs-alt-missing uwgs-alt-weak' );
            $preview.removeAttr( 'data-uwgs-label' );

            if ( needsAttention ) {
                if ( classification === 'weak' ) {
                    this.$el.addClass( 'uwgs-alt-weak' );
                    $preview.attr( 'data-uwgs-label', i18n.weakAlt || 'Alt text needs refinement' );
                } else {
                    this.$el.addClass( 'uwgs-alt-missing' );
                    $preview.attr( 'data-uwgs-label', i18n.missingAlt || 'Please provide alt text' );
                }
            }

            return this;
        };
    }

    // Apply override immediately if wp.media is already initialized (e.g., if
    // media-views loaded synchronously). Otherwise defer to DOMContentLoaded.
    if ( typeof wp !== 'undefined' && wp.media && wp.media.view && wp.media.view.Attachment ) {
        applyOverride();
    } else {
        document.addEventListener( 'DOMContentLoaded', applyOverride );
    }

} )( typeof uwgsMediaGridData !== 'undefined' ? uwgsMediaGridData : {} );
