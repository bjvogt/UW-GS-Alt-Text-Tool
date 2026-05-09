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
 *                    widget with alt text coverage stats. Supports bulk application of high-confidence
 *                    alt text suggestions. Built for UW Graduate School.
 * Version:           2.5.9
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

    const NONCE_ACTION           = 'uwgs_alt_text_inline_save';
    const NONCE_ALT_CHECK        = 'uwgs_get_attachment_alt';
    const NONCE_BULK_SAVE        = 'uwgs_bulk_save_alt_text';
    const META_KEY               = '_wp_attachment_image_alt';
    const NEEDS_ALT_KEY          = '_uwgs_needs_alt';
    const VERSION                = '2.5.9';
    const BULK_CONFIRM_THRESHOLD = 20;
    const OPTION_INSTRUCTIONS    = 'uwgs_alt_text_instructions';

    const LOW_QUALITY_WORDS = array(
        'image', 'photo', 'img', 'picture', 'screenshot',
        'graphic', 'thumbnail', 'banner', 'logo', 'icon',
    );

    const IMAGE_EXTENSIONS = array(
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg',
        'bmp', 'tif', 'tiff', 'avif', 'heic', 'heif',
    );

    public static function init() {
        $instance = new self();
        $instance->hooks();
    }

    private function hooks() {
        add_filter( 'manage_media_columns',            array( $this, 'register_column' ) );
        add_action( 'manage_media_custom_column',      array( $this, 'render_column' ), 10, 2 );
        add_filter( 'manage_upload_sortable_columns',  array( $this, 'register_sortable' ) );
        add_action( 'pre_get_posts',                   array( $this, 'handle_query' ) );
        add_filter( 'posts_clauses',                   array( $this, 'sort_by_alt_text' ), 10, 2 );
        add_action( 'admin_enqueue_scripts',           array( $this, 'enqueue_admin_assets' ) );
        add_action( 'wp_ajax_uwgs_save_alt_text',      array( $this, 'ajax_save_alt_text' ) );
        add_action( 'wp_ajax_uwgs_bulk_save_alt_text', array( $this, 'ajax_bulk_save_alt_text' ) );
        add_action( 'wp_ajax_uwgs_get_attachment_alt', array( $this, 'ajax_get_attachment_alt' ) );
        add_action( 'add_attachment',                  array( $this, 'flag_new_upload' ) );
        add_action( 'edit_attachment',                 array( $this, 'server_copy_caption_to_alt' ) );
        add_action( 'wp_dashboard_setup',              array( $this, 'register_dashboard_widget' ) );
        add_action( 'enqueue_block_editor_assets',     array( $this, 'enqueue_block_editor_assets' ) );
        // Settings page
        add_action( 'admin_menu',                      array( $this, 'register_settings_page' ) );
        add_action( 'admin_init',                      array( $this, 'register_settings' ) );
        // uw_stories ACF-based save warning (hooked via admin_enqueue_scripts)
        add_action( 'admin_enqueue_scripts',           array( $this, 'enqueue_uw_stories_assets' ) );
    }

    // =========================================================================
    // ALT TEXT QUALITY HELPERS
    // =========================================================================

    private function uwgs_alt_needs_attention( $alt ) {
        $trimmed = trim( (string) $alt );
        if ( $trimmed === '' ) { return true; }
        if ( mb_strlen( $trimmed ) < 3 ) { return true; }
        if ( preg_match( '/^https?:\/\//i', $trimmed ) ) { return true; }
        if ( preg_match( '/^www\./i', $trimmed ) ) { return true; }
        $ext_pattern = '/\.('. implode( '|', self::IMAGE_EXTENSIONS ). ')$/i';
        if ( preg_match( $ext_pattern, $trimmed ) ) { return true; }
        if ( ctype_digit( str_replace( ' ', '', $trimmed ) ) ) { return true; }
        if ( in_array( strtolower( $trimmed ), self::LOW_QUALITY_WORDS, true ) ) { return true; }
        if ( preg_match( '/^[a-z]{1,6}[_\-]\d+$/i', $trimmed ) ) { return true; }
        return false;
    }

    private function uwgs_classify_alt( $alt ) {
        $trimmed = trim( (string) $alt );
        if ( $trimmed === '' ) { return 'invalid'; }
        if ( preg_match( '/^https?:\/\//i', $trimmed ) ) { return 'invalid'; }
        if ( preg_match( '/^www\./i', $trimmed ) ) { return 'invalid'; }
        $ext_pattern = '/\.('. implode( '|', self::IMAGE_EXTENSIONS ). ')$/i';
        if ( preg_match( $ext_pattern, $trimmed ) ) { return 'invalid'; }
        if ( mb_strlen( $trimmed ) < 3 ) { return 'invalid'; }
        if ( ctype_digit( str_replace( ' ', '', $trimmed ) ) ) { return 'invalid'; }
        if ( in_array( strtolower( $trimmed ), self::LOW_QUALITY_WORDS, true ) ) { return 'invalid'; }
        if ( preg_match( '/^[a-z]{1,6}[_\-]\d+$/i', $trimmed ) ) { return 'invalid'; }
        $words = preg_split( '/\s+/', $trimmed, -1, PREG_SPLIT_NO_EMPTY );
        if ( count( $words ) === 1 ) { return 'weak'; }
        return 'good';
    }

    // =========================================================================
    // MEDIA LIBRARY LIST VIEW: COLUMN
    // =========================================================================

    public function register_column( $columns ) {
        $columns['uwgs_alt_text'] = __( 'Alt Text', 'uwgs-alt-text-tool' );
        return $columns;
    }

    public function render_column( $column_name, $post_id ) {
        if ( 'uwgs_alt_text' !== $column_name ) { return; }

        $mime     = get_post_mime_type( $post_id );
        $is_image = ( strpos( $mime, 'image/' ) === 0 );

        if ( ! $is_image ) {
            echo '<span style="color:#999;" aria-label="'. esc_attr__( 'Not applicable', 'uwgs-alt-text-tool' ). '">—</span>';
            return;
        }

        $alt            = get_post_meta( $post_id, self::META_KEY, true );
        $nonce          = wp_create_nonce( self::NONCE_ACTION. '_'. $post_id );
        $needs          = get_post_meta( $post_id, self::NEEDS_ALT_KEY, true );
        $can_edit       = current_user_can( 'edit_post', $post_id );
        $is_empty       = ( $alt === '' || $alt === false );
        $is_low_quality = ( ! $is_empty && $this->uwgs_alt_needs_attention( $alt ) );
        $needs_editor   = ( $is_empty || $is_low_quality );

        ?>
        <div class="uwgs-alt-wrap" data-post-id="<?php echo esc_attr( $post_id ); ?>">

            <?php if ( ! $needs_editor ) : ?>
                <?php /* Good alt text — display only, no editor */ ?>
                <span class="uwgs-alt-value uwgs-has-alt"><?php echo esc_html( $alt ); ?></span>

            <?php else : ?>
                <?php /* Missing or low-quality — show status badge then editor */ ?>
                <?php if ( $is_low_quality ) : ?>
                    <span class="uwgs-alt-value uwgs-low-quality"
                          title="<?php esc_attr_e( 'Alt text may not be meaningful — consider improving it', 'uwgs-alt-text-tool' ); ?>">
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
                <div class="uwgs-alt-editor"
                     id="uwgs-alt-editor-<?php echo esc_attr( $post_id ); ?>"
                     role="group"
                     aria-label="<?php esc_attr_e( 'Alt text editor', 'uwgs-alt-text-tool' ); ?>"
                     style="margin-top:5px;">
                    <label for="uwgs-alt-input-<?php echo esc_attr( $post_id ); ?>"
                           class="screen-reader-text">
                        <?php esc_html_e( 'Alt text', 'uwgs-alt-text-tool' ); ?>
                    </label>
                    <textarea
                           id="uwgs-alt-input-<?php echo esc_attr( $post_id ); ?>"
                           class="uwgs-alt-input"
                           placeholder="<?php esc_attr_e( 'Describe this image…', 'uwgs-alt-text-tool' ); ?>"
                           style="width:100%;max-width:280px;height:64px;resize:vertical;font-size:13px;line-height:1.4;"
                           rows="3"
                           data-post-id="<?php echo esc_attr( $post_id ); ?>"
                           data-saved-alt="<?php echo esc_attr( $alt ); ?>"
                    ></textarea>
                    <div class="uwgs-alt-hint" style="display:none;font-size:11px;color:#646970;font-style:italic;margin:2px 0 4px;"></div>
                    <div style="margin-top:4px;">
                        <button type="button"
                                class="uwgs-alt-save-btn button button-primary button-small"
                                data-nonce="<?php echo esc_attr( $nonce ); ?>">
                            <?php esc_html_e( 'Save', 'uwgs-alt-text-tool' ); ?>
                        </button>
                        <span class="uwgs-alt-spinner spinner"
                              style="float:none;margin:0 4px;vertical-align:middle;"
                              aria-hidden="true"></span>
                        <span class="uwgs-alt-feedback"
                              role="status"
                              aria-live="polite"
                              style="font-size:12px;display:block;margin-top:2px;"></span>
                    </div>
                </div>
                <?php endif; ?>
            <?php endif; ?>

        </div>
        <?php
    }

    public function register_sortable( $sortable_columns ) {
        $sortable_columns['uwgs_alt_text'] = 'uwgs_alt_text';
        return $sortable_columns;
    }

    // =========================================================================
    // QUERY: ATTENTION FILTER ONLY
    // =========================================================================

    public function handle_query( $query ) {
        if ( ! is_admin() || ! $query->is_main_query() ) { return; }
        $screen = get_current_screen();
        if ( ! $screen || 'upload' !== $screen->id ) { return; }

        if ( isset( $_GET['alt_filter'] ) && 'attention' === sanitize_key( $_GET['alt_filter'] ) ) {
            $ids = $this->get_attention_ids();
            if ( empty( $ids ) ) {
                $query->set( 'post__in', array( 0 ) );
            } else {
                $query->set( 'post_mime_type', 'image' );
                $query->set( 'post__in', $ids );
            }
        }
    }

    // =========================================================================
    // ALT TEXT COLUMN SORTING via posts_clauses
    // =========================================================================

    public function sort_by_alt_text( $clauses, $query ) {
        if ( ! is_admin() || ! $query->is_main_query() ) { return $clauses; }
        $screen = get_current_screen();
        if ( ! $screen || 'upload' !== $screen->id ) { return $clauses; }
        if ( 'uwgs_alt_text' !== $query->get( 'orderby' ) ) { return $clauses; }

        global $wpdb;
        $order      = strtoupper( $query->get( 'order' ) ) === 'DESC' ? 'DESC' : 'ASC';
        $join_alias = 'pm_uwgs_alt';

        if ( strpos( $clauses['join'], $join_alias ) === false ) {
            $clauses['join'].= $wpdb->prepare(
                " LEFT JOIN {$wpdb->postmeta} AS {$join_alias}
                  ON ( {$wpdb->posts}.ID = {$join_alias}.post_id
                  AND {$join_alias}.meta_key = %s )",
                self::META_KEY
            );
        }

        if ( $order === 'ASC' ) {
            $clauses['orderby'] = "
                CASE WHEN {$join_alias}.meta_value IS NULL OR {$join_alias}.meta_value = ''
                THEN 0 ELSE 1 END ASC,
                {$join_alias}.meta_value ASC
            ";
        } else {
            $clauses['orderby'] = "
                CASE WHEN {$join_alias}.meta_value IS NULL OR {$join_alias}.meta_value = ''
                THEN 1 ELSE 0 END ASC,
                {$join_alias}.meta_value DESC
            ";
        }

        $clauses['groupby'] = "{$wpdb->posts}.ID";
        return $clauses;
    }

    private function get_attention_ids() {
        $cached = get_transient( 'uwgs_attention_ids' );
        if ( false !== $cached ) { return $cached; }

        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.ID, pm.meta_value AS alt_text
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = %s
             WHERE p.post_type = 'attachment' AND p.post_status = 'inherit'
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
    // AJAX: SAVE SINGLE ALT TEXT
    // =========================================================================

    public function ajax_save_alt_text() {
        $post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
        if ( ! $post_id ) { wp_send_json_error( __( 'Invalid attachment ID.', 'uwgs-alt-text-tool' ) ); }
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], self::NONCE_ACTION. '_'. $post_id ) ) {
            wp_send_json_error( __( 'Security check failed.', 'uwgs-alt-text-tool' ) );
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            wp_send_json_error( __( 'You do not have permission to edit this attachment.', 'uwgs-alt-text-tool' ) );
        }
        if ( 'attachment' !== get_post_type( $post_id ) ) {
            wp_send_json_error( __( 'Invalid post type.', 'uwgs-alt-text-tool' ) );
        }
        $alt_text = isset( $_POST['alt_text'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['alt_text'] ) ) ) : '';
        if ( preg_match( '/^https?:\/\//i', $alt_text ) || preg_match( '/^www\./i', $alt_text ) ) {
            wp_send_json_error( __( 'URLs cannot be used as alt text.', 'uwgs-alt-text-tool' ) );
        }
        $ext_pattern = '/\.('. implode( '|', self::IMAGE_EXTENSIONS ). ')$/i';
        if ( preg_match( $ext_pattern, $alt_text ) ) {
            wp_send_json_error( __( 'Filenames cannot be used as alt text.', 'uwgs-alt-text-tool' ) );
        }
        update_post_meta( $post_id, self::META_KEY, $alt_text );
        delete_post_meta( $post_id, self::NEEDS_ALT_KEY );
        wp_send_json_success( array(
            'alt_text'        => $alt_text,
            'needs_attention' => $this->uwgs_alt_needs_attention( $alt_text ),
            'classification'  => $this->uwgs_classify_alt( $alt_text ),
            'message'         => __( 'Alt text saved.', 'uwgs-alt-text-tool' ),
        ) );
    }

    // =========================================================================
    // AJAX: BULK SAVE ALT TEXT
    // =========================================================================

    public function ajax_bulk_save_alt_text() {
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], self::NONCE_BULK_SAVE ) ) {
            wp_send_json_error( __( 'Security check failed.', 'uwgs-alt-text-tool' ) );
        }
        if ( ! current_user_can( 'upload_files' ) ) {
            wp_send_json_error( __( 'Insufficient permissions.', 'uwgs-alt-text-tool' ) );
        }
        $raw_updates = isset( $_POST['updates'] ) ? $_POST['updates'] : array();
        if ( ! is_array( $raw_updates ) || empty( $raw_updates ) ) {
            wp_send_json_error( __( 'No updates provided.', 'uwgs-alt-text-tool' ) );
        }
        $updated = array(); $failed = array(); $skipped = array(); $saved_values = array();
        $ext_pattern = '/\.('. implode( '|', self::IMAGE_EXTENSIONS ). ')$/i';
        foreach ( $raw_updates as $item ) {
            $post_id  = isset( $item['id'] )  ? absint( $item['id'] ) : 0;
            $alt_text = isset( $item['alt'] ) ? trim( sanitize_text_field( wp_unslash( $item['alt'] ) ) ) : '';
            if ( ! $post_id || empty( $alt_text ) ) { $failed[] = $post_id; continue; }
            if ( ! current_user_can( 'edit_post', $post_id ) ) { $failed[] = $post_id; continue; }
            if ( 'attachment' !== get_post_type( $post_id ) ) { $failed[] = $post_id; continue; }
            $mime = get_post_mime_type( $post_id );
            if ( strpos( $mime, 'image/' ) !== 0 ) { $failed[] = $post_id; continue; }
            if ( preg_match( '/^https?:\/\//i', $alt_text ) || preg_match( '/^www\./i', $alt_text ) ) { $skipped[] = $post_id; continue; }
            if ( preg_match( $ext_pattern, $alt_text ) ) { $skipped[] = $post_id; continue; }
            $classification = $this->uwgs_classify_alt( $alt_text );
            if ( $classification !== 'good' ) { $skipped[] = $post_id; continue; }
            $current_alt = get_post_meta( $post_id, self::META_KEY, true );
            if ( ! empty( $current_alt ) && $this->uwgs_classify_alt( $current_alt ) === 'good' ) { $skipped[] = $post_id; continue; }
            update_post_meta( $post_id, self::META_KEY, $alt_text );
            delete_post_meta( $post_id, self::NEEDS_ALT_KEY );
            $updated[] = $post_id;
            $saved_values[ (string) $post_id ] = $alt_text;
        }
        self::clear_stats_cache();
        wp_send_json_success( array(
            'updated'      => $updated,
            'failed'       => $failed,
            'skipped'      => $skipped,
            'saved_values' => $saved_values,
            'counts'       => array( 'updated' => count( $updated ), 'failed' => count( $failed ), 'skipped' => count( $skipped ) ),
        ) );
    }

    // =========================================================================
    // AJAX: GET ATTACHMENT ALT TEXT
    // =========================================================================

    public function ajax_get_attachment_alt() {
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], self::NONCE_ALT_CHECK ) ) {
            wp_send_json_error( 'Security check failed.' );
        }
        if ( ! current_user_can( 'upload_files' ) ) { wp_send_json_error( 'Insufficient permissions.' ); }
        $attachment_id = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;
        if ( ! $attachment_id ) { wp_send_json_error( 'Invalid attachment ID.' ); }
        if ( 'attachment' !== get_post_type( $attachment_id ) ) { wp_send_json_error( 'Not an attachment.' ); }
        $alt = get_post_meta( $attachment_id, self::META_KEY, true );
        wp_send_json_success( array(
            'alt'             => $alt,
            'has_alt'         => ! empty( $alt ),
            'needs_attention' => $this->uwgs_alt_needs_attention( $alt ),
            'classification'  => $this->uwgs_classify_alt( $alt ),
            'attachment_id'   => $attachment_id,
        ) );
    }

    // =========================================================================
    // DASHBOARD WIDGET
    // =========================================================================

    public function register_dashboard_widget() {
        if ( ! current_user_can( 'upload_files' ) ) { return; }
        wp_add_dashboard_widget( 'uwgs_alt_text_widget', __( 'Image Alt Text Coverage', 'uwgs-alt-text-tool' ), array( $this, 'render_dashboard_widget' ) );
    }

    private function get_alt_text_stats() {
        $cached = get_transient( 'uwgs_alt_text_stats' );
        if ( false !== $cached ) { return $cached; }
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.ID, pm.meta_value AS alt_text FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = %s
             WHERE p.post_type = 'attachment' AND p.post_status = 'inherit' AND p.post_mime_type LIKE 'image/%'",
            self::META_KEY
        ) );
        $total = count( $rows ); $missing = $low_quality = $good = 0;
        foreach ( $rows as $row ) {
            $trimmed = trim( (string) $row->alt_text );
            if ( $trimmed === '' ) { $missing++; }
            elseif ( $this->uwgs_alt_needs_attention( $row->alt_text ) ) { $low_quality++; }
            else { $good++; }
        }
        $new_missing = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
             WHERE p.post_type = 'attachment' AND p.post_status = 'inherit' AND p.post_mime_type LIKE 'image/%'
             AND pm.meta_key = %s AND pm.meta_value = '1'", self::NEEDS_ALT_KEY
        ) );
        $stats = array( 'total' => $total, 'good' => $good, 'missing' => $missing, 'low_quality' => $low_quality, 'needs_attention' => $missing + $low_quality, 'new_missing' => $new_missing );
        set_transient( 'uwgs_alt_text_stats', $stats, 12 * HOUR_IN_SECONDS );
        return $stats;
    }

    public static function clear_stats_cache() {
        delete_transient( 'uwgs_alt_text_stats' );
        delete_transient( 'uwgs_attention_ids' );
    }

    public function render_dashboard_widget() {
        $stats = $this->get_alt_text_stats();
        $total = $stats['total']; $good = $stats['good']; $missing = $stats['missing'];
        $low_quality = $stats['low_quality']; $needs_attention = $stats['needs_attention']; $new_missing = $stats['new_missing'];
        $pct = $total > 0 ? round( ( $good / $total ) * 100 ) : 100;
        $library_url = admin_url( 'upload.php?alt_filter=attention' );
        ?>
        <div class="uwgs-widget" style="font-size:13px;line-height:1.6;">
            <?php if ( $total === 0 ) : ?>
                <p style="color:#555;margin:0;"><?php esc_html_e( 'No images found in the media library.', 'uwgs-alt-text-tool' ); ?></p>
            <?php else : ?>
                <div style="margin-bottom:12px;">
                    <div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:4px;">
                        <span style="font-weight:600;font-size:14px;color:#1d2327;"><?php echo esc_html( $pct ); ?>% <?php esc_html_e( 'coverage', 'uwgs-alt-text-tool' ); ?></span>
                        <span style="color:#555;font-size:12px;"><?php echo esc_html( number_format_i18n( $good ) ); ?> <?php esc_html_e( 'of', 'uwgs-alt-text-tool' ); ?> <?php echo esc_html( number_format_i18n( $total ) ); ?> <?php esc_html_e( 'images', 'uwgs-alt-text-tool' ); ?></span>
                    </div>
                    <div style="background:#e0e0e0;border-radius:4px;height:10px;overflow:hidden;" role="progressbar" aria-valuenow="<?php echo esc_attr( $pct ); ?>" aria-valuemin="0" aria-valuemax="100" aria-label="<?php echo esc_attr( sprintf( __( 'Alt text coverage: %d%%', 'uwgs-alt-text-tool' ), $pct ) ); ?>">
                        <div style="background:#757575;width:<?php echo esc_attr( $pct ); ?>%;height:100%;border-radius:4px;transition:width 0.3s ease;"></div>
                    </div>
                </div>
                <table style="width:100%;border-collapse:collapse;margin-bottom:12px;">
                    <tbody>
                        <tr><td style="padding:3px 0;color:#555;"><?php esc_html_e( 'Total images', 'uwgs-alt-text-tool' ); ?></td><td style="padding:3px 0;text-align:right;font-weight:600;"><?php echo esc_html( number_format_i18n( $total ) ); ?></td></tr>
                        <tr><td style="padding:3px 0;color:#2e7d32;"><?php esc_html_e( 'Good alt text', 'uwgs-alt-text-tool' ); ?></td><td style="padding:3px 0;text-align:right;font-weight:600;color:#2e7d32;"><?php echo esc_html( number_format_i18n( $good ) ); ?></td></tr>
                        <tr><td style="padding:3px 0;color:#c62828;"><?php esc_html_e( 'Missing alt text', 'uwgs-alt-text-tool' ); ?></td><td style="padding:3px 0;text-align:right;font-weight:600;color:#c62828;"><?php echo esc_html( number_format_i18n( $missing ) ); ?></td></tr>
                        <tr><td style="padding:3px 0;color:#856404;"><?php esc_html_e( 'Low quality alt text', 'uwgs-alt-text-tool' ); ?><span style="font-size:11px;color:#999;display:block;"><?php esc_html_e( 'Generic, filename-like, URL, or too short', 'uwgs-alt-text-tool' ); ?></span></td><td style="padding:3px 0;text-align:right;font-weight:600;color:#856404;vertical-align:top;"><?php echo esc_html( number_format_i18n( $low_quality ) ); ?></td></tr>
                        <?php if ( $new_missing > 0 ) : ?><tr><td style="padding:3px 0;color:#555;font-style:italic;"><?php esc_html_e( 'New uploads missing alt text', 'uwgs-alt-text-tool' ); ?></td><td style="padding:3px 0;text-align:right;font-weight:600;color:#555;"><?php echo esc_html( number_format_i18n( $new_missing ) ); ?></td></tr><?php endif; ?>
                        <tr style="border-top:1px solid #e0e0e0;"><td style="padding:6px 0 3px;font-weight:600;color:#1d2327;"><?php esc_html_e( 'Total needing attention', 'uwgs-alt-text-tool' ); ?></td><td style="padding:6px 0 3px;text-align:right;font-weight:600;color:#c62828;"><?php echo esc_html( number_format_i18n( $needs_attention ) ); ?></td></tr>
                    </tbody>
                </table>
                <?php if ( $needs_attention > 0 ) : ?>
                    <p style="margin:0 0 6px;"><a href="<?php echo esc_url( $library_url ); ?>" class="button button-primary button-small"><?php printf( esc_html__( 'Fix %s images →', 'uwgs-alt-text-tool' ), esc_html( number_format_i18n( $needs_attention ) ) ); ?></a></p>
                <?php else : ?>
                    <p style="margin:0;color:#2e7d32;font-weight:600;">✓ <?php esc_html_e( 'All images have good alt text. Great work!', 'uwgs-alt-text-tool' ); ?></p>
                <?php endif; ?>
                <p style="margin:6px 0 0;font-size:11px;color:#999;"><?php esc_html_e( 'Stats refresh every 12 hours.', 'uwgs-alt-text-tool' ); ?> <a href="<?php echo esc_url( add_query_arg( 'uwgs_refresh_stats', '1' ) ); ?>" style="color:#999;"><?php esc_html_e( 'Refresh now', 'uwgs-alt-text-tool' ); ?></a></p>
            <?php endif; ?>
        </div>
        <?php
    }

    // =========================================================================
    // ADMIN ASSETS
    // =========================================================================

    public function enqueue_admin_assets( $hook ) {
        if ( isset( $_GET['uwgs_refresh_stats'] ) && '1' === $_GET['uwgs_refresh_stats'] && current_user_can( 'upload_files' ) ) {
            self::clear_stats_cache();
            wp_safe_redirect( remove_query_arg( 'uwgs_refresh_stats' ) );
            exit;
        }
        if ( 'upload.php' === $hook ) { $this->enqueue_list_view_assets(); }
        if ( 'media-new.php' === $hook ) { $this->enqueue_upload_page_assets(); }
        if ( in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
            global $post;
            if ( $post && 'post.php' === $hook && 'attachment' === get_post_type( $post->ID ) && strpos( get_post_mime_type( $post->ID ), 'image/' ) === 0 ) {
                $this->enqueue_attachment_edit_assets( $post->ID );
            }
            if ( ! did_action( 'enqueue_block_editor_assets' ) ) {
                $post_type = ( $post ) ? get_post_type( $post->ID ) : '';
                if ( 'uw_stories' !== $post_type ) {
                    $this->enqueue_classic_presave_assets();
                }
            }
            if ( ! did_action( 'wp_enqueue_media' ) ) { wp_enqueue_media(); }
            $this->enqueue_media_modal_caption_assets();
        }
    }

    // =========================================================================
    // LIST VIEW ASSETS
    // =========================================================================

    private function enqueue_list_view_assets() {

        $css = '
            .uwgs-alt-wrap      { line-height:1.5; }
            .uwgs-has-alt       { color:#2e7d32; }
            .uwgs-alt-blank     { color:#c62828; font-weight:600; }
            .uwgs-low-quality   { color:#856404; font-weight:600; display:block; margin-bottom:3px; }
            .uwgs-alt-new-flag  {
                display:inline-block; margin-left:4px; font-size:11px;
                background:#fff3cd; color:#856404; border:1px solid #ffc107;
                border-radius:3px; padding:1px 5px; vertical-align:middle;
            }
            .uwgs-alt-editor textarea { font-size:13px; font-family:inherit; }
            .uwgs-alt-feedback.success { color:#2e7d32; }
            .uwgs-alt-feedback.error   { color:#c62828; }
            /* Info bar (settings-driven instructions) */
            #uwgs-info-bar {
                padding:10px 14px;
                margin:8px 0;
                background:#f0f6fc;
                border:1px solid #72aee6;
                border-radius:3px;
                font-size:13px;
                color:#1d2327;
                line-height:1.6;
            }
            .uwgs-alt-wrap button:focus-visible,
            .uwgs-alt-wrap textarea:focus-visible { outline:2px solid #0073aa; outline-offset:2px; }
        ';

        wp_register_style( 'uwgs-alt-text-tool', false, array(), self::VERSION );
        wp_enqueue_style( 'uwgs-alt-text-tool' );
        wp_add_inline_style( 'uwgs-alt-text-tool', $css );

        // Build suggestion map for images needing attention on the current page.
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
                    $suggestions[ (string) $post_id ] = array( 'type' => 'caption', 'value' => sanitize_text_field( $caption ) );
                } else {
                    $suggestions[ (string) $post_id ] = array( 'type' => 'filename', 'value' => sanitize_text_field( $filename ) );
                }
            }
        }

        $instructions = get_option( self::OPTION_INSTRUCTIONS, '' );

        $data = array(
            'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
            'suggestions'  => $suggestions,
            'instructions' => wp_kses_post( $instructions ),
            'i18n'         => array(
                'emptyBlocked'     => __( 'Please enter a description before saving.', 'uwgs-alt-text-tool' ),
                'urlRejected'      => __( 'URLs cannot be used as alt text.', 'uwgs-alt-text-tool' ),
                'filenameRejected' => __( 'Filenames cannot be used as alt text.', 'uwgs-alt-text-tool' ),
                'saved'            => __( 'Saved.', 'uwgs-alt-text-tool' ),
                'saveFailed'       => __( 'Save failed. Please try again.', 'uwgs-alt-text-tool' ),
                'requestFailed'    => __( 'Request failed. Please try again.', 'uwgs-alt-text-tool' ),
                'blank'            => __( '(blank)', 'uwgs-alt-text-tool' ),
                'suggestionHint'   => __( 'Alt text suggestion from image metadata. Please review and save.', 'uwgs-alt-text-tool' ),
            ),
        );

        wp_add_inline_script( 'jquery', 'var uwgsAltData = '. wp_json_encode( $data ). ';' );

        $js = <<<'JS'
jQuery( function( $ ) {

    var data         = ( typeof uwgsAltData !== 'undefined' ) ? uwgsAltData : {};
    var ajaxUrl      = data.ajaxUrl     || '';
    var suggestions  = data.suggestions || {};
    var instructions = data.instructions || '';
    var i18n         = data.i18n        || {};

    var IMAGE_EXTENSIONS = /\.(jpg|jpeg|png|gif|webp|svg|bmp|tiff?|avif|heic|heif)$/i;

    // -------------------------------------------------------------------------
    // Suggestion classification
    // -------------------------------------------------------------------------

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

    function classifySuggestion( suggestion ) {
        if ( ! suggestion ) { return 'invalid'; }
        if ( suggestion.type === 'caption' ) { return 'good'; }
        var sanitized = sanitizeFilename( suggestion.value );
        var original  = suggestion.value;
        if ( /^https?:\/\//i.test( original.trim() ) || /^www\./i.test( original.trim() ) ) { return 'invalid'; }
        if ( IMAGE_EXTENSIONS.test( original.trim() ) || IMAGE_EXTENSIONS.test( sanitized ) ) { return 'invalid'; }
        if ( sanitized.length < 5 ) { return 'invalid'; }
        if ( /^(IMG|DSC|DSCN|MVI|MOV|P\d)[_\-\s]/i.test( original.trim() ) ) { return 'invalid'; }
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

    var classified = {};
    Object.keys( suggestions ).forEach( function( k ) {
        classified[ String( k ) ] = classifySuggestion( suggestions[ k ] );
    } );

    // -------------------------------------------------------------------------
    // Info bar — settings-driven instructions message
    // -------------------------------------------------------------------------

    if ( instructions ) {
        var $bar = $( '<div id="uwgs-info-bar">' ).html( instructions );
        var $tablenav = $( '.tablenav.top' );
        if ( $tablenav.length ) { $tablenav.before( $bar ); }
        else { $( '#wpbody-content .wrap' ).prepend( $bar ); }
    }

    // -------------------------------------------------------------------------
    // Input validation
    // -------------------------------------------------------------------------

    function validateAltText( value ) {
        var trimmed = value.trim();
        if ( trimmed === '' ) { return i18n.emptyBlocked || 'Please enter a description before saving.'; }
        if ( /^https?:\/\//i.test( trimmed ) || /^www\./i.test( trimmed ) ) { return i18n.urlRejected || 'URLs cannot be used as alt text.'; }
        if ( IMAGE_EXTENSIONS.test( trimmed ) ) { return i18n.filenameRejected || 'Filenames cannot be used as alt text.'; }
        return null;
    }

    // -------------------------------------------------------------------------
    // On page load: pre-populate textareas that have a "good" suggestion
    // -------------------------------------------------------------------------

    $( '.uwgs-alt-input' ).each( function() {
        var $ta     = $( this );
        var postId  = String( $ta.data( 'post-id' ) );
        var confidence = classified[ postId ] || null;
        var suggestion = suggestions[ postId ] || null;

        if ( ! suggestion || confidence !== 'good' ) { return; }

        var altText = suggestion.type === 'caption'
            ? suggestion.value
            : sanitizeFilename( suggestion.value );

        if ( ! altText || validateAltText( altText ) ) { return; }

        $ta.val( altText );

        // Show hint only for pre-populated rows
        var $hint = $ta.siblings( '.uwgs-alt-hint' );
        $hint.text( i18n.suggestionHint || 'Alt text suggestion from image metadata. Please review and save.' ).show();
        $ta.one( 'input', function() { $hint.hide(); } );
    } );

    // -------------------------------------------------------------------------
    // Keyboard navigation: Enter saves, Tab/Cmd+Enter saves and advances
    // -------------------------------------------------------------------------

    $( document ).on( 'keydown', '.uwgs-alt-input', function( e ) {
        var $wrap = $( this ).closest( '.uwgs-alt-wrap' );
        if ( e.which === 13 && ! e.shiftKey && ! e.metaKey && ! e.ctrlKey ) {
            e.preventDefault();
            $wrap.find( '.uwgs-alt-save-btn' ).trigger( 'click' );
            return;
        }
        if ( ( e.which === 13 && ( e.metaKey || e.ctrlKey ) ) ||
             ( e.which === 9  && ! e.shiftKey ) ) {
            e.preventDefault();
            saveAndAdvance( $wrap, 1 );
            return;
        }
        if ( e.which === 9 && e.shiftKey ) {
            e.preventDefault();
            saveAndAdvance( $wrap, -1 );
            return;
        }
    } );

    // Tab navigation across visible textareas
    function saveAndAdvance( $currentWrap, direction ) {
        var $allInputs = $( '.uwgs-alt-editor .uwgs-alt-input' );
        var $thisInput = $currentWrap.find( '.uwgs-alt-input' );
        var idx        = $allInputs.index( $thisInput );
        var $target    = $allInputs.eq( idx + direction );

        var nonce   = $currentWrap.find( '.uwgs-alt-save-btn' ).data( 'nonce' );
        var postId  = String( $currentWrap.data( 'post-id' ) );
        var altText = $thisInput.val().trim();
        var err     = validateAltText( altText );

        if ( err ) {
            $currentWrap.find( '.uwgs-alt-feedback' ).text( err ).removeClass( 'success' ).addClass( 'error' );
            return;
        }

        $.ajax( {
            url: ajaxUrl, type: 'POST',
            data: { action: 'uwgs_save_alt_text', post_id: parseInt( postId, 10 ), alt_text: altText, nonce: nonce },
            success: function( r ) {
                if ( r.success ) { applyColumnSave( $currentWrap, postId, r.data.alt_text || altText, r.data.needs_attention ); }
            },
            complete: function() {
                if ( $target.length ) { $target.trigger( 'focus' ); }
            }
        } );
    }

    // -------------------------------------------------------------------------
    // Per-row Save button
    // -------------------------------------------------------------------------

    $( document ).on( 'click', '.uwgs-alt-save-btn', function() {
        var $btn     = $( this );
        var $wrap    = $btn.closest( '.uwgs-alt-wrap' );
        var postId   = String( $wrap.data( 'post-id' ) );
        var nonce    = $btn.data( 'nonce' );
        var altText  = $wrap.find( '.uwgs-alt-input' ).val().trim();
        var $spinner = $wrap.find( '.uwgs-alt-spinner' );
        var $fb      = $wrap.find( '.uwgs-alt-feedback' );

        var err = validateAltText( altText );
        if ( err ) {
            $fb.text( err ).removeClass( 'success' ).addClass( 'error' );
            return;
        }

        $btn.prop( 'disabled', true );
        $spinner.addClass( 'is-active' ).attr( 'aria-hidden', 'true' );
        $fb.text( '' ).removeClass( 'success error' );

        $.ajax( {
            url: ajaxUrl, type: 'POST',
            data: { action: 'uwgs_save_alt_text', post_id: parseInt( postId, 10 ), alt_text: altText, nonce: nonce },
            success: function( r ) {
                if ( r.success ) {
                    applyColumnSave( $wrap, postId, r.data.alt_text || altText, r.data.needs_attention );
                } else {
                    $fb.text( r.data || i18n.saveFailed || 'Save failed.' ).addClass( 'error' );
                }
            },
            error: function() {
                $fb.text( i18n.requestFailed || 'Request failed.' ).addClass( 'error' );
            },
            complete: function() {
                $btn.prop( 'disabled', false );
                $spinner.removeClass( 'is-active' ).attr( 'aria-hidden', 'true' );
            }
        } );
    } );

    // -------------------------------------------------------------------------
    // After a successful save:
    //   - Good quality → replace entire cell with plain green text
    //   - Still low quality → update badge, keep editor open with error feedback
    // -------------------------------------------------------------------------

    function applyColumnSave( $wrap, postId, savedAlt, needsAttention ) {
        var key     = String( postId );
        var $editor = $wrap.find( '.uwgs-alt-editor' );
        var $badge  = $wrap.find( '.uwgs-alt-value' );
        var $fb     = $wrap.find( '.uwgs-alt-feedback' );
        var $hint   = $wrap.find( '.uwgs-alt-hint' );

        // Update data so future keyboard nav reads correctly
        $wrap.find( '.uwgs-alt-input' ).attr( 'data-saved-alt', savedAlt );
        delete classified[ key ];
        delete suggestions[ key ];

        if ( savedAlt && ! needsAttention ) {
            // ✓ Good — collapse editor, update badge to green text, stay in DOM
            $badge.text( savedAlt )
                  .removeClass( 'uwgs-alt-blank uwgs-low-quality' )
                  .addClass( 'uwgs-has-alt' )
                  .removeAttr( 'aria-label' );
            $wrap.find( '.uwgs-alt-new-flag' ).remove();
            $hint.hide();
            $editor.find( '.uwgs-alt-input' ).prop( 'disabled', true );
            $editor.find( '.uwgs-alt-save-btn' ).prop( 'disabled', true );
            $editor.hide();
        } else if ( savedAlt && needsAttention ) {
            // ⚠ Saved but still low quality — keep editor, update badge, note
            $badge.text( '⚠ ' + savedAlt )
                  .removeClass( 'uwgs-alt-blank uwgs-has-alt' )
                  .addClass( 'uwgs-low-quality' );
            $hint.hide();
            $fb.text( i18n.saved || 'Saved.' ).addClass( 'success' );
            setTimeout( function() { $fb.text( '' ).removeClass( 'success' ); }, 3000 );
        } else {
            // Empty saved (shouldn't happen due to validation)
            $badge.text( i18n.blank || '(blank)' )
                  .removeClass( 'uwgs-has-alt uwgs-low-quality' )
                  .addClass( 'uwgs-alt-blank' )
                  .attr( 'aria-label', 'Alt text is blank' );
        }
    }

} );
JS;

        wp_add_inline_script( 'jquery', $js );
    }

    // =========================================================================
    // UPLOAD PAGE ASSETS
    // =========================================================================

    private function enqueue_upload_page_assets() {
        $i18n = array( 'editPrompt' => __( 'Upload complete — click here to edit and add alt text', 'uwgs-alt-text-tool' ) );
        wp_add_inline_script( 'jquery', 'var uwgsUploadI18n = '. wp_json_encode( $i18n ). ';' );
        $js = <<<'JS'
( function() {
    'use strict';
    var i18n = ( typeof uwgsUploadI18n !== 'undefined' ) ? uwgsUploadI18n : {};
    var promptText = i18n.editPrompt || 'Upload complete — click here to edit and add alt text';
    function processRow( row ) {
        if ( ! row ) { return; }
        var editLink = row.querySelector( 'a[href*="action=edit"]' ) || row.querySelector( '.edit-attachment a' ) || row.querySelector( '.media-item-info a' );
        if ( ! editLink || editLink.getAttribute( 'data-uwgs-updated' ) ) { return; }
        if ( row.querySelector( '.upload-error' ) ) { return; }
        editLink.textContent = promptText; editLink.setAttribute( 'data-uwgs-updated', '1' );
        editLink.style.fontWeight = '600'; editLink.style.color = '#856404';
    }
    function observeUploadList() {
        var container = document.getElementById( 'media-items' );
        if ( ! container ) { return; }
        new MutationObserver( function( mutations ) {
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
        } ).observe( container, { childList: true, subtree: true } );
    }
    if ( document.readyState === 'loading' ) { document.addEventListener( 'DOMContentLoaded', observeUploadList ); }
    else { observeUploadList(); }
} )();
JS;
        wp_add_inline_script( 'jquery', $js, 'after' );
    }

    // =========================================================================
    // ATTACHMENT EDIT SCREEN ASSETS
    //
    // FIX v2.4.4 (Issue 3): Broadened alt field selector to catch all
    // possible field IDs on the attachment edit screen. Added inline
    // page notice when alt is blank on load. Save warning popup works
    // like the classic editor — warns on first click, allows on second.
    // =========================================================================

    private function enqueue_attachment_edit_assets( $post_id ) {

        $alt         = get_post_meta( $post_id, self::META_KEY, true );
        $caption     = get_post_field( 'post_excerpt', $post_id );
        $filename    = get_post_field( 'post_title', $post_id );
        $should_copy = ( empty( $alt ) && ! empty( $caption ) );

        $suggestion = null;
        if ( empty( $alt ) ) {
            if ( ! empty( trim( $caption ) ) ) {
                $suggestion = array( 'type' => 'caption', 'value' => sanitize_text_field( $caption ) );
            } elseif ( ! empty( $filename ) ) {
                $suggestion = array( 'type' => 'filename', 'value' => sanitize_text_field( $filename ) );
            }
        }

        $css = '.uwgs-attachment-alt-warning {
                display:none; margin-top:6px; padding:8px 10px;
                background:#fff3cd; border-left:4px solid #ffc107;
                color:#856404; font-size:13px; border-radius:0 3px 3px 0;
            }.uwgs-attachment-alt-warning.visible { display:block; }.uwgs-attachment-blank-notice {
                padding:8px 10px; margin-bottom:8px;
                background:#fff3cd; border-left:4px solid #ffc107;
                color:#856404; font-size:13px; border-radius:0 3px 3px 0;
            }.uwgs-alt-field-highlight { border-color:#c62828 !important; box-shadow:0 0 0 1px #c62828 !important; }.uwgs-caption-copy-notice { display:flex; align-items:flex-start; gap:8px; margin-top:6px; padding:7px 10px; background:#fff3cd; border-left:4px solid #ffc107; color:#856404; font-size:12px; line-height:1.5; border-radius:0 3px 3px 0; }.uwgs-caption-copy-notice button { background:none; border:none; padding:0; cursor:pointer; color:#856404; font-size:14px; line-height:1; flex-shrink:0; margin-left:auto; }.uwgs-caption-copy-notice button:hover { color:#5a4000; }.uwgs-attach-suggestion-hint { font-size:11px; color:#856404; font-style:italic; margin-top:4px; display:block; }
        ';

        wp_register_style( 'uwgs-attachment-edit', false, array(), self::VERSION );
        wp_enqueue_style( 'uwgs-attachment-edit' );
        wp_add_inline_style( 'uwgs-attachment-edit', $css );

        $i18n = array(
            'warningText'    => __( '⚠ This image has no alt text. Alt text is required for accessibility. Please add a description before saving, or confirm this image is decorative by clicking Update again.', 'uwgs-alt-text-tool' ),
            'blankNotice'    => __( '⚠ This image has no alt text. Please add a description below.', 'uwgs-alt-text-tool' ),
            'captionCopied'  => __( 'Alt text copied from caption — please review and edit if needed before saving.', 'uwgs-alt-text-tool' ),
            'dismissNotice'  => __( 'Dismiss', 'uwgs-alt-text-tool' ),
            'fromCaption'    => __( 'Suggested from caption — please review before saving', 'uwgs-alt-text-tool' ),
            'fromFilename'   => __( 'Suggested from filename — please review before saving', 'uwgs-alt-text-tool' ),
            'lowConfidence'  => __( 'This may be too brief — consider adding more detail', 'uwgs-alt-text-tool' ),
            'invalidSuggest' => __( 'This looks like a filename or URL — please write a meaningful description', 'uwgs-alt-text-tool' ),
        );

        $data = array(
            'i18n'         => $i18n,
            'shouldCopy'   => $should_copy,
            'captionValue' => $should_copy ? sanitize_text_field( $caption ) : '',
            'suggestion'   => $suggestion,
            'altIsBlank'   => empty( $alt ),
        );

        wp_add_inline_script( 'jquery', 'var uwgsAttachData = '. wp_json_encode( $data ). ';' );

        $js = <<<'JS'
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
JS;

        wp_add_inline_script( 'jquery', $js );
    }

    // =========================================================================
    // CLASSIC EDITOR + ALL NON-BLOCK-EDITOR POST TYPES: PRE-SAVE SCAN
    // =========================================================================

    private function enqueue_classic_presave_assets() {

        $css = '
            #uwgs-inline-notice {
                display:none; margin:8px 0 16px; padding:10px 14px;
                background:#fff3cd; border-left:4px solid #ffc107;
                color:#856404; font-size:13px; line-height:1.5; border-radius:0 3px 3px 0;
            }
            #uwgs-inline-notice.visible { display:flex; align-items:center; justify-content:space-between; gap:12px; }
            #uwgs-inline-notice.uwgs-notice-text    { flex:1; }
            #uwgs-inline-notice.uwgs-notice-dismiss {
                background:none; border:none; cursor:pointer;
                color:#856404; font-size:16px; padding:0; flex-shrink:0; line-height:1;
            }
            #uwgs-inline-notice.uwgs-notice-dismiss:hover { color:#5a4000; }
            #uwgs-presave-warning {
                display:none; position:fixed; top:32px; left:50%; transform:translateX(-50%);
                z-index:99999; min-width:320px; max-width:520px;
                padding:16px 20px; background:#fff3cd; border:2px solid #ffc107;
                border-radius:4px; color:#856404; font-size:13px; line-height:1.6;
                box-shadow:0 4px 12px rgba(0,0,0,0.15);
            }
            #uwgs-presave-warning.visible  { display:block; }
            #uwgs-presave-warning strong   { display:block; margin-bottom:8px; font-size:14px; }
            #uwgs-presave-warning p        { margin:0 0 12px; }
            #uwgs-presave-warning.uwgs-warning-actions { display:flex; gap:8px; align-items:center; }
            #uwgs-presave-warning button:focus-visible,
            #uwgs-inline-notice    button:focus-visible { outline:2px solid #0073aa; outline-offset:2px; }
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

    // =========================================================================
    // SAVE-FLOW ARCHITECTURE (Classic editor / non-block post types) — v2.5.2
    // =========================================================================
    // The classic editor used to bind click handlers to the specific button
    // IDs (#save, #save-post, #publish). That approach missed several real
    // save paths — keyboard Enter submits, programmatic submissions from
    // custom React/block save buttons, and any future button IDs introduced
    // by themes or plugins.
    //
    // The new flow uses a SINGLE delegated `submit` listener on form#post,
    // attached in capture phase so it runs before WordPress's own bubble-
    // phase submit handlers. Because we listen at the form level, we cover:
    //   * Mouse clicks on any submit button inside form#post
    //   * Keyboard Enter submits from any text field
    //   * Programmatic form.requestSubmit() from custom React/block code
    //   * Any future button additions — no per-button bindings to maintain
    //
    // When alt text is missing we preventDefault() the submit, run our async
    // scan, and either show the warning panel or programmatically resubmit
    // (so WordPress's own submit handlers still run for the actual save).
    //
    // A HARD BYPASS FLAG (`bypassValidation`) short-circuits the listener
    // after a "Save anyway" confirmation, preventing recursive validation
    // loops or double warnings on resubmission. There is no stored callback
    // to resume — "Save anyway" calls form.submit() directly with the
    // originating button's name=value preserved as a hidden input, so we
    // never depend on stale closure state from the original submit attempt.
    // =========================================================================

    var data = ( typeof uwgsPresaveData !== 'undefined' ) ? uwgsPresaveData : {};
    var ajaxUrl = data.ajaxUrl || ''; var nonce = data.altCheckNonce || ''; var i18n = data.i18n || {};
    var warningEl = null, noticeEl = null; var noticeDismissed = false; var preFocusEl = null;

    // Hard bypass flag — once set, our submit listener returns immediately.
    // Set by "Save anyway" and by the clean-scan resubmit path.
    var bypassValidation = false;

    // Re-entrancy guard for the async featured-image scan: prevents the user
    // from triggering parallel scans by mashing Enter while we await AJAX.
    var scanInFlight = false;

    function contentHasMissingAlt() {
        var allContent = [];
        if ( typeof window.tinyMCE !== 'undefined' && tinyMCE.editors && tinyMCE.editors.length ) {
            tinyMCE.editors.forEach( function( editor ) { if ( editor && editor.getContent ) { try { allContent.push( editor.getContent() ); } catch(e) {} } } );
        }
        var textarea = document.getElementById( 'content' );
        if ( textarea && textarea.value ) { allContent.push( textarea.value ); }
        if ( ! allContent.length ) { return false; }
        for ( var c = 0; c < allContent.length; c++ ) {
            if ( ! allContent[c] || allContent[c].indexOf( '<img' ) === -1 ) { continue; }
            var tmp = document.createElement( 'div' ); tmp.innerHTML = allContent[c];
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
        if ( el ) { var val = parseInt( el.value, 10 ); if ( val && val > 0 ) { return val; } }
        var candidates = document.querySelectorAll( 'input[type="hidden"][name*="thumbnail_id"],input[type="hidden"][id*="thumbnail_id"],input[type="hidden"][name*="featured_image"],input[type="hidden"][id*="featured_image"],input[type="hidden"][name*="featured_media"],input[type="hidden"][id*="featured_media"]' );
        for ( var i = 0; i < candidates.length; i++ ) { var id = parseInt( candidates[i].value, 10 ); if ( id && id > 0 ) { return id; } }
        return 0;
    }

    function featuredImageMissingAlt() {
        return new Promise( function( resolve ) {
            var thumbnailId = getFeaturedImageId();
            if ( ! thumbnailId ) { resolve( false ); return; }
            var formData = new FormData();
            formData.append( 'action', 'uwgs_get_attachment_alt' ); formData.append( 'nonce', nonce ); formData.append( 'attachment_id', thumbnailId );
            fetch( ajaxUrl, { method: 'POST', body: formData } ).then( function( r ) { return r.json(); } ).then( function( response ) { resolve( response.success && response.data.needs_attention ); } ).catch( function() { resolve( false ); } );
        } );
    }

    function buildNoticeBar() {
        noticeEl = document.createElement( 'div' ); noticeEl.id = 'uwgs-inline-notice';
        noticeEl.setAttribute( 'role', 'status' ); noticeEl.setAttribute( 'aria-live', 'polite' );
        var textSpan = document.createElement( 'span' ); textSpan.className = 'uwgs-notice-text'; noticeEl.appendChild( textSpan );
        var dismissBtn = document.createElement( 'button' ); dismissBtn.type = 'button'; dismissBtn.className = 'uwgs-notice-dismiss';
        dismissBtn.setAttribute( 'aria-label', i18n.dismiss || 'Dismiss' ); dismissBtn.textContent = '✕';
        dismissBtn.addEventListener( 'click', function() { noticeEl.classList.remove( 'visible' ); noticeDismissed = true; } );
        noticeEl.appendChild( dismissBtn );
        var anchor = document.getElementById( 'titlediv' ) || document.getElementById( 'post-body-content' );
        if ( anchor && anchor.parentNode ) { anchor.parentNode.insertBefore( noticeEl, anchor.nextSibling ); } else { document.body.appendChild( noticeEl ); }
    }

    function updateNoticeBar( hasContent, hasFeatured ) {
        if ( ! noticeEl ) { return; }
        if ( ! hasContent && ! hasFeatured ) { noticeEl.classList.remove( 'visible' ); noticeDismissed = false; return; }
        if ( noticeDismissed ) { return; }
        var textSpan = noticeEl.querySelector( '.uwgs-notice-text' ); if ( ! textSpan ) { return; }
        textSpan.textContent = hasContent && hasFeatured ? i18n.noticeBoth : hasFeatured ? i18n.noticeFeatured : i18n.noticeContent;
        noticeEl.classList.add( 'visible' );
    }

    function refreshNoticeBar( afterMediaInsert ) {
        if ( afterMediaInsert ) { noticeDismissed = false; }
        var hasContent = contentHasMissingAlt();
        featuredImageMissingAlt().then( function( hasFeatured ) { updateNoticeBar( hasContent, hasFeatured ); } );
    }

    function buildWarningPanel() {
        warningEl = document.createElement( 'div' ); warningEl.id = 'uwgs-presave-warning';
        warningEl.setAttribute( 'role', 'alertdialog' ); warningEl.setAttribute( 'aria-live', 'assertive' );
        warningEl.setAttribute( 'aria-modal', 'true' ); warningEl.setAttribute( 'tabindex', '-1' );
        warningEl.setAttribute( 'aria-labelledby', 'uwgs-presave-warning-title' );
        // Focus trap: cycle Tab within the dialog's focusable buttons.
        warningEl.addEventListener( 'keydown', function( e ) {
            if ( e.key !== 'Tab' ) { return; }
            var focusable = warningEl.querySelectorAll( 'button' );
            if ( ! focusable.length ) { return; }
            var first = focusable[0], last = focusable[ focusable.length - 1 ];
            if ( e.shiftKey ) {
                if ( document.activeElement === first ) { e.preventDefault(); last.focus(); }
            } else {
                if ( document.activeElement === last ) { e.preventDefault(); first.focus(); }
            }
        } );
        document.body.appendChild( warningEl );
    }

    // Programmatically resubmit form#post while preserving the originating
    // submitter (so WordPress sees Save Draft vs. Update vs. Publish via the
    // button's name=value pair). Prefers requestSubmit(submitter) so that
    // WordPress / ACF / any other submit handlers still run; falls back to
    // form.submit() with a hidden input on browsers without requestSubmit.
    function resubmitForm( form, submitter, useDirectSubmit ) {
        if ( ! form ) { return; }
        if ( ! useDirectSubmit && typeof form.requestSubmit === 'function' ) {
            try { form.requestSubmit( submitter || undefined ); return; } catch ( err ) { /* fall through */ }
        }
        // Direct native submission. Does not fire the submit event — neither
        // our listener nor any other submit handler runs again. Inject a
        // hidden input to preserve the originating button's name=value pair
        // (browsers normally include it only for user-initiated submits).
        if ( submitter && submitter.name ) {
            var hidden = document.createElement( 'input' );
            hidden.type = 'hidden';
            hidden.name = submitter.name;
            hidden.value = submitter.value || '';
            form.appendChild( hidden );
        }
        // Sync TinyMCE editors to their textareas before native submission,
        // because form.submit() does not fire the submit event that WordPress
        // normally uses to trigger tinyMCE.triggerSave().
        if ( typeof window.tinyMCE !== 'undefined' ) {
            try { tinyMCE.triggerSave(); } catch ( err ) {}
        }
        form.submit();
    }

    function showWarning( hasContent, hasFeatured, submitter ) {
        preFocusEl = document.activeElement;
        warningEl.innerHTML = '';
        var title = document.createElement( 'strong' ); title.id = 'uwgs-presave-warning-title'; title.textContent = i18n.warningTitle || '⚠ Accessibility: Images missing alt text';
        var body = document.createElement( 'p' ); body.textContent = hasContent && hasFeatured ? i18n.warningBodyBoth : hasFeatured ? i18n.warningBodyFeatured : i18n.warningBodyContent;
        var actions = document.createElement( 'div' ); actions.className = 'uwgs-warning-actions';
        var goBack = document.createElement( 'button' ); goBack.type = 'button'; goBack.className = 'button button-primary'; goBack.textContent = i18n.goBack || 'Go back and fix';
        goBack.addEventListener( 'click', function() { hideWarning(); if ( typeof window.tinyMCE !== 'undefined' && tinyMCE.activeEditor ) { tinyMCE.activeEditor.focus(); } } );
        var saveAnyway = document.createElement( 'button' ); saveAnyway.type = 'button'; saveAnyway.className = 'button'; saveAnyway.textContent = i18n.saveAnyway || 'Save anyway';
        saveAnyway.addEventListener( 'click', function() {
            hideWarning();
            // Hard bypass before the resubmit so even if requestSubmit fires
            // our listener again, it returns immediately. Then perform a
            // direct form submission — no callback to resume, no stale state.
            bypassValidation = true;
            resubmitForm( document.getElementById( 'post' ), submitter, /* useDirectSubmit */ true );
        } );
        actions.appendChild( goBack ); actions.appendChild( saveAnyway );
        warningEl.appendChild( title ); warningEl.appendChild( body ); warningEl.appendChild( actions );
        warningEl.classList.add( 'visible' ); goBack.focus();
    }

    function hideWarning() {
        warningEl.classList.remove( 'visible' );
        warningEl.innerHTML = '';
        if ( preFocusEl && typeof preFocusEl.focus === 'function' ) { try { preFocusEl.focus(); } catch(e) {} }
        preFocusEl = null;
    }

    // Single delegated submit interception on form#post. Capture phase so we
    // run before WordPress's own bubble-phase handlers and before jQuery
    // delegated listeners. This naturally covers click, keyboard, and any
    // programmatic form submission.
    function interceptFormSubmit() {
        var form = document.getElementById( 'post' );
        if ( ! form ) { return; } // not a post-edit screen with form#post

        form.addEventListener( 'submit', function( e ) {
            // Hard bypass after "Save anyway" or a clean-scan resubmit.
            if ( bypassValidation ) { return; }

            // Re-entrancy guard while an async scan is mid-flight.
            if ( scanInFlight ) { e.preventDefault(); e.stopImmediatePropagation(); return; }

            // Snapshot the originating button (event.submitter is supported in
            // all modern browsers; null means keyboard or programmatic submit).
            var submitter = e.submitter || null;

            var hasContent = contentHasMissingAlt();
            var thumbnailId = getFeaturedImageId();

            // Fast path: nothing to scan synchronously and no featured image
            // to fetch — let the submit continue uninterrupted.
            if ( ! hasContent && ! thumbnailId ) { return; }

            // Halt this submit while we run the async featured-image check.
            // We will either show the warning OR programmatically resubmit
            // if the scan comes back clean (so other submit handlers run).
            e.preventDefault();
            e.stopImmediatePropagation();

            scanInFlight = true;
            featuredImageMissingAlt().then( function( hasFeatured ) {
                scanInFlight = false;
                if ( hasContent || hasFeatured ) {
                    showWarning( hasContent, hasFeatured, submitter );
                } else {
                    // Clean scan — bypass our own check on resubmit and let
                    // the form go through naturally so other handlers run.
                    bypassValidation = true;
                    resubmitForm( form, submitter, /* useDirectSubmit */ false );
                }
            } );
        }, true );
    }

    function watchForModalClose() {
        new MutationObserver( function( mutations ) {
            mutations.forEach( function( mutation ) {
                mutation.removedNodes.forEach( function( node ) {
                    if ( node.nodeType === 1 && node.classList && node.classList.contains( 'media-modal' ) ) { setTimeout( function() { refreshNoticeBar( true ); }, 400 ); }
                } );
            } );
        } ).observe( document.body, { childList: true } );
    }

    function waitForTinyMCEThenScan() {
        var attempts = 0;
        ( function attempt() {
            attempts++;
            var ready = typeof window.tinyMCE !== 'undefined' && tinyMCE.editors && tinyMCE.editors.length > 0 && tinyMCE.editors[0].initialized;
            if ( ready ) { setTimeout( function() { refreshNoticeBar( false ); }, 200 ); return; }
            if ( attempts === 1 ) { refreshNoticeBar( false ); }
            if ( attempts < 100 ) { setTimeout( attempt, 100 ); }
        } )();
    }

    document.addEventListener( 'keydown', function( e ) { if ( e.key === 'Escape' && warningEl && warningEl.classList.contains( 'visible' ) ) { hideWarning(); } } );

    function bootstrap() {
        buildNoticeBar();
        buildWarningPanel();
        interceptFormSubmit();
        watchForModalClose();
        waitForTinyMCEThenScan();
    }

    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', bootstrap );
    } else { bootstrap(); }
} )();
JS;

        wp_add_inline_script( 'jquery', $js, 'after' );
    }

    // =========================================================================
    // ADD MEDIA MODAL: CAPTION-TO-ALT VIA MUTATIONOBSERVER
    // =========================================================================

    private function enqueue_media_modal_caption_assets() {
        $i18n = array( 'captionCopied' => __( 'Copied from caption — please review before inserting.', 'uwgs-alt-text-tool' ) );
        wp_add_inline_script( 'jquery', 'var uwgsModalCapI18n = '. wp_json_encode( $i18n ). ';' );
        $js = <<<'JS'
( function() {
    'use strict';
    var i18n = ( typeof uwgsModalCapI18n !== 'undefined' ) ? uwgsModalCapI18n : {};
    function applyCaptionToAlt( panel ) {
        if ( ! panel ) { return; }
        var altField = panel.querySelector( '#attachment-details-alt-text' ) || panel.querySelector( 'textarea[id*="alt-text"]' ) || panel.querySelector( '.setting.alt-text textarea' ) || panel.querySelector( '[data-setting="alt"] textarea' ) || panel.querySelector( '[data-setting="alt"] input' );
        var capField = panel.querySelector( '#attachment-details-caption' ) || panel.querySelector( 'textarea[id*="caption"]' ) || panel.querySelector( '[data-setting="caption"] textarea' ) || panel.querySelector( '[data-setting="caption"] input' );
        if ( ! altField || ! capField ) { return; }
        var altVal = altField.value.trim(), capVal = capField.value.trim();
        if ( altVal !== '' || capVal === '' ) { return; }
        if ( altField.getAttribute( 'data-uwgs-cap-applied' ) ) { return; }
        altField.setAttribute( 'data-uwgs-cap-applied', '1' ); altField.value = capVal;
        altField.dispatchEvent( new Event( 'change', { bubbles: true } ) ); altField.dispatchEvent( new Event( 'input', { bubbles: true } ) );
        var existing = panel.querySelector( '.uwgs-modal-cap-notice' ); if ( existing ) { existing.remove(); }
        var notice = document.createElement( 'div' ); notice.className = 'uwgs-modal-cap-notice'; notice.setAttribute( 'role', 'note' );
        notice.style.cssText = ['margin-top:4px','padding:5px 8px','background:#fff3cd','border-left:3px solid #ffc107','color:#856404','font-size:11px','line-height:1.4','border-radius:0 2px 2px 0','display:flex','align-items:center','justify-content:space-between','gap:6px'].join(';');
        var msg = document.createElement( 'span' ); msg.textContent = i18n.captionCopied || 'Copied from caption — please review before inserting.';
        var dismiss = document.createElement( 'button' ); dismiss.type = 'button'; dismiss.textContent = '✕'; dismiss.setAttribute( 'aria-label', 'Dismiss' );
        dismiss.style.cssText = 'background:none;border:none;cursor:pointer;color:#856404;font-size:12px;padding:0;flex-shrink:0;';
        dismiss.addEventListener( 'click', function() { notice.remove(); } );
        notice.appendChild( msg ); notice.appendChild( dismiss );
        var altSetting = altField.closest( '.setting' );
        if ( altSetting && altSetting.parentNode ) { altSetting.parentNode.insertBefore( notice, altSetting.nextSibling ); } else { altField.parentNode.insertBefore( notice, altField.nextSibling ); }
        altField.addEventListener( 'input', function() { notice.remove(); altField.removeAttribute( 'data-uwgs-cap-applied' ); }, { once: true } );
    }
    function processPanels( container ) {
        if ( ! container ) { return; }
        container.querySelectorAll( '.attachment-details.save-ready,.attachment-details' ).forEach( function( panel ) { setTimeout( function() { applyCaptionToAlt( panel ); }, 200 ); } );
    }
    function observeModalContent( modal ) {
        if ( ! modal || modal._uwgsObserved ) { return; }
        modal._uwgsObserved = true; processPanels( modal );
        new MutationObserver( function( mutations ) {
            mutations.forEach( function( mutation ) {
                mutation.addedNodes.forEach( function( node ) {
                    if ( node.nodeType !== 1 ) { return; }
                    if ( node.classList && node.classList.contains( 'attachment-details' ) ) { setTimeout( function() { applyCaptionToAlt( node ); }, 200 ); return; }
                    processPanels( node );
                } );
                if ( mutation.type === 'childList' && mutation.target ) {
                    var panel = mutation.target.closest ? mutation.target.closest( '.attachment-details' ) : null;
                    if ( panel ) { setTimeout( function() { applyCaptionToAlt( panel ); }, 200 ); }
                }
            } );
        } ).observe( modal, { childList: true, subtree: true } );
    }
    function observeForModal() {
        document.querySelectorAll( '.media-modal,.media-frame' ).forEach( observeModalContent );
        new MutationObserver( function( mutations ) {
            mutations.forEach( function( mutation ) {
                mutation.addedNodes.forEach( function( node ) {
                    if ( node.nodeType !== 1 ) { return; }
                    if ( node.classList && ( node.classList.contains( 'media-modal' ) || node.classList.contains( 'media-frame' ) ) ) { observeModalContent( node ); return; }
                    node.querySelectorAll && node.querySelectorAll( '.media-modal,.media-frame' ).forEach( observeModalContent );
                } );
            } );
        } ).observe( document.body, { childList: true, subtree: false } );
    }
    if ( document.readyState === 'loading' ) { document.addEventListener( 'DOMContentLoaded', observeForModal ); }
    else { observeForModal(); }
} )();
JS;
        wp_add_inline_script( 'jquery', $js, 'after' );
    }

    // =========================================================================
    // GUTENBERG: BLOCK CANVAS WARNING + PRE-PUBLISH PANEL + DRAFT SAVE WARNING
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
            'draftWarning'    => __( 'Some images are missing alt text. You can fix this before publishing.', 'uwgs-alt-text-tool' ),
        );
        wp_add_inline_script( 'wp-blocks', 'var uwgsGutenbergI18n = '. wp_json_encode( $i18n ). ';' );
        $js = <<<'JS'
