<?php
/**
 * Plugin Name:       UWGS Alt Text Tool
 * Plugin URI:        https://grad.uw.edu
 * Description:       Adds an Alt Text column to the Media Library list view with sortable, filterable, and inline-editable alt text. Built for UW Graduate School.
 * Version:           1.0.0
 * Author:            UW Graduate School
 * Author URI:        https://grad.uw.edu
 * License:           GPL-2.0+
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       uwgs-alt-text-tool
 * Domain Path:       /languages
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Main plugin class
 */
class UWGS_Alt_Text_Tool {

    /**
     * Nonce action for inline save
     */
    const NONCE_ACTION = 'uwgs_alt_text_inline_save';

    /**
     * Meta key for alt text
     */
    const META_KEY = '_wp_attachment_image_alt';

    /**
     * Boot the plugin
     */
    public static function init() {
        $instance = new self();
        $instance->hooks();
    }

    /**
     * Register all hooks
     */
    private function hooks() {

        // Columns
        add_filter( 'manage_media_columns',          array( $this, 'register_column' ) );
        add_action( 'manage_media_custom_column',    array( $this, 'render_column' ), 10, 2 );
        add_filter( 'manage_upload_sortable_columns', array( $this, 'register_sortable' ) );

        // Query handling (sort + blank filter)
        add_action( 'pre_get_posts', array( $this, 'handle_query' ) );

        // Toolbar filter button
        add_action( 'restrict_manage_posts', array( $this, 'render_filter_button' ) );

        // Inline edit assets
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

        // AJAX handler for inline save
        add_action( 'wp_ajax_uwgs_save_alt_text', array( $this, 'ajax_save_alt_text' ) );
    }

    /**
     * 1. Register the column
     */
    public function register_column( $columns ) {
        $columns['uwgs_alt_text'] = __( 'Alt Text', 'uwgs-alt-text-tool' );
        return $columns;
    }

    /**
     * 2. Render the column — includes inline edit UI
     */
    public function render_column( $column_name, $post_id ) {
        if ( 'uwgs_alt_text' !== $column_name ) {
            return;
        }

        $mime = get_post_mime_type( $post_id );
        $is_image = strpos( $mime, 'image/' ) === 0;

        // Non-images: no alt text needed
        if ( ! $is_image ) {
            echo '<span class="uwgs-alt-na" style="color:#999;">—</span>';
            return;
        }

        $alt     = get_post_meta( $post_id, self::META_KEY, true );
        $alt_esc = esc_attr( $alt );
        $nonce   = wp_create_nonce( self::NONCE_ACTION. '_'. $post_id );

        ?>
        <div class="uwgs-alt-wrap" data-post-id="<?php echo esc_attr( $post_id ); ?>">

            <?php // --- Display state --- ?>
            <div class="uwgs-alt-display">
                <?php if ( ! empty( $alt ) ) : ?>
                    <span class="uwgs-alt-value" style="color:#2e7d32;"><?php echo esc_html( $alt ); ?></span>
                <?php else : ?>
                    <span class="uwgs-alt-value uwgs-alt-blank" style="color:#c62828;font-weight:600;">
                        <?php esc_html_e( '(blank)', 'uwgs-alt-text-tool' ); ?>
                    </span>
                <?php endif; ?>
                <?php if ( current_user_can( 'upload_files' ) ) : ?>
                    <button type="button"
                            class="uwgs-alt-edit-btn button-link"
                            aria-label="<?php esc_attr_e( 'Edit alt text', 'uwgs-alt-text-tool' ); ?>"
                            style="margin-left:6px;font-size:12px;">
                        ✎ <?php esc_html_e( 'Edit', 'uwgs-alt-text-tool' ); ?>
                    </button>
                <?php endif; ?>
            </div>

            <?php // --- Edit state (hidden until Edit clicked) --- ?>
            <?php if ( current_user_can( 'upload_files' ) ) : ?>
            <div class="uwgs-alt-editor" style="display:none;margin-top:4px;">
                <input type="text"
                       class="uwgs-alt-input"
                       value="<?php echo $alt_esc; ?>"
                       placeholder="<?php esc_attr_e( 'Enter alt text…', 'uwgs-alt-text-tool' ); ?>"
                       style="width:100%;max-width:280px;"
                       aria-label="<?php esc_attr_e( 'Alt text for this image', 'uwgs-alt-text-tool' ); ?>"
                />
                <div style="margin-top:4px;">
                    <button type="button"
                            class="uwgs-alt-save-btn button button-primary button-small"
                            data-nonce="<?php echo esc_attr( $nonce ); ?>">
                        <?php esc_html_e( 'Save', 'uwgs-alt-text-tool' ); ?>
                    </button>
                    <button type="button" class="uwgs-alt-cancel-btn button button-small" style="margin-left:4px;">
                        <?php esc_html_e( 'Cancel', 'uwgs-alt-text-tool' ); ?>
                    </button>
                    <span class="uwgs-alt-spinner spinner" style="float:none;margin:0 4px;vertical-align:middle;"></span>
                    <span class="uwgs-alt-feedback" style="font-size:12px;"></span>
                </div>
            </div>
            <?php endif; ?>

        </div>
        <?php
    }

