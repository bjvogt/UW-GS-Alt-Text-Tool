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
 * Version:           2.3.2
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
    const VERSION         = '2.3.2';

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
    // =========================================================================

    private function uwgs_alt_needs_attention( $alt ) {

        $trimmed = trim( (string) $alt );
        if ( $trimmed === '' ) { return true; }
        if ( mb_strlen( $trimmed ) < 3 ) { return true; }
        if ( ctype_digit( str_replace( ' ', '', $trimmed ) ) ) { return true; }
        if ( in_array( strtolower( $trimmed ), self::LOW_QUALITY_WORDS, true ) ) { return true; }
        if ( preg_match( '/^[a-z]{1,6}[_\-]\d+$/i', $trimmed ) ) { return true; }

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
            echo '<span style="color:#999;" aria-label="'
                . esc_attr__( 'Not applicable', 'uwgs-alt-text-tool' ) . '">—</span>';
            return;
        }

        $alt      = get_post_meta( $post_id, self::META_KEY, true );
        $nonce    = wp_create_nonce( self::NONCE_ACTION . '_' . $post_id );
        $needs    = get_post_meta( $post_id, self::NEEDS_ALT_KEY, true );
        $can_edit = current_user_can( 'edit_post', $post_id );

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

                <?php // Task 3: Inline guidance — always visible when editor is open ?>
                <div class="uwgs-alt-guidance" role="note" aria-label="<?php esc_attr_e( 'Alt text guidance', 'uwgs-alt-text-tool' ); ?>">
                    <ul>
                        <li><?php esc_html_e( 'Describe the purpose of the image, not just what it is.', 'uwgs-alt-text-tool' ); ?></li>
                        <li><?php esc_html_e( 'Avoid phrases like "image of" or "photo of".', 'uwgs-alt-text-tool' ); ?></li>
                        <li><?php esc_html_e( 'Leave blank only if the image is purely decorative.', 'uwgs-alt-text-tool' ); ?></li>
                    </ul>
                </div>

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
            $ids = $this->get_attention_ids();
            if ( empty( $ids ) ) {
                $query->set( 'post__in', array( 0 ) );
            } else {
                $query->set( 'post_mime_type', 'image' );
                $query->set( 'post__in', $ids );
                $query->set( 'orderby', 'post__in' );
            }
        }
    }

    private function get_attention_ids() {
        $cached = get_transient( 'uwgs_attention_ids' );
        if ( false !== $cached ) { return $cached; }

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

        $current       = isset( $_GET['alt_filter'] ) ? sanitize_key( $_GET['alt_filter'] ) : '';
        $base_url      = admin_url( 'upload.php' );
        $passthrough   = array( 'm', 's', 'author', 'post_mime_type' );
        $extra         = array();

        foreach ( $passthrough as $param ) {
            if ( ! empty( $_GET[ $param ] ) ) {
                $extra[ $param ] = sanitize_text_field( $_GET[ $param ] );
            }
        }

        $is_active     = ( 'attention' === $current );
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
             || ! wp_verify_nonce( $_POST['nonce'], self::NONCE_ACTION . '_' . $post_id ) ) {
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

    private function get_alt_text_stats() {
        $cached = get_transient( 'uwgs_alt_text_stats' );
        if ( false !== $cached ) { return $cached; }

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

        $total       = count( $rows );
        $missing     = 0;
        $low_quality = 0;
        $good        = 0;

        foreach ( $rows as $row ) {
            $trimmed = trim( (string) $row->alt_text );
            if ( $trimmed === '' ) {
                $missing++;
            } elseif ( $this->uwgs_alt_needs_attention( $row->alt_text ) ) {
                $low_quality++;
            } else {
                $good++;
            }
        }

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

        $stats           = $this->get_alt_text_stats();
        $total           = $stats['total'];
        $good            = $stats['good'];
        $missing         = $stats['missing'];
        $low_quality     = $stats['low_quality'];
        $needs_attention = $stats['needs_attention'];
        $new_missing     = $stats['new_missing'];
        $pct             = $total > 0 ? round( ( $good / $total ) * 100 ) : 100;
        $library_url     = admin_url( 'upload.php?alt_filter=attention' );

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
                            <td style="padding:3px 0;color:#555;"><?php esc_html_e( 'Total images', 'uwgs-alt-text-tool' ); ?></td>
                            <td style="padding:3px 0;text-align:right;font-weight:600;"><?php echo esc_html( number_format_i18n( $total ) ); ?></td>
                        </tr>
                        <tr>
                            <td style="padding:3px 0;color:#2e7d32;"><?php esc_html_e( 'Good alt text', 'uwgs-alt-text-tool' ); ?></td>
                            <td style="padding:3px 0;text-align:right;font-weight:600;color:#2e7d32;"><?php echo esc_html( number_format_i18n( $good ) ); ?></td>
                        </tr>
                        <tr>
                            <td style="padding:3px 0;color:#c62828;"><?php esc_html_e( 'Missing alt text', 'uwgs-alt-text-tool' ); ?></td>
                            <td style="padding:3px 0;text-align:right;font-weight:600;color:#c62828;"><?php echo esc_html( number_format_i18n( $missing ) ); ?></td>
                        </tr>
                        <tr>
                            <td style="padding:3px 0;color:#856404;">
                                <?php esc_html_e( 'Low quality alt text', 'uwgs-alt-text-tool' ); ?>
                                <span style="font-size:11px;color:#999;display:block;">
                                    <?php esc_html_e( 'Generic, filename-like, or too short', 'uwgs-alt-text-tool' ); ?>
                                </span>
                            </td>
                            <td style="padding:3px 0;text-align:right;font-weight:600;color:#856404;vertical-align:top;"><?php echo esc_html( number_format_i18n( $low_quality ) ); ?></td>
                        </tr>
                        <?php if ( $new_missing > 0 ) : ?>
                        <tr>
                            <td style="padding:3px 0;color:#555;font-style:italic;"><?php esc_html_e( 'New uploads missing alt text', 'uwgs-alt-text-tool' ); ?></td>
                            <td style="padding:3px 0;text-align:right;font-weight:600;color:#555;"><?php echo esc_html( number_format_i18n( $new_missing ) ); ?></td>
                        </tr>
                        <?php endif; ?>
                        <tr style="border-top:1px solid #e0e0e0;">
                            <td style="padding:6px 0 3px;font-weight:600;color:#1d2327;"><?php esc_html_e( 'Total needing attention', 'uwgs-alt-text-tool' ); ?></td>
                            <td style="padding:6px 0 3px;text-align:right;font-weight:600;color:#c62828;"><?php echo esc_html( number_format_i18n( $needs_attention ) ); ?></td>
                        </tr>
                    </tbody>
                </table>

                <?php if ( $needs_attention > 0 ) : ?>
                    <p style="margin:0 0 6px;">
                        <a href="<?php echo esc_url( $library_url ); ?>" class="button button-primary button-small">
                            <?php printf( esc_html__( 'Fix %s images →', 'uwgs-alt-text-tool' ), esc_html( number_format_i18n( $needs_attention ) ) ); ?>
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

        $css = '
            .uwgs-alt-wrap                      { line-height:1.6; }
            .uwgs-has-alt                       { color:#2e7d32; }
            .uwgs-alt-blank                     { color:#c62828; font-weight:600; }
            .uwgs-low-quality                   { color:#856404; font-weight:600; }
            .uwgs-alt-new-flag                  {
                display:inline-block; margin-left:4px; font-size:11px;
                background:#fff3cd; color:#856404; border:1px solid #ffc107;
                border-radius:3px; padding:1px 5px; vertical-align:middle;
            }
            .uwgs-alt-edit-btn                  {
                cursor:pointer; text-decoration:underline; color:#2271b1;
                margin-left:6px; font-size:12px; background:none; border:none; padding:0;
            }
            .uwgs-alt-edit-btn:hover            { color:#135e96; }
            .uwgs-alt-feedback.success          { color:#2e7d32; }
            .uwgs-alt-feedback.error            { color:#c62828; }
            .uwgs-alt-editor input[type="text"] { font-size:13px; }

            /* Task 3: Inline guidance */
            .uwgs-alt-guidance {
                margin:6px 0 4px;
                padding:6px 8px;
                background:#f6f7f7;
                border-left:3px solid #c3c4c7;
                border-radius:0 2px 2px 0;
                font-size:11px;
                color:#646970;
                line-height:1.5;
            }
            .uwgs-alt-guidance ul {
                margin:0;
                padding:0 0 0 14px;
                list-style:disc;
            }
            .uwgs-alt-guidance ul li {
                margin:0;
                padding:0;
            }
        ';

        wp_register_style( 'uwgs-alt-text-tool', false, array(), self::VERSION );
        wp_enqueue_style( 'uwgs-alt-text-tool' );
        wp_add_inline_style( 'uwgs-alt-text-tool', $css );

        // Build per-attachment suggestion data
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
                'lowConfidence'      => __( 'Consider adding more detail for better accessibility', 'uwgs-alt-text-tool' ),
            ),
        );

        wp_add_inline_script( 'jquery', 'var uwgsAltData = ' . wp_json_encode( $data ) . ';' );

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
        s = s.replace( /&[#a-zA-Z0-9]+;/g, ' ' );   // 0. HTML entities
        s = s.replace( /\.[a-zA-Z0-9]+$/, '' );       // 1. Strip extension
        s = s.replace( /[-_]+/g, ' ' );               // 2. Hyphens/underscores to spaces
        s = s.replace( /\b\d+x\d+\b/gi, '' );         // 3. WP size patterns NNNxNNN
        s = s.replace( /\b(19|20)\d{6}\b/g, '' );     // 4. 8-digit dates
        s = s.replace( /\b(19|20)\d{2}(?![a-zA-Z0-9])/g, '' ); // 5. 4-digit years
        s = s.replace( /\bscaled\b/gi, '' );           // 6. 'scaled'
        s = s.replace( /\b\d{1,2}\b/g, '' );          // 7. Isolated 1-2 digit numbers
        s = s.replace( /\s{2,}/g, ' ' ).trim();       // 8. Collapse spaces
        s = s.replace( /\b[a-zA-Z]/g, function( c ) { return c.toUpperCase(); } ); // 9. Title case
        return s;
    }

    // -----------------------------------------------------------------
    // SUGGESTION QUALITY VALIDATION
    //
    // Returns one of three states:
    //   'good'         — auto-fill, show "please review" hint
    //   'low-confidence' — auto-fill, show "consider adding more detail" hint
    //   'junk'         — do not fill, show "please write a description" prompt
    //
    // 'low-confidence' applies when the suggestion is a single meaningful
    // word (e.g. "Logo", "Map", "Diagram") — valid in context but could
    // benefit from more detail. Previously these were rejected outright.
    //
    // 'junk' applies when:
    //   - Fewer than 5 characters total
    //   - Camera filename pattern (IMG_, DSC_, etc.)
    //   - More than half tokens are numeric/short codes
    //   - Zero meaningful words after tokenization
    // -----------------------------------------------------------------

    function validateFilenameSuggestion( sanitized, original ) {

        // Outright junk: too short
        if ( sanitized.length < 5 ) {
            return 'junk';
        }

        // Outright junk: camera filename pattern in original
        if ( /^(IMG|DSC|DSCN|MVI|MOV|P\d)[_\-\s]/i.test( original.trim() ) ) {
            return 'junk';
        }

        var tokens = sanitized.split( /\s+/ ).filter( function( t ) { return t.length > 0; } );

        // Count meaningful words
        var meaningfulWords = tokens.filter( function( t ) {
            if ( t.length <= 1 ) { return false; }
            if ( /^\d+$/.test( t ) ) { return false; }
            if ( /^[A-Z0-9]+$/.test( t ) && ! /[AEIOU]/i.test( t ) ) { return false; }
            return true;
        } );

        // Outright junk: no meaningful words at all
        if ( meaningfulWords.length === 0 ) {
            return 'junk';
        }

        // Outright junk: more than half tokens are numeric/short codes
        var junkTokens = tokens.filter( function( t ) {
            return /^\d+$/.test( t ) || ( t.length <= 3 && /^[A-Z0-9]+$/i.test( t ) );
        } );
        if ( junkTokens.length > tokens.length / 2 ) {
            return 'junk';
        }

        // Low confidence: exactly one meaningful word, no spaces
        // e.g. "Logo", "Map", "Diagram" — valid but could use more detail
        if ( meaningfulWords.length === 1 && sanitized.indexOf( ' ' ) === -1 ) {
            return 'low-confidence';
        }

        // Low confidence: single-word result even after sanitization
        // (had spaces in original but collapsed to one token)
        if ( tokens.length === 1 && meaningfulWords.length === 1 ) {
            return 'low-confidence';
        }

        return 'good';
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
                // Filename: sanitize then validat |