( function( wp, i18n ) {
    'use strict';
    if ( typeof wp === 'undefined' ) { return; }
    var el = wp.element.createElement; var Fragment = wp.element.Fragment;
    var registerPlugin = wp.plugins ? wp.plugins.registerPlugin : null;
    var PluginPrePublishPanel = ( wp.editor && wp.editor.PluginPrePublishPanel ) ? wp.editor.PluginPrePublishPanel : ( wp.editPost ? wp.editPost.PluginPrePublishPanel : null );
    var useSelect = wp.data ? wp.data.useSelect : null; var subscribe = wp.data ? wp.data.subscribe : null;
    var addFilter = wp.hooks ? wp.hooks.addFilter : null; var createHOC = wp.compose ? wp.compose.createHigherOrderComponent : null;
    if ( addFilter && createHOC ) {
        var withAltWarning = createHOC( function( BlockEdit ) {
            return function( props ) {
                if ( props.name !== 'core/image' ) { return el( BlockEdit, props ); }
                var alt = ( props.attributes && props.attributes.alt ) || ''; var url = ( props.attributes && props.attributes.url ) || '';
                var bannerStyle = { display:'flex', alignItems:'center', gap:'8px', margin:'4px 0 0', padding:'8px 12px', background:'#fff3cd', borderLeft:'4px solid #ffc107', color:'#856404', fontSize:'13px', lineHeight:'1.5', borderRadius:'0 3px 3px 0' };
                return el( Fragment, null, el( BlockEdit, props ),
                    ( url !== '' && alt.trim() === '' ) ? el( 'div', { style: bannerStyle, role:'alert', 'aria-live':'polite', className:'uwgs-block-alt-warning' }, el( 'span', { 'aria-hidden':'true' }, '⚠' ), el( 'span', null, i18n.canvasBanner || 'Missing alt text — add it in the sidebar.' ) ) : null
                );
            };
        }, 'withAltWarning' );
        addFilter( 'editor.BlockEdit', 'uwgs-alt-text-tool/with-alt-warning', withAltWarning );
    }
    if ( ! registerPlugin || ! PluginPrePublishPanel || ! useSelect || ! subscribe ) { return; }

    // =========================================================================
    // SAVE-FLOW ARCHITECTURE (Gutenberg / block editor) — v2.5.2
    // =========================================================================
    // Gutenberg saves go through wp.data / REST, NOT a traditional form#post
    // submission, so the delegated `submit` listener used by the classic and
    // ACF flows does not apply here. Compatibility with the new architecture
    // is achieved by:
    //   * Showing a passive notice + pre-publish panel (no save blocking) so
    //     UI behavior is preserved exactly as before.
    //   * Using `isSavingPost` / `isAutosavingPost` transitions (below) as
    //     the async-aware analog of our bypass flag — `wasSaving` makes sure
    //     the warning fires once per save attempt, and `hasShownAltWarning`
    //     prevents the same notice from doubling up if the saving observer
    //     ticks more than once during a single save cycle.
    //   * No stored callback continuation — Gutenberg's redux store IS the
    //     state machine; we read from it rather than hold references to
    //     anything that could go stale between save attempts.
    // For uw_stories specifically, this Gutenberg path is bypassed because
    // uw_stories uses ACF + classic TinyMCE; the form#post intercept in
    // enqueue_uw_stories_assets handles those saves.
    // =========================================================================

    function hasImageBlocksMissingAlt( blocks ) {
        if ( ! blocks || ! blocks.length ) { return false; }
        for ( var i = 0; i < blocks.length; i++ ) {
            var block = blocks[i];
            if ( block.name === 'core/image' ) { var alt = ( block.attributes && block.attributes.alt ) ? block.attributes.alt.trim() : ''; if ( alt === '' ) { return true; } }
            if ( block.innerBlocks && block.innerBlocks.length ) { if ( hasImageBlocksMissingAlt( block.innerBlocks ) ) { return true; } }
        }
        return false;
    }
    function checkAltIssues() {
        var blockEditorStore = wp.data.select( 'core/block-editor' ); if ( ! blockEditorStore ) { return false; }
        if ( hasImageBlocksMissingAlt( blockEditorStore.getBlocks() ) ) { return true; }
        var featuredId = wp.data.select( 'core/editor' ).getEditedPostAttribute( 'featured_media' );
        if ( featuredId && featuredId > 0 ) { var media = wp.data.select( 'core' ).getMedia( featuredId, { context: 'edit' } ); if ( media && ( media.alt_text || '' ).trim() === '' ) { return true; } }
        return false;
    }
    var wasSaving = false, hasShownAltWarning = false;
    subscribe( function() {
        var editorSelect = wp.data.select( 'core/editor' ); if ( ! editorSelect ) { return; }
        var isSaving = editorSelect.isSavingPost(); var isAutosaving = editorSelect.isAutosavingPost();
        if ( isSaving && ! isAutosaving && ! wasSaving ) {
            wasSaving = true;
            if ( checkAltIssues() && ! hasShownAltWarning ) {
                hasShownAltWarning = true;
                var noticesDispatch = wp.data.dispatch( 'core/notices' );
                if ( noticesDispatch && noticesDispatch.createNotice ) { noticesDispatch.createNotice( 'warning', i18n.draftWarning || 'Some images are missing alt text. You can fix this before publishing.', { id: 'uwgs-alt-text-draft-warning', isDismissible: true } ); }
            }
        }
        if ( ! isSaving && wasSaving ) { wasSaving = false; hasShownAltWarning = false; }
    } );
    subscribe( function() {
        var editorStore = wp.data.select( 'core/edit-post' ) || wp.data.select( 'core/editor' ); if ( ! editorStore ) { return; }
        var sidebarOpen = editorStore.isPublishSidebarOpened ? editorStore.isPublishSidebarOpened() : false; if ( ! sidebarOpen ) { return; }
        if ( checkAltIssues() ) {
            var editPostDispatch = wp.data.dispatch( 'core/edit-post' );
            if ( editPostDispatch && editPostDispatch.toggleEditorPanelOpened ) {
                var panelId = 'uwgs-alt-text-panel/uwgs-alt-text-panel';
                var isOpen = editorStore.isEditorPanelOpened ? editorStore.isEditorPanelOpened( panelId ) : false;
                if ( ! isOpen ) { editPostDispatch.toggleEditorPanelOpened( panelId ); }
            }
        }
    } );
    function UWGSAltTextPanel() {
        var contentMissing = useSelect( function( select ) { return hasImageBlocksMissingAlt( select( 'core/block-editor' ).getBlocks() ); } );
        var featuredMissing = useSelect( function( select ) {
            var featuredId = select( 'core/editor' ).getEditedPostAttribute( 'featured_media' ); if ( ! featuredId || featuredId < 1 ) { return false; }
            var media = select( 'core' ).getMedia( featuredId, { context: 'edit' } ); if ( ! media ) { return false; }
            return ( media.alt_text || '' ).trim() === '';
        } );
        var hasIssues = contentMissing || featuredMissing;
        var message = contentMissing && featuredMissing ? i18n.warningBoth : featuredMissing ? i18n.warningFeatured : i18n.warningContent;
        return el( PluginPrePublishPanel, { name:'uwgs-alt-text-panel', title: i18n.panelTitle || 'Image Accessibility', initialOpen: hasIssues, className: hasIssues ? 'uwgs-prepublish-warning' : 'uwgs-prepublish-ok' },
            hasIssues ? el( Fragment, null, el( 'p', { style: { margin:'0 0 10px', color:'#856404', fontSize:'13px', lineHeight:'1.6' } }, message ), el( 'p', { style: { margin:'0', fontSize:'12px', color:'#555', fontStyle:'italic' } }, i18n.decorativeNote ) )
            : el( 'p', { style: { margin:'0', color:'#2e7d32', fontSize:'13px' } }, i18n.allGood )
        );
    }
    registerPlugin( 'uwgs-alt-text-tool', { render: UWGSAltTextPanel } );
} )( window.wp, ( typeof uwgsGutenbergI18n !== 'undefined' ? uwgsGutenbergI18n : {} ) );
JS;
        wp_add_inline_script( 'wp-edit-post', $js, 'after' );
    }

    // =========================================================================
    // SETTINGS PAGE
    // =========================================================================

    public function register_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        add_options_page(
            __( 'Alt Text Tool', 'uwgs-alt-text-tool' ),
            __( 'Alt Text Tool', 'uwgs-alt-text-tool' ),
            'manage_options',
            'uwgs-alt-text-tool',
            array( $this, 'render_settings_page' )
        );
    }

    public function register_settings() {
        register_setting(
            'uwgs_alt_text_settings',
            self::OPTION_INSTRUCTIONS,
            array(
                'type'              => 'string',
                'sanitize_callback' => 'wp_kses_post',
                'default'           => '',
            )
        );
        add_settings_section(
            'uwgs_main',
            __( 'Media Library Settings', 'uwgs-alt-text-tool' ),
            '__return_false',
            'uwgs-alt-text-tool'
        );
        add_settings_field(
            'uwgs_instructions',
            __( 'Instructions message', 'uwgs-alt-text-tool' ),
            array( $this, 'render_instructions_field' ),
            'uwgs-alt-text-tool',
            'uwgs_main'
        );
    }

    public function render_instructions_field() {
        $value = get_option( self::OPTION_INSTRUCTIONS, '' );
        echo '<textarea name="' . esc_attr( self::OPTION_INSTRUCTIONS ) . '" rows="4" cols="60" class="large-text">'
            . esc_textarea( $value ) . '</textarea>';
        echo '<p class="description">'
            . esc_html__( 'This message appears in the blue box above the media library list. HTML is allowed. Leave blank to hide the box.', 'uwgs-alt-text-tool' )
            . '</p>';
    }

    public function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Alt Text Tool Settings', 'uwgs-alt-text-tool' ); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields( 'uwgs_alt_text_settings' );
                do_settings_sections( 'uwgs-alt-text-tool' );
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    // =========================================================================
    // UW_STORIES: ACF + TINYMCE SAVE WARNING
    //
    // uw_stories uses ACF for all fields (including image fields) and TinyMCE
    // for rich text content. postType is undefined in the block-editor store,
    // so we cannot use wp.data subscribe. Instead we hook into ACF's JS action
    // system (acf.addAction 'submit') which fires before ACF serialises the
    // form, giving us a clean intercept point.
    //
    // We scan:
    //   - All TinyMCE instances for <img> tags without alt text
    //   - All ACF image fields for missing alt text (via AJAX to the existing
    //     uwgs_get_attachment_alt endpoint)
    // =========================================================================

    public function enqueue_uw_stories_assets( $hook ) {
        if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) { return; }
        global $post;
        if ( ! $post || get_post_type( $post->ID ) !== 'uw_stories' ) { return; }
        if ( ! current_user_can( 'edit_post', $post->ID ) ) { return; }

        $css = '
            #uwgs-stories-warning {
                display:none; position:fixed; top:32px; left:50%; transform:translateX(-50%);
                z-index:99999; min-width:320px; max-width:520px;
                padding:16px 20px; background:#fff3cd; border:2px solid #ffc107;
                border-radius:4px; color:#856404; font-size:13px; line-height:1.6;
                box-shadow:0 4px 12px rgba(0,0,0,0.15);
            }
            #uwgs-stories-warning.visible { display:block; }
            #uwgs-stories-warning strong  { display:block; margin-bottom:8px; font-size:14px; }
            #uwgs-stories-warning p       { margin:0 0 12px; }
            .uwgs-stories-warning-actions { display:flex; gap:8px; align-items:center; }
            #uwgs-stories-warning button:focus-visible { outline:2px solid #0073aa; outline-offset:2px; }
        ';

        wp_register_style( 'uwgs-stories-warning', false, array(), self::VERSION );
        wp_enqueue_style( 'uwgs-stories-warning' );
        wp_add_inline_style( 'uwgs-stories-warning', $css );

        $data = array(
            'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
            'altCheckNonce' => wp_create_nonce( self::NONCE_ALT_CHECK ),
            'i18n'          => array(
                'warningTitle'        => __( '⚠ Accessibility: Images missing alt text', 'uwgs-alt-text-tool' ),
                'warningBodyContent'  => __( 'One or more images in this post are missing alt text. Please go back and add descriptions, or click "Save anyway" if all images are decorative.', 'uwgs-alt-text-tool' ),
                'warningBodyFeatured' => __( 'One or more ACF image fields are missing alt text. Please edit those images in the Media Library and add descriptions, or click "Save anyway" if they are decorative.', 'uwgs-alt-text-tool' ),
                'warningBodyBoth'     => __( 'One or more images and ACF image fields are missing alt text. Please add descriptions before saving, or click "Save anyway" if all images are decorative.', 'uwgs-alt-text-tool' ),
                'saveAnyway'          => __( 'Save anyway', 'uwgs-alt-text-tool' ),
                'goBack'              => __( 'Go back and fix', 'uwgs-alt-text-tool' ),
            ),
        );

        wp_add_inline_script( 'jquery', 'var uwgsStoriesData = ' . wp_json_encode( $data ) . ';' );

        $js = <<<'JS'