    /**
     * 3. Register sortable column
     */
    public function register_sortable( $sortable_columns ) {
        $sortable_columns['uwgs_alt_text'] = 'uwgs_alt_text';
        return $sortable_columns;
    }

    /**
     * 4. Handle sort + blank-alt filter in query
     *    Preserves all existing WP media filters
     */
    public function handle_query( $query ) {
        if ( ! is_admin() || ! $query->is_main_query() ) {
            return;
        }

        $screen = get_current_screen();
        if ( ! $screen || 'upload' !== $screen->id ) {
            return;
        }

        // --- Sort by alt text ---
        if ( 'uwgs_alt_text' === $query->get( 'orderby' ) ) {
            $query->set( 'meta_key', self::META_KEY );
            $query->set( 'orderby', 'meta_value' );
        }

        // --- Filter: blank alt text only (?alt_filter=blank) ---
        if ( isset( $_GET['alt_filter'] ) && 'blank' === sanitize_key( $_GET['alt_filter'] ) ) {

            // Images only
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

    /**
     * 5. Render "Blank Alt Text" filter button in toolbar
     */
    public function render_filter_button( $post_type ) {
        if ( 'attachment' !== $post_type ) {
            return;
        }

        $current  = isset( $_GET['alt_filter'] ) ? sanitize_key( $_GET['alt_filter'] ) : '';
        $base_url = admin_url( 'upload.php' );

        // Preserve existing query params
        $passthrough = array( 'm', 's', 'author', 'post_mime_type' );
        $extra       = array();
        foreach ( $passthrough as $param ) {
            if ( ! empty( $_GET[ $param ] ) ) {
                $extra[ $param ] = sanitize_text_field( $_GET[ $param ] );
            }
        }

        $blank_url = add_query_arg( array_merge( $extra, array( 'alt_filter' => 'blank' ) ), $base_url );
        $clear_url = add_query_arg( array_merge( $extra, array( 'alt_filter' => '' ) ), $base_url );

        $is_active = ( 'blank' === $current );

        echo '<a href="'. esc_url( $is_active ? $clear_url : $blank_url ). '" '. 'class="button'. ( $is_active ? ' button-primary' : '' ). '" '. 'style="margin-left:4px;">';
        echo $is_active
            ? esc_html__( '✕ Clear Alt Filter', 'uwgs-alt-text-tool' )
            : esc_html__( '⚠ Blank Alt Text', 'uwgs-alt-text-tool' );
        echo '</a>';
    }

    /**
     * 6. Enqueue JS + inline CSS for inline editor
     *    Only loads on the media library screen
     */
    public function enqueue_assets( $hook ) {
        if ( 'upload.php' !== $hook ) {
            return;
        }

        // Inline CSS
        $css = '.uwgs-alt-wrap { line-height: 1.5; }.uwgs-alt-edit-btn { cursor: pointer; text-decoration: underline; color: #2271b1; }.uwgs-alt-edit-btn:hover { color: #135e96; }.uwgs-alt-feedback.success { color: #2e7d32; }.uwgs-alt-feedback.error   { color: #c62828; }.uwgs-alt-editor input[type="text"] { font-size: 13px; }
        ';
        wp_register_style( 'uwgs-alt-text-tool', false );
        wp_enqueue_style( 'uwgs-alt-text-tool' );
        wp_add_inline_style( 'uwgs-alt-text-tool', $css );

        // Inline JS (no external file needed)
        wp_enqueue_script( 'jquery' );

        $js = '
        jQuery( function( $ ) {

            var ajaxUrl = '. json_encode( admin_url( "admin-ajax.php" ) ). ';

            // Open editor
            $( document ).on( "click", ".uwgs-alt-edit-btn", function() {
                var $wrap = $( this ).closest( ".uwgs-alt-wrap" );
                $wrap.find( ".uwgs-alt-display" ).hide();
                $wrap.find( ".uwgs-alt-editor" ).show();
                $wrap.find( ".uwgs-alt-input" ).trigger( "focus" );
            } );

            // Cancel edit
            $( document ).on( "click", ".uwgs-alt-cancel-btn", function() {
                var $wrap = $( this ).closest( ".uwgs-alt-wrap" );
                $wrap.find( ".uwgs-alt-editor" ).hide();
                $wrap.find( ".uwgs-alt-display" ).show();
                $wrap.find( ".uwgs-alt-feedback" ).text( "" ).removeClass( "success error" );
            } );

            // Save on Enter key
            $( document ).on( "keydown", ".uwgs-alt-input", function( e ) {
                if ( 13 === e.which ) {
                    e.preventDefault();
                    $( this ).closest( ".uwgs-alt-wrap" ).find( ".uwgs-alt-save-btn" ).trigger( "click" );
                }
            } );

            // Save alt text via AJAX
            $( document ).on( "click", ".uwgs-alt-save-btn", function() {
                var $btn     = $( this );
                var $wrap    = $btn.closest( ".uwgs-alt-wrap" );
                var postId   = $wrap.data( "post-id" );
                var nonce    = $btn.data( "nonce" );
                var altText  = $wrap.find( ".uwgs-alt-input" ).val();
                var $spinner  = $wrap.find( ".uwgs-alt-spinner" );
                var $feedback = $wrap.find( ".uwgs-alt-feedback" );

                $btn.prop( "disabled", true );
                $spinner.addClass( "is-active" );
                $feedback.text( "" ).removeClass( "success error" );

                $.ajax( {
                    url:  ajaxUrl,
                    type: "POST",
                    data: {
                        action:   "uwgs_save_alt_text",
                        post_id:  postId,
                        alt_text: altText,
                        nonce:    nonce
                    },
                    success: function( response ) {
                        if ( response.success ) {
                            // Update display
                            var $display = $wrap.find( ".uwgs-alt-display" );
                            var $value   = $display.find( ".uwgs-alt-value" );

                            if ( altText.length ) {
                                $value.text( altText ).css( "color", "#2e7d32" ).removeClass( "uwgs-alt-blank" ).css( "font-weight", "normal" );
                            } else {
                                $value.text( "(blank)" ).css( { "color": "#c62828", "font-weight": "600" } ).addClass( "uwgs-alt-blank" );
                            }

                            $wrap.find( ".uwgs-alt-editor" ).hide();
                            $display.show();
                            $feedback.text( "" );
                        } else {
                            $feedback.text( response.data || "Save failed." ).addClass( "error" );
                        }
                    },
                    error: function() {
                        $feedback.text( "Request failed. Please try again." ).addClass( "error" );
                    },
                    complete: function() {
                        $btn.prop( "disabled", false );
                        $spinner.removeClass( "is-active" );
                    }
                } );
            } );

        } );
        ';

        wp_add_inline_script( 'jquery', $js );
    }

    /**
     * 7. AJAX handler: save alt text
     *    Validates nonce, capability, and sanitizes input
     */
    public function ajax_save_alt_text() {

        // Validate post_id
        $post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
        if ( ! $post_id ) {
            wp_send_json_error( __( 'Invalid attachment ID.', 'uwgs-alt-text-tool' ) );
        }

        // Verify nonce (per-post)
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], self::NONCE_ACTION. '_'. $post_id ) ) {
            wp_send_json_error( __( 'Security check failed.', 'uwgs-alt-text-tool' ) );
        }

        // Check capability
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            wp_send_json_error( __( 'You do not have permission to edit this attachment.', 'uwgs-alt-text-tool' ) );
        }

        // Confirm it's an attachment
        if ( 'attachment' !== get_post_type( $post_id ) ) {
            wp_send_json_error( __( 'Invalid post type.', 'uwgs-alt-text-tool' ) );
        }

        // Sanitize and save
        $alt_text = isset( $_POST['alt_text'] ) ? sanitize_text_field( wp_unslash( $_POST['alt_text'] ) ) : '';
        update_post_meta( $post_id, self::META_KEY, $alt_text );

        wp_send_json_success( array(
            'alt_text' => $alt_text,
            'message'  => __( 'Alt text saved.', 'uwgs-alt-text-tool' ),
        ) );
    }
}

// Boot
add_action( 'plugins_loaded', array( 'UWGS_Alt_Text_Tool', 'init' ) );