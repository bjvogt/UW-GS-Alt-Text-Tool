<?php
/**
 * Plugin Name:       UWGS Alt Text Tool
 * Plugin URI:        https://grad.uw.edu
 * Description:       Adds an Alt Text column to the Media Library list view with sortable, filterable,
 *                    and inline-editable alt text. Warns editors when images are missing alt text at
 *                    save/publish time in both classic and block editors. Copies caption to alt text
 *                    with notice on attachment edit screen and in the Add Media modal. Updates upload
 *                    status messages to prompt alt text entry. Built for UW Graduate School.
 * Version:           1.8.0
 * Author:            UW Graduate School
 * Author URI:        https://grad.uw.edu
 * License:           GPL-2.0+
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       uwgs-alt-text-tool
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class UWGS_Alt_Text_Tool {

    const NONCE_ACTION  = 'uwgs_alt_text_inline_save';
    const META_KEY      = '_wp_attachment_image_alt';
    const NEEDS_ALT_KEY = '_uwgs_needs_alt';
    const VERSION       = '1.7.0';

    public static function init() {
        $instance = new self();
        $instance->hooks();
    }

    private function hooks() {

        // Media Library list view
        add_filter( 'manage_media_columns',           array( $this, 'register_column' ) );
        add_action( 'manage_media_custom_column',     array( $this, 'render_column' ), 10, 2 );
        add_filter( 'manage_upload_sortable_columns', array( $this, 'register_sortable' ) );

        // Query: sort + blank filter
        add_action( 'pre_get_posts', array( $this, 'handle_query' ) );

        // Toolbar filter button
        add_action( 'restrict_manage_posts', array( $this, 'render_filter_button' ) );

        // Admin assets
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );

        // AJAX: inline save
        add_action( 'wp_ajax_uwgs_save_alt_text', array( $this, 'ajax_save_alt_text' ) );

        // Flag new image uploads missing alt text
        add_action( 'add_attachment', array( $this, 'flag_new_upload' ) );

        // Gutenberg: block canvas warning + pre-publish panel
        add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_block_editor_assets' ) );
    }

    // =========================================================================
    // MEDIA LIBRARY LIST VIEW: COLUMN
    // =========================================================================

    public function register_column( $columns ) {
        $columns['uwgs_alt_text'] = __( 'Alt Text', 'uwgs-alt-text-tool' );
        return $columns;
    }

    public function render_column( $column_name, $post_id ) {
        if ( 'uwgs_alt_text' !== $column_name ) {
            return;
        }

        $mime     = get_post_mime_type( $post_id );
        $is_image = ( strpos( $mime, 'image/' ) === 0 );

        if ( ! $is_image ) {
            echo '<span style="color:#999;" aria-label="'. esc_attr__( 'Not applicable', 'uwgs-alt-text-tool' ). '">—</span>';
            return;
        }

        $alt      = get_post_meta( $post_id, self::META_KEY, true );
        $nonce    = wp_create_nonce( self::NONCE_ACTION. '_'. $post_id );
        $needs    = get_post_meta( $post_id, self::NEEDS_ALT_KEY, true );
        $can_edit = current_user_can( 'edit_post', $post_id );

        ?>
        <div class="uwgs-alt-wrap" data-post-id="<?php echo esc_attr( $post_id ); ?>">

            <div class="uwgs-alt-display">

                <?php if ( ! empty( $alt ) ) : ?>
                    <span class="uwgs-alt-value uwgs-has-alt"><?php echo esc_html( $alt ); ?></span>
                <?php else : ?>
                    <span class="uwgs-alt-value uwgs-alt-blank"
                          aria-label="<?php esc_attr_e( 'Alt text is blank', 'uwgs-alt-text-tool' ); ?>">
                        <?php esc_html_e( '(blank)', 'uwgs-alt-text-tool' ); ?>
                    </span>
                    <?php if ( $needs ) : ?>
                        <span class="uwgs-alt-new-flag"
                              aria-label="<?php esc_attr_e( 'Uploaded without alt text', 'uwgs-alt-text-tool' ); ?>"
                              title="<?php esc_attr_e( 'This image was uploaded without alt text', 'uwgs-alt-text-tool' ); ?>">
                            <?php esc_html_e( 'New', 'uwgs-alt-text-tool' ); ?>
                        </span>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if ( $can_edit ) : ?>
                    <button type="button"
                            class="uwgs-alt-edit-btn button-link"
                            aria-label="<?php echo esc_attr( sprintf(
                                __( 'Edit alt text for attachment %d', 'uwgs-alt-text-tool' ),
                                $post_id
                            ) ); ?>"
                            aria-expanded="false"
                            aria-controls="uwgs-alt-editor-<?php echo esc_attr( $post_id ); ?>">
                        ✎ <?php esc_html_e( 'Edit', 'uwgs-alt-text-tool' ); ?>
                    </button>
                <?php endif; ?>

            </div>

            <?php if ( $can_edit ) : ?>
            <div class="uwgs-alt-editor"
                 id="uwgs-alt-editor-<?php echo esc_attr( $post_id ); ?>"
                 style="display:none;margin-top:6px;"
                 role="group"
                 aria-label="<?php esc_attr_e( 'Alt text editor', 'uwgs-alt-text-tool' ); ?>">

                <label for="uwgs-alt-input-<?php echo esc_attr( $post_id ); ?>"
                       class="screen-reader-text">
                    <?php esc_html_e( 'Alt text', 'uwgs-alt-text-tool' ); ?>
                </label>
                <input type="text"
                       id="uwgs-alt-input-<?php echo esc_attr( $post_id ); ?>"
                       class="uwgs-alt-input"
                       value="<?php echo esc_attr( $alt ); ?>"
                       placeholder="<?php esc_attr_e( 'Enter alt text…', 'uwgs-alt-text-tool' ); ?>"
                       style="width:100%;max-width:280px;"
                />
                <div style="margin-top:4px;">
                    <button type="button"
                            class="uwgs-alt-save-btn button button-primary button-small"
                            data-nonce="<?php echo esc_attr( $nonce ); ?>">
                        <?php esc_html_e( 'Save', 'uwgs-alt-text-tool' ); ?>
                    </button>
                    <button type="button"
                            class="uwgs-alt-cancel-btn button button-small"
                            style="margin-left:4px;">
                        <?php esc_html_e( 'Cancel', 'uwgs-alt-text-tool' ); ?>
                    </button>
                    <span class="uwgs-alt-spinner spinner"
                          style="float:none;margin:0 4px;vertical-align:middle;"
                          aria-hidden="true"></span>
                    <span class="uwgs-alt-feedback"
                          role="status"
                          aria-live="polite"
                          style="font-size:12px;"></span>
                </div>

            </div>
            <?php endif; ?>

        </div>
        <?php
    }

    public function register_sortable( $sortable_columns ) {
        $sortable_columns['uwgs_alt_text'] = 'uwgs_alt_text';
        return $sortable_columns;
    }

    // =========================================================================
    // QUERY: SORT + BLANK FILTER
    // =========================================================================

    public function handle_query( $query ) {
        if ( ! is_admin() || ! $query->is_main_query() ) {
            return;
        }

        $screen = get_current_screen();
        if ( ! $screen || 'upload' !== $screen->id ) {
            return;
        }

        if ( 'uwgs_alt_text' === $query->get( 'orderby' ) ) {
            $query->set( 'meta_key', self::META_KEY );
            $query->set( 'orderby', 'meta_value' );
        }

        if ( isset( $_GET['alt_filter'] ) && 'blank' === sanitize_key( $_GET['alt_filter'] ) ) {
            $query->set( 'post_mime_type', 'image' );
            $query->set( 'meta_query', array(
                'relation' => 'OR',
                array(
                    'key'     => self::META_KEY,
                    'compare' => 'NOT EXISTS',
                ),
                array(
                    'key'     => self::META_KEY,
                    'value'   => '',
                    'compare' => '=',
                ),
            ) );
        }
    }

    // =========================================================================
    // TOOLBAR FILTER BUTTON
    // =========================================================================

    public function render_filter_button( $post_type ) {
        if ( 'attachment' !== $post_type ) {
            return;
        }

        $current  = isset( $_GET['alt_filter'] ) ? sanitize_key( $_GET['alt_filter'] ) : '';
        $base_url = admin_url( 'upload.php' );

        $passthrough = array( 'm', 's', 'author', 'post_mime_type' );
        $extra       = array();
        foreach ( $passthrough as $param ) {
            if ( ! empty( $_GET[ $param ] ) ) {
                $extra[ $param ] = sanitize_text_field( $_GET[ $param ] );
            }
        }

        $is_active = ( 'blank' === $current );
        $blank_url = add_query_arg( array_merge( $extra, array( 'alt_filter' => 'blank' ) ), $base_url );
        $clear_url = add_query_arg( array_merge( $extra, array( 'alt_filter' => '' ) ), $base_url );

        printf(
            '<a href="%s" class="button%s" style="margin-left:4px;" aria-pressed="%s">%s</a>',
            esc_url( $is_active ? $clear_url : $blank_url ),
            $is_active ? ' button-primary' : '',
            $is_active ? 'true' : 'false',
            $is_active
                ? esc_html__( '✕ Clear Alt Filter', 'uwgs-alt-text-tool' )
                : esc_html__( '⚠ Blank Alt Text', 'uwgs-alt-text-tool' )
        );
    }

    // =========================================================================
    // FLAG NEW UPLOADS
    // =========================================================================

    public function flag_new_upload( $post_id ) {
        $mime = get_post_mime_type( $post_id );
        if ( strpos( $mime, 'image/' ) !== 0 ) {
            return;
        }
        $alt = get_post_meta( $post_id, self::META_KEY, true );
        if ( empty( $alt ) ) {
            update_post_meta( $post_id, self::NEEDS_ALT_KEY, '1' );
        }
    }

    // =========================================================================
    // ADMIN ASSETS
    // =========================================================================

    public function enqueue_admin_assets( $hook ) {

        // Media Library list view
        if ( 'upload.php' === $hook ) {
            $this->enqueue_list_view_assets();
        }

        // Media -> Add New: per-file upload status message
        if ( 'media-new.php' === $hook ) {
            $this->enqueue_upload_page_assets();
        }

        // Post edit screens
        if ( in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {

            // Attachment edit screen
            global $post;
            if (
                $post
                && 'post.php' === $hook
                && 'attachment' === get_post_type( $post->ID )
                && strpos( get_post_mime_type( $post->ID ), 'image/' ) === 0
            ) {
                $this->enqueue_attachment_edit_assets( $post->ID );
            }

            // Classic editor: pre-save TinyMCE scan
            if ( ! did_action( 'enqueue_block_editor_assets' ) ) {
                $this->enqueue_classic_presave_assets();
            }

            // Add Media modal: caption-to-alt via MutationObserver
            if ( ! did_action( 'wp_enqueue_media' ) ) {
                wp_enqueue_media();
            }
            $this->enqueue_media_modal_caption_assets();
        }
    }

    // -------------------------------------------------------------------------
    // List view assets
    // -------------------------------------------------------------------------

    private function enqueue_list_view_assets() {

        $css = '.uwgs-alt-wrap                      { line-height:1.6; }.uwgs-has-alt                       { color:#2e7d32; }.uwgs-alt-blank                     { color:#c62828; font-weight:600; }.uwgs-alt-new-flag                  {
                display:inline-block;
                margin-left:4px; font-size:11px;
                background:#fff3cd; color:#856404;
                border:1px solid #ffc107; border-radius:3px;
                padding:1px 5px; vertical-align:middle;
            }.uwgs-alt-edit-btn                  {
                cursor:pointer; text-decoration:underline;
                color:#2271b1; margin-left:6px; font-size:12px;
                background:none; border:none; padding:0;
            }.uwgs-alt-edit-btn:hover            { color:#135e96; }.uwgs-alt-feedback.success          { color:#2e7d32; }.uwgs-alt-feedback.error            { color:#c62828; }.uwgs-alt-editor input[type="text"] { font-size:13px; }
        ';

        wp_register_style( 'uwgs-alt-text-tool', false, array(), self::VERSION );
        wp_enqueue_style( 'uwgs-alt-text-tool' );
        wp_add_inline_style( 'uwgs-alt-text-tool', $css );

        $data = array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'i18n'    => array(
                'saveFailed'    => __( 'Save failed. Please try again.', 'uwgs-alt-text-tool' ),
                'requestFailed' => __( 'Request failed. Please try again.', 'uwgs-alt-text-tool' ),
                'saved'         => __( 'Saved.', 'uwgs-alt-text-tool' ),
                'blank'         => __( '(blank)', 'uwgs-alt-text-tool' ),
            ),
        );

        wp_add_inline_script(
            'jquery',
            'var uwgsAltData = '. wp_json_encode( $data ). ';'
        );

        $js = <<<'JS'
jQuery( function( $ ) {

    var data    = ( typeof uwgsAltData !== 'undefined' ) ? uwgsAltData : {};
    var ajaxUrl = data.ajaxUrl || '';
    var i18n    = data.i18n   || {};

    $( document ).on( 'click', '.uwgs-alt-edit-btn', function() {
        var $wrap = $( this ).closest( '.uwgs-alt-wrap' );
        $wrap.find( '.uwgs-alt-display' ).hide();
        var $editor = $wrap.find( '.uwgs-alt-editor' );
        $editor.show();
        $editor.find( '.uwgs-alt-input' ).trigger( 'focus' );
        $( this ).attr( 'aria-expanded', 'true' );
    } );

    $( document ).on( 'click', '.uwgs-alt-cancel-btn', function() {
        var $wrap = $( this ).closest( '.uwgs-alt-wrap' );
        $wrap.find( '.uwgs-alt-editor' ).hide();
        var $display = $wrap.find( '.uwgs-alt-display' );
        $display.show();
        $display.find( '.uwgs-alt-edit-btn' ).attr( 'aria-expanded', 'false' ).trigger( 'focus' );
        $wrap.find( '.uwgs-alt-feedback' ).text( '' ).removeClass( 'success error' );
    } );

    $( document ).on( 'keydown', '.uwgs-alt-input', function( e ) {
        if ( 13 === e.which ) {
            e.preventDefault();
            $( this ).closest( '.uwgs-alt-wrap' ).find( '.uwgs-alt-save-btn' ).trigger( 'click' );
        }
        if ( 27 === e.which ) {
            $( this ).closest( '.uwgs-alt-wrap' ).find( '.uwgs-alt-cancel-btn' ).trigger( 'click' );
        }
    } );

    $( document ).on( 'click', '.uwgs-alt-save-btn', function() {
        var $btn      = $( this );
        var $wrap     = $btn.closest( '.uwgs-alt-wrap' );
        var postId    = $wrap.data( 'post-id' );
        var nonce     = $btn.data( 'nonce' );
        var altText   = $wrap.find( '.uwgs-alt-input' ).val().trim();
        var $spinner  = $wrap.find( '.uwgs-alt-spinner' );
        var $feedback = $wrap.find( '.uwgs-alt-feedback' );

        $btn.prop( 'disabled', true );
        $spinner.addClass( 'is-active' ).attr( 'aria-hidden', 'false' );
        $feedback.text( '' ).removeClass( 'success error' );

        $.ajax( {
            url:  ajaxUrl,
            type: 'POST',
            data: {
                action:   'uwgs_save_alt_text',
                post_id:  postId,
                alt_text: altText,
                nonce:    nonce
            },
            success: function( response ) {
                if ( response.success ) {
                    var $display = $wrap.find( '.uwgs-alt-display' );
                    var $value   = $display.find( '.uwgs-alt-value' );

                    if ( altText.length ) {
                        $value.text( altText ).removeClass( 'uwgs-alt-blank' ).addClass( 'uwgs-has-alt' ).css( 'font-weight', 'normal' ).removeAttr( 'aria-label' );
                        $wrap.find( '.uwgs-alt-new-flag' ).remove();
                    } else {
                        $value.text( i18n.blank || '(blank)' ).removeClass( 'uwgs-has-alt' ).addClass( 'uwgs-alt-blank' ).attr( 'aria-label', 'Alt text is blank' );
                    }

                    $wrap.find( '.uwgs-alt-editor' ).hide();
                    $display.show();
                    $display.find( '.uwgs-alt-edit-btn' ).attr( 'aria-expanded', 'false' ).trigger( 'focus' );

                    $feedback.text( i18n.saved || 'Saved.' ).addClass( 'success' );
                    setTimeout( function() {
                        $feedback.text( '' ).removeClass( 'success' );
                    }, 3000 );

                } else {
                    $feedback.text( response.data || i18n.saveFailed ).addClass( 'error' );
                }
            },
            error: function() {
                $feedback.text( i18n.requestFailed ).addClass( 'error' );
            },
            complete: function() {
                $btn.prop( 'disabled', false );
                $spinner.removeClass( 'is-active' ).attr( 'aria-hidden', 'true' );
            }
        } );
    } );

} );
JS;

        wp_add_inline_script( 'jquery', $js );
    }

    // -------------------------------------------------------------------------
    // Media -> Add New: per-file upload status message
    //
    // Uses MutationObserver on #media-items to detect when each upload row
    // reaches its final state (edit link appears) and replaces the link text
    // with a prompt to add alt text. Only fires for image uploads.
    // -------------------------------------------------------------------------

    private function enqueue_upload_page_assets() {

        $i18n = array(
            'editPrompt' => __( 'Upload complete — click here to edit and add alt text', 'uwgs-alt-text-tool' ),
        );

        wp_add_inline_script(
            'jquery',
            'var uwgsUploadI18n = '. wp_json_encode( $i18n ). ';'
        );

        $js = <<<'JS'
( function() {

    'use strict';

    var i18n       = ( typeof uwgsUploadI18n !== 'undefined' ) ? uwgsUploadI18n : {};
    var promptText = i18n.editPrompt || 'Upload complete — click here to edit and add alt text';

    /**
     * Process a single upload row.
     * Finds the edit link and replaces its text if this is an image row
     * and the edit link hasn't already been updated.
     */
    function processRow( row ) {
        if ( ! row ) { return; }

        // Only process image rows — check for image thumbnail or type class
        var isImage = (
            row.querySelector( '.media-item img' ) !== null ||
            row.className.indexOf( 'type-image' ) > -1 ||
            row.querySelector( '.filename' ) !== null
        );

        // Find the edit link — WP renders it as an <a> inside.edit-attachment
        // or as a direct link in the row's.filename or.edit area
        var editLink = row.querySelector( 'a[href*="action=edit"]' )
                    || row.querySelector( '.edit-attachment a' )
                    || row.querySelector( '.media-item-info a' );

        if ( ! editLink ) { return; }

        // Don't update twice
        if ( editLink.getAttribute( 'data-uwgs-updated' ) ) { return; }

        // Only update for images — check the href for attachment edit screen
        // and confirm the row doesn't already show an error state
        if ( row.querySelector( '.upload-error' ) ) { return; }

        editLink.textContent = promptText;
        editLink.setAttribute( 'data-uwgs-updated', '1' );
        editLink.style.fontWeight = '600';
        editLink.style.color      = '#856404';
    }

    /**
     * Observe #media-items for new rows and changes within rows.
     * Fires processRow when an edit link appears in a row.
     */
    function observeUploadList() {
        var container = document.getElementById( 'media-items' );
        if ( ! container ) { return; }

        var observer = new MutationObserver( function( mutations ) {
            mutations.forEach( function( mutation ) {

                // New rows added
                mutation.addedNodes.forEach( function( node ) {
                    if ( node.nodeType !== 1 ) { return; }
                    // The node itself might be the row
                    if ( node.id && node.id.indexOf( 'media-item-' ) === 0 ) {
                        processRow( node );
                    }
                    // Or it might contain rows
                    var rows = node.querySelectorAll
                        ? node.querySelectorAll( '[id^="media-item-"]' )
                        : [];
                    rows.forEach( processRow );
                } );

                // Changes within existing rows (edit link added after upload)
                if (
                    mutation.type === 'childList' &&
                    mutation.target &&
                    mutation.target.closest
                ) {
                    var row = mutation.target.closest( '[id^="media-item-"]' );
                    if ( row ) {
                        processRow( row );
                    }
                }
            } );
        } );

        observer.observe( container, {
            childList: true,
            subtree:   true,
        } );
    }

    // Start observing when DOM is ready
    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', observeUploadList );
    } else {
        observeUploadList();
    }

} )();
JS;

        wp_add_inline_script( 'jquery', $js, 'after' );
    }

    // -------------------------------------------------------------------------
    // Attachment edit screen assets
    //
    // Reads alt and caption server-side at render time.
    // If alt is empty and caption exists, pre-populates alt field via JS
    // and shows a yellow "Copied from caption — please review" notice.
    // -------------------------------------------------------------------------

    private function enqueue_attachment_edit_assets( $post_id ) {

        $alt     = get_post_meta( $post_id, self::META_KEY, true );
        $caption = get_post_field( 'post_excerpt', $post_id );

        // Determine if we should copy caption to alt
        $should_copy = ( empty( $alt ) && ! empty( $caption ) );

        $css = '.uwgs-attachment-alt-warning {
                display:none; margin-top:6px; padding:8px 10px;
                background:#fff3cd; border-left:4px solid #ffc107;
                color:#856404; font-size:13px; border-radius:0 3px 3px 0;
            }.uwgs-attachment-alt-warning.visible { display:block; }
            #attachment_alt.uwgs-field-highlight {
                border-color:#c62828 !important;
                box-shadow:0 0 0 1px #c62828 !important;
            }.uwgs-caption-copy-notice {
                display:flex;
                align-items:flex-start;
                gap:8px;
                margin-top:6px;
                padding:7px 10px;
                background:#fff3cd;
                border-left:4px solid #ffc107;
                color:#856404;
                font-size:12px;
                line-height:1.5;
                border-radius:0 3px 3px 0;
            }.uwgs-caption-copy-notice button {
                background:none; border:none; padding:0;
                cursor:pointer; color:#856404;
                font-size:14px; line-height:1;
                flex-shrink:0; margin-left:auto;
            }.uwgs-caption-copy-notice button:hover { color:#5a4000; }
        ';

        wp_register_style( 'uwgs-attachment-edit', false, array(), self::VERSION );
        wp_enqueue_style( 'uwgs-attachment-edit' );
        wp_add_inline_style( 'uwgs-attachment-edit', $css );

        $i18n = array(
            'warningText'     => __( '⚠ This image has no alt text. Alt text is required for accessibility. Please add a description before saving, or confirm this image is decorative by clicking Save again.', 'uwgs-alt-text-tool' ),
            'captionCopied'   => __( 'Alt text copied from caption — please review and edit if needed before saving.', 'uwgs-alt-text-tool' ),
            'dismissNotice'   => __( 'Dismiss', 'uwgs-alt-text-tool' ),
        );

        $data = array(
            'i18n'        => $i18n,
            'shouldCopy'  => $should_copy,
            'captionValue' => $should_copy ? sanitize_text_field( $caption ) : '',
        );

        wp_add_inline_script(
            'jquery',
            'var uwgsAttachData = '. wp_json_encode( $data ). ';'
        );

        $js = <<<'JS'
jQuery( function( $ ) {

    var data       = ( typeof uwgsAttachData !== 'undefined' ) ? uwgsAttachData : {};
    var i18n       = data.i18n         || {};
    var shouldCopy = data.shouldCopy   || false;
    var capVal     = data.captionValue || '';

    var $altField  = $( '#attachment_alt' );
    var $submitBtn = $( '#publish, input[name="save"]' );
    var warned     = false;

    if ( ! $altField.length ) { return; }

    // -----------------------------------------------------------------
    // CAPTION → ALT: pre-populate + show review notice
    // -----------------------------------------------------------------

    if ( shouldCopy && capVal ) {

        // Set the alt field value
        $altField.val( capVal );

        // Build and insert the review notice
        var $notice = $( '<div>' ).addClass( 'uwgs-caption-copy-notice' ).attr( { 'role': 'note', 'aria-live': 'polite' } );

        var $msg = $( '<span>' ).text( i18n.captionCopied || 'Alt text copied from caption — please review before saving.' );

        var $dismiss = $( '<button>' ).attr( {
                'type':       'button',
                'aria-label': i18n.dismissNotice || 'Dismiss',
                'title':      i18n.dismissNotice || 'Dismiss',
            } ).html( '✕' ).on( 'click', function() {
                $notice.remove();
            } );

        $notice.append( $msg ).append( $dismiss );
        $altField.after( $notice );

        // Clear notice when editor modifies the field
        $altField.one( 'input', function() {
            $notice.remove();
        } );
    }

    // -----------------------------------------------------------------
    // SAVE WARNING: soft block if alt still empty
    // -----------------------------------------------------------------

    var $warning = $( '<div>' ).addClass( 'uwgs-attachment-alt-warning' ).attr( { 'role': 'alert', 'aria-live': 'assertive' } ).text( i18n.warningText || '⚠ This image has no alt text. Please add a description or click Save again to proceed.' );

    // Insert warning after notice (if present) or after alt field
    $altField.after( $warning );

    $altField.on( 'input', function() {
        if ( $( this ).val().trim().length ) {
            $( this ).removeClass( 'uwgs-field-highlight' );
            $warning.removeClass( 'visible' );
            warned = false;
        }
    } );

    $submitBtn.on( 'click', function( e ) {
        if ( $altField.val().trim().length ) {
            warned = false;
            return true;
        }
        if ( ! warned ) {
            e.preventDefault();
            warned = true;
            $altField.addClass( 'uwgs-field-highlight' );
            $warning.addClass( 'visible' );
            $altField.trigger( 'focus' );
            return false;
        }
        warned = false;
        return true;
    } );

} );
JS;

        wp_add_inline_script( 'jquery', $js );
    }

    // -------------------------------------------------------------------------
    // Add Media modal: caption-to-alt via MutationObserver
    //
    // When the attachment details sidebar renders in the media modal,
    // checks if alt is empty and caption has a value.
    // If so, copies caption to alt field and shows a review notice.
    // Uses MutationObserver — no Backbone or wp.media timing dependency.
    // -------------------------------------------------------------------------

