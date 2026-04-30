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
 * Version:           2.3.1
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
    const VERSION         = '2.3.0';

    /**
     * Generic words that are not meaningful alt text.
     * Case-insensitive exact match.
     */
    const LOW_QUALITY_WORDS = array(
        'image', 'photo', 'img', 'picture', 'screenshot',
        'graphic', 'thumbnail', 'banner', 'logo', 'icon',
    );

    public static function init() {
        $instance = new self();
        $instance->hooks();
    }

    private function hooks() {

        // Media Library list view
        add_filter( 'manage_media_columns',           array( $this, 'register_column' ) );
        add_action( 'manage_media_custom_column',     array( $this, 'render_column' ), 10, 2 );
        add_filter( 'manage_upload_sortable_columns', array( $this, 'register_sortable' ) );

        // Query: sort + attention filter
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
    // ALT TEXT QUALITY HELPER
    //
    // Single source of truth for "needs attention" definition.
    // Returns true if alt text is missing, empty, whitespace-only,
    // too short, a generic placeholder word, filename-like, or numeric-only.
    // Used by stats, filter query, and column display.
    // =========================================================================

    private function uwgs_alt_needs_attention( $alt ) {

        // 1. Missing or whitespace-only
        $trimmed = trim( (string) $alt );
        if ( $trimmed === '' ) {
            return true;
        }

        // 2. Too short (under 3 characters)
        if ( mb_strlen( $trimmed ) < 3 ) {
            return true;
        }

        // 3. Numeric only
        if ( ctype_digit( str_replace( ' ', '', $trimmed ) ) ) {
            return true;
        }

        // 4. Generic placeholder word (case-insensitive exact match)
        if ( in_array( strtolower( $trimmed ), self::LOW_QUALITY_WORDS, true ) ) {
            return true;
        }

        // 5. Filename-like: word_digits or word-digits pattern
        //    e.g. IMG_1234, DSC00981, photo_001, img-456
        if ( preg_match( '/^[a-z]{1,6}[_\-]\d+$/i', $trimmed ) ) {
            return true;
        }

        return false;
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

        // Determine display state
        $is_empty       = ( $alt === '' || $alt === false );
        $is_low_quality = ( ! $is_empty && $this->uwgs_alt_needs_attention( $alt ) );

        ?>
        <div class="uwgs-alt-wrap" data-post-id="<?php echo esc_attr( $post_id ); ?>">

            <div class="uwgs-alt-display">

                <?php if ( ! empty( $alt ) && ! $is_low_quality ) : ?>
                    <span class="uwgs-alt-value uwgs-has-alt"><?php echo esc_html( $alt ); ?></span>

                <?php elseif ( $is_low_quality ) : ?>
                    <span class="uwgs-alt-value uwgs-low-quality"
                          title="<?php esc_attr_e( 'Alt text may not be meaningful', 'uwgs-alt-text-tool' ); ?>">
                        ⚠ <?php echo esc_html( $alt ); ?>
                    </span>

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
    // QUERY: SORT + ATTENTION FILTER
    //
    // Two-pass approach:
    // Pass 1: WP_Query catches cheap SQL cases (NOT EXISTS + empty string)
    //         plus images with alt text present (for low-quality PHP check)
    // Pass 2: PHP post-processes to exclude low-quality alt text from results
    //
    // For the filter we use a session transient to store IDs that passed
    // PHP post-processing, then filter by those IDs in the main query.
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

        if ( isset( $_GET['alt_filter'] ) && 'attention' === sanitize_key( $_GET['alt_filter'] ) ) {

            // Get IDs needing attention via $wpdb (same logic as stats)
            $ids = $this->get_attention_ids();

            if ( empty( $ids ) ) {
                // No results — force query to return nothing
                $query->set( 'post__in', array( 0 ) );
            } else {
                $query->set( 'post_mime_type', 'image' );
                $query->set( 'post__in', $ids );
                $query->set( 'orderby', 'post__in' );
            }
        }
    }

    /**
     * Get all image attachment IDs whose alt text needs attention.
     * Uses a single $wpdb query + PHP post-processing.
     * Result cached in transient for 12 hours (same cache as stats).
     *
     * @return int[]
     */
    private function get_attention_ids() {
        $cached = get_transient( 'uwgs_attention_ids' );
        if ( false !== $cached ) {
            return $cached;
        }

        global $wpdb;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.ID, pm.meta_value AS alt_text
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm
                 ON p.ID = pm.post_id
                 AND pm.meta_key = %s
             WHERE p.post_type = 'attachment'
               AND p.post_status = 'inherit'
               AND p.post_mime_type LIKE 'image/%'",
            self::META_KEY
        ) );

        $ids = array();
        foreach ( $rows as $row ) {
            if ( $this->uwgs_alt_needs_attention( $row->alt_text ) ) {
                $ids[] = (int) $row->ID;
            }
        }

        set_transient( 'uwgs_attention_ids', $ids, 12 * HOUR_IN_SECONDS );
        return $ids;
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

        $is_active    = ( 'attention' === $current );
        $attention_url = add_query_arg( array_merge( $extra, array( 'alt_filter' => 'attention' ) ), $base_url );
        $clear_url     = add_query_arg( array_merge( $extra, array( 'alt_filter' => '' ) ), $base_url );

        printf(
            '<a href="%s" class="button%s" style="margin-left:4px;" aria-pressed="%s">%s</a>',
            esc_url( $is_active ? $clear_url : $attention_url ),
            $is_active ? ' button-primary' : '',
            $is_active ? 'true' : 'false',
            $is_active
                ? esc_html__( '✕ Clear Filter', 'uwgs-alt-text-tool' )
                : esc_html__( '⚠ Alt text issues', 'uwgs-alt-text-tool' )
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
            'alt_text'        => $alt_text,
            'needs_attention' => $this->uwgs_alt_needs_attention( $alt_text ),
            'message'         => __( 'Alt text saved.', 'uwgs-alt-text-tool' ),
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
            'alt'             => $alt,
            'has_alt'         => ! empty( $alt ),
            'needs_attention' => $this->uwgs_alt_needs_attention( $alt ),
            'attachment_id'   => $attachment_id,
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

    /**
     * Get alt text coverage stats using a single $wpdb query + PHP post-processing.
     *
     * Returns:
     *   total          — all images in media library
     *   good           — images with meaningful alt text
     *   missing        — alt text absent or empty/whitespace
     *   low_quality    — alt text present but junk (generic word, filename-like etc.)
     *   needs_attention — missing + low_quality combined
     *   new_missing    — images flagged as uploaded without alt text
     */
    private function get_alt_text_stats() {
        $cached = get_transient( 'uwgs_alt_text_stats' );
        if ( false !== $cached ) { return $cached; }

        global $wpdb;

        // Single query: all image attachments + their alt text
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.ID, pm.meta_value AS alt_text
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm
                 ON p.ID = pm.post_id
                 AND pm.meta_key = %s
             WHERE p.post_type = 'attachment'
               AND p.post_status = 'inherit'
               AND p.post_mime_type LIKE 'image/%'",
            self::META_KEY
        ) );

        $total       = count( $rows );
        $missing     = 0;
        $low_quality = 0;
        $good        = 0;

        foreach ( $rows as $row ) {
            $trimmed = trim( (string) $row->alt_text );

            if ( $trimmed === '' ) {
                // Truly missing or empty
                $missing++;
            } elseif ( $this->uwgs_alt_needs_attention( $row->alt_text ) ) {
                // Present but low quality
                $low_quality++;
            } else {
                $good++;
            }
        }

        // New uploads missing alt text (flagged by plugin on upload)
        $new_missing = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT p.ID)
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
             WHERE p.post_type = 'attachment'
               AND p.post_status = 'inherit'
               AND p.post_mime_type LIKE 'image/%'
               AND pm.meta_key = %s
               AND pm.meta_value = '1'",
            self::NEEDS_ALT_KEY
        ) );

        $stats = array(
            'total'           => $total,
            'good'            => $good,
            'missing'         => $missing,
            'low_quality'     => $low_quality,
            'needs_attention' => $missing + $low_quality,
            'new_missing'     => $new_missing,
        );

        set_transient( 'uwgs_alt_text_stats', $stats, 12 * HOUR_IN_SECONDS );
        return $stats;
    }

    public static function clear_stats_cache() {
        delete_transient( 'uwgs_alt_text_stats' );
        delete_transient( 'uwgs_attention_ids' );
    }

    public function render_dashboard_widget() {

        $stats          = $this->get_alt_text_stats();
        $total          = $stats['total'];
        $good           = $stats['good'];
        $missing        = $stats['missing'];
        $low_quality    = $stats['low_quality'];
        $needs_attention = $stats['needs_attention'];
        $new_missing    = $stats['new_missing'];

        // Coverage percentage based on good alt text only
        $pct         = $total > 0 ? round( ( $good / $total ) * 100 ) : 100;
        $library_url = admin_url( 'upload.php?alt_filter=attention' );

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
                            <?php echo esc_html( number_format_i18n( $good ) ); ?>
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
                            <td style="padding:3px 0;color:#555;">
                                <?php esc_html_e( 'Total images', 'uwgs-alt-text-tool' ); ?>
                            </td>
                            <td style="padding:3px 0;text-align:right;font-weight:600;">
                                <?php echo esc_html( number_format_i18n( $total ) ); ?>
                            </td>
                        </tr>
                        <tr>
                            <td style="padding:3px 0;color:#2e7d32;">
                                <?php esc_html_e( 'Good alt text', 'uwgs-alt-text-tool' ); ?>
                            </td>
                            <td style="padding:3px 0;text-align:right;font-weight:600;color:#2e7d32;">
                                <?php echo esc_html( number_format_i18n( $good ) ); ?>
                            </td>
                        </tr>
                        <tr>
                            <td style="padding:3px 0;color:#c62828;">
                                <?php esc_html_e( 'Missing alt text', 'uwgs-alt-text-tool' ); ?>
                            </td>
                            <td style="padding:3px 0;text-align:right;font-weight:600;color:#c62828;">
                                <?php echo esc_html( number_format_i18n( $missing ) ); ?>
                            </td>
                        </tr>
                        <tr>
                            <td style="padding:3px 0;color:#856404;">
                                <?php esc_html_e( 'Low quality alt text', 'uwgs-alt-text-tool' ); ?>
                                <span style="font-size:11px;color:#999;display:block;">
                                    <?php esc_html_e( 'Generic, filename-like, or too short', 'uwgs-alt-text-tool' ); ?>
                                </span>
                            </td>
                            <td style="padding:3px 0;text-align:right;font-weight:600;color:#856404;vertical-align:top;">
                                <?php echo esc_html( number_format_i18n( $low_quality ) ); ?>
                            </td>
                        </tr>
                        <?php if ( $new_missing > 0 ) : ?>
                        <tr>
                            <td style="padding:3px 0;color:#555;font-style:italic;">
                                <?php esc_html_e( 'New uploads missing alt text', 'uwgs-alt-text-tool' ); ?>
                            </td>
                            <td style="padding:3px 0;text-align:right;font-weight:600;color:#555;">
                                <?php echo esc_html( number_format_i18n( $new_missing ) ); ?>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <tr style="border-top:1px solid #e0e0e0;">
                            <td style="padding:6px 0 3px;font-weight:600;color:#1d2327;">
                                <?php esc_html_e( 'Total needing attention', 'uwgs-alt-text-tool' ); ?>
                            </td>
                            <td style="padding:6px 0 3px;text-align:right;font-weight:600;color:#c62828;">
                                <?php echo esc_html( number_format_i18n( $needs_attention ) ); ?>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <?php if ( $needs_attention > 0 ) : ?>
                    <p style="margin:0 0 6px;">
                        <a href="<?php echo esc_url( $library_url ); ?>" class="button button-primary button-small">
                            <?php printf(
                                esc_html__( 'Fix %s images →', 'uwgs-alt-text-tool' ),
                                esc_html( number_format_i18n( $needs_attention ) )
                            ); ?>
                        </a>
                    </p>
                <?php else : ?>
                    <p style="margin:0;color:#2e7d32;font-weight:600;">
                        ✓ <?php esc_html_e( 'All images have good alt text. Great work!', 'uwgs-alt-text-tool' ); ?>
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

    $css = '.uwgs-alt-wrap                      { line-height:1.6; }.uwgs-has-alt                       { color:#2e7d32; }.uwgs-alt-blank                     { color:#c62828; font-weight:600; }.uwgs-low-quality                   { color:#856404; font-weight:600; }.uwgs-alt-new-flag                  {
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

    $suggestions = array();
    global $wp_query;
    if ( $wp_query && ! empty( $wp_query->posts ) ) {
        foreach ( $wp_query->posts as $attachment ) {
            $post_id = is_object( $attachment ) ? $attachment->ID : (int) $attachment;
            $mime    = get_post_mime_type( $post_id );
            if ( strpos( $mime, 'image/' ) !== 0 ) { continue; }
            $alt = get_post_meta( $post_id, self::META_KEY, true );
            if ( ! empty( $alt ) && ! $this->uwgs_alt_needs_attention( $alt ) ) { continue; }
            $caption  = get_post_field( 'post_excerpt', $post_id );
            $filename = get_post_field( 'post_title', $post_id );
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
            'saveFailed'         => __( 'Save failed. Please try again.', 'uwgs-alt-text-tool' ),
            'requestFailed'      => __( 'Request failed. Please try again.', 'uwgs-alt-text-tool' ),
            'saved'              => __( 'Saved.', 'uwgs-alt-text-tool' ),
            'blank'              => __( '(blank)', 'uwgs-alt-text-tool' ),
            'fromCaption'        => __( 'Suggested from caption — please review', 'uwgs-alt-text-tool' ),
            'fromFilename'       => __( 'Suggested from filename — please review', 'uwgs-alt-text-tool' ),
            'lowQualitySuggest'  => __( 'Low-quality suggestion — please write a description', 'uwgs-alt-text-tool' ),
        ),
    );

    wp_add_inline_script( 'jquery', 'var uwgsAltData = '. wp_json_encode( $data ). ';' );

    $js = <<<'JS'
jQuery( function( $ ) {

    var data        = ( typeof uwgsAltData !== 'undefined' ) ? uwgsAltData : {};
    var ajaxUrl     = data.ajaxUrl     || '';
    var suggestions = data.suggestions || {};
    var i18n        = data.i18n        || {};

    // -----------------------------------------------------------------
    // FILENAME SANITIZATION
    // -----------------------------------------------------------------

    function sanitizeFilename( raw ) {
        var s = raw;
        // 0. Decode all HTML entities to a space
        s = s.replace( /&[#a-zA-Z0-9]+;/g, ' ' );
        // 1. Strip extension
        s = s.replace( /\.[a-zA-Z0-9]+$/, '' );
        // 2. Replace hyphens and underscores with spaces
        s = s.replace( /[-_]+/g, ' ' );
        // 3. Remove WP thumbnail size patterns: NNNxNNN
        s = s.replace( /\b\d+x\d+\b/gi, '' );
        // 4. Remove 8-digit date patterns (YYYYMMDD)
        s = s.replace( /\b(19|20)\d{6}\b/g, '' );
        // 5. Remove standalone 4-digit years not followed by alphanumeric
        s = s.replace( /\b(19|20)\d{2}(?![a-zA-Z0-9])/g, '' );
        // 6. Remove 'scaled'
        s = s.replace( /\bscaled\b/gi, '' );
        // 7. Remove isolated 1-2 digit numbers
        s = s.replace( /\b\d{1,2}\b/g, '' );
        // 8. Collapse spaces and trim
        s = s.replace( /\s{2,}/g, ' ' ).trim();
        // 9. Title case — words starting with a letter only
        s = s.replace( /\b[a-zA-Z]/g, function( c ) { return c.toUpperCase(); } );
        return s;
    }

    // -----------------------------------------------------------------
    // SUGGESTION QUALITY VALIDATION
    //
    // Applied only to filename-derived suggestions (not captions).
    // Returns true if the suggestion is good enough to auto-fill.
    //
    // Fails if ANY of these are true:
    //   1. Fewer than 2 meaningful words (non-numeric, length > 1)
    //   2. More than half the tokens are numeric or short codes
    //   3. Original filename matches camera pattern (IMG_, DSC_, etc.)
    //   4. Result is a single token with no spaces
    //   5. Fewer than 5 characters total after sanitization
    // -----------------------------------------------------------------

    function validateFilenameSuggestion( sanitized, original ) {

        // Rule 5: too short overall
        if ( sanitized.length < 5 ) {
            return false;
        }

        // Rule 4: single token — no spaces at all
        if ( sanitized.indexOf( ' ' ) === -1 ) {
            return false;
        }

        // Rule 3: camera filename pattern in the original (pre-sanitization)
        // Catches IMG_1234, DSC00981, DSCN0001, MVI_001, MOV_001, P1234567
        if ( /^(IMG|DSC|DSCN|MVI|MOV|P\d)[_\-\s]/i.test( original.trim() ) ) {
            return false;
        }

        // Split into tokens for word-level checks
        var tokens = sanitized.split( /\s+/ ).filter( function( t ) { return t.length > 0; } );

        // Rule 1: fewer than 2 meaningful words
        // A "meaningful word" is a token that:
        //   - is longer than 1 character
        //   - is not purely numeric
        //   - is not a pure alphanumeric code (all digits + letters, no vowels)
        var meaningfulWords = tokens.filter( function( t ) {
            if ( t.length <= 1 ) { return false; }
            if ( /^\d+$/.test( t ) ) { return false; }
            // Pure code: all uppercase letters + digits, no lowercase vowels
            // e.g. "A4B", "XK2", "088A" — but NOT "Meany", "Theater"
            if ( /^[A-Z0-9]+$/.test( t ) && ! /[AEIOU]{1}/i.test( t ) ) { return false; }
            return true;
        } );

        if ( meaningfulWords.length < 2 ) {
            return false;
        }

        // Rule 2: more than half the tokens are numeric or short codes
        var junkTokens = tokens.filter( function( t ) {
            return /^\d+$/.test( t ) || ( t.length <= 3 && /^[A-Z0-9]+$/i.test( t ) );
        } );

        if ( junkTokens.length > tokens.length / 2 ) {
            return false;
        }

        return true;
    }

    // -----------------------------------------------------------------
    // OPEN INLINE EDITOR
    // -----------------------------------------------------------------

    $( document ).on( 'click', '.uwgs-alt-edit-btn', function() {
        var $wrap   = $( this ).closest( '.uwgs-alt-wrap' );
        var postId  = $wrap.data( 'post-id' );
        var $editor = $wrap.find( '.uwgs-alt-editor' );
        var $input  = $editor.find( '.uwgs-alt-input' );

        $wrap.find( '.uwgs-alt-display' ).hide();
        $editor.show();
        $editor.find( '.uwgs-alt-suggestion-hint' ).remove();

        if ( suggestions[ postId ] ) {
            var suggestion = suggestions[ postId ];

            if ( suggestion.type === 'caption' ) {
                // Captions: apply directly, no validation
                $input.val( suggestion.value );
                showHint( $input, i18n.fromCaption || 'Suggested from caption — please review', 'caption' );

            } else {
                // Filename: sanitize first, then validate
                var sanitized = sanitizeFilename( suggestion.value );
                var isGood    = validateFilenameSuggestion( sanitized, suggestion.value );

                if ( isGood ) {
                    // Good suggestion — auto-fill and show review hint
                    $input.val( sanitized );
                    showHint( $input, i18n.fromFilename || 'Suggested from filename — please review', 'filename' );
                } else {
                    // Poor suggestion — leave input empty, show prompt
                    $input.val( '' );
                    showHint( $input, i18n.lowQualitySuggest || 'Low-quality suggestion — please write a description', 'low-quality' );
                }
            }
        }

        $input.trigger( 'focus' );
        $( this ).attr( 'aria-expanded', 'true' );
    } );

    /**
     * Show a hint below the input field.
     * type: 'caption' | 'filename' | 'low-quality'
     */
    function showHint( $input, text, type ) {
        $input.closest( '.uwgs-alt-editor' ).find( '.uwgs-alt-suggestion-hint' ).remove();

        var styles = {
            'caption':     { color: '#856404', fontStyle: 'italic' },
            'filename':    { color: '#856404', fontStyle: 'italic' },
            'low-quality': { color: '#555',    fontStyle: 'normal', fontWeight: '600' },
        };

        var style = styles[ type ] || styles['filename'];

        var $hint = $( '<p>' ).addClass( 'uwgs-alt-suggestion-hint' ).css( $.extend( { 'margin': '4px 0 0', 'font-size': '11px' }, style ) ).text( text );

        $input.after( $hint );
        $input.one( 'input', function() { $hint.remove(); } );
    }

    // -----------------------------------------------------------------
    // CANCEL
    // -----------------------------------------------------------------

    $( document ).on( 'click', '.uwgs-alt-cancel-btn', function() {
        var $wrap = $( this ).closest( '.uwgs-alt-wrap' );
        $wrap.find( '.uwgs-alt-editor' ).hide();
        $wrap.find( '.uwgs-alt-suggestion-hint' ).remove();
        var $display = $wrap.find( '.uwgs-alt-display' );
        $display.show();
        $display.find( '.uwgs-alt-edit-btn' ).attr( 'aria-expanded', 'false' ).trigger( 'focus' );
        $wrap.find( '.uwgs-alt-feedback' ).text( '' ).removeClass( 'success error' );
    } );

    // -----------------------------------------------------------------
    // KEYBOARD
    // -----------------------------------------------------------------

    $( document ).on( 'keydown', '.uwgs-alt-input', function( e ) {
        if ( 13 === e.which ) {
            e.preventDefault();
            $( this ).closest( '.uwgs-alt-wrap' ).find( '.uwgs-alt-save-btn' ).trigger( 'click' );
        }
        if ( 27 === e.which ) {
            $( this ).closest( '.uwgs-alt-wrap' ).find( '.uwgs-alt-cancel-btn' ).trigger( 'click' );
        }
    } );

    // -----------------------------------------------------------------
    // SAVE
    // -----------------------------------------------------------------

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

                    if ( altText.length && ! response.data.needs_attention ) {
                        $value.text( altText ).removeClass( 'uwgs-alt-blank uwgs-low-quality' ).addClass( 'uwgs-has-alt' ).css( 'font-weight', 'normal' ).removeAttr( 'aria-label' );
                        $wrap.find( '.uwgs-alt-new-flag' ).remove();
                        delete suggestions[ postId ];
                    } else if ( altText.length && response.data.needs_attention ) {
                        $value.text( '⚠ ' + altText ).removeClass( 'uwgs-alt-blank uwgs-has-alt' ).addClass( 'uwgs-low-quality' );
                    } else {
                        $value.text( i18n.blank || '(blank)' ).removeClass( 'uwgs-has-alt uwgs-low-quality' ).addClass( 'uwgs-alt-blank' ).attr( 'aria-label', 'Alt text is blank' );
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
        var $msg = $( '<span>' ).text( i18n.captionCopied || 'Alt text copied from caption — please review before saving.' );
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

    function contentHasMissingAlt() {
        var allContent = [];

        if ( typeof window.tinyMCE !== 'undefined' && tinyMCE.editors && tinyMCE.editors.length ) {
            tinyMCE.editors.forEach( function( editor ) {
                if ( editor && editor.getContent ) {
                    try { allContent.push( editor.getContent() ); } catch(e) {}
                }
            } );
        }

        var textarea = document.getElementById( 'content' );
        if ( textarea && textarea.value ) {
            allContent.push( textarea.value );
        }

        if ( ! allContent.length ) { return false; }

        for ( var c = 0; c < allContent.length; c++ ) {
            if ( ! allContent[c] ) { continue; }
            if ( allContent[c].indexOf( '<img' ) === -1 ) { continue; }
            var tmp = document.createElement( 'div' );
            tmp.innerHTML = allContent[c];
            var imgs = tmp.querySelectorAll( 'img' );
            for ( var i = 0; i < imgs.length; i++ ) {
                if ( imgs[i].getAttribute( 'data-mce-bogus' ) ) { continue; }
                if ( ( imgs[i].getAttribute( 'alt' ) || '' ).trim() === '' ) { return true; }
            }
        }

        return false;
    }

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
            fetch( ajaxUrl, { method: 'POST', body: formData } ).then( function( r ) { return r.json(); } ).then( function( response ) {
                    // needs_attention covers both missing and low-quality
                    resolve( response.success && response.data.needs_attention );
                } ).catch( function() { resolve( false ); } );
        } );
    }

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

            }, true );
        } );
    }

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
    var addFilter = wp.hooks   ? wp.hooks.addFilter                    : null;
    var createHOC = wp.compose ? wp.compose.createHigherOrderComponent : null;

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

    if ( subscribe ) {
        subscribe( function() {
            var editorStore = wp.data.select( 'core/edit-post' )
                           || wp.data.select( 'core/editor' );
            if ( ! editorStore ) { return; }

            var sidebarOpen = editorStore.isPublishSidebarOpened
                ? editorStore.isPublishSidebarOpened()
                : false;
            if ( ! sidebarOpen ) { return; }

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
                var editPostDispatch = wp.data.dispatch( 'core/edit-post' );
                if ( editPostDispatch && editPostDispatch.toggleEditorPanelOpened ) {
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