( function( $ ) {
    'use strict';

    // =========================================================================
    // SAVE-FLOW ARCHITECTURE (uw_stories — ACF + TinyMCE) — v2.5.4
    // =========================================================================
    // ACF's validation fires on the form SUBMIT event (not the click):
    //   1. User clicks #publish
    //   2. ACF's onClickSubmit (jQuery, document bubble) just stores the event
    //   3. Browser default click action fires the form submit event
    //   4. ACF's onSubmit (jQuery, document bubble) runs async validation
    //      — it prevents the submit, validates, then on success calls
    //      jQuery $(form).submit() which fires a 2nd submit event
    //   5. On the 2nd submit ACF sees validation already passed → allows it
    //   6. Browser default of the 2nd submit → form POSTs to server
    //
    // Complications:
    //   a. WP publish/update buttons use form="post" attribute and may sit
    //      OUTSIDE the <form id="post"> element in the DOM, so a listener
    //      on the form never receives their clicks.
    //   b. ACF's onSubmit checks e.isDefaultPrevented(): if we prevented a
    //      submit event, ACF just calls allowSubmit() (no-op) and does nothing —
    //      the form never sends. So intercepting the submit event breaks saves.
    //
    // Fix: intercept CLICK events at the DOCUMENT level in capture phase —
    // document is always an ancestor of every button regardless of DOM position.
    //
    //   1. Document capture listener filters for submit-type buttons that target
    //      form#post (via btn.form or the form="post" attribute).
    //
    //   2. We stop the click, run our async alt-text scan, then:
    //      — Issues found → show warning panel.
    //      — Clean scan   → set a one-shot bypass, call btn.click().
    //        The bypass is consumed immediately on the re-click so the next
    //        user save attempt always re-scans.
    //
    //   3. "Save anyway" sets bypassOnce and re-clicks the original button so
    //      ACF's full save flow runs (nonces, TinyMCE sync, featured image).
    // =========================================================================

    var data    = ( typeof uwgsStoriesData !== 'undefined' ) ? uwgsStoriesData : {};
    var ajaxUrl = data.ajaxUrl        || '';
    var nonce   = data.altCheckNonce  || '';
    var i18n    = data.i18n           || {};

    // One-shot flag: consumed the moment the re-click is handled, so the very
    // next user-initiated click always re-runs the alt-text scan.
    var bypassOnce = false;

    // The button that triggered the current warning — used by "Save anyway".
    var capturedBtn = null;

    // Re-entrancy guard while async AJAX scan is in-flight.
    var scanInFlight = false;

    // -------------------------------------------------------------------------
    // Warning panel (built once, reused across saves)
    // -------------------------------------------------------------------------

    var $warning    = $( '<div id="uwgs-stories-warning" role="alertdialog" aria-live="assertive" aria-modal="true" aria-labelledby="uwgs-stories-warning-title" tabindex="-1">' );
    var preFocusEl  = null;
    $( function() {
        $( 'body' ).append( $warning );
        // Focus trap: cycle Tab within the dialog's focusable buttons.
        $warning[0].addEventListener( 'keydown', function( e ) {
            if ( e.key !== 'Tab' ) { return; }
            var focusable = $warning[0].querySelectorAll( 'button' );
            if ( ! focusable.length ) { return; }
            var first = focusable[0], last = focusable[ focusable.length - 1 ];
            if ( e.shiftKey ) {
                if ( document.activeElement === first ) { e.preventDefault(); last.focus(); }
            } else {
                if ( document.activeElement === last ) { e.preventDefault(); first.focus(); }
            }
        } );
    } );

    function showWarning( hasContent, hasFeatured ) {
        preFocusEl = document.activeElement;
        $warning.empty();
        var bodyText = ( hasContent && hasFeatured ) ? i18n.warningBodyBoth
                     : hasFeatured                  ? i18n.warningBodyFeatured
                                                    : i18n.warningBodyContent;
        var $title   = $( '<strong id="uwgs-stories-warning-title">' ).text( i18n.warningTitle || '⚠ Accessibility: Images missing alt text' );
        var $body    = $( '<p>' ).text( bodyText );
        var $actions = $( '<div class="uwgs-stories-warning-actions">' );

        var $goBack = $( '<button type="button" class="button button-primary">' )
            .text( i18n.goBack || 'Go back and fix' )
            .on( 'click', function() { hideWarning(); } );

        var $saveAnyway = $( '<button type="button" class="button">' )
            .text( i18n.saveAnyway || 'Save anyway' )
            .on( 'click', function() {
                hideWarning();
                // Use requestSubmit so ACF's full save flow runs normally from
                // this async context. Fall back to click() for old browsers.
                var form = document.getElementById( 'post' );
                if ( form && typeof form.requestSubmit === 'function' && capturedBtn ) {
                    try { form.requestSubmit( capturedBtn ); return; } catch ( err ) {}
                }
                bypassOnce = true;
                if ( capturedBtn ) { capturedBtn.click(); }
            } );

        $actions.append( $goBack ).append( $saveAnyway );
        $warning.append( $title ).append( $body ).append( $actions );
        $warning.addClass( 'visible' );
        $goBack[0].focus();
    }

    function hideWarning() {
        $warning.removeClass( 'visible' ).empty();
        if ( preFocusEl && typeof preFocusEl.focus === 'function' ) { try { preFocusEl.focus(); } catch(e) {} }
        preFocusEl = null;
    }

    $( document ).on( 'keydown', function( e ) {
        if ( e.key === 'Escape' && $warning.hasClass( 'visible' ) ) { hideWarning(); }
    } );

    // -------------------------------------------------------------------------
    // Alt-text scan helpers
    // -------------------------------------------------------------------------

    function tinyMCEHasMissingAlt() {
        if ( typeof window.tinymce === 'undefined' ) { return false; }
        var found = false;
        tinymce.editors.forEach( function( editor ) {
            if ( found || ! editor || ! editor.getContent ) { return; }
            try {
                var content = editor.getContent();
                if ( ! content || content.indexOf( '<img' ) === -1 ) { return; }
                var tmp = document.createElement( 'div' );
                tmp.innerHTML = content;
                tmp.querySelectorAll( 'img' ).forEach( function( img ) {
                    if ( img.getAttribute( 'data-mce-bogus' ) ) { return; }
                    if ( ( img.getAttribute( 'alt' ) || '' ).trim() === '' ) { found = true; }
                } );
            } catch ( e ) {}
        } );
        return found;
    }

    function getAcfImageIds() {
        var ids = [];
        $( '.acf-field[data-type="image"] input[type="hidden"].acf-image-value,' +
           '.acf-image-uploader input[type="hidden"]' ).each( function() {
            var val = parseInt( $( this ).val(), 10 );
            if ( val && val > 0 ) { ids.push( val ); }
        } );
        // Also include the WP native featured image.
        var thumb = document.getElementById( '_thumbnail_id' );
        if ( thumb ) {
            var thumbId = parseInt( thumb.value, 10 );
            if ( thumbId && thumbId > 0 ) { ids.push( thumbId ); }
        }
        return ids.filter( function( v, i, a ) { return a.indexOf( v ) === i; } );
    }

    function checkAcfImagesMissingAlt( ids, callback ) {
        if ( ! ids.length ) { callback( false ); return; }
        var missing = false;
        var remaining = ids.length;
        ids.forEach( function( id ) {
            var fd = new FormData();
            fd.append( 'action', 'uwgs_get_attachment_alt' );
            fd.append( 'nonce', nonce );
            fd.append( 'attachment_id', id );
            fetch( ajaxUrl, { method: 'POST', body: fd } )
                .then( function( r ) { return r.json(); } )
                .then( function( response ) {
                    if ( response.success && response.data.needs_attention ) { missing = true; }
                } )
                .catch( function() {} )
                .finally( function() {
                    remaining--;
                    if ( remaining === 0 ) { callback( missing ); }
                } );
        } );
    }

    // -------------------------------------------------------------------------
    // DOCUMENT-LEVEL CLICK INTERCEPT (capture phase — fires before everything).
    // We bind to document so buttons outside <form id="post"> are also caught.
    // -------------------------------------------------------------------------

    function isSubmitForPostForm( btn ) {
        // Must be a submit-type button or a known WP publish/save button.
        if ( btn.type !== 'submit' && btn.id !== 'publish' && btn.id !== 'save-post' && btn.id !== 'save' ) {
            return false;
        }
        // Resolve the associated form: native .form property or the form="…" attribute.
        var form = btn.form || ( btn.getAttribute( 'form' ) ? document.getElementById( btn.getAttribute( 'form' ) ) : null );
        if ( ! form && typeof btn.closest === 'function' ) { form = btn.closest( 'form' ); }
        return ( form && form.id === 'post' );
    }

    function interceptSaveClicks() {
        document.addEventListener( 'click', function( e ) {
            // Consume the one-shot bypass immediately so the next user click always re-scans.
            if ( bypassOnce ) { bypassOnce = false; return; }

            // Re-entrancy guard while async scan is in-flight.
            if ( scanInFlight ) { e.preventDefault(); e.stopImmediatePropagation(); return; }

            // Walk up from e.target in case the user clicked a child node (e.g. a
            // <span> inside the button), then verify this targets form#post.
            var btn = e.target;
            if ( btn.nodeType !== 1 ) { return; } // ignore text nodes
            if ( ! isSubmitForPostForm( btn ) ) {
                // Try the closest ancestor button/input in case of inner elements.
                if ( typeof btn.closest === 'function' ) {
                    btn = btn.closest( 'input[type="submit"], button[type="submit"]' );
                    if ( ! btn || ! isSubmitForPostForm( btn ) ) { return; }
                } else { return; }
            }

            var hasContent = tinyMCEHasMissingAlt();
            var acfIds     = getAcfImageIds();

            // Fast path: nothing to warn about — let the click through normally.
            if ( ! hasContent && acfIds.length === 0 ) { return; }

            // Halt this click (prevents form submit AND ACF's jQuery click handler).
            e.preventDefault();
            e.stopImmediatePropagation();

            capturedBtn = btn; // store at module scope for "Save anyway"
            scanInFlight = true;
            checkAcfImagesMissingAlt( acfIds, function( hasFeatured ) {
                scanInFlight = false;
                if ( hasContent || hasFeatured ) {
                    showWarning( hasContent, hasFeatured );
                } else {
                    // Clean scan: use requestSubmit so ACF's full save flow runs
                    // reliably from this async context. Fall back to click().
                    var form = document.getElementById( 'post' );
                    if ( form && typeof form.requestSubmit === 'function' && capturedBtn ) {
                        try { form.requestSubmit( capturedBtn ); return; } catch ( err ) {}
                    }
                    bypassOnce = true;
                    if ( capturedBtn ) { capturedBtn.click(); }
                }
            } );
        }, true ); // capture phase
    }

    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', interceptSaveClicks );
    } else {
        interceptSaveClicks();
    }

} )( jQuery );
JS;

        wp_add_inline_script( 'jquery', $js, 'after' );
    }
}

// Clear stats cache when alt text changes
add_action( 'wp_ajax_uwgs_save_alt_text',      array( 'UWGS_Alt_Text_Tool', 'clear_stats_cache' ), 1 );
add_action( 'wp_ajax_uwgs_bulk_save_alt_text', array( 'UWGS_Alt_Text_Tool', 'clear_stats_cache' ), 1 );
add_action( 'edit_attachment',                 array( 'UWGS_Alt_Text_Tool', 'clear_stats_cache' ) );
add_action( 'add_attachment',                  array( 'UWGS_Alt_Text_Tool', 'clear_stats_cache' ) );

add_action( 'plugins_loaded', array( 'UWGS_Alt_Text_Tool', 'init' ) );