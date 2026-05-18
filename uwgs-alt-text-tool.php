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
 * Version:           3.1.0
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

// =============================================================================
// ALT TEXT QUALITY SERVICE
//
// Single canonical source for all alt-text quality evaluation. PHP code inside
// UWGS_Alt_Text_Tool delegates here; the JavaScript mirror is UWGSAltUtils
// (js/uwgs-alt-utils.js). When a rule changes, update BOTH files and the
// get_rules_description() documentation returned to the settings page.
// =============================================================================

class UWGS_Alt_Quality {

    // Must stay in sync with UWGSAltUtils.IMAGE_EXTENSIONS in JS.
    const IMAGE_EXTENSIONS = array(
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg',
        'bmp', 'tif', 'tiff', 'avif', 'heic', 'heif',
    );

    // Must stay in sync with UWGSAltUtils.LOW_QUALITY_WORDS in JS.
    const LOW_QUALITY_WORDS = array(
        'image', 'photo', 'img', 'picture', 'screenshot',
        'graphic', 'thumbnail', 'banner', 'logo', 'icon',
    );

    /**
     * Returns true if the alt text requires editorial attention.
     *
     * A value needing attention is: empty, too short, a URL, an image filename,
     * all-numeric, a single low-quality word, or a camera-roll filename pattern.
     * Mirrors UWGSAltUtils.needsAttention() in JavaScript exactly.
     */
    public static function needs_attention( $alt ) {
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

    /**
     * Classifies alt text quality as 'good', 'weak', or 'invalid'.
     *
     * 'invalid' — triggers any needs_attention rule (plus single-word variants).
     * 'weak'    — single word that passes all invalid rules.
     * 'good'    — two or more words, all rules pass.
     *
     * Mirrors UWGSAltUtils.classify() in JavaScript exactly.
     */
    public static function classify( $alt ) {
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

    /**
     * Returns human-readable rule descriptions for the settings page.
     * Keep this list in sync with the rule code above and with UWGSAltUtils.
     */
    public static function get_rules_description() {
        return array(
            array(
                'label'       => __( 'Empty or missing', 'uwgs-alt-text-tool' ),
                'description' => __( 'Any image with no alt text is flagged as needing attention.', 'uwgs-alt-text-tool' ),
                'outcome'     => 'needs_attention',
            ),
            array(
                'label'       => __( 'Under 3 characters', 'uwgs-alt-text-tool' ),
                'description' => __( 'Alt text must be at least 3 characters long.', 'uwgs-alt-text-tool' ),
                'outcome'     => 'needs_attention',
            ),
            array(
                'label'       => __( 'URL or web address', 'uwgs-alt-text-tool' ),
                'description' => __( 'Text beginning with http://, https://, or www. is rejected — these are not meaningful descriptions.', 'uwgs-alt-text-tool' ),
                'outcome'     => 'needs_attention',
            ),
            array(
                'label'       => __( 'Image filename', 'uwgs-alt-text-tool' ),
                'description' => sprintf(
                    /* translators: %s: comma-separated list of rejected extensions */
                    __( 'Text ending in a recognized image extension (%s) is rejected. Write a description, not a filename.', 'uwgs-alt-text-tool' ),
                    '.' . implode( ', .', self::IMAGE_EXTENSIONS )
                ),
                'outcome'     => 'needs_attention',
            ),
            array(
                'label'       => __( 'All-numeric', 'uwgs-alt-text-tool' ),
                'description' => __( 'Text consisting entirely of digits is flagged as uninformative.', 'uwgs-alt-text-tool' ),
                'outcome'     => 'needs_attention',
            ),
            array(
                'label'       => __( 'Generic single word', 'uwgs-alt-text-tool' ),
                'description' => sprintf(
                    /* translators: %s: comma-separated list of rejected words */
                    __( 'The following words used alone are rejected as too generic: %s.', 'uwgs-alt-text-tool' ),
                    implode( ', ', self::LOW_QUALITY_WORDS )
                ),
                'outcome'     => 'needs_attention',
            ),
            array(
                'label'       => __( 'Camera roll filename pattern', 'uwgs-alt-text-tool' ),
                'description' => __( 'Patterns like IMG_1234, DSC0001, or P5_20 are recognized as camera filenames and flagged as not descriptive.', 'uwgs-alt-text-tool' ),
                'outcome'     => 'needs_attention',
            ),
            array(
                'label'       => __( 'Single meaningful word', 'uwgs-alt-text-tool' ),
                'description' => __( 'A single word that passes all other checks saves successfully but is marked "weak quality" — a badge indicates it could be improved with more detail.', 'uwgs-alt-text-tool' ),
                'outcome'     => 'weak',
            ),
            array(
                'label'       => __( 'Two or more meaningful words', 'uwgs-alt-text-tool' ),
                'description' => __( 'Alt text with at least two words that pass all checks is classified as good quality and shown in green.', 'uwgs-alt-text-tool' ),
                'outcome'     => 'good',
            ),
        );
    }
}

class UWGS_Alt_Text_Tool {

    const NONCE_ACTION           = 'uwgs_alt_text_inline_save';
    const NONCE_ALT_CHECK        = 'uwgs_get_attachment_alt';
    const NONCE_BULK_SAVE        = 'uwgs_bulk_save_alt_text';
    const NONCE_UNUSED_SCAN      = 'uwgs_unused_scan';
    const NONCE_UNUSED_ACTION    = 'uwgs_unused_action';
    const META_KEY               = '_wp_attachment_image_alt';
    const NEEDS_ALT_KEY          = '_uwgs_needs_alt';
    const META_UNUSED_STATUS     = '_uwgs_unused_status';
    const META_UNUSED_FILE_SIZE  = '_uwgs_unused_file_size';
    const META_GA_PAGEVIEWS      = '_uwgs_ga_pageviews';
    const META_GA_SYNCED_AT      = '_uwgs_ga_synced_at';
    const VERSION                = '3.1.0';
    const BULK_CONFIRM_THRESHOLD = 20;
    const OPTION_INSTRUCTIONS    = 'uwgs_alt_text_instructions';
    const OPTION_UNUSED_SCOPE    = 'uwgs_unused_scope';
    const OPTION_UNUSED_LAST_SCAN = 'uwgs_unused_last_scan';

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
        add_action( 'wp_ajax_uwgs_get_attachment_alt',           array( $this, 'ajax_get_attachment_alt' ) );
        add_action( 'wp_ajax_uwgs_check_attachments_alt_batch',   array( $this, 'ajax_check_attachments_alt_batch' ) );
        add_action( 'add_attachment',                  array( $this, 'flag_new_upload' ) );
        add_action( 'edit_attachment',                 array( $this, 'server_copy_caption_to_alt' ) );
        add_action( 'wp_dashboard_setup',              array( $this, 'register_dashboard_widget' ) );
        add_action( 'enqueue_block_editor_assets',     array( $this, 'enqueue_block_editor_assets' ) );
        // Settings page
        add_action( 'admin_menu',                      array( $this, 'register_settings_page' ) );
        add_action( 'admin_init',                      array( $this, 'register_settings' ) );
        // uw_stories ACF-based save warning (hooked via admin_enqueue_scripts)
        add_action( 'admin_enqueue_scripts',           array( $this, 'enqueue_uw_stories_assets' ) );
        add_action( 'rest_api_init',                   array( $this, 'register_rest_routes' ) );
        add_action( 'load-upload.php',                 array( $this, 'redirect_alt_sort_to_images' ) );
        add_filter( 'wp_prepare_attachment_for_js',    array( $this, 'add_alt_status_to_attachment_js' ), 10, 2 );
        // Unused media page + scanner
        add_action( 'admin_menu',                      array( $this, 'register_unused_media_page' ) );
        add_action( 'wp_ajax_uwgs_scan_batch',         array( $this, 'ajax_scan_batch' ) );
        add_action( 'wp_ajax_uwgs_exclude_attachment', array( $this, 'ajax_exclude_attachment' ) );
        add_action( 'wp_ajax_uwgs_bulk_trash_unused',  array( $this, 'ajax_bulk_trash_unused' ) );
        add_action( 'wp_ajax_uwgs_export_csv',         array( $this, 'ajax_export_csv' ) );
        add_action( 'wp_ajax_uwgs_dismiss_scan_notice',array( $this, 'ajax_dismiss_scan_notice' ) );
        add_action( 'admin_notices',                   array( $this, 'render_unused_scan_notice' ) );
        add_filter( 'set_screen_option_uwgs_unused_per_page', function( $status, $option, $value ) {
            return max( 1, (int) $value );
        }, 10, 3 );
    }

    // =========================================================================
    // ALT TEXT QUALITY HELPERS
    // =========================================================================

    private function uwgs_alt_needs_attention( $alt ) {
        return UWGS_Alt_Quality::needs_attention( $alt );
    }

    private function uwgs_classify_alt( $alt ) {
        return UWGS_Alt_Quality::classify( $alt );
    }

    // Returns the standard alt-status array for one attachment ID.
    // Shared by all four read handlers (AJAX get, AJAX batch, REST get, REST batch).
    private function get_attachment_alt_data( $id ) {
        $alt = get_post_meta( $id, self::META_KEY, true );
        return array(
            'alt'             => $alt,
            'has_alt'         => ! empty( $alt ),
            'needs_attention' => $this->uwgs_alt_needs_attention( $alt ),
            'classification'  => $this->uwgs_classify_alt( $alt ),
        );
    }

    // Validates a candidate alt text string.
    // Returns a translated error string on failure, null on success.
    private function validate_alt_text_input( $alt_text ) {
        if ( preg_match( '/^https?:\/\//i', $alt_text ) || preg_match( '/^www\./i', $alt_text ) ) {
            return __( 'URLs cannot be used as alt text.', 'uwgs-alt-text-tool' );
        }
        $ext_pattern = '/\.('. implode( '|', UWGS_Alt_Quality::IMAGE_EXTENSIONS ). ')$/i';
        if ( preg_match( $ext_pattern, $alt_text ) ) {
            return __( 'Filenames cannot be used as alt text.', 'uwgs-alt-text-tool' );
        }
        return null;
    }

    // Persists alt text for one image attachment and returns the response data array.
    // Handles meta update, NEEDS_ALT_KEY flag, and cache invalidation.
    private function perform_save_alt( $id, $alt_text ) {
        update_post_meta( $id, self::META_KEY, $alt_text );
        if ( ! $this->uwgs_alt_needs_attention( $alt_text ) ) {
            delete_post_meta( $id, self::NEEDS_ALT_KEY );
        } else {
            update_post_meta( $id, self::NEEDS_ALT_KEY, '1' );
        }
        self::clear_stats_cache();
        return array(
            'alt_text'        => $alt_text,
            'needs_attention' => $this->uwgs_alt_needs_attention( $alt_text ),
            'classification'  => $this->uwgs_classify_alt( $alt_text ),
            'message'         => __( 'Alt text saved.', 'uwgs-alt-text-tool' ),
        );
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
        $mime = get_post_mime_type( $post_id );
        if ( strpos( $mime, 'image/' ) !== 0 ) {
            wp_send_json_error( __( 'Attachment is not an image.', 'uwgs-alt-text-tool' ) );
        }
        $alt_text = isset( $_POST['alt_text'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['alt_text'] ) ) ) : '';
        $err = $this->validate_alt_text_input( $alt_text );
        if ( $err ) { wp_send_json_error( $err ); }
        wp_send_json_success( $this->perform_save_alt( $post_id, $alt_text ) );
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
        $data = $this->get_attachment_alt_data( $attachment_id );
        $data['attachment_id'] = $attachment_id;
        wp_send_json_success( $data );
    }

    // =========================================================================
    // AJAX: CHECK MULTIPLE ATTACHMENTS ALT TEXT (BATCH)
    // =========================================================================

