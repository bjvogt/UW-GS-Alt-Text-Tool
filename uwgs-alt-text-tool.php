<?php
/**
 * Plugin Name:       UWGS Alt Text Tool
 * Plugin URI:        https://grad.uw.edu
 * Description:       Adds an Alt Text column to the Media Library list view with sortable, filterable,
 *                    and inline-editable alt text. Warns editors when images are missing alt text at
 *                    save/publish time in both classic and block editors, including featured images.
 *                    Shows persistent in-editor notices when images are missing alt text. Copies
 *                    caption to alt text server-side on attachment save and in the Add Media modal.
 *                    Updates upload status messages to prompt alt text entry. Shows a dashboard
 *                    widget with alt text coverage stats. Built for UW Graduate School.
 * Version:           2.1.4
 * Author:            UW Graduate School
 * Author URI:        https://grad.uw.edu
 * License:           GPL-2.0+
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       uwgs-alt-text-tool
 * Domain Path:       /languages
 *
 * IMPORTANT: bump VERSION constant whenever JS or CSS changes.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class UWGS_Alt_Text_Tool {

    const NONCE_ACTION    = 'uwgs_alt_text_inline_save';
    const NONCE_ALT_CHECK = 'uwgs_get_attachment_alt';
    const META_KEY        = '_wp_attachment_image_alt';
    const NEEDS_ALT_KEY   = '_uwgs_needs_alt';
    const VERSION         = '2.1.3';

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

        // AJAX: get attachment alt text by ID
        add_action( 'wp_ajax_uwgs_get_attachment_alt', array( $this, 'ajax_get_attachment_alt' ) );

        // Flag new image uploads missing alt text
        add_action( 'add_attachment', array( $this, 'flag_new_upload' ) );

        // Server-side: copy caption to alt on attachment edit
        add_action( 'edit_attachment', array( $this, 'server_copy_caption_to_alt' ) );

        // Dashboard widget
        add_action( 'wp_dashboard_setup', array( $this, 'register_dashboard_widget' ) );

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
                array( 'key' => self::META_KEY, 'compare' => 'NOT EXISTS' ),
                array( 'key' => self::META_KEY, 'value' => '', 'compare' => '=' ),
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
        if ( strpos( $mime, 'image/' ) !== 0 ) { return; }
        $alt = get_post_meta( $post_id, self::META_KEY, true );
        if ( empty( $alt ) ) {
            update_post_meta( $post_id, self::NEEDS_ALT_KEY, '1' );
        }
    }

    // =========================================================================
    // SERVER-SIDE: COPY CAPTION TO ALT ON ATTACHMENT EDIT
    // =========================================================================

    public function server_copy_caption_to_alt( $post_id ) {
        $mime = get_post_mime_type( $post_id );
        if ( strpos( $mime, 'image/' ) !== 0 ) { return; }
        $current_alt = get_post_meta( $post_id, self::META_KEY, true );
        if ( ! empty( $current_alt ) ) { return; }
        $caption = get_post_field( 'post_excerpt', $post_id );
        if ( empty( $caption ) ) { return; }
        update_post_meta( $post_id, self::META_KEY, sanitize_text_field( $caption ) );
        delete_post_meta( $post_id, self::NEEDS_ALT_KEY );
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

    // =========================================================================
    // AJAX: GET ATTACHMENT ALT TEXT
    // =========================================================================

    public function ajax_get_attachment_alt() {

        if ( ! isset( $_POST['nonce'] )
             || ! wp_verify_nonce( $_POST['nonce'], self::NONCE_ALT_CHECK ) ) {
            wp_send_json_error( 'Security check failed.' );
        }

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( 'Insufficient permissions.' );
        }

        $attachment_id = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;
        if ( ! $attachment_id ) {
            wp_send_json_error( 'Invalid attachment ID.' );
        }

        if ( 'attachment' !== get_post_type( $attachment_id ) ) {
            wp_send_json_error( 'Not an attachment.' );
        }

        $alt = get_post_meta( $attachment_id, self::META_KEY, true );

        wp_send_json_success( array(
            'alt'           => $alt,
            'has_alt'       => ! empty( $alt ),
            'attachment_id' => $attachment_id,
        ) );
    }

    // =========================================================================
    // DASHBOARD WIDGET
    // =========================================================================

    public function register_dashboard_widget() {
        if ( ! current_user_can( 'upload_files' ) ) { return; }
        wp_add_dashboard_widget(
            'uwgs_alt_text_widget',
            __( 'Image Alt Text Coverage', 'uwgs-alt-text-tool' ),
            array( $this, 'render_dashboard_widget' )
        );
    }

    private function get_alt_text_stats() {
        $cached = get_transient( 'uwgs_alt_text_stats' );
        if ( false !== $cached ) { return $cached; }

        $total_query = new WP_Query( array(
            'post_type' => 'attachment', 'post_mime_type' => 'image',
            'post_status' => 'inherit', 'posts_per_page' => -1,
            'fields' => 'ids', 'no_found_rows' => false,
        ) );
        $total = (int) $total_query->found_posts;

        $missing_query = new WP_Query( array(
            'post_type' => 'attachment', 'post_mime_type' => 'image',
            'post_status' => 'inherit', 'posts_per_page' => -1,
            'fields' => 'ids', 'no_found_rows' => false,
            'meta_query' => array(
                'relation' => 'OR',
                array( 'key' => self::META_KEY, 'compare' => 'NOT EXISTS' ),
                array( 'key' => self::META_KEY, 'value' => '', 'compare' => '=' ),
            ),
        ) );
        $missing = (int) $missing_query->found_posts;

        $new_query = new WP_Query( array(
            'post_type' => 'attachment', 'post_mime_type' => 'image',
            'post_status' => 'inherit', 'posts_per_page' => -1,
            'fields' => 'ids', 'no_found_rows' => false,
            'meta_query' => array(
                array( 'key' => self::NEEDS_ALT_KEY, 'value' => '1', 'compare' => '=' ),
            ),
        ) );
        $new_missing = (int) $new_query->found_posts;

        $stats = array(
            'total'       => $total,
            'with_alt'    => max( 0, $total - $missing ),
            'missing'     => $missing,
            'new_missing' => $new_missing,
        );

        set_transient( 'uwgs_alt_text_stats', $stats, 12 * HOUR_IN_SECONDS );
        return $stats;
    }

    public static function clear_stats_cache() {
        delete_transient( 'uwgs_alt_text_stats' );
    }

    public function render_dashboard_widget() {

        $stats       = $this->get_alt_text_stats();
        $total       = $stats['total'];
        $with_alt    = $stats['with_alt'];
        $missing     = $stats['missing'];
        $new_missing = $stats['new_missing'];
        $pct         = $total > 0 ? round( ( $with_alt / $total ) * 100 ) : 100;
        $library_url = admin_url( 'upload.php?alt_filter=blank' );

        ?>
        <div class="uwgs-widget" style="font-size:13px;line-height:1.6;">

            <?php if ( $total === 0 ) : ?>
                <p style="color:#555;margin:0;">
                    <?php esc_html_e( 'No images found in the media library.', 'uwgs-alt-text-tool' ); ?>
                </p>
            <?php else : ?>

                <div style="margin-bottom:12px;">
                    <div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:4px;">
                        <span style="font-weight:600;font-size:14px;color:#1d2327;">
                            <?php echo esc_html( $pct ); ?>%
                            <?php esc_html_e( 'coverage', 'uwgs-alt-text-tool' ); ?>
                        </span>
                        <span style="color:#555;font-size:12px;">
                            <?php echo esc_html( number_format_i18n( $with_alt ) ); ?>
                            <?php esc_html_e( 'of', 'uwgs-alt-text-tool' ); ?>
                            <?php echo esc_html( number_format_i18n( $total ) ); ?>
                            <?php esc_html_e( 'images', 'uwgs-alt-text-tool' ); ?>
                        </span>
                    </div>
                    <div style="background:#e0e0e0;border-radius:4px;height:10px;overflow:hidden;"
                         role="progressbar"
                         aria-valuenow="<?php echo esc_attr( $pct ); ?>"
                         aria-valuemin="0"
                         aria-valuemax="100"
                         aria-label="<?php echo esc_attr( sprintf(
                             __( 'Alt text coverage: %d%%', 'uwgs-alt-text-tool' ), $pct
                         ) ); ?>">
                        <div style="background:#757575;width:<?php echo esc_attr( $pct ); ?>%;height:100%;border-radius:4px;transition:width 0.3s ease;"></div>
                    </div>
                </div>

                <table style="width:100%;border-collapse:collapse;margin-bottom:12px;">
                    <tbody>
                        <tr>
                            <td style="padding:3px 0;color:#555;"><?php esc_html_e( 'Total images', 'uwgs-alt-text-tool' ); ?></td>
                            <td style="padding:3px 0;text-align:right;font-weight:600;"><?php echo esc_html( number_format_i18n( $total ) ); ?></td>
                        </tr>
                        <tr>
                            <td style="padding:3px 0;color:#2e7d32;"><?php esc_html_e( 'Have alt text', 'uwgs-alt-text-tool' ); ?></td>
                            <td style="padding:3px 0;text-align:right;font-weight:600;color:#2e7d32;"><?php echo esc_html( number_format_i18n( $with_alt ) ); ?></td>
                        </tr>
                        <tr>
                            <td style="padding:3px 0;color:#c62828;"><?php esc_html_e( 'Missing alt text', 'uwgs-alt-text-tool' ); ?></td>
                            <td style="padding:3px 0;text-align:right;font-weight:600;color:#c62828;"><?php echo esc_html( number_format_i18n( $missing ) ); ?></td>
                        </tr>
                        <?php if ( $new_missing > 0 ) : ?>
                        <tr>
                            <td style="padding:3px 0;color:#856404;"><?php esc_html_e( 'New uploads missing alt text', 'uwgs-alt-text-tool' ); ?></td>
                            <td style="padding:3px 0;text-align:right;font-weight:600;color:#856404;"><?php echo esc_html( number_format_i18n( $new_missing ) ); ?></td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>

                <?php if ( $missing > 0 ) : ?>
                    <p style="margin:0 0 6px;">
                        <a href="<?php echo esc_url( $library_url ); ?>" class="button button-primary button-small">
                            <?php printf( esc_html__( 'Fix %s images →', 'uwgs-alt-text-tool' ), esc_html( number_format_i18n( $missing ) ) ); ?>
                        </a>
                    </p>
                <?php else : ?>
                    <p style="margin:0;color:#2e7d32;font-weight:600;">
                        ✓ <?php esc_html_e( 'All images have alt text. Great work!', 'uwgs-alt-text-tool' ); ?>
                    </p>
                <?php endif; ?>

                <p style="margin:6px 0 0;font-size:11px;color:#999;">
                    <?php esc_html_e( 'Stats refresh every 12 hours.', 'uwgs-alt-text-tool' ); ?>
                    <a href="<?php echo esc_url( add_query_arg( 'uwgs_refresh_stats', '1' ) ); ?>" style="color:#999;">
                        <?php esc_html_e( 'Refresh now', 'uwgs-alt-text-tool' ); ?>
                    </a>
                </p>

            <?php endif; ?>
        </div>
        <?php
    }

    // =========================================================================
    // ADMIN ASSETS
    // =========================================================================

    public function enqueue_admin_assets( $hook ) {

        if (
            isset( $_GET['uwgs_refresh_stats'] ) &&
            '1' === $_GET['uwgs_refresh_stats'] &&
            current_user_can( 'upload_files' )
        ) {
            self::clear_stats_cache();
            wp_safe_redirect( remove_query_arg( 'uwgs_refresh_stats' ) );
            exit;
        }

        if ( 'upload.php' === $hook ) {
            $this->enqueue_list_view_assets();
        }

        if ( 'media-new.php' === $hook ) {
            $this->enqueue_upload_page_assets();
        }

        if ( in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {

            global $post;
            if (
                $post
                && 'post.php' === $hook
                && 'attachment' === get_post_type( $post->ID )
                && strpos( get_post_mime_type( $post->ID ), 'image/' ) === 0
            ) {
                $this->enqueue_attachment_edit_assets( $post->ID );
            }

            // All non-block-editor post types get the same presave treatment —
            // including uw_stories. The inline notice bar gracefully falls back
            // to document.body if #titlediv is absent.
            if ( ! did_action( 'enqueue_block_editor_assets' ) ) {
                $this->enqueue_classic_presave_assets();
            }

            if ( ! did_action( 'wp_enqueue_media' ) ) {
                wp_enqueue_media();
            }
            $this->enqueue_media_modal_caption_assets();
        }
    }

    // =========================================================================
    // LIST VIEW ASSETS
    // =========================================================================

    private function enqueue_list_view_assets() {

        $css = '.uwgs-alt-wrap                      { line-height:1.6; }.uwgs-has-alt                       { color:#2e7d32; }.uwgs-alt-blank                     { color:#c62828; font-weight:600; }.uwgs-alt-new-flag                  {
                display:inline-block; margin-left:4px; font-size:11px;
                background:#fff3cd; color:#856404; border:1px solid #ffc107;
                border-radius:3px; padding:1px 5px; vertical-align:middle;
            }.uwgs-alt-edit-btn                  {
                cursor:pointer; text-decoration:underline; color:#2271b1;
                margin-left:6px; font-size:12px; background:none; border:none; padding:0;
            }.uwgs-alt-edit-btn:hover            { color:#135e96; }.uwgs-alt-feedback.success          { color:#2e7d32; }.uwgs-alt-feedback.error            { color:#c62828; }.uwgs-alt-editor input[type="text"] { font-size:13px; }
        ';

        wp_register_style( 'uwgs-alt-text-tool', false, array(), self::VERSION );
        wp_enqueue_style( 'uwgs-alt-text-tool' );
        wp_add_inline_style( 'uwgs-alt-text-tool', $css );

        // Build per-attachment suggestion data: caption first, then filename
        $suggestions = array();
        global $wp_query;
        if ( $wp_query && ! empty( $wp_query->posts ) ) {
            foreach ( $wp_query->posts as $attachment ) {
                $post_id = is_object( $attachment ) ? $attachment->ID : (int) $attachment;
                $mime    = get_post_mime_type( $post_id );
                if ( strpos( $mime, 'image/' ) !== 0 ) { continue; }
                $alt = get_post_meta( $post_id, self::META_KEY, true );
                if ( ! empty( $alt ) ) { continue; }
                $caption  = get_post_field( 'post_excerpt', $post_id );
                $filename = get_the_title( $post_id );
                if ( ! empty( trim( $caption ) ) ) {
                    $suggestions[ $post_id ] = array(
                        'type'  => 'caption',
                        'value' => sanitize_text_field( $caption ),
                    );
                } else {
                    $suggestions[ $post_id ] = array(
                        'type'  => 'filename',
                        'value' => sanitize_text_field( $filename ),
                    );
                }
            }
        }

        $data = array(
            'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
            'suggestions' => $suggestions,
            'i18n'        => array(
                'saveFailed'    => __( 'Save failed. Please try again.', 'uwgs-alt-text-tool' ),
                'requestFailed' => __( 'Request failed. Please try again.', 'uwgs-alt-text-tool' ),
                'saved'         => __( 'Saved.', 'uwgs-alt-text-tool' ),
                'blank'         => __( '(blank)', 'uwgs-alt-text-tool' ),
                'fromCaption'   => __( 'Suggested from caption — please review', 'uwgs-alt-text-tool' ),
                'fromFilename'  => __( 'Suggested from filename — please review', 'uwgs-alt-text-tool' ),
            ),
        );

        wp_add_inline_script( 'jquery', 'var uwgsAltData = '. wp_json_encode( $data ). ';' );

        $js = <<<'JS'
jQuery( function( $ ) {

    var data        = ( typeof uwgsAltData !== 'undefined' ) ? uwgsAltData : {};
    var ajaxUrl     = data.ajaxUrl     || '';
    var suggestions = data.suggestions || {};
    var i18n        = data.i18n        || {};

    function sanitizeFilename( raw ) {
        var s = raw;
        // 1. Strip extension
        s = s.replace( /\.[a-zA-Z0-9]+$/, '' );
        // 2. Replace HTML entities for multiplication sign (&#215; or &times;)
        //    before other processing so NNNxNNN patterns are caught
        s = s.replace( /&#215;|&times;/gi, 'x' );
        // 3. Replace hyphens and underscores with spaces
        s = s.replace( /[-_]+/g, ' ' );
        // 4. Remove WP thumbnail size patterns: NNNxNNN (plain x or entity-replaced)
        s = s.replace( /\b\d+x\d+\b/gi, '' );
        // 5. Remove 8-digit date patterns (YYYYMMDD: 19xxxxxx or 20xxxxxx)
        s = s.replace( /\b(19|20)\d{6}\b/g, '' );
        // 6. Remove standalone 4-digit years (1900-2099) only when NOT
        //    immediately followed by letters (avoids stripping 2026 from 2026uw3mt)
        s = s.replace( /\b(19|20)\d{2}(?![a-zA-Z0-9])/g, '' );
        // 7. Remove 'scaled' (WP large image suffix)
        s = s.replace( /\bscaled\b/gi, '' );
        // 8. Remove isolated 1-2 digit numbers (size variants)
        //    but preserve longer reference numbers
        s = s.replace( /\b\d{1,2}\b/g, '' );
        // 9. Collapse multiple spaces and trim
        s = s.replace( /\s{2,}/g, ' ' ).trim();
        // 10. Title case
        s = s.replace( /\b\w/g, function( c ) { return c.toUpperCase(); } );
        return s;
    }

    $( document ).on( 'click', '.uwgs-alt-edit-btn', function() {
        var $wrap   = $( this ).closest( '.uwgs-alt-wrap' );
        var postId  = $wrap.data( 'post-id' );
        var $editor = $wrap.find( '.uwgs-alt-editor' );
        var $input  = $editor.find( '.uwgs-alt-input' );

        $wrap.find( '.uwgs-alt-display' ).hide();
        $editor.show();
        $editor.find( '.uwgs-alt-suggestion-hint' ).remove();

        if ( $input.val().trim() === '' && suggestions[ postId ] ) {
            var suggestion = suggestions[ postId ];
            var value      = suggestion.type === 'filename'
                ? sanitizeFilename( suggestion.value )
                : suggestion.value;

            if ( value ) {
                $input.val( value );
                var hintText = suggestion.type === 'caption'
                    ? ( i18n.fromCaption || 'Suggested from caption — please review' )
                    : ( i18n.fromFilename || 'Suggested from filename — please review' );
                var $hint = $( '<p>' ).addClass( 'uwgs-alt-suggestion-hint' ).css( { 'margin': '4px 0 0', 'font-size': '11px', 'color': '#856404', 'font-style': 'italic' } ).text( hintText );
                $input.after( $hint );
                $input.one( 'input', function() { $hint.remove(); } );
            }
        }

        $input.trigger( 'focus' );
        $( this ).attr( 'aria-expanded', 'true' );
    } );

    $( document ).on( 'click', '.uwgs-alt-cancel-btn', function() {
        var $wrap = $( this ).closest( '.uwgs-alt-wrap' );
        $wrap.find( '.uwgs-alt-editor' ).hide();
        $wrap.find( '.uwgs-alt-suggestion-hint' ).remove();
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
            url: ajaxUrl, type: 'POST',
            data: { action: 'uwgs_save_alt_text', post_id: postId, alt_text: altText, nonce: nonce },
            success: function( response ) {
                if ( response.success ) {
                    var $display = $wrap.find( '.uwgs-alt-display' );
                    var $value   = $display.find( '.uwgs-alt-value' );
                    if ( altText.length ) {
                        $value.text( altText ).removeClass( 'uwgs-alt-blank' ).addClass( 'uwgs-has-alt' ).css( 'font-weight', 'normal' ).removeAttr( 'aria-label' );
                        $wrap.find( '.uwgs-alt-new-flag' ).remove();
                        delete suggestions[ postId ];
                    } else {
                        $value.text( i18n.blank || '(blank)' ).removeClass( 'uwgs-has-alt' ).addClass( 'uwgs-alt-blank' ).attr( 'aria-label', 'Alt text is blank' );
                    }
                    $wrap.find( '.uwgs-alt-editor' ).hide();
                    $wrap.find( '.uwgs-alt-suggestion-hint' ).remove();
                    $display.show();
                    $display.find( '.uwgs-alt-edit-btn' ).attr( 'aria-expanded', 'false' ).trigger( 'focus' );
                    $feedback.text( i18n.saved || 'Saved.' ).addClass( 'success' );
                    setTimeout( function() { $feedback.text( '' ).removeClass( 'success' ); }, 3000 );
                } else {
                    $feedback.text( response.data || i18n.saveFailed ).addClass( 'error' );
                }
            },
            error: function() { $feedback.text( i18n.requestFailed ).addClass( 'error' ); },
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

    // =========================================================================
    // UPLOAD PAGE ASSETS
    // =========================================================================

    private function enqueue_upload_page_assets() {

        $i18n = array(
            'editPrompt' => __( 'Upload complete — click here to edit and add alt text', 'uwgs-alt-text-tool' ),
        );

        wp_add_inline_script( 'jquery', 'var uwgsUploadI18n = '. wp_json_encode( $i18n ). ';' );

        $js = <<<'JS'
( function() {
    'use strict';
    var i18n       = ( typeof uwgsUploadI18n !== 'undefined' ) ? uwgsUploadI18n : {};
    var promptText = i18n.editPrompt || 'Upload complete — click here to edit and add alt text';

    function processRow( row ) {
        if ( ! row ) { return; }
        var editLink = row.querySelector( 'a[href*="action=edit"]' )
                    || row.querySelector( '.edit-attachment a' )
                    || row.querySelector( '.media-item-info a' );
        if ( ! editLink || editLink.getAttribute( 'data-uwgs-updated' ) ) { return; }
        if ( row.querySelector( '.upload-error' ) ) { return; }
        editLink.textContent = promptText;
        editLink.setAttribute( 'data-uwgs-updated', '1' );
        editLink.style.fontWeight = '600';
        editLink.style.color      = '#856404';
    }

    function observeUploadList() {
        var container = document.getElementById( 'media-items' );
        if ( ! container ) { return; }
        var observer = new MutationObserver( function( mutations ) {
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
        } );
        observer.observe( container, { childList: true, subtree: true } );
    }

    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', observeUploadList );
    } else {
        observeUploadList();
    }
} )();
JS;

        wp_add_inline_script( 'jquery', $js, 'after' );
    }

    // =========================================================================
    // ATTACHMENT EDIT SCREEN ASSETS
    // =========================================================================

    private function enqueue_attachment_edit_assets( $post_id ) {

        $alt         = get_post_meta( $post_id, self::META_KEY, true );
        $caption     = get_post_field( 'post_excerpt', $post_id );
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
                display:flex; align-items:flex-start; gap:8px; margin-top:6px;
                padding:7px 10px; background:#fff3cd; border-left:4px solid #ffc107;
                color:#856404; font-size:12px; line-height:1.5; border-radius:0 3px 3px 0;
            }.uwgs-caption-copy-notice button {
                background:none; border:none; padding:0; cursor:pointer;
                color:#856404; font-size:14px; line-height:1; flex-shrink:0; margin-left:auto;
            }.uwgs-caption-copy-notice button:hover { color:#5a4000; }
        ';

        wp_register_style( 'uwgs-attachment-edit', false, array(), self::VERSION );
        wp_enqueue_style( 'uwgs-attachment-edit' );
        wp_add_inline_style( 'uwgs-attachment-edit', $css );

        $i18n = array(
            'warningText'   => __( '⚠ This image has no alt text. Alt text is required for accessibility. Please add a description before saving, or confirm this image is decorative by clicking Save again.', 'uwgs-alt-text-tool' ),
            'captionCopied' => __( 'Alt text copied from caption — please review and edit if needed before saving.', 'uwgs-alt-text-tool' ),
            'dismissNotice' => __( 'Dismiss', 'uwgs-alt-text-tool' ),
        );

        $data = array(
            'i18n'         => $i18n,
            'shouldCopy'   => $should_copy,
            'captionValue' => $should_copy ? sanitize_text_field( $caption ) : '',
        );

        wp_add_inline_script( 'jquery', 'var uwgsAttachData = '. wp_json_encode( $data ). ';' );

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

    if ( shouldCopy && capVal ) {
        $altField.val( capVal );
        var $notice = $( '<div>' ).addClass( 'uwgs-caption-copy-notice' ).attr( { 'role': 'note', 'aria-live': 'polite' } );
        var $msg     = $( '<span>' ).text( i18n.captionCopied || 'Alt text copied from caption — please review before saving.' );
        var $dismiss = $( '<button>' ).attr( { 'type': 'button', 'aria-label': i18n.dismissNotice || 'Dismiss', 'title': i18n.dismissNotice || 'Dismiss' } ).html( '✕' ).on( 'click', function() { $notice.remove(); } );
        $notice.append( $msg ).append( $dismiss );
        $altField.after( $notice );
        $altField.one( 'input', function() { $notice.remove(); } );
    }

    var $warning = $( '<div>' ).addClass( 'uwgs-attachment-alt-warning' ).attr( { 'role': 'alert', 'aria-live': 'assertive' } ).text( i18n.warningText || '⚠ This image has no alt text. Please add a description or click Save again to proceed.' );
    $altField.after( $warning );

    $altField.on( 'input', function() {
        if ( $( this ).val().trim().length ) {
            $( this ).removeClass( 'uwgs-field-highlight' );
            $warning.removeClass( 'visible' );
            warned = false;
        }
    } );

    $submitBtn.on( 'click', function( e ) {
        if ( $altField.val().trim().length ) { warned = false; return true; }
        if ( ! warned ) {
            e.preventDefault(); warned = true;
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

    // =========================================================================
    // CLASSIC EDITOR + ALL NON-BLOCK-EDITOR POST TYPES: PRE-SAVE SCAN
    //
    // Applies to: posts, pages, custom post types including uw_stories.
    // The inline notice bar anchors to #titlediv if present; falls back
    // to document.body if absent (e.g. uw_stories has no #titlediv).
    // The popup warning works regardless of post type DOM structure.
    // =========================================================================

    private function enqueue_classic_presave_assets() {

        $css = '
            #uwgs-inline-notice {
                display:none;
                margin:8px 0 16px;
                padding:10px 14px;
                background:#fff3cd;
                border-left:4px solid #ffc107;
                color:#856404;
                font-size:13px;
                line-height:1.5;
                border-radius:0 3px 3px 0;
            }
            #uwgs-inline-notice.visible {
                display:flex;
                align-items:center;
                justify-content:space-between;
                gap:12px;
            }
            #uwgs-inline-notice.uwgs-notice-text { flex:1; }
            #uwgs-inline-notice.uwgs-notice-dismiss {
                background:none; border:none; cursor:pointer;
                color:#856404; font-size:16px; padding:0;
                flex-shrink:0; line-height:1;
            }
            #uwgs-inline-notice.uwgs-notice-dismiss:hover { color:#5a4000; }
            #uwgs-presave-warning {
                display:none;
                position:fixed; top:32px; left:50%; transform:translateX(-50%);
                z-index:99999; min-width:320px; max-width:520px;
                padding:16px 20px; background:#fff3cd; border:2px solid #ffc107;
                border-radius:4px; color:#856404; font-size:13px; line-height:1.6;
                box-shadow:0 4px 12px rgba(0,0,0,0.15);
            }
            #uwgs-presave-warning.visible { display:block; }
            #uwgs-presave-warning strong  { display:block; margin-bottom:8px; font-size:14px; }
            #uwgs-presave-warning p       { margin:0 0 12px; }
            #uwgs-presave-warning.uwgs-warning-actions { display:flex; gap:8px; align-items:center; }
        ';

        wp_register_style( 'uwgs-classic-presave', false, array(), self::VERSION );
        wp_enqueue_style( 'uwgs-classic-presave' );
        wp_add_inline_style( 'uwgs-classic-presave', $css );

        $data = array(
            'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
            'altCheckNonce' => wp_create_nonce( self::NONCE_ALT_CHECK ),
            'i18n'          => array(
                'noticeContent'       => __( '⚠ One or more images in this post are missing alt text. Please add descriptions before publishing.', 'uwgs-alt-text-tool' ),
                'noticeFeatured'      => __( '⚠ The featured image is missing alt text. Please add a description before publishing.', 'uwgs-alt-text-tool' ),
                'noticeBoth'          => __( '⚠ Images and the featured image in this post are missing alt text. Please add descriptions before publishing.', 'uwgs-alt-text-tool' ),
                'warningTitle'        => __( '⚠ Accessibility: Images missing alt text', 'uwgs-alt-text-tool' ),
                'warningBodyContent'  => __( 'One or more images in this post are missing alt text. Please go back and add descriptions, or click "Save anyway" if all images are decorative.', 'uwgs-alt-text-tool' ),
                'warningBodyFeatured' => __( 'The featured image for this post is missing alt text. Please edit the featured image and add a description, or click "Save anyway" if it is decorative.', 'uwgs-alt-text-tool' ),
                'warningBodyBoth'     => __( 'One or more images and the featured image are missing alt text. Please go back and add descriptions, or click "Save anyway" if all images are decorative.', 'uwgs-alt-text-tool' ),
                'saveAnyway'          => __( 'Save anyway', 'uwgs-alt-text-tool' ),
                'goBack'              => __( 'Go back and fix', 'uwgs-alt-text-tool' ),
                'dismiss'             => __( 'Dismiss', 'uwgs-alt-text-tool' ),
            ),
        );

        wp_add_inline_script( 'jquery', 'var uwgsPresaveData = '. wp_json_encode( $data ). ';' );

        $js = <<<'JS'
( function() {

    'use strict';

    var data    = ( typeof uwgsPresaveData !== 'undefined' ) ? uwgsPresaveData : {};
    var ajaxUrl = data.ajaxUrl       || '';
    var nonce   = data.altCheckNonce || '';
    var i18n    = data.i18n          || {};

    var warningEl       = null;
    var noticeEl        = null;
    var saveTarget      = null;
    var saving          = false;
    var noticeDismissed = false;

    // -----------------------------------------------------------------
    // CONTENT SCAN
    // Uses tinyMCE.triggerSave() to flush all editors before reading.
    // Falls back to textarea for non-TinyMCE contexts (uw_stories etc).
    // -----------------------------------------------------------------

    function getPostContent() {
        // Flush all TinyMCE instances to their respective textareas
        if ( typeof window.tinyMCE !== 'undefined' && tinyMCE.editors && tinyMCE.editors.length ) {
            try { tinyMCE.triggerSave(); } catch(e) {}
        }
        // Return standard #content textarea value for standard editors
        var textarea = document.getElementById( 'content' );
        return textarea ? textarea.value : '';
    }

    function contentHasMissingAlt() {
        var allContent = [];

        if ( typeof window.tinyMCE !== 'undefined' && tinyMCE.editors && tinyMCE.editors.length ) {
            // Read directly from every TinyMCE instance — covers both standard
            // #content editors AND ACF iframe editors (data-id="acf-editor-*")
            tinyMCE.editors.forEach( function( editor ) {
                if ( editor && editor.getContent ) {
                    try {
                        allContent.push( editor.getContent() );
                    } catch(e) {}
                }
            } );
        }

        // Also read #content textarea as fallback (standard classic editor)
        var textarea = document.getElementById( 'content' );
        if ( textarea && textarea.value ) {
            allContent.push( textarea.value );
        }

        // If nothing found anywhere, nothing to scan
        if ( ! allContent.length ) { return false; }

        // Scan all collected content for images missing alt text
        for ( var c = 0; c < allContent.length; c++ ) {
            if ( ! allContent[c] ) { continue; }
            var tmp = document.createElement( 'div' );
            tmp.innerHTML = allContent[c];
            var imgs = tmp.querySelectorAll( 'img' );
            for ( var i = 0; i < imgs.length; i++ ) {
                var alt = ( imgs[i].getAttribute( 'alt' ) || '' ).trim();
                // Skip TinyMCE UI elements (resize handles etc)
                if ( imgs[i].getAttribute( 'data-mce-bogus' ) ) { continue; }
                if ( alt === '' ) { return true; }
            }
        }

        return false;
    }

    // -----------------------------------------------------------------
    // FEATURED IMAGE CHECK
    // -----------------------------------------------------------------

    function getFeaturedImageId() {
        var el = document.getElementById( '_thumbnail_id' );
        if ( el ) {
            var val = parseInt( el.value, 10 );
            if ( val && val > 0 ) { return val; }
        }
        var candidates = document.querySelectorAll(
            'input[type="hidden"][name*="thumbnail_id"],' +
            'input[type="hidden"][id*="thumbnail_id"],' +
            'input[type="hidden"][name*="featured_image"],' +
            'input[type="hidden"][id*="featured_image"],' +
            'input[type="hidden"][name*="featured_media"],' +
            'input[type="hidden"][id*="featured_media"]'
        );
        for ( var i = 0; i < candidates.length; i++ ) {
            var id = parseInt( candidates[i].value, 10 );
            if ( id && id > 0 ) { return id; }
        }
        return 0;
    }

    function featuredImageMissingAlt() {
        return new Promise( function( resolve ) {
            var thumbnailId = getFeaturedImageId();
            if ( ! thumbnailId ) { resolve( false ); return; }
            var formData = new FormData();
            formData.append( 'action',        'uwgs_get_attachment_alt' );
            formData.append( 'nonce',         nonce );
            formData.append( 'attachment_id', thumbnailId );
            fetch( ajaxUrl, { method: 'POST', body: formData } ).then( function( r ) { return r.json(); } ).then( function( response ) { resolve( response.success && ! response.data.has_alt ); } ).catch( function() { resolve( false ); } );
        } );
    }

    // -----------------------------------------------------------------
    // INLINE NOTICE BAR
    // Anchors to #titlediv if present (standard posts/pages).
    // Falls back to document.body for post types without #titlediv
    // (e.g. uw_stories) — appended to body, harmless if not visible.
    // Stays dismissed until new media inserted or all issues resolved.
    // -----------------------------------------------------------------

    function buildNoticeBar() {
        noticeEl = document.createElement( 'div' );
        noticeEl.id = 'uwgs-inline-notice';
        noticeEl.setAttribute( 'role',     'status' );
        noticeEl.setAttribute( 'aria-live','polite' );

        var textSpan = document.createElement( 'span' );
        textSpan.className = 'uwgs-notice-text';
        noticeEl.appendChild( textSpan );

        var dismissBtn = document.createElement( 'button' );
        dismissBtn.type      = 'button';
        dismissBtn.className = 'uwgs-notice-dismiss';
        dismissBtn.setAttribute( 'aria-label', i18n.dismiss || 'Dismiss' );
        dismissBtn.textContent = '✕';
        dismissBtn.addEventListener( 'click', function() {
            noticeEl.classList.remove( 'visible' );
            noticeDismissed = true;
        } );
        noticeEl.appendChild( dismissBtn );

        // Anchor: prefer #titlediv (standard WP), fall back to body
        var anchor = document.getElementById( 'titlediv' )
                  || document.getElementById( 'post-body-content' );
        if ( anchor && anchor.parentNode ) {
            anchor.parentNode.insertBefore( noticeEl, anchor.nextSibling );
        } else {
            document.body.appendChild( noticeEl );
        }
    }

    function updateNoticeBar( hasContent, hasFeatured ) {
        if ( ! noticeEl ) { return; }

        if ( ! hasContent && ! hasFeatured ) {
            noticeEl.classList.remove( 'visible' );
            noticeDismissed = false;
            return;
        }

        if ( noticeDismissed ) { return; }

        var textSpan = noticeEl.querySelector( '.uwgs-notice-text' );
        if ( ! textSpan ) { return; }

        var msg;
        if ( hasContent && hasFeatured ) {
            msg = i18n.noticeBoth     || '⚠ Images and the featured image are missing alt text.';
        } else if ( hasFeatured ) {
            msg = i18n.noticeFeatured || '⚠ The featured image is missing alt text.';
        } else {
            msg = i18n.noticeContent  || '⚠ One or more images are missing alt text.';
        }

        textSpan.textContent = msg;
        noticeEl.classList.add( 'visible' );
    }

    function refreshNoticeBar( afterMediaInsert ) {
        if ( afterMediaInsert ) { noticeDismissed = false; }
        var hasContent = contentHasMissingAlt();
        featuredImageMissingAlt().then( function( hasFeatured ) {
            updateNoticeBar( hasContent, hasFeatured );
        } );
    }

    // -----------------------------------------------------------------
    // PRE-SAVE WARNING MODAL
    // -----------------------------------------------------------------

    function buildWarningPanel() {
        warningEl = document.createElement( 'div' );
        warningEl.id = 'uwgs-presave-warning';
        warningEl.setAttribute( 'role',       'alertdialog' );
        warningEl.setAttribute( 'aria-live',  'assertive' );
        warningEl.setAttribute( 'aria-modal', 'false' );
        warningEl.setAttribute( 'tabindex',   '-1' );
        document.body.appendChild( warningEl );
    }

    function showWarning( hasContent, hasFeatured ) {
        warningEl.innerHTML = '';

        var title = document.createElement( 'strong' );
        title.textContent = i18n.warningTitle || '⚠ Accessibility: Images missing alt text';

        var bodyText;
        if ( hasContent && hasFeatured ) {
            bodyText = i18n.warningBodyBoth     || 'Images and the featured image are missing alt text.';
        } else if ( hasFeatured ) {
            bodyText = i18n.warningBodyFeatured || 'The featured image is missing alt text.';
        } else {
            bodyText = i18n.warningBodyContent  || 'One or more images are missing alt text.';
        }

        var body = document.createElement( 'p' );
        body.textContent = bodyText;

        var actions = document.createElement( 'div' );
        actions.className = 'uwgs-warning-actions';

        var goBack = document.createElement( 'button' );
        goBack.type = 'button'; goBack.className = 'button button-primary';
        goBack.textContent = i18n.goBack || 'Go back and fix';
        goBack.addEventListener( 'click', function() {
            hideWarning();
            if ( typeof window.tinyMCE !== 'undefined' && tinyMCE.activeEditor ) {
                tinyMCE.activeEditor.focus();
            }
        } );

        var saveAnyway = document.createElement( 'button' );
        saveAnyway.type = 'button'; saveAnyway.className = 'button';
        saveAnyway.textContent = i18n.saveAnyway || 'Save anyway';
        saveAnyway.addEventListener( 'click', function() {
            hideWarning();
            saving = true;
            if ( saveTarget ) { saveTarget.click(); }
        } );

        actions.appendChild( goBack );
        actions.appendChild( saveAnyway );
        warningEl.appendChild( title );
        warningEl.appendChild( body );
        warningEl.appendChild( actions );
        warningEl.classList.add( 'visible' );
        warningEl.focus();
    }

    function hideWarning() {
        warningEl.classList.remove( 'visible' );
        warningEl.innerHTML = '';
        saveTarget = null;
        saving = false;
    }

    // -----------------------------------------------------------------
    // SAVE BUTTON INTERCEPT — capture phase
    // Runs both content scan (sync) and featured image check (async)
    // in parallel. Neither can suppress the other.
    // -----------------------------------------------------------------

    function interceptSaveButtons() {
        var saveIds = [ 'save', 'save-post', 'publish' ];
        saveIds.forEach( function( id ) {
            var btn = document.getElementById( id );
            if ( ! btn ) { return; }

            btn.addEventListener( 'click', function( e ) {
                if ( saving ) { saving = false; return; }
                if ( warningEl.classList.contains( 'visible' ) ) { return; }

                e.preventDefault();
                e.stopImmediatePropagation();
                saveTarget = btn;

                var hasContent = contentHasMissingAlt();

                featuredImageMissingAlt().then( function( hasFeatured ) {
                    if ( hasContent || hasFeatured ) {
                        showWarning( hasContent, hasFeatured );
                    } else {
                        saving = true;
                        btn.click();
                    }
                } );

            }, true ); // capture phase — fires before all other handlers
        } );
    }

    // -----------------------------------------------------------------
    // WATCH FOR MEDIA MODAL CLOSE — re-scan after insert
    // -----------------------------------------------------------------

    function watchForModalClose() {
        var modalObserver = new MutationObserver( function( mutations ) {
            mutations.forEach( function( mutation ) {
                mutation.removedNodes.forEach( function( node ) {
                    if (
                        node.nodeType === 1 &&
                        node.classList &&
                        node.classList.contains( 'media-modal' )
                    ) {
                        setTimeout( function() { refreshNoticeBar( true ); }, 400 );
                    }
                } );
            } );
        } );
        modalObserver.observe( document.body, { childList: true } );
    }

    // -----------------------------------------------------------------
    // WAIT FOR TINYMCE THEN INITIAL SCAN
    // -----------------------------------------------------------------

    function waitForTinyMCEThenScan() {
        var attempts    = 0;
        var maxAttempts = 100;

        function attempt() {
            attempts++;
            var tinyReady = (
                typeof window.tinyMCE !== 'undefined' &&
                tinyMCE.editors &&
                tinyMCE.editors.length > 0 &&
                tinyMCE.editors[0].initialized
            );

            if ( tinyReady ) {
                setTimeout( function() { refreshNoticeBar( false ); }, 200 );
                return;
            }

            // Immediate textarea scan on first attempt
            if ( attempts === 1 ) { refreshNoticeBar( false ); }

            if ( attempts < maxAttempts ) { setTimeout( attempt, 100 ); }
        }

        attempt();
    }

    document.addEventListener( 'keydown', function( e ) {
        if ( e.key === 'Escape' && warningEl && warningEl.classList.contains( 'visible' ) ) {
            hideWarning();
        }
    } );

    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', function() {
            buildNoticeBar();
            buildWarningPanel();
            interceptSaveButtons();
            watchForModalClose();
            waitForTinyMCEThenScan();
        } );
    } else {
        buildNoticeBar();
        buildWarningPanel();
        interceptSaveButtons();
        watchForModalClose();
        waitForTinyMCEThenScan();
    }

} )();
JS;

        wp_add_inline_script( 'jquery', $js, 'after' );
    }

    // =========================================================================
    // ADD MEDIA MODAL: CAPTION-TO-ALT VIA MUTATIONOBSERVER
    // =========================================================================

    private function enqueue_media_modal_caption_assets() {

        $i18n = array(
            'captionCopied' => __( 'Copied from caption — please review before inserting.', 'uwgs-alt-text-tool' ),
        );

        wp_add_inline_script( 'jquery', 'var uwgsModalCapI18n = '. wp_json_encode( $i18n ). ';' );

        $js = <<<'JS'
( function() {

    'use strict';

    var i18n = ( typeof uwgsModalCapI18n !== 'undefined' ) ? uwgsModalCapI18n : {};

    function applyCaptionToAlt( panel ) {
        if ( ! panel ) { return; }

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

        if ( altVal !== '' || capVal === '' ) { return; }
        if ( altField.getAttribute( 'data-uwgs-cap-applied' ) ) { return; }

        altField.setAttribute( 'data-uwgs-cap-applied', '1' );
        altField.value = capVal;
        altField.dispatchEvent( new Event( 'change', { bubbles: true } ) );
        altField.dispatchEvent( new Event( 'input',  { bubbles: true } ) );

        var existing = panel.querySelector( '.uwgs-modal-cap-notice' );
        if ( existing ) { existing.remove(); }

        var notice = document.createElement( 'div' );
        notice.className = 'uwgs-modal-cap-notice';
        notice.setAttribute( 'role', 'note' );
        notice.style.cssText = [
            'margin-top:4px', 'padding:5px 8px', 'background:#fff3cd',
            'border-left:3px solid #ffc107', 'color:#856404', 'font-size:11px',
            'line-height:1.4', 'border-radius:0 2px 2px 0', 'display:flex',
            'align-items:center', 'justify-content:space-between', 'gap:6px',
        ].join( ';' );

        var msg = document.createElement( 'span' );
        msg.textContent = i18n.captionCopied || 'Copied from caption — please review before inserting.';

        var dismiss = document.createElement( 'button' );
        dismiss.type = 'button'; dismiss.textContent = '✕';
        dismiss.setAttribute( 'aria-label', 'Dismiss' );
        dismiss.style.cssText = 'background:none;border:none;cursor:pointer;color:#856404;font-size:12px;padding:0;flex-shrink:0;';
        dismiss.addEventListener( 'click', function() { notice.remove(); } );

        notice.appendChild( msg );
        notice.appendChild( dismiss );

        var altSetting = altField.closest( '.setting' );
        if ( altSetting && altSetting.parentNode ) {
            altSetting.parentNode.insertBefore( notice, altSetting.nextSibling );
        } else {
            altField.parentNode.insertBefore( notice, altField.nextSibling );
        }

        altField.addEventListener( 'input', function() {
            notice.remove();
            altField.removeAttribute( 'data-uwgs-cap-applied' );
        }, { once: true } );
    }

    function processPanels( container ) {
        if ( ! container ) { return; }
        container.querySelectorAll( '.attachment-details.save-ready,.attachment-details' ).forEach( function( panel ) {
                setTimeout( function() { applyCaptionToAlt( panel ); }, 200 );
            } );
    }

    function observeModalContent( modal ) {
        if ( ! modal || modal._uwgsObserved ) { return; }
        modal._uwgsObserved = true;
        processPanels( modal );

        var observer = new MutationObserver( function( mutations ) {
            mutations.forEach( function( mutation ) {
                mutation.addedNodes.forEach( function( node ) {
                    if ( node.nodeType !== 1 ) { return; }
                    if ( node.classList && node.classList.contains( 'attachment-details' ) ) {
                        setTimeout( function() { applyCaptionToAlt( node ); }, 200 );
                        return;
                    }
                    processPanels( node );
                } );
                if ( mutation.type === 'childList' && mutation.target ) {
                    var panel = mutation.target.closest ? mutation.target.closest( '.attachment-details' ) : null;
                    if ( panel ) { setTimeout( function() { applyCaptionToAlt( panel ); }, 200 ); }
                }
            } );
        } );

        observer.observe( modal, { childList: true, subtree: true } );
    }

    function observeForModal() {
        document.querySelectorAll( '.media-modal,.media-frame' ).forEach( observeModalContent );
        var bodyObserver = new MutationObserver( function( mutations ) {
            mutations.forEach( function( mutation ) {
                mutation.addedNodes.forEach( function( node ) {
                    if ( node.nodeType !== 1 ) { return; }
                    if ( node.classList && ( node.classList.contains( 'media-modal' ) || node.classList.contains( 'media-frame' ) ) ) {
                        observeModalContent( node ); return;
                    }
                    node.querySelectorAll && node.querySelectorAll( '.media-modal,.media-frame' ).forEach( observeModalContent );
                } );
            } );
        } );
        bodyObserver.observe( document.body, { childList: true, subtree: false } );
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
    //
    // Fix in v2.1.3: added wp.data.subscribe to force the pre-publish
    // panel to re-evaluate and open when issues are detected at publish
    // time, regardless of whether the panel was previously mounted.
    // =========================================================================

    public function enqueue_block_editor_assets() {
        if ( ! current_user_can( 'upload_files' ) ) { return; }

        $i18n = array(
            'panelTitle'      => __( 'Image Accessibility', 'uwgs-alt-text-tool' ),
            'allGood'         => __( '✓ All images in this post have alt text.', 'uwgs-alt-text-tool' ),
            'warningContent'  => __( 'One or more images in this post are missing alt text. Click each image block and add a description in the Alt Text field in the right sidebar, or mark it as decorative.', 'uwgs-alt-text-tool' ),
            'warningFeatured' => __( 'The featured image for this post is missing alt text. Edit the featured image and add a description in the Alt Text field.', 'uwgs-alt-text-tool' ),
            'warningBoth'     => __( 'One or more images and the featured image are missing alt text. Please add descriptions before publishing, or mark decorative images as such.', 'uwgs-alt-text-tool' ),
            'decorativeNote'  => __( 'If an image is purely decorative, leave alt text empty and check "Mark as decorative" in the block settings sidebar.', 'uwgs-alt-text-tool' ),
            'canvasBanner'    => __( 'Missing alt text — click this image, then add alt text in the sidebar panel on the right.', 'uwgs-alt-text-tool' ),
        );

        wp_add_inline_script( 'wp-blocks', 'var uwgsGutenbergI18n = '. wp_json_encode( $i18n ). ';' );

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
    var subscribe = wp.data    ? wp.data.subscribe                     : null;
    var dispatch  = wp.data    ? wp.data.dispatch                      : null;
    var addFilter = wp.hooks   ? wp.hooks.addFilter                    : null;
    var createHOC = wp.compose ? wp.compose.createHigherOrderComponent : null;

    // -----------------------------------------------------------------
    // BLOCK CANVAS WARNING HOC
    // -----------------------------------------------------------------

    if ( addFilter && createHOC ) {
        var withAltWarning = createHOC( function( BlockEdit ) {
            return function( props ) {
                if ( props.name !== 'core/image' ) { return el( BlockEdit, props ); }

                var alt      = ( props.attributes && props.attributes.alt ) || '';
                var url      = ( props.attributes && props.attributes.url ) || '';
                var hasImage = url !== '';
                var hasAlt   = alt.trim() !== '';

                var bannerStyle = {
                    display: 'flex', alignItems: 'center', gap: '8px',
                    margin: '4px 0 0', padding: '8px 12px',
                    background: '#fff3cd', borderLeft: '4px solid #ffc107',
                    color: '#856404', fontSize: '13px', lineHeight: '1.5',
                    borderRadius: '0 3px 3px 0',
                };

                return el( Fragment, null,
                    el( BlockEdit, props ),
                    ( hasImage && ! hasAlt )
                        ? el( 'div', {
                                style: bannerStyle, role: 'alert',
                                'aria-live': 'polite', className: 'uwgs-block-alt-warning',
                            },
                            el( 'span', { 'aria-hidden': 'true' }, '⚠' ),
                            el( 'span', null, i18n.canvasBanner || 'Missing alt text — add it in the sidebar.' )
                          )
                        : null
                );
            };
        }, 'withAltWarning' );

        addFilter( 'editor.BlockEdit', 'uwgs-alt-text-tool/with-alt-warning', withAltWarning );
    }

    if ( ! registerPlugin || ! PluginPrePublishPanel || ! useSelect ) { return; }

    // -----------------------------------------------------------------
    // HELPER: check blocks recursively for missing alt text
    // -----------------------------------------------------------------

    function hasImageBlocksMissingAlt( blocks ) {
        if ( ! blocks || ! blocks.length ) { return false; }
        for ( var i = 0; i < blocks.length; i++ ) {
            var block = blocks[i];
            if ( block.name === 'core/image' ) {
                var alt = ( block.attributes && block.attributes.alt )
                    ? block.attributes.alt.trim() : '';
                if ( alt === '' ) { return true; }
            }
            if ( block.innerBlocks && block.innerBlocks.length ) {
                if ( hasImageBlocksMissingAlt( block.innerBlocks ) ) { return true; }
            }
        }
        return false;
    }

    // -----------------------------------------------------------------
    // SUBSCRIBE: when the pre-publish panel opens, force it to show
    // the correct open state by dispatching an editPost action.
    //
    // wp.data.subscribe fires on every store change. We watch for the
    // pre-publish panel becoming active (isPublishSidebarOpened) and
    // if we have issues, ensure our panel is open.
    // -----------------------------------------------------------------

    if ( subscribe && dispatch ) {
        subscribe( function() {
            var editorStore = wp.data.select( 'core/edit-post' )
                           || wp.data.select( 'core/editor' );
            if ( ! editorStore ) { return; }

            // Only act when pre-publish sidebar is open
            var sidebarOpen = editorStore.isPublishSidebarOpened
                ? editorStore.isPublishSidebarOpened()
                : false;

            if ( ! sidebarOpen ) { return; }

            // Check for issues
            var blockEditorStore = wp.data.select( 'core/block-editor' );
            if ( ! blockEditorStore ) { return; }

            var blocks         = blockEditorStore.getBlocks();
            var contentMissing = hasImageBlocksMissingAlt( blocks );

            var featuredId = wp.data.select( 'core/editor' ).getEditedPostAttribute( 'featured_media' );
            var featuredMissing = false;
            if ( featuredId && featuredId > 0 ) {
                var media = wp.data.select( 'core' ).getMedia( featuredId, { context: 'edit' } );
                if ( media ) {
                    featuredMissing = ( media.alt_text || '' ).trim() === '';
                }
            }

            if ( contentMissing || featuredMissing ) {
                // Open our specific panel via editPost dispatch
                var editPostDispatch = wp.data.dispatch( 'core/edit-post' );
                if ( editPostDispatch && editPostDispatch.toggleEditorPanelOpened ) {
                    // Only open if not already open
                    var panelId = 'uwgs-alt-text-panel/uwgs-alt-text-panel';
                    var isOpen  = editorStore.isEditorPanelOpened
                        ? editorStore.isEditorPanelOpened( panelId )
                        : false;
                    if ( ! isOpen ) {
                        editPostDispatch.toggleEditorPanelOpened( panelId );
                    }
                }
            }
        } );
    }

    // -----------------------------------------------------------------
    // PRE-PUBLISH PANEL COMPONENT
    // -----------------------------------------------------------------

    function UWGSAltTextPanel() {

        var contentMissing = useSelect( function( select ) {
            var blocks = select( 'core/block-editor' ).getBlocks();
            return hasImageBlocksMissingAlt( blocks );
        } );

        var featuredMissing = useSelect( function( select ) {
            var featuredId = select( 'core/editor' ).getEditedPostAttribute( 'featured_media' );
            if ( ! featuredId || featuredId < 1 ) { return false; }
            var media = select( 'core' ).getMedia( featuredId, { context: 'edit' } );
            if ( ! media ) { return false; }
            return ( media.alt_text || '' ).trim() === '';
        } );

        var hasIssues = contentMissing || featuredMissing;

        var message;
        if ( contentMissing && featuredMissing ) {
            message = i18n.warningBoth     || 'Images and the featured image are missing alt text.';
        } else if ( featuredMissing ) {
            message = i18n.warningFeatured || 'The featured image is missing alt text.';
        } else {
            message = i18n.warningContent  || 'One or more images are missing alt text.';
        }

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
                    el( 'p', { style: { margin: '0 0 10px', color: '#856404', fontSize: '13px', lineHeight: '1.6' } }, message ),
                    el( 'p', { style: { margin: '0', fontSize: '12px', color: '#555', fontStyle: 'italic' } },
                        i18n.decorativeNote || 'If an image is decorative, mark it as decorative in block settings.' )
                  )
                : el( 'p', { style: { margin: '0', color: '#2e7d32', fontSize: '13px' } },
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
}

// Clear stats cache when alt text changes
add_action( 'wp_ajax_uwgs_save_alt_text', array( 'UWGS_Alt_Text_Tool', 'clear_stats_cache' ), 1 );
add_action( 'edit_attachment',            array( 'UWGS_Alt_Text_Tool', 'clear_stats_cache' ) );
add_action( 'add_attachment',             array( 'UWGS_Alt_Text_Tool', 'clear_stats_cache' ) );

add_action( 'plugins_loaded', array( 'UWGS_Alt_Text_Tool', 'init' ) );