private function enqueue_media_modal_caption_assets() {

    $i18n = array(
        'captionCopied' => __( 'Copied from caption — please review before inserting.', 'uwgs-alt-text-tool' ),
    );

    wp_add_inline_script(
        'jquery',
        'var uwgsModalCapI18n = '. wp_json_encode( $i18n ). ';'
    );

    $js = <<<'JS'
( function() {

    'use strict';

    var i18n = ( typeof uwgsModalCapI18n !== 'undefined' ) ? uwgsModalCapI18n : {};

    /**
     * Apply caption-to-alt copy on a confirmed details panel.
     * Uses confirmed field IDs from DOM inspection:
     *   Alt:     #attachment-details-alt-text     (TEXTAREA)
     *   Caption: #attachment-details-caption      (TEXTAREA)
     */
    function applyCaptionToAlt( panel ) {
        if ( ! panel ) { return; }

        // Use confirmed IDs first, fall back to broader selectors
        var altField = panel.querySelector( '#attachment-details-alt-text' )
                    || panel.querySelector( 'textarea[id*="alt-text"]' )
                    || panel.querySelector( '.setting.alt-text textarea' )
                    || panel.querySelector( '[data-setting="alt"] textarea' )
                    || panel.querySelector( '[data-setting="alt"] input' );

        var capField = panel.querySelector( '#attachment-details-caption' )
                    || panel.querySelector( 'textarea[id*="caption"]' )
                    || panel.querySelector( '[data-setting="caption"] textarea' )
                    || panel.querySelector( '[data-setting="caption"] input' );

        if ( ! altField || ! capField ) { return; }

        var altVal = altField.value.trim();
        var capVal = capField.value.trim();

        // Only copy if alt is empty and caption has a value
        if ( altVal !== '' || capVal === '' ) { return; }

        // Don't apply twice to the same panel instance
        if ( altField.getAttribute( 'data-uwgs-cap-applied' ) ) { return; }
        altField.setAttribute( 'data-uwgs-cap-applied', '1' );

        // Set alt field value
        altField.value = capVal;

        // Trigger change so Backbone model syncs the value
        altField.dispatchEvent( new Event( 'change', { bubbles: true } ) );
        altField.dispatchEvent( new Event( 'input',  { bubbles: true } ) );

        // Show review notice below the alt field's parent.setting span
        var existing = panel.querySelector( '.uwgs-modal-cap-notice' );
        if ( existing ) { existing.remove(); }

        var notice = document.createElement( 'div' );
        notice.className = 'uwgs-modal-cap-notice';
        notice.setAttribute( 'role', 'note' );
        notice.style.cssText = [
            'margin-top:4px',
            'padding:5px 8px',
            'background:#fff3cd',
            'border-left:3px solid #ffc107',
            'color:#856404',
            'font-size:11px',
            'line-height:1.4',
            'border-radius:0 2px 2px 0',
            'display:flex',
            'align-items:center',
            'justify-content:space-between',
            'gap:6px',
        ].join( ';' );

        var msg = document.createElement( 'span' );
        msg.textContent = i18n.captionCopied || 'Copied from caption — please review before inserting.';

        var dismiss = document.createElement( 'button' );
        dismiss.type        = 'button';
        dismiss.textContent = '✕';
        dismiss.setAttribute( 'aria-label', 'Dismiss' );
        dismiss.style.cssText = [
            'background:none',
            'border:none',
            'cursor:pointer',
            'color:#856404',
            'font-size:12px',
            'padding:0',
            'flex-shrink:0',
        ].join( ';' );
        dismiss.addEventListener( 'click', function() {
            notice.remove();
        } );

        notice.appendChild( msg );
        notice.appendChild( dismiss );

        // Insert after the.setting.alt-text span, or after the field itself
        var altSetting = altField.closest( '.setting' );
        if ( altSetting && altSetting.parentNode ) {
            altSetting.parentNode.insertBefore( notice, altSetting.nextSibling );
        } else {
            altField.parentNode.insertBefore( notice, altField.nextSibling );
        }

        // Remove notice and clear flag when editor changes the alt field
        altField.addEventListener( 'input', function() {
            notice.remove();
            altField.removeAttribute( 'data-uwgs-cap-applied' );
        }, { once: true } );
    }

    /**
     * Find all confirmed details panels in a container and process them.
     * Confirmed class pattern from DOM inspection: 'attachment-details save-ready'
     * Also handles 'attachment-details' alone for robustness.
     */
    function processPanels( container ) {
        if ( ! container ) { return; }

        // querySelectorAll with confirmed class — note: both classes must be present
        var panels = container.querySelectorAll(
            '.attachment-details.save-ready, ' +
            '.attachment-details'
        );

        panels.forEach( function( panel ) {
            // Short delay to allow Backbone to finish rendering field values
            setTimeout( function() { applyCaptionToAlt( panel ); }, 200 );
        } );
    }

    /**
     * Observe a modal or frame container for details panels appearing or changing.
     * Targets confirmed container:.media-sidebar.visible >.attachment-details.save-ready
     */
    function observeModalContent( modal ) {
        if ( ! modal || modal._uwgsObserved ) { return; }
        modal._uwgsObserved = true;

        // Process any panels already present
        processPanels( modal );

        var observer = new MutationObserver( function( mutations ) {
            mutations.forEach( function( mutation ) {

                // New nodes added anywhere in the modal
                mutation.addedNodes.forEach( function( node ) {
                    if ( node.nodeType !== 1 ) { return; }

                    // Node itself is a details panel
                    if (
                        node.classList &&
                        node.classList.contains( 'attachment-details' )
                    ) {
                        setTimeout( function() { applyCaptionToAlt( node ); }, 200 );
                        return;
                    }

                    // Node contains details panels
                    processPanels( node );
                } );

                // Existing node content changed — re-check if it's inside a panel
                // This catches Backbone re-rendering field values after selection
                if ( mutation.type === 'childList' && mutation.target ) {
                    var panel = mutation.target.closest
                        ? mutation.target.closest( '.attachment-details' )
                        : null;
                    if ( panel ) {
                        setTimeout( function() { applyCaptionToAlt( panel ); }, 200 );
                    }
                }
            } );
        } );

        observer.observe( modal, {
            childList: true,
            subtree:   true,
        } );
    }

    /**
     * Watch document.body for the media modal appearing.
     * Confirmed container classes:.media-modal,.media-frame
     */
    function observeForModal() {

        // Handle modals already in DOM
        document.querySelectorAll( '.media-modal,.media-frame' ).forEach( observeModalContent );

        // Watch for modals added dynamically
        var bodyObserver = new MutationObserver( function( mutations ) {
            mutations.forEach( function( mutation ) {
                mutation.addedNodes.forEach( function( node ) {
                    if ( node.nodeType !== 1 ) { return; }

                    if (
                        node.classList && (
                            node.classList.contains( 'media-modal' ) ||
                            node.classList.contains( 'media-frame' )
                        )
                    ) {
                        observeModalContent( node );
                        return;
                    }

                    node.querySelectorAll && node.querySelectorAll( '.media-modal,.media-frame' ).forEach( observeModalContent );
                } );
            } );
        } );

        bodyObserver.observe( document.body, {
            childList: true,
            subtree:   false, // Only watch direct children of body for performance
        } );
    }

    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', observeForModal );
    } else {
        observeForModal();
    }

} )();
JS;

    wp_add_inline_script( 'jquery', $js, 'after' );
}

    // =========================================================================
    // GUTENBERG: BLOCK CANVAS WARNING + PRE-PUBLISH PANEL
    // =========================================================================

    public function enqueue_block_editor_assets() {
        if ( ! current_user_can( 'upload_files' ) ) {
            return;
        }

        $i18n = array(
            'panelTitle'     => __( 'Image Accessibility', 'uwgs-alt-text-tool' ),
            'allGood'        => __( '✓ All images in this post have alt text.', 'uwgs-alt-text-tool' ),
            'warningIntro'   => __( 'The following images are missing alt text. Click each image block and add a description in the Alt Text field in the right sidebar, or mark it as decorative.', 'uwgs-alt-text-tool' ),
            'decorativeNote' => __( 'If an image is purely decorative, leave alt text empty and check "Mark as decorative" in the block settings sidebar.', 'uwgs-alt-text-tool' ),
            'noFilename'     => __( '(no filename)', 'uwgs-alt-text-tool' ),
            'canvasBanner'   => __( 'Missing alt text — click this image, then add alt text in the sidebar panel on the right.', 'uwgs-alt-text-tool' ),
        );

        wp_add_inline_script(
            'wp-blocks',
            'var uwgsGutenbergI18n = '. wp_json_encode( $i18n ). ';'
        );

        $js = <<<'JS'
( function( wp, i18n ) {

    'use strict';

    if ( typeof wp === 'undefined' ) { return; }

    var el       = wp.element.createElement;
    var Fragment = wp.element.Fragment;

    var registerPlugin = wp.plugins ? wp.plugins.registerPlugin : null;

    var PluginPrePublishPanel = ( wp.editor && wp.editor.PluginPrePublishPanel )
        ? wp.editor.PluginPrePublishPanel
        : ( wp.editPost ? wp.editPost.PluginPrePublishPanel : null );

    var useSelect = wp.data    ? wp.data.useSelect                     : null;
    var addFilter = wp.hooks   ? wp.hooks.addFilter                    : null;
    var createHOC = wp.compose ? wp.compose.createHigherOrderComponent : null;

    // Block canvas warning HOC
    if ( addFilter && createHOC ) {

        var withAltWarning = createHOC( function( BlockEdit ) {
            return function( props ) {

                if ( props.name !== 'core/image' ) {
                    return el( BlockEdit, props );
                }

                var alt      = ( props.attributes && props.attributes.alt ) || '';
                var url      = ( props.attributes && props.attributes.url ) || '';
                var hasImage = url !== '';
                var hasAlt   = alt.trim() !== '';

                var bannerStyle = {
                    display:      'flex',
                    alignItems:   'center',
                    gap:          '8px',
                    margin:       '4px 0 0',
                    padding:      '8px 12px',
                    background:   '#fff3cd',
                    borderLeft:   '4px solid #ffc107',
                    color:        '#856404',
                    fontSize:     '13px',
                    lineHeight:   '1.5',
                    borderRadius: '0 3px 3px 0',
                };

                return el(
                    Fragment,
                    null,
                    el( BlockEdit, props ),
                    ( hasImage && ! hasAlt )
                        ? el( 'div', {
                                style:       bannerStyle,
                                role:        'alert',
                                'aria-live': 'polite',
                                className:   'uwgs-block-alt-warning',
                            },
                            el( 'span', { 'aria-hidden': 'true' }, '⚠' ),
                            el( 'span', null,
                                i18n.canvasBanner || 'Missing alt text — add it in the sidebar.'
                            )
                          )
                        : null
                );
            };
        }, 'withAltWarning' );

        addFilter(
            'editor.BlockEdit',
            'uwgs-alt-text-tool/with-alt-warning',
            withAltWarning
        );
    }

    // Pre-publish panel
    if ( ! registerPlugin || ! PluginPrePublishPanel || ! useSelect ) { return; }

    function findImageBlocksMissingAlt( blocks ) {
        var missing = [];
        if ( ! blocks || ! blocks.length ) { return missing; }
        blocks.forEach( function( block ) {
            if ( block.name === 'core/image' ) {
                var alt = ( block.attributes && block.attributes.alt )
                    ? block.attributes.alt.trim() : '';
                if ( alt === '' ) {
                    missing.push( {
                        clientId: block.clientId || '',
                        filename: getFilename( ( block.attributes && block.attributes.url ) || '' ),
                    } );
                }
            }
            if ( block.innerBlocks && block.innerBlocks.length ) {
                missing = missing.concat( findImageBlocksMissingAlt( block.innerBlocks ) );
            }
        } );
        return missing;
    }

    function getFilename( url ) {
        if ( ! url ) { return i18n.noFilename || '(no filename)'; }
        var parts = url.split( '/' );
        var last  = parts[ parts.length - 1 ] || '';
        return last.split( '?' )[0] || ( i18n.noFilename || '(no filename)' );
    }

    function UWGSAltTextPanel() {
        var blocks    = useSelect( function( select ) {
            return select( 'core/block-editor' ).getBlocks();
        }, [] );
        var missing   = findImageBlocksMissingAlt( blocks );
        var hasIssues = missing.length > 0;

        return el(
            PluginPrePublishPanel,
            {
                name:        'uwgs-alt-text-panel',
                title:       i18n.panelTitle || 'Image Accessibility',
                initialOpen: hasIssues,
                className:   hasIssues ? 'uwgs-prepublish-warning' : 'uwgs-prepublish-ok',
            },
            hasIssues
                ? el( Fragment, null,
                    el( 'p', { style: { margin:'0 0 8px', color:'#856404', fontSize:'13px', lineHeight:'1.5' } },
                        i18n.warningIntro || 'The following images are missing alt text:' ),
                    el( 'ul', { style: { margin:'0 0 10px 16px', padding:'0', fontSize:'13px', color:'#c62828', lineHeight:'1.6' } },
                        missing.map( function( item, index ) {
                            return el( 'li', { key: item.clientId || index }, item.filename );
                        } )
                    ),
                    el( 'p', { style: { margin:'0', fontSize:'12px', color:'#555', fontStyle:'italic' } },
                        i18n.decorativeNote || 'If an image is decorative, mark it as decorative in block settings.' )
                  )
                : el( 'p', { style: { margin:'0', color:'#2e7d32', fontSize:'13px' } },
                    i18n.allGood || '✓ All images in this post have alt text.' )
        );
    }

    registerPlugin( 'uwgs-alt-text-tool', { render: UWGSAltTextPanel } );

} )(
    window.wp,
    ( typeof uwgsGutenbergI18n !== 'undefined' ? uwgsGutenbergI18n : {} )
);
JS;

        wp_add_inline_script( 'wp-edit-post', $js, 'after' );
    }

    // =========================================================================
    // AJAX: SAVE ALT TEXT
    // =========================================================================

    public function ajax_save_alt_text() {

        $post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
        if ( ! $post_id ) {
            wp_send_json_error( __( 'Invalid attachment ID.', 'uwgs-alt-text-tool' ) );
        }

        if ( ! isset( $_POST['nonce'] )
             || ! wp_verify_nonce( $_POST['nonce'], self::NONCE_ACTION. '_'. $post_id ) ) {
            wp_send_json_error( __( 'Security check failed.', 'uwgs-alt-text-tool' ) );
        }

        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            wp_send_json_error( __( 'You do not have permission to edit this attachment.', 'uwgs-alt-text-tool' ) );
        }

        if ( 'attachment' !== get_post_type( $post_id ) ) {
            wp_send_json_error( __( 'Invalid post type.', 'uwgs-alt-text-tool' ) );
        }

        $alt_text = isset( $_POST['alt_text'] )
            ? sanitize_text_field( wp_unslash( $_POST['alt_text'] ) )
            : '';

        update_post_meta( $post_id, self::META_KEY, $alt_text );
        delete_post_meta( $post_id, self::NEEDS_ALT_KEY );

        wp_send_json_success( array(
            'alt_text' => $alt_text,
            'message'  => __( 'Alt text saved.', 'uwgs-alt-text-tool' ),
        ) );
    }
}

add_action( 'plugins_loaded', array( 'UWGS_Alt_Text_Tool', 'init' ) );