    public function ajax_check_attachments_alt_batch() {
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], self::NONCE_ALT_CHECK ) ) {
            wp_send_json_error( 'Security check failed.' );
        }
        if ( ! current_user_can( 'upload_files' ) ) { wp_send_json_error( 'Insufficient permissions.' ); }
        $raw_ids = isset( $_POST['ids'] ) ? (array) $_POST['ids'] : array();
        $results = array();
        foreach ( $raw_ids as $raw_id ) {
            $id = absint( $raw_id );
            if ( ! $id ) { continue; }
            if ( 'attachment' !== get_post_type( $id ) ) { continue; }
            $results[ (string) $id ] = $this->get_attachment_alt_data( $id );
        }
        wp_send_json_success( $results );
    }

    // =========================================================================
    // REST API: ROUTES + HANDLERS
    //
    // Namespace: uwgs-alt-text/v1
    // All routes require upload_files capability, verified via permission_callback.
    // Nonce is the standard WP REST nonce (wp_rest), supplied to JS via
    // wp_create_nonce('wp_rest') in the enqueue functions.
    // =========================================================================

    public function register_rest_routes() {
        $namespace = 'uwgs-alt-text/v1';

        // GET /uwgs-alt-text/v1/attachments/{id} — single attachment alt status.
        register_rest_route( $namespace, '/attachments/(?P<id>\d+)', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'rest_get_attachment_alt' ),
            'permission_callback' => function() { return current_user_can( 'upload_files' ); },
            'args'                => array(
                'id' => array( 'validate_callback' => function( $v ) { return is_numeric( $v ) && (int) $v > 0; } ),
            ),
        ) );

        // POST /uwgs-alt-text/v1/attachments/check — batch alt-status check.
        // Body: { ids: [1, 2, 3] }
        // Response: { "1": { alt, has_alt, needs_attention, classification }, … }
        register_rest_route( $namespace, '/attachments/check', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_check_attachments_batch' ),
            'permission_callback' => function() { return current_user_can( 'upload_files' ); },
        ) );

        // POST /uwgs-alt-text/v1/attachments/{id} — save alt text for one image.
        // Body: { alt_text: "…" } — auth via X-WP-Nonce header (no per-ID nonce needed).
        register_rest_route( $namespace, '/attachments/(?P<id>\d+)', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_save_attachment_alt' ),
            'permission_callback' => function() { return current_user_can( 'upload_files' ); },
            'args'                => array(
                'id' => array( 'validate_callback' => function( $v ) { return is_numeric( $v ) && (int) $v > 0; } ),
            ),
        ) );

        // POST /uwgs-alt-text/v1/attachments/bulk — bulk save alt text.
        // Body: { updates: [ { id: 1, alt_text: "…" }, … ] }
        // Response: { updated, skipped, failed, saved_values, counts }
        register_rest_route( $namespace, '/attachments/bulk', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_bulk_save_attachments' ),
            'permission_callback' => function() { return current_user_can( 'upload_files' ); },
        ) );
    }

    public function rest_get_attachment_alt( WP_REST_Request $request ) {
        $id = absint( $request['id'] );
        if ( ! $id || 'attachment' !== get_post_type( $id ) ) {
            return new WP_Error( 'invalid_id', __( 'Invalid attachment ID.', 'uwgs-alt-text-tool' ), array( 'status' => 404 ) );
        }
        if ( ! current_user_can( 'edit_post', $id ) ) {
            return new WP_Error( 'forbidden', __( 'You do not have permission to view this attachment.', 'uwgs-alt-text-tool' ), array( 'status' => 403 ) );
        }
        $data = $this->get_attachment_alt_data( $id );
        $data['attachment_id'] = $id;
        return rest_ensure_response( $data );
    }

    public function rest_check_attachments_batch( WP_REST_Request $request ) {
        $raw_ids = $request->get_param( 'ids' );
        if ( ! is_array( $raw_ids ) ) { $raw_ids = array(); }
        $results = array();
        foreach ( $raw_ids as $raw_id ) {
            $id = absint( $raw_id );
            if ( ! $id || 'attachment' !== get_post_type( $id ) ) { continue; }
            $results[ (string) $id ] = $this->get_attachment_alt_data( $id );
        }
        return rest_ensure_response( $results );
    }

    public function rest_save_attachment_alt( WP_REST_Request $request ) {
        $id = absint( $request['id'] );
        if ( ! $id || 'attachment' !== get_post_type( $id ) ) {
            return new WP_Error( 'invalid_id', __( 'Invalid attachment ID.', 'uwgs-alt-text-tool' ), array( 'status' => 404 ) );
        }
        if ( ! current_user_can( 'edit_post', $id ) ) {
            return new WP_Error( 'forbidden', __( 'You do not have permission to edit this attachment.', 'uwgs-alt-text-tool' ), array( 'status' => 403 ) );
        }
        $mime = get_post_mime_type( $id );
        if ( strpos( $mime, 'image/' ) !== 0 ) {
            return new WP_Error( 'not_image', __( 'Attachment is not an image.', 'uwgs-alt-text-tool' ), array( 'status' => 400 ) );
        }
        $alt_text = trim( sanitize_text_field( wp_unslash( (string) $request->get_param( 'alt_text' ) ) ) );
        $err = $this->validate_alt_text_input( $alt_text );
        if ( $err ) {
            return new WP_Error( 'invalid_alt', $err, array( 'status' => 400 ) );
        }
        return rest_ensure_response( $this->perform_save_alt( $id, $alt_text ) );
    }

    public function rest_bulk_save_attachments( WP_REST_Request $request ) {
        $raw_updates = $request->get_param( 'updates' );
        if ( ! is_array( $raw_updates ) || empty( $raw_updates ) ) {
            return new WP_Error( 'no_updates', __( 'No updates provided.', 'uwgs-alt-text-tool' ), array( 'status' => 400 ) );
        }
        $updated = array(); $failed = array(); $skipped = array(); $saved_values = array();
        foreach ( $raw_updates as $item ) {
            $id       = isset( $item['id'] )       ? absint( $item['id'] )                                          : 0;
            $alt_text = isset( $item['alt_text'] ) ? trim( sanitize_text_field( wp_unslash( $item['alt_text'] ) ) ) : '';
            if ( ! $id || empty( $alt_text ) )                                              { $failed[]  = $id; continue; }
            if ( ! current_user_can( 'edit_post', $id ) )                                   { $failed[]  = $id; continue; }
            if ( 'attachment' !== get_post_type( $id ) )                                    { $failed[]  = $id; continue; }
            if ( strpos( get_post_mime_type( $id ), 'image/' ) !== 0 )                      { $failed[]  = $id; continue; }
            if ( $this->validate_alt_text_input( $alt_text ) )                              { $skipped[] = $id; continue; }
            if ( $this->uwgs_classify_alt( $alt_text ) !== 'good' )                         { $skipped[] = $id; continue; }
            $current = get_post_meta( $id, self::META_KEY, true );
            if ( ! empty( $current ) && $this->uwgs_classify_alt( $current ) === 'good' )   { $skipped[] = $id; continue; }
            update_post_meta( $id, self::META_KEY, $alt_text );
            delete_post_meta( $id, self::NEEDS_ALT_KEY );
            $updated[] = $id;
            $saved_values[ (string) $id ] = $alt_text;
        }
        self::clear_stats_cache();
        return rest_ensure_response( array(
            'updated'      => $updated,
            'failed'       => $failed,
            'skipped'      => $skipped,
            'saved_values' => $saved_values,
            'counts'       => array( 'updated' => count( $updated ), 'failed' => count( $failed ), 'skipped' => count( $skipped ) ),
        ) );
    }

    // =========================================================================
    // MEDIA LIBRARY: SORT REDIRECT + GRID OVERLAY HELPERS
    // =========================================================================

    // When the user sorts by the Alt Text column, redirect to include
    // post_mime_type=image so the "Images" tab highlights and non-image media
    // is absent. Fires on load-upload.php before the query runs.
    public function redirect_alt_sort_to_images() {
        if ( ! isset( $_GET['orderby'] ) || 'uwgs_alt_text' !== sanitize_key( $_GET['orderby'] ) ) { return; }
        // Filter form submitted (user clearing or changing filter) — redirect to clean upload.php.
        if ( isset( $_GET['filter_action'] ) ) {
            wp_safe_redirect( admin_url( 'upload.php' ) );
            exit;
        }
        if ( isset( $_GET['post_mime_type'] ) && 'image' === sanitize_key( $_GET['post_mime_type'] ) ) { return; }
        wp_safe_redirect( add_query_arg( 'post_mime_type', 'image' ) );
        exit;
    }

    // Attach alt quality data to the attachment JSON used by the media grid.
    // Only adds fields for image attachments; extra fields are ignored in other
    // contexts (the CSS overlay is only injected on upload.php).
    public function add_alt_status_to_attachment_js( $response, $attachment ) {
        if ( ( $response['type'] ?? '' ) !== 'image' ) { return $response; }
        $alt = get_post_meta( $attachment->ID, self::META_KEY, true );
        $response['uwgsNeedsAttention'] = $this->uwgs_alt_needs_attention( $alt );
        $response['uwgsClassification'] = $this->uwgs_classify_alt( $alt );
        return $response;
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

            <?php
            // Unused media summary
            $last_scan   = get_option( self::OPTION_UNUSED_LAST_SCAN, 0 );
            $unused_url  = admin_url( 'upload.php?page=uwgs-unused-media' );
            ?>
            <div style="margin-top:14px;padding-top:10px;border-top:1px solid #e0e0e0;">
                <div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:4px;">
                    <span style="font-weight:600;font-size:13px;color:#1d2327;"><?php esc_html_e( 'Unused Media', 'uwgs-alt-text-tool' ); ?></span>
                    <a href="<?php echo esc_url( $unused_url ); ?>" style="font-size:12px;"><?php esc_html_e( 'View all →', 'uwgs-alt-text-tool' ); ?></a>
                </div>
                <?php if ( ! $last_scan ) : ?>
                    <p style="margin:2px 0 0;font-size:12px;color:#555;"><?php esc_html_e( 'Not yet scanned.', 'uwgs-alt-text-tool' ); ?> <a href="<?php echo esc_url( $unused_url ); ?>"><?php esc_html_e( 'Run first scan →', 'uwgs-alt-text-tool' ); ?></a></p>
                <?php else :
                    $unused_stats = $this->get_unused_stats();
                    $total_unused = $unused_stats['unused'] + $unused_stats['uncertain'];
                    ?>
                    <p style="margin:2px 0 0;font-size:13px;">
                        <?php if ( $total_unused > 0 ) : ?>
                            <span style="color:#c62828;font-weight:600;"><?php echo esc_html( number_format_i18n( $total_unused ) ); ?></span>
                            <span style="color:#555;"> <?php esc_html_e( 'items flagged as potentially unused', 'uwgs-alt-text-tool' ); ?></span>
                        <?php else : ?>
                            <span style="color:#2e7d32;font-weight:600;">&#10003; <?php esc_html_e( 'No unused media detected', 'uwgs-alt-text-tool' ); ?></span>
                        <?php endif; ?>
                    </p>
                    <p style="margin:3px 0 0;font-size:11px;color:#999;">
                        <?php printf( esc_html__( 'Last scanned: %s', 'uwgs-alt-text-tool' ), esc_html( wp_date( get_option( 'date_format' ), $last_scan ) ) ); ?>
                    </p>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    private function get_unused_stats() {
        $cached = get_transient( 'uwgs_unused_stats' );
        if ( false !== $cached ) { return $cached; }
        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT pm.meta_value AS status, COUNT(*) AS cnt
                 FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = %s
                 WHERE p.post_type = 'attachment' AND p.post_status = 'inherit'
                   AND pm.meta_value IN ('unused','uncertain')
                 GROUP BY pm.meta_value",
                self::META_UNUSED_STATUS
            )
        );
        $stats = array( 'unused' => 0, 'uncertain' => 0 );
        foreach ( $rows as $row ) {
            $stats[ $row->status ] = (int) $row->cnt;
        }
        set_transient( 'uwgs_unused_stats', $stats, 6 * HOUR_IN_SECONDS );
        return $stats;
    }

    public static function clear_unused_stats_cache() {
        delete_transient( 'uwgs_unused_stats' );
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
        // Shared quality utilities module — registered once, enqueued on demand per screen.
        wp_register_script( 'uwgs-alt-utils', plugins_url( 'js/uwgs-alt-utils.js', __FILE__ ), array(), self::VERSION, true );

        if ( 'upload.php' === $hook ) {
            $this->enqueue_list_view_assets();
            $this->enqueue_media_grid_assets();
            $this->enqueue_attachment_details_assets();
        }
        if ( 'media_page_uwgs-unused-media' === $hook ) { $this->enqueue_unused_assets(); }
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
            $this->enqueue_attachment_details_assets();
        }
    }

    // =========================================================================
    // MEDIA GRID ASSETS (upload.php grid mode — overlay on missing/weak alt)
    // =========================================================================

    private function enqueue_media_grid_assets() {
        $data = array(
            'i18n' => array(
                'missingAlt' => __( 'Please provide alt text',   'uwgs-alt-text-tool' ),
                'weakAlt'    => __( 'Alt text needs refinement', 'uwgs-alt-text-tool' ),
            ),
        );

        wp_register_script( 'uwgs-media-grid', plugins_url( 'js/uwgs-media-grid.js', __FILE__ ), array( 'media-views' ), self::VERSION, true );
        wp_enqueue_script( 'uwgs-media-grid' );
        wp_add_inline_script( 'uwgs-media-grid', 'var uwgsMediaGridData = ' . wp_json_encode( $data ) . ';', 'before' );

        // Overlay badge along the bottom of grid tiles — mirrors the WP PDF filename label style.
        // Targets .attachment-preview (position:relative, overflow:visible) rather than
        // .thumbnail (position:absolute, overflow:hidden) so the badge is never clipped.
        // z-index:10 ensures the badge renders above the absolutely-positioned .thumbnail.
        $css = '
            .attachments-browser .attachment.uwgs-alt-missing .attachment-preview::after,
            .attachments-browser .attachment.uwgs-alt-weak    .attachment-preview::after {
                content: attr(data-uwgs-label);
                position: absolute; bottom:4px; left:50%; transform:translateX(-50%);
                white-space:nowrap;
                padding:3px 8px; font-size:11px; line-height:1.4; border-radius:3px;
                text-align:center; color:#fff; background:rgba(0,0,0,0.55);
                pointer-events:none; z-index:10;
            }
        ';

        wp_register_style( 'uwgs-media-grid', false, array(), self::VERSION );
        wp_enqueue_style( 'uwgs-media-grid' );
        wp_add_inline_style( 'uwgs-media-grid', $css );
    }

    // =========================================================================
    // ATTACHMENT DETAILS ASSETS (media modal + upload.php grid details panel)
    // =========================================================================

    private function enqueue_attachment_details_assets() {
        $data = array(
            'i18n' => array(
                'blankWarning'         => __( 'This image has no alt text. Please add a description.', 'uwgs-alt-text-tool' ),
                'weakWarning'          => __( 'Alt text may need improvement. Please review the description.', 'uwgs-alt-text-tool' ),
                'useSuggestionCaption' => __( 'Use caption as alt text', 'uwgs-alt-text-tool' ),
                'useSuggestionFilename'=> __( 'Use filename as alt text', 'uwgs-alt-text-tool' ),
                'suggestionApplied'    => __( 'Suggestion applied — click elsewhere to save.', 'uwgs-alt-text-tool' ),
                'navWarning'           => __( 'This image still has no alt text.', 'uwgs-alt-text-tool' ),
                'navProceed'           => __( 'Continue anyway', 'uwgs-alt-text-tool' ),
                'navStay'              => __( 'Add alt text', 'uwgs-alt-text-tool' ),
                'closeWarning'         => __( 'This image has no alt text.', 'uwgs-alt-text-tool' ),
                'closeAnyway'          => __( 'Close without alt text', 'uwgs-alt-text-tool' ),
                'addAlt'               => __( 'Add alt text', 'uwgs-alt-text-tool' ),
            ),
        );

        $css = '
            /* Warning banner — full-width, between upload details and form fields. */
            .uwgs-details-warning {
                display: block;
                margin: 0 0 10px;
                padding: 8px 12px;
                background: #fff3cd;
                border-left: 4px solid #ffc107;
                color: #856404;
                font-size: 13px;
                line-height: 1.5;
                border-radius: 0 3px 3px 0;
            }
            .uwgs-details-suggestion {
                display: block;
                margin: -6px 0 10px;
                white-space: normal;
                text-align: left;
            }
        ';

        wp_register_style( 'uwgs-attachment-details', false, array(), self::VERSION );
        wp_enqueue_style( 'uwgs-attachment-details' );
        wp_add_inline_style( 'uwgs-attachment-details', $css );

        wp_register_script( 'uwgs-attachment-details', plugins_url( 'js/uwgs-attachment-details.js', __FILE__ ), array( 'uwgs-alt-utils', 'media-views' ), self::VERSION, true );
        wp_enqueue_script( 'uwgs-attachment-details' );
        wp_add_inline_script( 'uwgs-attachment-details', 'var uwgsDetailsData = ' . wp_json_encode( $data ) . ';', 'before' );
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
            .uwgs-alt-feedback.success  { color:#2e7d32; }
            .uwgs-alt-feedback.warning  { color:#856404; }
            .uwgs-alt-feedback.error    { color:#c62828; }
            /* Info bar (settings-driven instructions) */
            #uwgs-info-bar {
                display:flex; align-items:flex-start; gap:8px;
                padding:10px 14px;
                margin:8px 0;
                background:#f0f6fc;
                border:1px solid #72aee6;
                border-radius:3px;
                font-size:13px;
                color:#1d2327;
                line-height:1.6;
            }
            .uwgs-info-bar-toggle {
                background:none; border:none; cursor:pointer;
                color:#1d2327; font-size:11px; padding:0;
                flex-shrink:0; line-height:1.8; margin-top:1px;
            }
            .uwgs-info-bar-toggle:focus-visible { outline:2px solid #0073aa; outline-offset:2px; }
            #uwgs-info-bar .uwgs-info-bar-content { flex:1; }
            #uwgs-info-bar.collapsed .uwgs-info-bar-content { display:none; }
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
            'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
            'restUrl'       => rest_url( 'uwgs-alt-text/v1' ),
            'restNonce'     => wp_create_nonce( 'wp_rest' ),
            'suggestions'   => $suggestions,
            'instructions'  => wp_kses_post( $instructions ),
            'columnVisible' => ! in_array( 'uwgs_alt_text', get_hidden_columns( 'upload' ), true ),
            'i18n'          => array(
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

        wp_register_script( 'uwgs-list-view', plugins_url( 'js/uwgs-list-view.js', __FILE__ ), array( 'jquery', 'uwgs-alt-utils' ), self::VERSION, true );
        wp_enqueue_script( 'uwgs-list-view' );
        wp_add_inline_script( 'uwgs-list-view', 'var uwgsAltData = ' . wp_json_encode( $data ) . ';', 'before' );
    }


    // =========================================================================
    // UPLOAD PAGE ASSETS
    // =========================================================================

    private function enqueue_upload_page_assets() {
        $i18n = array( 'editPrompt' => __( 'Upload complete — click here to edit and add alt text', 'uwgs-alt-text-tool' ) );
        wp_register_script( 'uwgs-upload-page', plugins_url( 'js/uwgs-upload-page.js', __FILE__ ), array( 'jquery' ), self::VERSION, true );
        wp_enqueue_script( 'uwgs-upload-page' );
        wp_add_inline_script( 'uwgs-upload-page', 'var uwgsUploadI18n = ' . wp_json_encode( $i18n ) . ';', 'before' );
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

        wp_register_script( 'uwgs-attachment-edit', plugins_url( 'js/uwgs-attachment-edit.js', __FILE__ ), array( 'jquery', 'uwgs-alt-utils' ), self::VERSION, true );
        wp_enqueue_script( 'uwgs-attachment-edit' );
        wp_add_inline_script( 'uwgs-attachment-edit', 'var uwgsAttachData = ' . wp_json_encode( $data ) . ';', 'before' );

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
            'restUrl'       => rest_url( 'uwgs-alt-text/v1/' ),
            'restNonce'     => wp_create_nonce( 'wp_rest' ),
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

        wp_register_script( 'uwgs-classic-presave', plugins_url( 'js/uwgs-classic-presave.js', __FILE__ ), array( 'jquery', 'uwgs-alt-utils' ), self::VERSION, true );
        wp_enqueue_script( 'uwgs-classic-presave' );
        wp_add_inline_script( 'uwgs-classic-presave', 'var uwgsPresaveData = ' . wp_json_encode( $data ) . ';', 'before' );

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

    // The submit button that triggered the current scan; held at module scope
    // so hideWarning() can re-enable it on any dismiss path.
    var activeSubmitter = null;

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
        warningEl.setAttribute( 'aria-describedby', 'uwgs-presave-warning-body' );
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
        var body = document.createElement( 'p' ); body.id = 'uwgs-presave-warning-body'; body.textContent = hasContent && hasFeatured ? i18n.warningBodyBoth : hasFeatured ? i18n.warningBodyFeatured : i18n.warningBodyContent;
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
        // Prevent background scroll while dialog is open.
        document.body.style.overflow = 'hidden';
        warningEl.classList.add( 'visible' ); goBack.focus();
    }

    function hideWarning() {
        warningEl.classList.remove( 'visible' );
        warningEl.innerHTML = '';
        document.body.style.overflow = '';
        // Re-enable the button that triggered the scan so the user can retry.
        if ( activeSubmitter ) { activeSubmitter.disabled = false; activeSubmitter = null; }
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

            // Disable the triggering button to block double-clicks / autosave
            // races during the async scan; restored in hideWarning() or below.
            activeSubmitter = submitter;
            if ( submitter ) { submitter.disabled = true; }

            scanInFlight = true;
            featuredImageMissingAlt().then( function( hasFeatured ) {
                scanInFlight = false;
                if ( hasContent || hasFeatured ) {
                    showWarning( hasContent, hasFeatured, submitter );
                    // button stays disabled until hideWarning() re-enables it
                } else {
                    // Clean scan — re-enable before resubmit so WP submit
                    // handlers see the button in its normal state.
                    if ( activeSubmitter ) { activeSubmitter.disabled = false; activeSubmitter = null; }
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

    }

    // =========================================================================
    // ADD MEDIA MODAL: CAPTION-TO-ALT VIA MUTATIONOBSERVER
    // =========================================================================

    private function enqueue_media_modal_caption_assets() {
        $i18n = array( 'captionCopied' => __( 'Copied from caption — please review before inserting.', 'uwgs-alt-text-tool' ) );
        wp_register_script( 'uwgs-media-modal', plugins_url( 'js/uwgs-media-modal.js', __FILE__ ), array( 'jquery' ), self::VERSION, true );
        wp_enqueue_script( 'uwgs-media-modal' );
        wp_add_inline_script( 'uwgs-media-modal', 'var uwgsModalCapI18n = ' . wp_json_encode( $i18n ) . ';', 'before' );
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
    }

    // =========================================================================
    // GUTENBERG: BLOCK CANVAS WARNING + PRE-PUBLISH PANEL + DRAFT SAVE WARNING
    // =========================================================================

    public function enqueue_block_editor_assets() {
        if ( ! current_user_can( 'upload_files' ) ) { return; }

        $data = array(
            'restUrl'   => rest_url( 'uwgs-alt-text/v1/' ),
            'restNonce' => wp_create_nonce( 'wp_rest' ),
            'i18n'      => array(
                'panelTitle'      => __( 'Image Accessibility', 'uwgs-alt-text-tool' ),
                'allGood'         => __( '✓ All images in this post have alt text.', 'uwgs-alt-text-tool' ),
                'warningContent'  => __( 'One or more images in this post are missing alt text. Click each image block and add a description in the Alt Text field in the right sidebar, or mark it as decorative.', 'uwgs-alt-text-tool' ),
                'warningFeatured' => __( 'The featured image for this post is missing alt text. Edit the featured image and add a description in the Alt Text field.', 'uwgs-alt-text-tool' ),
                'warningBoth'     => __( 'One or more images and the featured image are missing alt text. Please add descriptions before publishing, or mark decorative images as such.', 'uwgs-alt-text-tool' ),
                'decorativeNote'  => __( 'If an image is purely decorative, leave alt text empty and check "Mark as decorative" in the block settings sidebar.', 'uwgs-alt-text-tool' ),
                'canvasBanner'    => __( 'Missing alt text — click this image, then add alt text in the sidebar panel on the right.', 'uwgs-alt-text-tool' ),
                'draftWarning'    => __( 'Some images are missing alt text. You can fix this before publishing.', 'uwgs-alt-text-tool' ),
                'sidebarPanelTitle' => __( 'Image Accessibility', 'uwgs-alt-text-tool' ),
                'sidebarAllGood'    => __( '✓ All images have alt text', 'uwgs-alt-text-tool' ),
                'sidebarMissing'    => __( '⚠ Images missing alt text', 'uwgs-alt-text-tool' ),
            ),
        );

        wp_register_script(
            'uwgs-block-editor',
            plugins_url( 'js/uwgs-block-editor.js', __FILE__ ),
            array( 'wp-edit-post', 'wp-blocks', 'wp-hooks', 'wp-compose', 'wp-data', 'wp-element', 'wp-plugins' ),
            self::VERSION,
            true
        );
        wp_enqueue_script( 'uwgs-block-editor' );
        wp_add_inline_script( 'uwgs-block-editor', 'var uwgsBlockEditorData = ' . wp_json_encode( $data ) . ';', 'before' );

        // Legacy heredoc kept as an unused variable — content is now served from
        // js/uwgs-block-editor.js. Remove this block once JS file is confirmed stable.
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
        // $js is retained as reference only — do not re-inject. uwgs-block-editor.js is the active file.
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
        // Tab: Alt Text
        register_setting(
            'uwgs_alt_text_settings',
            self::OPTION_INSTRUCTIONS,
            array(
                'type'              => 'string',
                'sanitize_callback' => 'wp_kses_post',
                'default'           => '',
            )
        );
        add_settings_section( 'uwgs_main', __( 'Media Library Settings', 'uwgs-alt-text-tool' ), '__return_false', 'uwgs-alt-text-tool' );
        add_settings_field( 'uwgs_instructions', __( 'Instructions message', 'uwgs-alt-text-tool' ), array( $this, 'render_instructions_field' ), 'uwgs-alt-text-tool', 'uwgs_main' );

        // Tab: Unused Media
        register_setting(
            'uwgs_unused_settings',
            self::OPTION_UNUSED_SCOPE,
            array(
                'type'              => 'string',
                'sanitize_callback' => function( $v ) { return in_array( $v, array( 'all', 'images' ), true ) ? $v : 'all'; },
                'default'           => 'all',
            )
        );
        add_settings_section( 'uwgs_unused_main', '', '__return_false', 'uwgs-unused-settings' );
        add_settings_field( 'uwgs_unused_scope', __( 'Scan scope', 'uwgs-alt-text-tool' ), array( $this, 'render_unused_scope_field' ), 'uwgs-unused-settings', 'uwgs_unused_main' );
    }

    public function render_unused_scope_field() {
        $value = get_option( self::OPTION_UNUSED_SCOPE, 'all' );
        echo '<fieldset>';
        echo '<label><input type="radio" name="' . esc_attr( self::OPTION_UNUSED_SCOPE ) . '" value="all"' . checked( $value, 'all', false ) . '> ' . esc_html__( 'All media types (images, video, audio, documents)', 'uwgs-alt-text-tool' ) . '</label><br>';
        echo '<label><input type="radio" name="' . esc_attr( self::OPTION_UNUSED_SCOPE ) . '" value="images"' . checked( $value, 'images', false ) . '> ' . esc_html__( 'Images only', 'uwgs-alt-text-tool' ) . '</label>';
        echo '</fieldset>';
        echo '<p class="description">' . esc_html__( 'Controls which attachment types are included when scanning for unused media.', 'uwgs-alt-text-tool' ) . '</p>';
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
        $active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'alt-text';
        $base_url   = admin_url( 'options-general.php?page=uwgs-alt-text-tool' );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Alt Text Tool Settings', 'uwgs-alt-text-tool' ); ?></h1>
            <nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e( 'Settings tabs', 'uwgs-alt-text-tool' ); ?>">
                <a href="<?php echo esc_url( $base_url . '&tab=alt-text' ); ?>" class="nav-tab<?php echo $active_tab === 'alt-text' ? ' nav-tab-active' : ''; ?>"><?php esc_html_e( 'Alt Text', 'uwgs-alt-text-tool' ); ?></a>
                <a href="<?php echo esc_url( $base_url . '&tab=unused-media' ); ?>" class="nav-tab<?php echo $active_tab === 'unused-media' ? ' nav-tab-active' : ''; ?>"><?php esc_html_e( 'Unused Media', 'uwgs-alt-text-tool' ); ?></a>
                <a href="<?php echo esc_url( $base_url . '&tab=google-analytics' ); ?>" class="nav-tab<?php echo $active_tab === 'google-analytics' ? ' nav-tab-active' : ''; ?>"><?php esc_html_e( 'Google Analytics', 'uwgs-alt-text-tool' ); ?></a>
            </nav>

            <?php if ( $active_tab === 'alt-text' ) : ?>
                <form method="post" action="options.php" style="margin-top:20px;">
                    <?php
                    settings_fields( 'uwgs_alt_text_settings' );
                    do_settings_sections( 'uwgs-alt-text-tool' );
                    submit_button();
                    ?>
                </form>
                <hr>
                <h2><?php esc_html_e( 'How Alt Text Quality Is Evaluated', 'uwgs-alt-text-tool' ); ?></h2>
                <p><?php esc_html_e( 'Rules are applied in order; the first match determines the outcome.', 'uwgs-alt-text-tool' ); ?></p>
                <?php $this->render_quality_rules_table(); ?>
                <p class="description" style="max-width:800px;margin-top:8px;">
                    <?php esc_html_e( 'Rules are enforced in both PHP and JavaScript. To adjust a rule, update both js/uwgs-alt-utils.js and the UWGS_Alt_Quality class in the plugin file.', 'uwgs-alt-text-tool' ); ?>
                </p>

            <?php elseif ( $active_tab === 'unused-media' ) : ?>
                <form method="post" action="options.php" style="margin-top:20px;">
                    <?php
                    settings_fields( 'uwgs_unused_settings' );
                    do_settings_sections( 'uwgs-unused-settings' );
                    submit_button( __( 'Save Unused Media Settings', 'uwgs-alt-text-tool' ) );
                    ?>
                </form>
                <?php $this->render_excluded_items_table(); ?>

            <?php elseif ( $active_tab === 'google-analytics' ) : ?>
                <div style="margin-top:20px;max-width:600px;">
                    <h2 style="margin-top:0;"><?php esc_html_e( 'Google Analytics Integration', 'uwgs-alt-text-tool' ); ?></h2>
                    <?php
                    $site_kit_active = $this->is_site_kit_ga4_connected();
                    if ( $site_kit_active ) : ?>
                        <div class="notice notice-success inline" style="margin:0 0 16px;">
                            <p><?php esc_html_e( 'Google Site Kit is connected and GA4 is active. Traffic data integration will use this connection — no additional setup required.', 'uwgs-alt-text-tool' ); ?></p>
                        </div>
                        <p><?php esc_html_e( 'When GA4 data syncing is added in a future update, it will automatically use your existing Site Kit authentication.', 'uwgs-alt-text-tool' ); ?></p>
                    <?php else : ?>
                        <div class="notice notice-info inline" style="margin:0 0 16px;">
                            <p><?php printf( esc_html__( 'Google Site Kit is not connected. To enable GA4 traffic data in the Unused Media scanner, %s and connect it to GA4.', 'uwgs-alt-text-tool' ), '<a href="' . esc_url( admin_url( 'admin.php?page=googlesitekit-splash' ) ) . '">' . esc_html__( 'install Google Site Kit', 'uwgs-alt-text-tool' ) . '</a>' ); ?></p>
                        </div>
                        <p style="color:#555;"><?php esc_html_e( 'GA4 traffic data integration is planned for a future update. It will show pageview counts per media file so you can prioritize which unused items are safe to remove.', 'uwgs-alt-text-tool' ); ?></p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_quality_rules_table() {
        $rules = UWGS_Alt_Quality::get_rules_description();
        $outcome_colors = array( 'needs_attention' => '#c62828', 'weak' => '#856404', 'good' => '#2e7d32' );
        $outcome_labels = array(
            'needs_attention' => __( 'Needs attention', 'uwgs-alt-text-tool' ),
            'weak'            => __( 'Weak quality', 'uwgs-alt-text-tool' ),
            'good'            => __( 'Good quality', 'uwgs-alt-text-tool' ),
        );
        ?>
        <table class="widefat striped" style="max-width:800px;">
            <thead><tr>
                <th><?php esc_html_e( 'Rule', 'uwgs-alt-text-tool' ); ?></th>
                <th><?php esc_html_e( 'Description', 'uwgs-alt-text-tool' ); ?></th>
                <th><?php esc_html_e( 'Outcome', 'uwgs-alt-text-tool' ); ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ( $rules as $rule ) :
                $color = isset( $outcome_colors[ $rule['outcome'] ] ) ? $outcome_colors[ $rule['outcome'] ] : '#555';
                $label = isset( $outcome_labels[ $rule['outcome'] ] ) ? $outcome_labels[ $rule['outcome'] ] : esc_html( $rule['outcome'] );
            ?>
                <tr>
                    <td style="font-weight:600;white-space:nowrap;"><?php echo esc_html( $rule['label'] ); ?></td>
                    <td><?php echo esc_html( $rule['description'] ); ?></td>
                    <td style="color:<?php echo esc_attr( $color ); ?>;font-weight:600;white-space:nowrap;"><?php echo esc_html( $label ); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    private function render_excluded_items_table() {
        global $wpdb;
        $excluded = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.ID, p.post_title, p.post_mime_type, p.post_date
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
             WHERE p.post_type = 'attachment' AND p.post_status = 'inherit'
               AND pm.meta_key = %s AND pm.meta_value = 'excluded'
             ORDER BY p.post_date DESC LIMIT 200",
            self::META_UNUSED_STATUS
        ) );
        if ( empty( $excluded ) ) { return; }
        $nonce = wp_create_nonce( self::NONCE_UNUSED_ACTION );
        ?>
        <hr style="margin-top:24px;">
        <h2><?php esc_html_e( 'Excluded Items', 'uwgs-alt-text-tool' ); ?></h2>
        <p class="description"><?php esc_html_e( 'These items were manually excluded from unused media scans. Remove exclusion to include them in future scans.', 'uwgs-alt-text-tool' ); ?></p>
        <table class="widefat striped" style="max-width:800px;">
            <thead><tr>
                <th><?php esc_html_e( 'File', 'uwgs-alt-text-tool' ); ?></th>
                <th><?php esc_html_e( 'Type', 'uwgs-alt-text-tool' ); ?></th>
                <th><?php esc_html_e( 'Uploaded', 'uwgs-alt-text-tool' ); ?></th>
                <th><?php esc_html_e( 'Action', 'uwgs-alt-text-tool' ); ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ( $excluded as $item ) : ?>
                <tr>
                    <td><?php echo esc_html( $item->post_title ); ?></td>
                    <td><?php echo esc_html( $item->post_mime_type ); ?></td>
                    <td><?php echo esc_html( get_the_date( 'Y-m-d', $item->ID ) ); ?></td>
                    <td>
                        <button type="button" class="button button-small uwgs-unexclude"
                                data-id="<?php echo esc_attr( $item->ID ); ?>"
                                data-nonce="<?php echo esc_attr( $nonce ); ?>">
                            <?php esc_html_e( 'Remove exclusion', 'uwgs-alt-text-tool' ); ?>
                        </button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    private function is_site_kit_ga4_connected() {
        if ( ! function_exists( 'googlesitekit_activate' ) && ! class_exists( 'Google\Site_Kit\Plugin' ) ) {
            return false;
        }
        $options = get_option( 'googlesitekit_analytics-4_settings', array() );
        return ! empty( $options['propertyID'] );
    }

    // =========================================================================
    // UNUSED MEDIA: ADMIN PAGE
    // =========================================================================

    public function register_unused_media_page() {
        if ( ! current_user_can( 'upload_files' ) ) { return; }
        $hook = add_submenu_page(
            'upload.php',
            __( 'Unused Media', 'uwgs-alt-text-tool' ),
            __( 'Unused Media', 'uwgs-alt-text-tool' ),
            'upload_files',
            'uwgs-unused-media',
            array( $this, 'render_unused_media_page' )
        );
        add_action( "load-{$hook}", array( $this, 'unused_media_screen_options' ) );
    }

    public function unused_media_screen_options() {
        add_screen_option( 'per_page', array(
            'label'   => __( 'Items per page', 'uwgs-alt-text-tool' ),
            'default' => 50,
            'option'  => 'uwgs_unused_per_page',
        ) );
    }

    public function render_unused_media_page() {
        if ( ! current_user_can( 'upload_files' ) ) { return; }

        $last_scan   = get_option( self::OPTION_UNUSED_LAST_SCAN, 0 );
        $confidence  = isset( $_GET['confidence'] ) ? sanitize_key( $_GET['confidence'] ) : 'all';
        $type_filter = isset( $_GET['media_type'] ) ? sanitize_key( $_GET['media_type'] ) : 'all';
        $paged       = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
        $per_page    = (int) get_user_option( 'uwgs_unused_per_page' );
        if ( $per_page < 1 ) { $per_page = 50; }

        $allowed_orderby = array( 'date', 'author', 'type', 'size', 'confidence' );
        $orderby         = isset( $_GET['orderby'] ) && in_array( $_GET['orderby'], $allowed_orderby, true ) ? $_GET['orderby'] : 'date';
        $order           = isset( $_GET['order'] ) && strtoupper( $_GET['order'] ) === 'ASC' ? 'ASC' : 'DESC';

        $results = $this->get_unused_attachments( array(
            'confidence' => $confidence,
            'type'       => $type_filter,
            'per_page'   => $per_page,
            'page'       => $paged,
            'orderby'    => $orderby,
            'order'      => $order,
        ) );

        $attachments = $results['attachments'];
        $total       = $results['total'];
        $total_pages = $results['pages'];

        $scan_nonce   = wp_create_nonce( self::NONCE_UNUSED_SCAN );
        $action_nonce = wp_create_nonce( self::NONCE_UNUSED_ACTION );
        $base_url     = admin_url( 'upload.php?page=uwgs-unused-media' );

        // Stale scan warning threshold: 7 days
        $scan_stale = $last_scan && ( time() - $last_scan ) > 7 * DAY_IN_SECONDS;
        ?>
        <div class="wrap" id="uwgs-unused-wrap">
            <h1><?php esc_html_e( 'Unused Media', 'uwgs-alt-text-tool' ); ?></h1>
            <div style="margin-bottom:12px;">
                <button type="button" id="uwgs-rescan-btn" class="button"
                        data-nonce="<?php echo esc_attr( $scan_nonce ); ?>">
                    &#9654; <?php esc_html_e( 'Rescan', 'uwgs-alt-text-tool' ); ?>
                </button>
            </div>

            <?php if ( $scan_stale ) : ?>
                <div class="notice notice-warning inline" style="margin:8px 0;">
                    <p><?php printf( esc_html__( 'Scan results are from %s and may be outdated. Run a new scan for current results.', 'uwgs-alt-text-tool' ), esc_html( wp_date( get_option( 'date_format' ), $last_scan ) ) ); ?></p>
                </div>
            <?php endif; ?>

            <div id="uwgs-scan-progress" style="display:none;margin:12px 0;max-width:500px;">
                <p id="uwgs-scan-status" style="margin:0 0 6px;font-size:13px;color:#555;"></p>
                <div style="background:#e0e0e0;border-radius:4px;height:12px;overflow:hidden;">
                    <div id="uwgs-scan-bar" style="background:#0073aa;width:0%;height:100%;border-radius:4px;transition:width 0.2s ease;"></div>
                </div>
            </div>

            <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;margin:16px 0 12px;">
                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                    <?php if ( $last_scan ) : ?>
                        <span style="font-size:12px;color:#777;">
                            <?php printf( esc_html__( 'Scanned %s', 'uwgs-alt-text-tool' ), esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last_scan ) ) ); ?>
                            &middot;
                            <?php printf( esc_html__( '%s items', 'uwgs-alt-text-tool' ), '<strong>' . esc_html( number_format_i18n( $total ) ) . '</strong>' ); ?>
                        </span>
                    <?php else : ?>
                        <span style="font-size:12px;color:#777;"><?php esc_html_e( 'No scan has been run yet.', 'uwgs-alt-text-tool' ); ?></span>
                    <?php endif; ?>

                    <!-- Type filter -->
                    <span style="font-size:12px;">
                        <?php
                        $types = array(
                            'all'      => __( 'All types', 'uwgs-alt-text-tool' ),
                            'image'    => __( 'Images', 'uwgs-alt-text-tool' ),
                            'video'    => __( 'Video', 'uwgs-alt-text-tool' ),
                            'audio'    => __( 'Audio', 'uwgs-alt-text-tool' ),
                            'document' => __( 'Documents', 'uwgs-alt-text-tool' ),
                        );
                        foreach ( $types as $key => $label ) :
                            $url = add_query_arg( array( 'media_type' => $key, 'confidence' => $confidence, 'paged' => 1 ), $base_url );
                            if ( $key === $type_filter ) :
                                echo '<strong>' . esc_html( $label ) . '</strong>';
                            else :
                                echo '<a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
                            endif;
                            echo $key !== 'document' ? ' &middot; ' : '';
                        endforeach;
                        ?>
                    </span>

                    <!-- Confidence filter -->
                    <span style="font-size:12px;">
                        <?php
                        $conf_opts = array(
                            'all'       => __( 'All confidence', 'uwgs-alt-text-tool' ),
                            'unused'    => __( 'Unused only', 'uwgs-alt-text-tool' ),
                            'uncertain' => __( 'Uncertain only', 'uwgs-alt-text-tool' ),
                        );
                        foreach ( $conf_opts as $key => $label ) :
                            $url = add_query_arg( array( 'confidence' => $key, 'media_type' => $type_filter, 'paged' => 1 ), $base_url );
                            if ( $key === $confidence ) :
                                echo '<strong>' . esc_html( $label ) . '</strong>';
                            else :
                                echo '<a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
                            endif;
                            echo $key !== 'uncertain' ? ' &middot; ' : '';
                        endforeach;
                        ?>
                    </span>
                </div>

                <?php if ( ! empty( $attachments ) ) : ?>
                    <a href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'uwgs_export_csv', 'confidence' => $confidence, 'media_type' => $type_filter ), admin_url( 'admin-ajax.php' ) ), self::NONCE_UNUSED_ACTION, 'nonce' ) ); ?>" class="button">
                        &#8595; <?php esc_html_e( 'Export CSV', 'uwgs-alt-text-tool' ); ?>
                    </a>
                <?php endif; ?>
            </div>

            <?php if ( empty( $attachments ) ) : ?>
                <p style="color:#555;margin:24px 0;">
                    <?php if ( ! $last_scan ) : ?>
                        <?php esc_html_e( 'Click Rescan to scan your media library for unused items.', 'uwgs-alt-text-tool' ); ?>
                    <?php else : ?>
                        <?php esc_html_e( 'No unused media found matching the current filters.', 'uwgs-alt-text-tool' ); ?>
                    <?php endif; ?>
                </p>
            <?php else : ?>

                <?php
                // Helper: render a sortable column header link.
                $sort_header = function( $col_key, $label, $width = '' ) use ( $orderby, $order, $base_url, $confidence, $type_filter, $paged ) {
                    $is_active   = ( $orderby === $col_key );
                    $next_order  = ( $is_active && $order === 'DESC' ) ? 'ASC' : 'DESC';
                    $arrow       = $is_active ? ( $order === 'DESC' ? ' &#8595;' : ' &#8593;' ) : '';
                    $url         = add_query_arg( array(
                        'orderby'    => $col_key,
                        'order'      => $next_order,
                        'confidence' => $confidence,
                        'media_type' => $type_filter,
                        'paged'      => 1,
                    ), $base_url );
                    $style       = $width ? ' style="width:' . esc_attr( $width ) . ';"' : '';
                    $class       = $is_active ? ' sorted ' . strtolower( $order ) : ' sortable desc';
                    echo '<th scope="col"' . $style . ' class="column-' . esc_attr( $col_key ) . $class . '">'
                        . '<a href="' . esc_url( $url ) . '"><span>' . esc_html( $label ) . '</span>'
                        . '<span class="sorting-indicator">' . $arrow . '</span></a></th>';
                };

                $first_item  = ( ( $paged - 1 ) * $per_page ) + 1;
                $last_item   = min( $paged * $per_page, $total );
                ?>
                <form method="post" id="uwgs-unused-form">
                    <?php wp_nonce_field( self::NONCE_UNUSED_ACTION, 'uwgs_unused_nonce' ); ?>
                    <div class="tablenav top">
                        <div class="alignleft actions bulkactions">
                            <select id="uwgs-bulk-action" name="bulk_action">
                                <option value=""><?php esc_html_e( 'Bulk actions', 'uwgs-alt-text-tool' ); ?></option>
                                <option value="trash"><?php esc_html_e( 'Move to Trash', 'uwgs-alt-text-tool' ); ?></option>
                            </select>
                            <button type="button" id="uwgs-bulk-apply" class="button"><?php esc_html_e( 'Apply', 'uwgs-alt-text-tool' ); ?></button>
                            <span id="uwgs-bulk-feedback" style="font-size:12px;color:#555;margin-left:8px;"></span>
                        </div>
                        <div class="tablenav-pages" style="float:right;line-height:2;">
                            <span class="displaying-num">
                                <?php echo esc_html( sprintf(
                                    /* translators: 1: first item number, 2: last item number, 3: total */
                                    _n( '%2$s of %3$s item', '%1$s–%2$s of %3$s items', $total, 'uwgs-alt-text-tool' ),
                                    number_format_i18n( $first_item ),
                                    number_format_i18n( $last_item ),
                                    number_format_i18n( $total )
                                ) ); ?>
                            </span>
                        </div>
                        <br class="clear">
                    </div>

                    <table class="wp-list-table widefat fixed striped" id="uwgs-unused-table">
                        <thead><tr>
                            <td class="manage-column column-cb check-column"><input type="checkbox" id="uwgs-select-all" aria-label="<?php esc_attr_e( 'Select all', 'uwgs-alt-text-tool' ); ?>"></td>
                            <th scope="col" style="width:60px;"><?php esc_html_e( 'Preview', 'uwgs-alt-text-tool' ); ?></th>
                            <th scope="col"><?php esc_html_e( 'File', 'uwgs-alt-text-tool' ); ?></th>
                            <?php $sort_header( 'type',       __( 'Type',       'uwgs-alt-text-tool' ), '80px' ); ?>
                            <?php $sort_header( 'size',       __( 'Size',       'uwgs-alt-text-tool' ), '70px' ); ?>
                            <?php $sort_header( 'date',       __( 'Uploaded',   'uwgs-alt-text-tool' ), '100px' ); ?>
                            <?php $sort_header( 'author',     __( 'By',         'uwgs-alt-text-tool' ), '100px' ); ?>
                            <?php $sort_header( 'confidence', __( 'Confidence', 'uwgs-alt-text-tool' ), '110px' ); ?>
                            <th scope="col" style="width:120px;"><?php esc_html_e( 'Actions', 'uwgs-alt-text-tool' ); ?></th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ( $attachments as $attachment ) :
                            $status    = get_post_meta( $attachment->ID, self::META_UNUSED_STATUS, true );
                            $mime      = $attachment->post_mime_type;
                            $is_image  = strpos( $mime, 'image/' ) === 0;
                            $thumb_url = $is_image ? wp_get_attachment_image_url( $attachment->ID, array( 40, 40 ) ) : '';
                            $file_url  = wp_get_attachment_url( $attachment->ID );
                            $meta      = wp_get_attachment_metadata( $attachment->ID );
                            $file_size = '';
                            if ( isset( $meta['filesize'] ) ) {
                                $file_size = size_format( $meta['filesize'] );
                            } elseif ( $file_url ) {
                                $file_path = get_attached_file( $attachment->ID );
                                if ( $file_path && file_exists( $file_path ) ) {
                                    $file_size = size_format( filesize( $file_path ) );
                                }
                            }
                            $author    = get_userdata( $attachment->post_author );
                            $author_name = $author ? $author->display_name : '—';
                            $conf_label = $status === 'unused'
                                ? '<span style="color:#c62828;font-weight:600;" title="' . esc_attr__( 'No reference found anywhere in content, metadata, options, or theme files', 'uwgs-alt-text-tool' ) . '">' . esc_html__( 'Unused', 'uwgs-alt-text-tool' ) . '</span>'
                                : '<span style="color:#856404;font-weight:600;" title="' . esc_attr__( 'Found only in trashed content — may be safely removable', 'uwgs-alt-text-tool' ) . '">' . esc_html__( 'Uncertain', 'uwgs-alt-text-tool' ) . '</span>';
                        ?>
                            <tr data-id="<?php echo esc_attr( $attachment->ID ); ?>">
                                <th class="check-column"><input type="checkbox" name="attachment_ids[]" value="<?php echo esc_attr( $attachment->ID ); ?>"></th>
                                <td>
                                    <?php if ( $thumb_url ) : ?>
                                        <a href="<?php echo esc_url( $file_url ); ?>" target="_blank">
                                            <img src="<?php echo esc_url( $thumb_url ); ?>" alt="" width="40" height="40" style="object-fit:cover;border-radius:2px;display:block;">
                                        </a>
                                    <?php else : ?>
                                        <span style="font-size:20px;display:block;text-align:center;" aria-hidden="true">&#128196;</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="<?php echo esc_url( $file_url ); ?>" target="_blank" style="word-break:break-all;">
                                        <?php echo esc_html( basename( $file_url ) ); ?>
                                    </a>
                                    <?php if ( $attachment->post_title && $attachment->post_title !== basename( $file_url ) ) : ?>
                                        <div style="font-size:11px;color:#777;"><?php echo esc_html( $attachment->post_title ); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td style="font-size:11px;color:#555;"><?php echo esc_html( $mime ); ?></td>
                                <td style="font-size:12px;color:#555;"><?php echo esc_html( $file_size ?: '—' ); ?></td>
                                <td style="font-size:12px;"><?php echo esc_html( get_the_date( 'Y-m-d', $attachment->ID ) ); ?></td>
                                <td style="font-size:12px;"><?php echo esc_html( $author_name ); ?></td>
                                <td><?php echo $conf_label; ?></td>
                                <td>
                                    <div style="display:flex;gap:4px;align-items:center;">
                                        <button type="button" class="button button-small uwgs-exclude-btn"
                                                data-id="<?php echo esc_attr( $attachment->ID ); ?>"
                                                data-nonce="<?php echo esc_attr( $action_nonce ); ?>"
                                                title="<?php esc_attr_e( 'Keep this item — exclude from future scans', 'uwgs-alt-text-tool' ); ?>">
                                            <?php esc_html_e( 'Keep', 'uwgs-alt-text-tool' ); ?>
                                        </button>
                                        <button type="button" class="button button-small uwgs-trash-single-btn"
                                                data-id="<?php echo esc_attr( $attachment->ID ); ?>"
                                                data-nonce="<?php echo esc_attr( $action_nonce ); ?>"
                                                style="color:#b32d2e;border-color:#b32d2e;"
                                                title="<?php esc_attr_e( 'Move to Trash', 'uwgs-alt-text-tool' ); ?>">
                                            <?php esc_html_e( 'Trash', 'uwgs-alt-text-tool' ); ?>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>

                    <div class="tablenav bottom" style="margin-top:8px;">
                        <?php if ( $total_pages > 1 ) : ?>
                            <div class="tablenav-pages">
                                <span class="displaying-num">
                                    <?php echo esc_html( sprintf(
                                        _n( '%2$s of %3$s item', '%1$s–%2$s of %3$s items', $total, 'uwgs-alt-text-tool' ),
                                        number_format_i18n( $first_item ),
                                        number_format_i18n( $last_item ),
                                        number_format_i18n( $total )
                                    ) ); ?>
                                </span>
                                <?php
                                echo paginate_links( array(
                                    'base'     => add_query_arg( 'paged', '%#%', $base_url ),
                                    'format'   => '',
                                    'current'  => $paged,
                                    'total'    => $total_pages,
                                    'add_args' => array(
                                        'confidence' => $confidence,
                                        'media_type' => $type_filter,
                                        'orderby'    => $orderby,
                                        'order'      => $order,
                                    ),
                                    'prev_text' => '&laquo;',
                                    'next_text' => '&raquo;',
                                ) );
                                ?>
                            </div>
                        <?php endif; ?>
                        <br class="clear">
                    </div>

                    <div id="uwgs-trash-confirm-dialog" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:99999;align-items:center;justify-content:center;">
                        <div style="background:#fff;border-radius:4px;padding:24px;max-width:420px;width:90%;box-shadow:0 8px 24px rgba(0,0,0,0.2);" role="alertdialog" aria-labelledby="uwgs-trash-confirm-title" aria-modal="true">
                            <h2 id="uwgs-trash-confirm-title" style="margin:0 0 12px;font-size:16px;"><?php esc_html_e( 'Move to Trash?', 'uwgs-alt-text-tool' ); ?></h2>
                            <p id="uwgs-trash-confirm-msg" style="margin:0 0 16px;font-size:13px;color:#555;"></p>
                            <p style="margin:0 0 20px;font-size:12px;color:#777;"><?php esc_html_e( 'Items will be moved to Trash. You have 30 days to restore them via Media > Library.', 'uwgs-alt-text-tool' ); ?></p>
                            <div style="display:flex;gap:8px;">
                                <button type="button" id="uwgs-trash-confirm-yes" class="button button-primary"><?php esc_html_e( 'Move to Trash', 'uwgs-alt-text-tool' ); ?></button>
                                <button type="button" id="uwgs-trash-confirm-no" class="button"><?php esc_html_e( 'Cancel', 'uwgs-alt-text-tool' ); ?></button>
                            </div>
                        </div>
                    </div>
                </form>
            <?php endif; ?>
        </div>
        <?php
    }

    private function get_unused_attachments( $args = array() ) {
        $defaults = array(
            'confidence' => 'all',
            'type'       => 'all',
            'per_page'   => 50,
            'page'       => 1,
            'orderby'    => 'date',
            'order'      => 'DESC',
        );
        $args = wp_parse_args( $args, $defaults );

        $status_values = array( 'unused', 'uncertain' );
        if ( $args['confidence'] === 'unused' )    { $status_values = array( 'unused' ); }
        if ( $args['confidence'] === 'uncertain' ) { $status_values = array( 'uncertain' ); }

        $meta_query = array(
            'status_clause' => array(
                'key'     => self::META_UNUSED_STATUS,
                'value'   => $status_values,
                'compare' => 'IN',
            ),
        );

        $query_args = array(
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => $args['per_page'],
            'paged'          => $args['page'],
            'order'          => $args['order'],
            'meta_query'     => $meta_query,
        );

        // Map orderby param to WP_Query equivalents.
        switch ( $args['orderby'] ) {
            case 'author':
                $query_args['orderby'] = 'author';
                break;
            case 'size':
                // Order by file-size meta stored during scan; use named meta clause.
                $query_args['meta_query']['size_clause'] = array(
                    'key'     => self::META_UNUSED_FILE_SIZE,
                    'type'    => 'NUMERIC',
                    'compare' => 'EXISTS',
                );
                $query_args['orderby'] = array( 'size_clause' => $args['order'] );
                unset( $query_args['order'] );
                break;
            case 'confidence':
                // Order alphabetically by status meta value (uncertain > unused).
                $query_args['orderby']  = 'status_clause';
                break;
            case 'type':
                // post_mime_type is not a native WP_Query orderby; handled via filter below.
                $query_args['orderby'] = 'date'; // fallback, overridden by filter
                break;
            default: // 'date'
                $query_args['orderby'] = 'date';
                break;
        }

        if ( $args['type'] !== 'all' ) {
            switch ( $args['type'] ) {
                case 'image':    $query_args['post_mime_type'] = 'image'; break;
                case 'video':    $query_args['post_mime_type'] = 'video'; break;
                case 'audio':    $query_args['post_mime_type'] = 'audio'; break;
                case 'document':
                    $query_args['post_mime_type'] = array(
                        'application/pdf',
                        'application/msword',
                        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                        'application/vnd.ms-excel',
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        'text/plain',
                        'text/csv',
                    );
                    break;
            }
        }

        if ( $args['orderby'] === 'type' ) {
            global $wpdb;
            $sql_order = $args['order'];
            $mime_filter = function( $orderby ) use ( $wpdb, $sql_order ) {
                return "{$wpdb->posts}.post_mime_type {$sql_order}";
            };
            add_filter( 'posts_orderby', $mime_filter );
            $query = new WP_Query( $query_args );
            remove_filter( 'posts_orderby', $mime_filter );
        } else {
            $query = new WP_Query( $query_args );
        }

        return array(
            'attachments' => $query->posts,
            'total'       => $query->found_posts,
            'pages'       => $query->max_num_pages,
        );
    }

    // =========================================================================
    // UNUSED MEDIA: SCANNER
    // =========================================================================

    private function build_theme_index() {
        $dirs = array_unique( array_filter( array(
            get_template_directory(),
            get_stylesheet_directory(),
        ) ) );

        $references = array();
        foreach ( $dirs as $dir ) {
            if ( ! is_dir( $dir ) ) { continue; }
            try {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
                    RecursiveIteratorIterator::LEAVES_ONLY
                );
                foreach ( $iterator as $file ) {
                    if ( ! $file->isFile() ) { continue; }
                    if ( ! in_array( strtolower( $file->getExtension() ), array( 'php', 'css', 'js' ), true ) ) { continue; }
                    $content = @file_get_contents( $file->getPathname() );
                    if ( false === $content || strpos( $content, '/uploads/' ) === false ) { continue; }
                    preg_match_all( '#/uploads/[^\s\'")\]>\\\\]+#', $content, $matches );
                    foreach ( $matches[0] as $match ) {
                        $references[] = trim( $match, '"\'()[]{}<>' );
                    }
                }
            } catch ( Exception $e ) {
                // Unreadable directory — skip silently.
            }
        }

        return array_unique( $references );
    }

    /**
     * Build an inverted in-use index from 4 bulk queries so per-attachment
     * lookup is O(1) PHP array access instead of per-attachment SQL.
     *
     * Runs once per full scan (offset=0) and is cached as a transient.
     *
     * Returns array with keys:
     *   active_paths, active_filenames, active_ids  — found in live content
     *   trash_paths,  trash_filenames,  trash_ids   — found only in trash
     *   meta_ids, meta_paths, meta_filenames        — found in postmeta
     *   option_paths, option_filenames              — found in autoloaded options
     */
    private function build_in_use_index() {
        global $wpdb;

        $idx = array(
            'active_paths'     => array(),
            'active_filenames' => array(),
            'active_ids'       => array(),
            'trash_paths'      => array(),
            'trash_filenames'  => array(),
            'trash_ids'        => array(),
            'meta_ids'         => array(),
            'meta_paths'       => array(),
            'meta_filenames'   => array(),
            'option_paths'     => array(),
            'option_filenames' => array(),
        );

        // Helper: extract path fragment and basename from a raw /uploads/… match.
        $add_ref = function( $raw_path, array &$paths, array &$filenames ) {
            $path = trim( $raw_path, '"\'()[]{}< >,' );
            // Strip query string / fragment
            $path = preg_replace( '/[?#].*$/', '', $path );
            if ( strlen( $path ) < 3 ) { return; }
            $paths[ $path ]                  = true;
            $filenames[ basename( $path ) ]  = true;
        };

        // --- 1. Post content: live posts and trashed posts -------------------------
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $rows = $wpdb->get_results(
            "SELECT post_content, post_status
             FROM {$wpdb->posts}
             WHERE post_type != 'attachment'
               AND post_status NOT IN ('auto-draft')
               AND ( post_content LIKE '%/uploads/%'
                  OR post_content LIKE '%\"id\":%' )"
        );
        foreach ( (array) $rows as $row ) {
            $is_trash = ( $row->post_status === 'trash' );

            // Path references
            if ( preg_match_all( '#/uploads/([^\s\'")\]>\\\\]+)#', $row->post_content, $m ) ) {
                $path_bucket = $is_trash ? 'trash_paths' : 'active_paths';
                $file_bucket = $is_trash ? 'trash_filenames' : 'active_filenames';
                foreach ( $m[1] as $raw ) {
                    $add_ref( $raw, $idx[ $path_bucket ], $idx[ $file_bucket ] );
                }
            }

            // Gutenberg block "id": NNN references
            if ( preg_match_all( '#"id"\s*:\s*(\d+)#', $row->post_content, $m ) ) {
                $id_bucket = $is_trash ? 'trash_ids' : 'active_ids';
                foreach ( $m[1] as $att_id ) {
                    $idx[ $id_bucket ][ (int) $att_id ] = true;
                }
            }
        }

        // --- 2. Postmeta ----------------------------------------------------------

        // 2a. Exact numeric meta_value matches (featured images, etc.)
        //     Intersect all numeric meta values with actual attachment IDs to
        //     avoid false positives from coincidental integers.
        $att_ids = $wpdb->get_col(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type = 'attachment' AND post_status = 'inherit'"
        );
        $att_ids_set = array_flip( array_map( 'intval', (array) $att_ids ) );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $numeric_meta = $wpdb->get_col(
            "SELECT DISTINCT meta_value FROM {$wpdb->postmeta}
             WHERE meta_value REGEXP '^[0-9]+$'"
        );
        foreach ( (array) $numeric_meta as $v ) {
            $n = (int) $v;
            if ( $n > 0 && isset( $att_ids_set[ $n ] ) ) {
                $idx['meta_ids'][ $n ] = true;
            }
        }

        // 2b. Meta values containing upload paths (ACF, page builder, serialized data)
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $meta_vals = $wpdb->get_col(
            "SELECT meta_value FROM {$wpdb->postmeta}
             WHERE meta_value LIKE '%/uploads/%'"
        );
        foreach ( (array) $meta_vals as $val ) {
            if ( preg_match_all( '#/uploads/([^\s\'")\]>\\\\]+)#', $val, $m ) ) {
                foreach ( $m[1] as $raw ) {
                    $add_ref( $raw, $idx['meta_paths'], $idx['meta_filenames'] );
                }
            }
        }

        // --- 3. Autoloaded options (widgets, customizer, theme mods) ---------------
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $opt_vals = $wpdb->get_col(
            "SELECT option_value FROM {$wpdb->options}
             WHERE autoload = 'yes' AND option_value LIKE '%/uploads/%'"
        );
        foreach ( (array) $opt_vals as $val ) {
            if ( preg_match_all( '#/uploads/([^\s\'")\]>\\\\]+)#', $val, $m ) ) {
                foreach ( $m[1] as $raw ) {
                    $add_ref( $raw, $idx['option_paths'], $idx['option_filenames'] );
                }
            }
        }

        return $idx;
    }

    /**
     * Classify one attachment using pre-built in-use and theme indexes.
     * All lookups are O(1) PHP array access — no SQL queries here.
     */
    private function scan_attachment_status( $id, array $in_use_index, array $theme_index ) {
        $url = wp_get_attachment_url( $id );
        if ( ! $url ) { return 'in_use'; }

        $upload_dir = wp_upload_dir();
        $rel_path   = ltrim( str_replace( $upload_dir['baseurl'], '', $url ), '/' );
        if ( empty( $rel_path ) ) { return 'in_use'; }

        $filename = basename( $rel_path );

        // 1. Active post content
        if ( isset( $in_use_index['active_paths'][ $rel_path ] ) )        { return 'in_use'; }
        if ( isset( $in_use_index['active_filenames'][ $filename ] ) )     { return 'in_use'; }
        if ( isset( $in_use_index['active_ids'][ $id ] ) )                 { return 'in_use'; }

        // 2. Postmeta
        if ( isset( $in_use_index['meta_ids'][ $id ] ) )                   { return 'in_use'; }
        if ( isset( $in_use_index['meta_paths'][ $rel_path ] ) )           { return 'in_use'; }
        if ( isset( $in_use_index['meta_filenames'][ $filename ] ) )       { return 'in_use'; }

        // 3. Autoloaded options
        if ( isset( $in_use_index['option_paths'][ $rel_path ] ) )         { return 'in_use'; }
        if ( isset( $in_use_index['option_filenames'][ $filename ] ) )     { return 'in_use'; }

        // 4. Theme files (small list, loop is fine)
        foreach ( $theme_index as $ref ) {
            if ( strpos( $ref, $filename ) !== false || strpos( $ref, $rel_path ) !== false ) {
                return 'in_use';
            }
        }

        // Found only in trashed content → uncertain
        if ( isset( $in_use_index['trash_paths'][ $rel_path ] ) )          { return 'uncertain'; }
        if ( isset( $in_use_index['trash_filenames'][ $filename ] ) )      { return 'uncertain'; }
        if ( isset( $in_use_index['trash_ids'][ $id ] ) )                  { return 'uncertain'; }

        return 'unused';
    }

    // =========================================================================
    // UNUSED MEDIA: AJAX HANDLERS
    // =========================================================================

    public function ajax_scan_batch() {
        check_ajax_referer( self::NONCE_UNUSED_SCAN, 'nonce' );
        if ( ! current_user_can( 'upload_files' ) ) { wp_send_json_error( 'Permission denied.' ); }

        $offset     = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
        $batch_size = 50;

        // Build both indexes once at the start of the scan; load from transient on later batches.
        if ( $offset === 0 ) {
            $in_use_index = $this->build_in_use_index();
            $theme_index  = $this->build_theme_index();
            set_transient( 'uwgs_in_use_index', $in_use_index, HOUR_IN_SECONDS );
            set_transient( 'uwgs_theme_index',  $theme_index,  HOUR_IN_SECONDS );
        } else {
            $in_use_index = get_transient( 'uwgs_in_use_index' );
            $theme_index  = get_transient( 'uwgs_theme_index' );
            if ( false === $in_use_index ) {
                $in_use_index = $this->build_in_use_index();
                set_transient( 'uwgs_in_use_index', $in_use_index, HOUR_IN_SECONDS );
            }
            if ( false === $theme_index ) {
                $theme_index = $this->build_theme_index();
                set_transient( 'uwgs_theme_index', $theme_index, HOUR_IN_SECONDS );
            }
        }

        $scope = get_option( self::OPTION_UNUSED_SCOPE, 'all' );

        global $wpdb;
        $mime_clause = '';
        if ( $scope === 'images' ) {
            $mime_clause = "AND post_mime_type LIKE 'image/%'";
        }

        $total = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts}
             WHERE post_type = 'attachment' AND post_status = 'inherit' {$mime_clause}"
        );

        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type = 'attachment' AND post_status = 'inherit' {$mime_clause}
             ORDER BY ID ASC LIMIT %d OFFSET %d",
            $batch_size, $offset
        ) );

        foreach ( $ids as $raw_id ) {
            $att_id  = (int) $raw_id;
            $current = get_post_meta( $att_id, self::META_UNUSED_STATUS, true );
            if ( $current === 'excluded' ) { continue; }

            $status = $this->scan_attachment_status( $att_id, $in_use_index, $theme_index );
            update_post_meta( $att_id, self::META_UNUSED_STATUS, $status );

            // Cache file size during scan so we don't re-stat later
            $meta = wp_get_attachment_metadata( $att_id );
            if ( isset( $meta['filesize'] ) ) {
                update_post_meta( $att_id, self::META_UNUSED_FILE_SIZE, (int) $meta['filesize'] );
            }
        }

        $processed = $offset + count( $ids );
        $done      = ( $processed >= $total || count( $ids ) === 0 );

        if ( $done ) {
            update_option( self::OPTION_UNUSED_LAST_SCAN, time() );
            delete_transient( 'uwgs_in_use_index' );
            delete_transient( 'uwgs_theme_index' );
            self::clear_unused_stats_cache();
            $this->log_scan_to_stream();
            $this->set_scan_notice_flags();
        }

        wp_send_json_success( array(
            'processed' => $processed,
            'total'     => $total,
            'done'      => $done,
            'offset'    => $processed,
        ) );
    }

    public function ajax_exclude_attachment() {
        check_ajax_referer( self::NONCE_UNUSED_ACTION, 'nonce' );
        if ( ! current_user_can( 'upload_files' ) ) { wp_send_json_error( 'Permission denied.' ); }

        $id     = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
        $action = isset( $_POST['exclude_action'] ) ? sanitize_key( $_POST['exclude_action'] ) : 'exclude';

        if ( ! $id || 'attachment' !== get_post_type( $id ) ) {
            wp_send_json_error( 'Invalid attachment.' );
        }

        if ( $action === 'unexclude' ) {
            delete_post_meta( $id, self::META_UNUSED_STATUS );
        } else {
            update_post_meta( $id, self::META_UNUSED_STATUS, 'excluded' );
        }

        self::clear_unused_stats_cache();
        wp_send_json_success( array( 'id' => $id, 'action' => $action ) );
    }

    public function ajax_bulk_trash_unused() {
        check_ajax_referer( self::NONCE_UNUSED_ACTION, 'nonce' );
        if ( ! current_user_can( 'delete_posts' ) ) { wp_send_json_error( 'Permission denied.' ); }

        $raw_ids = isset( $_POST['ids'] ) ? (array) $_POST['ids'] : array();
        if ( empty( $raw_ids ) ) { wp_send_json_error( 'No IDs provided.' ); }

        $trashed = array(); $failed = array();
        foreach ( $raw_ids as $raw_id ) {
            $att_id = absint( $raw_id );
            if ( ! $att_id || 'attachment' !== get_post_type( $att_id ) ) { $failed[] = $att_id; continue; }
            if ( ! current_user_can( 'delete_post', $att_id ) ) { $failed[] = $att_id; continue; }

            $result = wp_trash_post( $att_id );
            if ( $result ) {
                $trashed[] = $att_id;
            } else {
                $failed[] = $att_id;
            }
        }

        self::clear_unused_stats_cache();
        wp_send_json_success( array(
            'trashed' => $trashed,
            'failed'  => $failed,
            'counts'  => array( 'trashed' => count( $trashed ), 'failed' => count( $failed ) ),
        ) );
    }

    public function ajax_export_csv() {
        if ( ! isset( $_GET['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['nonce'] ) ), self::NONCE_UNUSED_ACTION ) ) {
            wp_die( 'Security check failed.' );
        }
        if ( ! current_user_can( 'upload_files' ) ) { wp_die( 'Permission denied.' ); }

        $confidence  = isset( $_GET['confidence'] ) ? sanitize_key( $_GET['confidence'] ) : 'all';
        $type_filter = isset( $_GET['media_type'] ) ? sanitize_key( $_GET['media_type'] ) : 'all';

        $results = $this->get_unused_attachments( array(
            'confidence' => $confidence,
            'type'       => $type_filter,
            'per_page'   => 9999,
            'page'       => 1,
        ) );

        header( 'Content-Type: text/csv; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename="unused-media-' . gmdate( 'Y-m-d' ) . '.csv"' );
        header( 'Pragma: no-cache' );

        $out = fopen( 'php://output', 'w' );
        fprintf( $out, chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) ); // UTF-8 BOM for Excel

        fputcsv( $out, array(
            __( 'ID', 'uwgs-alt-text-tool' ),
            __( 'Filename', 'uwgs-alt-text-tool' ),
            __( 'Title', 'uwgs-alt-text-tool' ),
            __( 'MIME Type', 'uwgs-alt-text-tool' ),
            __( 'File Size', 'uwgs-alt-text-tool' ),
            __( 'URL', 'uwgs-alt-text-tool' ),
            __( 'Upload Date', 'uwgs-alt-text-tool' ),
            __( 'Uploaded By', 'uwgs-alt-text-tool' ),
            __( 'Confidence', 'uwgs-alt-text-tool' ),
        ) );

        foreach ( $results['attachments'] as $attachment ) {
            $file_url  = wp_get_attachment_url( $attachment->ID );
            $status    = get_post_meta( $attachment->ID, self::META_UNUSED_STATUS, true );
            $file_size = get_post_meta( $attachment->ID, self::META_UNUSED_FILE_SIZE, true );
            $author    = get_userdata( $attachment->post_author );

            fputcsv( $out, array(
                $attachment->ID,
                $file_url ? basename( $file_url ) : '',
                $attachment->post_title,
                $attachment->post_mime_type,
                $file_size ? size_format( (int) $file_size ) : '',
                $file_url,
                get_the_date( 'Y-m-d', $attachment->ID ),
                $author ? $author->display_name : '',
                $status,
            ) );
        }

        fclose( $out );
        exit;
    }

    public function ajax_dismiss_scan_notice() {
        check_ajax_referer( self::NONCE_UNUSED_ACTION, 'nonce' );
        if ( ! current_user_can( 'upload_files' ) ) { wp_send_json_error( 'Permission denied.' ); }
        update_user_meta( get_current_user_id(), '_uwgs_scan_notice_dismissed', get_option( self::OPTION_UNUSED_LAST_SCAN, 0 ) );
        wp_send_json_success();
    }

    // =========================================================================
    // UNUSED MEDIA: ADMIN NOTICE + STREAM
    // =========================================================================

    public function render_unused_scan_notice() {
        if ( ! current_user_can( 'upload_files' ) ) { return; }
        $last_scan = get_option( self::OPTION_UNUSED_LAST_SCAN, 0 );
        if ( ! $last_scan ) { return; }
        $dismissed = (int) get_user_meta( get_current_user_id(), '_uwgs_scan_notice_dismissed', true );
        if ( $dismissed >= $last_scan ) { return; }

        // Only show on admin pages, not on the unused media page itself
        $screen = get_current_screen();
        if ( $screen && $screen->id === 'media_page_uwgs-unused-media' ) { return; }

        $stats  = $this->get_unused_stats();
        $total  = $stats['unused'] + $stats['uncertain'];
        if ( $total === 0 ) { return; }

        $nonce = wp_create_nonce( self::NONCE_UNUSED_ACTION );
        $url   = admin_url( 'upload.php?page=uwgs-unused-media' );
        ?>
        <div class="notice notice-warning is-dismissible" id="uwgs-scan-notice" data-nonce="<?php echo esc_attr( $nonce ); ?>">
            <p>
                <?php printf(
                    esc_html__( 'Unused media scan (%1$s): %2$s items flagged as potentially unused. %3$s', 'uwgs-alt-text-tool' ),
                    esc_html( wp_date( get_option( 'date_format' ), $last_scan ) ),
                    '<strong>' . esc_html( number_format_i18n( $total ) ) . '</strong>',
                    '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Review unused media →', 'uwgs-alt-text-tool' ) . '</a>'
                ); ?>
            </p>
        </div>
        <script>
        ( function() {
            var notice = document.getElementById( 'uwgs-scan-notice' );
            if ( ! notice ) { return; }
            notice.addEventListener( 'click', function( e ) {
                if ( ! e.target.classList.contains( 'notice-dismiss' ) ) { return; }
                var nonce = notice.dataset.nonce;
                var fd = new FormData();
                fd.append( 'action', 'uwgs_dismiss_scan_notice' );
                fd.append( 'nonce', nonce );
                fetch( <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>, { method: 'POST', body: fd } );
            } );
        } )();
        </script>
        <?php
    }

    private function set_scan_notice_flags() {
        // Reset dismissed flag for all admins so they see the notice on next login
        global $wpdb;
        $wpdb->delete( $wpdb->usermeta, array( 'meta_key' => '_uwgs_scan_notice_dismissed' ), array( '%s' ) );
    }

    private function log_scan_to_stream() {
        if ( ! function_exists( 'do_action' ) ) { return; }
        $stats   = $this->get_unused_stats();
        $unused  = $stats['unused'];
        $uncert  = $stats['uncertain'];
        // Stream log entry — visible in Stream > Activity for all admins
        do_action( 'wp_stream_log_data', null, array(
            'connector' => 'uwgs-media-tool',
            'message'   => sprintf(
                /* translators: 1: unused count, 2: uncertain count */
                __( 'Unused media scan complete: %1$d unused, %2$d uncertain', 'uwgs-alt-text-tool' ),
                $unused, $uncert
            ),
            'args'      => array( 'unused' => $unused, 'uncertain' => $uncert ),
            'object_id' => null,
            'context'   => 'scan',
            'action'    => 'scanned',
        ) );
    }

    // =========================================================================
    // UNUSED MEDIA: ADMIN ASSETS
    // =========================================================================

    private function enqueue_unused_assets() {
        $data = array(
            'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
            'scanNonce'  => wp_create_nonce( self::NONCE_UNUSED_SCAN ),
            'actionNonce'=> wp_create_nonce( self::NONCE_UNUSED_ACTION ),
            'i18n'       => array(
                'scanning'       => __( 'Scanning…', 'uwgs-alt-text-tool' ),
                'scanComplete'   => __( 'Scan complete. Refreshing results…', 'uwgs-alt-text-tool' ),
                'scanError'      => __( 'Scan error. Please try again.', 'uwgs-alt-text-tool' ),
                'trashing'       => __( 'Moving to Trash…', 'uwgs-alt-text-tool' ),
                'trashDone'      => __( '%d items moved to Trash.', 'uwgs-alt-text-tool' ),
                'trashError'     => __( 'Some items could not be trashed.', 'uwgs-alt-text-tool' ),
                'confirmTrash'   => __( 'Move %d item(s) to Trash? You have 30 days to restore them.', 'uwgs-alt-text-tool' ),
                'selectItems'    => __( 'Please select at least one item.', 'uwgs-alt-text-tool' ),
                'excluding'      => __( 'Excluding…', 'uwgs-alt-text-tool' ),
                'excluded'       => __( 'Item excluded from future scans.', 'uwgs-alt-text-tool' ),
                'unexcluded'     => __( 'Exclusion removed.', 'uwgs-alt-text-tool' ),
            ),
        );

        wp_register_script( 'uwgs-unused', plugins_url( 'js/uwgs-unused.js', __FILE__ ), array( 'jquery' ), self::VERSION, true );
        wp_enqueue_script( 'uwgs-unused' );
        wp_add_inline_script( 'uwgs-unused', 'var uwgsUnusedData = ' . wp_json_encode( $data ) . ';', 'before' );
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
    //   - All ACF image fields for missing alt text (via AJAX batch endpoint
    //     uwgs_check_attachments_alt_batch)
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
            'restUrl'       => rest_url( 'uwgs-alt-text/v1/' ),
            'restNonce'     => wp_create_nonce( 'wp_rest' ),
            'i18n'          => array(
                'warningTitle'        => __( '⚠ Accessibility: Images missing alt text', 'uwgs-alt-text-tool' ),
                'warningBodyContent'  => __( 'One or more images in this post are missing alt text. Please go back and add descriptions, or click "Save anyway" if all images are decorative.', 'uwgs-alt-text-tool' ),
                'warningBodyFeatured' => __( 'One or more ACF image fields are missing alt text. Please edit those images in the Media Library and add descriptions, or click "Save anyway" if they are decorative.', 'uwgs-alt-text-tool' ),
                'warningBodyBoth'     => __( 'One or more images and ACF image fields are missing alt text. Please add descriptions before saving, or click "Save anyway" if all images are decorative.', 'uwgs-alt-text-tool' ),
                'saveAnyway'          => __( 'Save anyway', 'uwgs-alt-text-tool' ),
                'goBack'              => __( 'Go back and fix', 'uwgs-alt-text-tool' ),
            ),
        );

        wp_register_script( 'uwgs-stories', plugins_url( 'js/uwgs-stories.js', __FILE__ ), array( 'jquery', 'uwgs-alt-utils' ), self::VERSION, true );
        wp_enqueue_script( 'uwgs-stories' );
        wp_add_inline_script( 'uwgs-stories', 'var uwgsStoriesData = ' . wp_json_encode( $data ) . ';', 'before' );

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

    var $warning    = $( '<div id="uwgs-stories-warning" role="alertdialog" aria-live="assertive" aria-modal="true" aria-labelledby="uwgs-stories-warning-title" aria-describedby="uwgs-stories-warning-body" tabindex="-1">' );
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
        var $body    = $( '<p id="uwgs-stories-warning-body">' ).text( bodyText );
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
        // Prevent background scroll while dialog is open.
        document.body.style.overflow = 'hidden';
        $warning.addClass( 'visible' );
        $goBack[0].focus();
    }

    function hideWarning() {
        $warning.removeClass( 'visible' ).empty();
        document.body.style.overflow = '';
        // Re-enable the save button so the user can retry after Go Back / Escape.
        if ( capturedBtn ) { capturedBtn.disabled = false; }
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
            // Disable the button to block double-clicks / autosave races;
            // restored in hideWarning() on dismiss or below on clean scan.
            btn.disabled = true;
            scanInFlight = true;
            checkAcfImagesMissingAlt( acfIds, function( hasFeatured ) {
                scanInFlight = false;
                if ( hasContent || hasFeatured ) {
                    showWarning( hasContent, hasFeatured );
                    // button stays disabled until hideWarning() re-enables it
                } else {
                    // Clean scan: re-enable before resubmit so ACF sees the
                    // button in its normal state during the save flow.
                    capturedBtn.disabled = false;
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

    }
}

// Clear stats cache when alt text changes
add_action( 'wp_ajax_uwgs_save_alt_text',      array( 'UWGS_Alt_Text_Tool', 'clear_stats_cache' ), 1 );
add_action( 'wp_ajax_uwgs_bulk_save_alt_text', array( 'UWGS_Alt_Text_Tool', 'clear_stats_cache' ), 1 );
add_action( 'edit_attachment',                 array( 'UWGS_Alt_Text_Tool', 'clear_stats_cache' ) );
add_action( 'add_attachment',                  array( 'UWGS_Alt_Text_Tool', 'clear_stats_cache' ) );

add_action( 'plugins_loaded', array( 'UWGS_Alt_Text_Tool', 'init' ) );