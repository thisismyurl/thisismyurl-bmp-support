<?php
/**
 * Plugin Name:       BMP Support by Christopher Ross
 * Plugin URI:        https://thisismyurl.com/thisismyurl-bmp-support/
 * Description:       Enables BMP uploads and non-destructively re-encodes them to a web-safe format (PNG or WebP) with backups, bulk processing, and one-click restoration.
 * Version:           1.6174.1642
 * Author:            Christopher Ross
 * Author URI:        https://thisismyurl.com/
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       thisismyurl-bmp-support
 *
 * The release process stamps the real X.6jjj.hhmm (Toronto) value into the
 * Version: header above, the TIMU_BMP_VERSION constant, and readme.txt Stable
 * tag in one edit. Keep all three identical — a header/constant drift caused a
 * perpetual-update bug on image-support.
 *
 * @package TIMU_BMP_Support
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'TIMU_BMP_VERSION' ) ) {
    define( 'TIMU_BMP_VERSION', '1.6174.1642' );
}

require_once plugin_dir_path( __FILE__ ) . 'includes/class-backup-adapter.php';

class TIMU_BMP_Support {

    const AJAX_NONCE_ACTION = 'timu_bmp_nonce';
    const BACKUP_META_KEY   = '_timu_bmp_original_path';
    const SAVINGS_META_KEY  = '_timu_bmp_savings';
    const CONVERTED_AT_KEY  = '_timu_bmp_converted_at';
    const OPTION_KEY        = 'timu_bmp_support_options';
    const SETTINGS_GROUP    = 'timu_bmp_support_settings';
    const CRON_HOOK         = 'timu_bmp_auto_optimize_event';
    const ENV_OPTION_KEY    = 'timu_bmp_environment_status';
    const ADMIN_TICK_LOCK   = 'timu_bmp_admin_tick_lock';
    const BATCH_BACKUP_LOCK = 'timu_bmp_batch_backup_lock';
    const SOURCE_MIME       = 'image/bmp';

    /**
     * Initialize plugin hooks.
     *
     * @return void
     */
    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'add_admin_menu' ) );
        add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ) );
        add_action( 'admin_notices', array( __CLASS__, 'maybe_show_environment_notice' ) );
        add_action( 'init', array( __CLASS__, 'sync_auto_optimize_schedule' ), 25 );
        add_action( 'admin_init', array( __CLASS__, 'maybe_auto_optimize_on_admin_access' ), 40 );
        add_action( self::CRON_HOOK, array( __CLASS__, 'run_auto_optimize_cron' ) );
        add_filter( 'cron_schedules', array( __CLASS__, 'register_cron_schedules' ) );
        add_filter( 'wp_generate_attachment_metadata', array( __CLASS__, 'maybe_optimize_on_upload' ), 99, 2 );
        add_action( 'wp_ajax_timu_bmp_optimize', array( __CLASS__, 'ajax_bulk_optimize' ) );
        add_action( 'wp_ajax_timu_bmp_process_batch', array( __CLASS__, 'ajax_process_batch' ) );
        add_action( 'wp_ajax_timu_bmp_restore_single', array( __CLASS__, 'ajax_restore_single' ) );
        add_action( 'wp_ajax_timu_bmp_reencode_originals', array( __CLASS__, 'ajax_reencode_originals' ) );
        add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( __CLASS__, 'add_plugin_action_links' ) );

        // Allow BMP uploads even when the host or another plugin has disabled them.
        add_filter( 'upload_mimes', array( __CLASS__, 'allow_bmp_uploads' ) );
        add_filter( 'wp_check_filetype_and_ext', array( __CLASS__, 'allow_real_bmp_filetype' ), 10, 4 );
    }

    /**
     * Register plugin settings.
     *
     * @return void
     */
    public static function register_settings() {
        register_setting(
            self::SETTINGS_GROUP,
            self::OPTION_KEY,
            array(
                'type'              => 'array',
                'sanitize_callback' => array( __CLASS__, 'sanitize_options' ),
                'default'           => self::get_default_options(),
                'autoload'          => false,
                'show_in_rest'      => false,
            )
        );
    }

    /**
     * Allow BMP files through WordPress's upload-mime allow list.
     *
     * @param array $mimes Existing allowed mime types keyed by extension pattern.
     *
     * @return array
     */
    public static function allow_bmp_uploads( $mimes ) {
        $mimes['bmp'] = self::SOURCE_MIME;

        return $mimes;
    }

    /**
     * Keep real BMP files from being rejected by WordPress real-mime sniffing.
     *
     * WordPress fingerprints uploads against finfo, which reports BMP files as
     * image/bmp or image/x-ms-bmp depending on platform. When the sniffed type
     * does not match the extension allow-list value, core nulls out the result
     * and the upload is refused. This guard re-affirms the BMP extension/mime
     * pair when the filename is a .bmp and core could not resolve it.
     *
     * @param array  $data     File data array (ext, type, proper_filename).
     * @param string $file     Full path to the uploaded file.
     * @param string $filename The name of the file.
     * @param array  $mimes    Allowed mime types.
     *
     * @return array
     */
    public static function allow_real_bmp_filetype( $data, $file, $filename, $mimes ) {
        if ( ! empty( $data['ext'] ) && ! empty( $data['type'] ) ) {
            return $data;
        }

        if ( 'bmp' !== strtolower( (string) pathinfo( $filename, PATHINFO_EXTENSION ) ) ) {
            return $data;
        }

        $data['ext']  = 'bmp';
        $data['type'] = self::SOURCE_MIME;

        return $data;
    }

    /**
     * Enqueue admin assets for the tools page.
     *
     * @param string $hook_suffix Current admin page suffix.
     *
     * @return void
     */
    public static function enqueue_admin_assets( $hook_suffix ) {
        if ( 'tools_page_bmp-optimizer' !== $hook_suffix ) {
            return;
        }

        wp_enqueue_script(
            'timu-bmp-support-admin',
            plugin_dir_url( __FILE__ ) . 'assets/js/admin.js',
            array( 'jquery' ),
            TIMU_BMP_VERSION,
            true
        );
    }

    /**
     * Register the Tools submenu page.
     *
     * @return void
     */
    public static function add_admin_menu() {
        add_management_page(
            __( 'BMP Support', 'thisismyurl-bmp-support' ),
            __( 'BMP Support', 'thisismyurl-bmp-support' ),
            'manage_options',
            'bmp-optimizer',
            array( __CLASS__, 'render_admin_page' )
        );
    }

    /**
     * Add Settings and Donate links to plugin row actions.
     *
     * @param array $links Existing plugin row links.
     *
     * @return array
     */
    public static function add_plugin_action_links( $links ) {
        $settings_url = admin_url( 'tools.php?page=bmp-optimizer&tab=settings' );
        $donate_url   = self::get_thisismyurl_link( 'https://github.com/sponsors/thisismyurl', 'plugin_row_donate' );

        $custom_links = array(
            '<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Settings', 'thisismyurl-bmp-support' ) . '</a>',
            '<a href="' . esc_url( $donate_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Sponsor', 'thisismyurl-bmp-support' ) . '</a>',
        );

        return array_merge( $custom_links, $links );
    }

    /**
     * Return default plugin options.
     *
     * @return array
     */
    private static function get_default_options() {
        return array(
            'optimize_target'           => 'png',
            'quality'                   => 82,
            'batch_size'                => 10,
            'auto_optimize_batch'       => 3,
            'delete_backups_uninstall'  => 1,
            'strip_metadata'            => 1,
            'embed_metadata'            => 1,
            'optimize_on_upload'        => 1,
            'auto_optimize_enabled'     => 0,
            'auto_optimize_admin'       => 1,
            'auto_optimize_cron'        => 1,
            'auto_optimize_interval'    => 'hourly',
            'list_per_page'             => 25,
            'report_bandwidth_cost_gb'  => 0.08,
            'report_monthly_image_hits' => 50000,
            'track_outbound_utms'       => 1,
        );
    }

    /**
     * Retrieve plugin options merged with defaults.
     *
     * @return array
     */
    private static function get_options() {
        $saved = get_option( self::OPTION_KEY, array() );
        if ( ! is_array( $saved ) ) {
            $saved = array();
        }

        return wp_parse_args( $saved, self::get_default_options() );
    }

    /**
     * Sanitize plugin options.
     *
     * @param array $input Unsanitized option values.
     *
     * @return array
     */
    public static function sanitize_options( $input ) {
        $defaults = self::get_default_options();
        $input    = is_array( $input ) ? $input : array();

        $target = isset( $input['optimize_target'] ) ? sanitize_key( (string) $input['optimize_target'] ) : $defaults['optimize_target'];
        if ( ! array_key_exists( $target, self::get_target_format_map() ) ) {
            $target = $defaults['optimize_target'];
        }

        $quality = isset( $input['quality'] ) ? absint( $input['quality'] ) : $defaults['quality'];
        $quality = min( 100, max( 0, $quality ) );

        $batch_size = isset( $input['batch_size'] ) ? absint( $input['batch_size'] ) : $defaults['batch_size'];
        $batch_size = min( 100, max( 1, $batch_size ) );

        $auto_batch = isset( $input['auto_optimize_batch'] ) ? absint( $input['auto_optimize_batch'] ) : $defaults['auto_optimize_batch'];
        $auto_batch = min( 25, max( 1, $auto_batch ) );

        $allowed_intervals = array( 'fifteen_minutes', 'hourly', 'twicedaily', 'daily' );
        $interval          = isset( $input['auto_optimize_interval'] ) ? sanitize_key( (string) $input['auto_optimize_interval'] ) : 'hourly';
        if ( ! in_array( $interval, $allowed_intervals, true ) ) {
            $interval = 'hourly';
        }

        $report_cost_gb = isset( $input['report_bandwidth_cost_gb'] ) ? (float) $input['report_bandwidth_cost_gb'] : (float) $defaults['report_bandwidth_cost_gb'];
        $report_cost_gb = min( 10, max( 0, $report_cost_gb ) );

        $report_hits = isset( $input['report_monthly_image_hits'] ) ? absint( $input['report_monthly_image_hits'] ) : (int) $defaults['report_monthly_image_hits'];
        $report_hits = min( 100000000, max( 0, $report_hits ) );

        return array(
            'optimize_target'           => $target,
            'quality'                   => $quality,
            'batch_size'                => $batch_size,
            'auto_optimize_batch'       => $auto_batch,
            'delete_backups_uninstall'  => isset( $input['delete_backups_uninstall'] ) ? 1 : 0,
            'strip_metadata'            => isset( $input['strip_metadata'] ) ? 1 : 0,
            'embed_metadata'            => isset( $input['embed_metadata'] ) ? 1 : 0,
            'optimize_on_upload'        => isset( $input['optimize_on_upload'] ) ? 1 : 0,
            'auto_optimize_enabled'     => isset( $input['auto_optimize_enabled'] ) ? 1 : 0,
            'auto_optimize_admin'       => isset( $input['auto_optimize_admin'] ) ? 1 : 0,
            'auto_optimize_cron'        => isset( $input['auto_optimize_cron'] ) ? 1 : 0,
            'auto_optimize_interval'    => $interval,
            'list_per_page'             => min( 500, max( 5, isset( $input['list_per_page'] ) ? absint( $input['list_per_page'] ) : 25 ) ),
            'report_bandwidth_cost_gb'  => $report_cost_gb,
            'report_monthly_image_hits' => $report_hits,
            'track_outbound_utms'       => isset( $input['track_outbound_utms'] ) ? 1 : 0,
        );
    }

    /**
     * Activation callback. Records environment capability details for admins.
     *
     * @return void
     */
    public static function activate_plugin() {
        $status = array(
            'checked_at'  => time(),
            'has_imagick' => extension_loaded( 'imagick' ) && class_exists( 'Imagick' ),
            'has_gd'      => function_exists( 'gd_info' ),
            'php'         => PHP_VERSION,
            'wp_version'  => get_bloginfo( 'version' ),
        );

        update_option( self::ENV_OPTION_KEY, $status, false );
        set_transient( 'timu_bmp_activation_status', $status, MINUTE_IN_SECONDS * 5 );
    }

    /**
     * Deactivation callback. Clears scheduled events and locks.
     *
     * @return void
     */
    public static function deactivate_plugin() {
        while ( false !== wp_next_scheduled( self::CRON_HOOK ) ) {
            $timestamp = wp_next_scheduled( self::CRON_HOOK );
            if ( false === $timestamp ) {
                break;
            }
            wp_unschedule_event( (int) $timestamp, self::CRON_HOOK );
        }
        delete_transient( self::ADMIN_TICK_LOCK );
    }

    /**
     * Register custom schedules used by background auto optimization.
     *
     * @param array $schedules Existing schedules.
     * @return array
     */
    public static function register_cron_schedules( $schedules ) {
        if ( ! isset( $schedules['fifteen_minutes'] ) ) {
            $schedules['fifteen_minutes'] = array(
                'interval' => 15 * MINUTE_IN_SECONDS,
                'display'  => __( 'Every 15 Minutes', 'thisismyurl-bmp-support' ),
            );
        }

        return $schedules;
    }

    /**
     * Show environment notice after activation or when no image engine exists.
     *
     * @return void
     */
    public static function maybe_show_environment_notice() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $status = get_transient( 'timu_bmp_activation_status' );
        if ( false !== $status ) {
            delete_transient( 'timu_bmp_activation_status' );
        } else {
            $status = get_option( self::ENV_OPTION_KEY, array() );
        }

        if ( empty( $status ) || ! is_array( $status ) ) {
            return;
        }

        $has_imagick = ! empty( $status['has_imagick'] );
        $has_gd      = ! empty( $status['has_gd'] );

        if ( ! $has_imagick && ! $has_gd ) {
            echo '<div class="notice notice-error"><p>';
            echo esc_html__( 'BMP Support requires GD or Imagick. Neither image engine was detected, so conversions cannot run until one is enabled.', 'thisismyurl-bmp-support' );
            echo '</p></div>';
        }
    }

    /**
     * Return whether at least one supported image engine is available.
     *
     * @return bool
     */
    private static function has_supported_image_engine() {
        return ( extension_loaded( 'imagick' ) && class_exists( 'Imagick' ) ) || function_exists( 'gd_info' );
    }

    /**
     * Build a thisismyurl link with optional static, privacy-safe UTM tags.
     *
     * @param string $url      Destination URL.
     * @param string $campaign Campaign identifier.
     * @return string
     */
    private static function get_thisismyurl_link( $url, $campaign ) {
        $options = self::get_options();
        if ( empty( $options['track_outbound_utms'] ) ) {
            return $url;
        }

        return add_query_arg(
            array(
                'utm_source'   => 'wp_plugin',
                'utm_medium'   => 'bmp_support',
                'utm_campaign' => sanitize_key( $campaign ),
            ),
            $url
        );
    }

    /**
     * Keep auto-optimization cron scheduling aligned with plugin settings.
     *
     * @return void
     */
    public static function sync_auto_optimize_schedule() {
        $options         = self::get_options();
        $should_schedule = ! empty( $options['auto_optimize_enabled'] ) && ! empty( $options['auto_optimize_cron'] );

        if ( ! $should_schedule ) {
            while ( false !== wp_next_scheduled( self::CRON_HOOK ) ) {
                $timestamp = wp_next_scheduled( self::CRON_HOOK );
                if ( false === $timestamp ) {
                    break;
                }
                wp_unschedule_event( (int) $timestamp, self::CRON_HOOK );
            }
            return;
        }

        $interval = isset( $options['auto_optimize_interval'] ) ? $options['auto_optimize_interval'] : 'hourly';
        $event    = wp_get_scheduled_event( self::CRON_HOOK );

        if ( $event && isset( $event->schedule ) && $event->schedule !== $interval ) {
            wp_unschedule_event( (int) $event->timestamp, self::CRON_HOOK );
            $event = false;
        }

        if ( ! $event ) {
            wp_schedule_event( time() + MINUTE_IN_SECONDS, $interval, self::CRON_HOOK );
        }
    }

    /**
     * Optimize new uploads right after metadata generation, when enabled.
     *
     * @param array $metadata      Attachment metadata.
     * @param int   $attachment_id Attachment ID.
     * @return array
     */
    public static function maybe_optimize_on_upload( $metadata, $attachment_id ) {
        $options = self::get_options();

        if ( empty( $options['optimize_on_upload'] ) ) {
            return $metadata;
        }

        if ( ! self::has_supported_image_engine() ) {
            return $metadata;
        }

        if ( get_post_meta( $attachment_id, self::BACKUP_META_KEY, true ) ) {
            return $metadata;
        }

        if ( self::SOURCE_MIME !== get_post_mime_type( $attachment_id ) ) {
            return $metadata;
        }

        self::convert_bmp_to_target( (int) $attachment_id, self::get_quality_setting() );

        return $metadata;
    }

    /**
     * Process a small optimization batch when admin pages are accessed.
     *
     * @return void
     */
    public static function maybe_auto_optimize_on_admin_access() {
        if ( ! is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
            return;
        }

        $options = self::get_options();
        if ( empty( $options['auto_optimize_enabled'] ) || empty( $options['auto_optimize_admin'] ) ) {
            return;
        }

        if ( get_transient( self::ADMIN_TICK_LOCK ) ) {
            return;
        }

        set_transient( self::ADMIN_TICK_LOCK, 1, MINUTE_IN_SECONDS * 5 );
        self::run_auto_optimize_batch( 'admin' );
    }

    /**
     * Cron callback for background auto optimization.
     *
     * @return void
     */
    public static function run_auto_optimize_cron() {
        self::run_auto_optimize_batch( 'cron' );
    }

    /**
     * Execute one small auto optimization batch.
     *
     * @param string $context Trigger context.
     * @return void
     */
    private static function run_auto_optimize_batch( $context ) {
        if ( ! self::has_supported_image_engine() ) {
            return;
        }

        $options = self::get_options();
        $limit   = isset( $options['auto_optimize_batch'] ) ? (int) $options['auto_optimize_batch'] : 3;
        $limit   = min( 25, max( 1, $limit ) );

        $query = new WP_Query(
            array(
                'post_type'      => 'attachment',
                'post_status'    => 'inherit',
                'posts_per_page' => $limit,
                'no_found_rows'  => true,
                'orderby'        => 'ID',
                'order'          => 'ASC',
                'post_mime_type' => self::SOURCE_MIME,
                'meta_query'     => array(
                    array(
                        'key'     => self::BACKUP_META_KEY,
                        'compare' => 'NOT EXISTS',
                    ),
                ),
            )
        );

        if ( empty( $query->posts ) ) {
            return;
        }

        TIMU_BMP_Backup_Adapter::snapshot( 'BMP auto optimize', array() );

        foreach ( $query->posts as $post ) {
            self::convert_bmp_to_target( (int) $post->ID, self::get_quality_setting() );
        }
    }

    /**
     * Build reporting metrics for a selected date window.
     *
     * @param string $range_key Date range key.
     * @return array
     */
    private static function get_report_metrics( $range_key ) {
        $now   = time();
        $start = 0;

        switch ( $range_key ) {
            case '30d':
                $start = $now - ( 30 * DAY_IN_SECONDS );
                break;
            case '90d':
                $start = $now - ( 90 * DAY_IN_SECONDS );
                break;
            case '365d':
                $start = $now - ( 365 * DAY_IN_SECONDS );
                break;
            case 'all':
            default:
                $start = 0;
                break;
        }

        $query = new WP_Query(
            array(
                'post_type'      => 'attachment',
                'post_status'    => 'inherit',
                'posts_per_page' => -1,
                'no_found_rows'  => true,
                'post_mime_type' => array_values( self::get_target_format_map() ),
                'meta_query'     => array(
                    array(
                        'key'     => self::BACKUP_META_KEY,
                        'compare' => 'EXISTS',
                    ),
                ),
            )
        );

        $converted_count = 0;
        $bytes_saved     = 0;

        if ( ! empty( $query->posts ) ) {
            foreach ( $query->posts as $post ) {
                $backup_ref = get_post_meta( $post->ID, self::BACKUP_META_KEY, true );
                if ( ! $backup_ref || 'external' === $backup_ref ) {
                    continue;
                }

                $converted_at = (int) get_post_meta( $post->ID, self::CONVERTED_AT_KEY, true );
                if ( $start > 0 && ( $converted_at <= 0 || $converted_at < $start ) ) {
                    continue;
                }

                $converted_count++;
                $bytes_saved += (int) get_post_meta( $post->ID, self::SAVINGS_META_KEY, true );
            }
        }

        $options         = self::get_options();
        $monthly_hits    = isset( $options['report_monthly_image_hits'] ) ? (int) $options['report_monthly_image_hits'] : 0;
        $cost_per_gb     = isset( $options['report_bandwidth_cost_gb'] ) ? (float) $options['report_bandwidth_cost_gb'] : 0.0;
        $avg_saved_bytes = $converted_count > 0 ? ( $bytes_saved / $converted_count ) : 0;
        $gb_per_month    = ( $avg_saved_bytes * $monthly_hits ) / ( 1024 * 1024 * 1024 );
        $monthly_roi     = $gb_per_month * $cost_per_gb;

        return array(
            'range'           => $range_key,
            'converted_count' => $converted_count,
            'bytes_saved'     => $bytes_saved,
            'gb_saved'        => $bytes_saved / ( 1024 * 1024 * 1024 ),
            'avg_saved_kb'    => $avg_saved_bytes / 1024,
            'monthly_hits'    => $monthly_hits,
            'cost_per_gb'     => $cost_per_gb,
            'monthly_roi'     => $monthly_roi,
            'annual_roi'      => $monthly_roi * 12,
        );
    }

    /**
     * Get active conversion quality.
     *
     * @return int
     */
    private static function get_quality_setting() {
        $options = self::get_options();
        return (int) $options['quality'];
    }

    /**
     * Get active processing batch size.
     *
     * @return int
     */
    private static function get_batch_size_setting() {
        $options = self::get_options();
        return (int) $options['batch_size'];
    }

    /**
     * Get the configured optimize-target format key (png or webp).
     *
     * @return string
     */
    private static function get_target_setting() {
        $options = self::get_options();
        $target  = isset( $options['optimize_target'] ) ? (string) $options['optimize_target'] : 'png';

        return array_key_exists( $target, self::get_target_format_map() ) ? $target : 'png';
    }

    /**
     * Map of allowed output-target keys to their mime types and extensions.
     *
     * @return array
     */
    private static function get_target_format_map() {
        return array(
            'png'  => array(
                'mime'      => 'image/png',
                'extension' => 'png',
            ),
            'webp' => array(
                'mime'      => 'image/webp',
                'extension' => 'webp',
            ),
        );
    }

    /**
     * Initialize the WordPress Filesystem API.
     *
     * @return WP_Filesystem_Base|false
     */
    private static function init_fs() {
        global $wp_filesystem;

        if ( empty( $wp_filesystem ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }

        return $wp_filesystem;
    }

    /**
     * Regenerate image metadata after file replacement.
     *
     * @param int    $attachment_id Attachment ID.
     * @param string $absolute_path Absolute file path.
     *
     * @return void
     */
    private static function regenerate_metadata( $attachment_id, $absolute_path ) {
        if ( ! file_exists( $absolute_path ) ) {
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/image.php';

        $metadata = wp_generate_attachment_metadata( $attachment_id, $absolute_path );
        if ( ! is_wp_error( $metadata ) ) {
            wp_update_attachment_metadata( $attachment_id, $metadata );
        }
    }

    /**
     * Replace a file extension while preserving the path.
     *
     * @param string $path      File path.
     * @param string $extension New extension without dot.
     *
     * @return string
     */
    private static function swap_extension( $path, $extension ) {
        return preg_replace( '/\.[^.]+$/', '.' . ltrim( $extension, '.' ), $path );
    }

    /**
     * Build the backup directory path for an attachment.
     *
     * @param int $attachment_id Attachment ID.
     *
     * @return string
     */
    private static function get_backup_dir( $attachment_id ) {
        $upload_dir = wp_upload_dir();
        $rel_path   = get_post_meta( $attachment_id, '_wp_attached_file', true );
        $subdir     = dirname( $rel_path );

        if ( '.' === $subdir ) {
            $subdir = '';
        }

        return trailingslashit( $upload_dir['basedir'] . '/bmp-backups/' . $subdir );
    }

    /**
     * Return lists of pending and managed media items.
     *
     * @return array
     */
    public static function get_media_lists() {
        $target_mimes = array_values(
            array_map(
                static function ( $format ) {
                    return $format['mime'];
                },
                self::get_target_format_map()
            )
        );

        $query = new WP_Query(
            array(
                'post_type'      => 'attachment',
                'post_status'    => 'inherit',
                'posts_per_page' => -1,
                'no_found_rows'  => true,
                'post_mime_type' => array_merge( array( self::SOURCE_MIME ), $target_mimes ),
            )
        );

        $pending = array();
        $media   = array();

        if ( ! empty( $query->posts ) ) {
            foreach ( $query->posts as $post ) {
                $file      = get_attached_file( $post->ID );
                $mime      = get_post_mime_type( $post->ID );
                $orig_path = get_post_meta( $post->ID, self::BACKUP_META_KEY, true );
                $is_source = ( self::SOURCE_MIME === $mime );

                if ( ! $file || ! file_exists( $file ) ) {
                    $post->timu_bmp_status = 'missing';
                    $media[]               = $post;
                    continue;
                }

                if ( $orig_path ) {
                    $media[] = $post;
                } elseif ( $is_source ) {
                    $pending[] = $post;
                } else {
                    $post->timu_bmp_status = 'external';
                    $media[]               = $post;
                }
            }
        }

        return array(
            'pending' => $pending,
            'media'   => $media,
        );
    }

    /**
     * Convert a BMP image to the configured target format and back up the original.
     *
     * @param int $attachment_id Attachment ID.
     * @param int $quality       Output quality (used for WebP target).
     *
     * @return true|WP_Error
     */
    public static function convert_bmp_to_target( $attachment_id, $quality = null ) {
        $fs        = self::init_fs();
        $full_path = get_attached_file( $attachment_id );

        if ( null === $quality ) {
            $quality = self::get_quality_setting();
        }

        if ( ! $fs || ! $full_path || ! $fs->exists( $full_path ) ) {
            return new WP_Error( 'missing', __( 'File does not exist.', 'thisismyurl-bmp-support' ) );
        }

        if ( ! self::has_supported_image_engine() ) {
            return new WP_Error( 'engine', __( 'No supported image engine found. Enable GD or Imagick.', 'thisismyurl-bmp-support' ) );
        }

        $info = wp_getimagesize( $full_path );
        if ( empty( $info['mime'] ) || self::SOURCE_MIME !== $info['mime'] ) {
            return new WP_Error( 'mime', __( 'Only BMP source files can be optimized.', 'thisismyurl-bmp-support' ) );
        }

        $target      = self::get_target_setting();
        $format_map  = self::get_target_format_map();
        $target_mime = $format_map[ $target ]['mime'];
        $target_ext  = $format_map[ $target ]['extension'];

        // Take an extra Vault/Shadow safety snapshot of this file before touching it.
        TIMU_BMP_Backup_Adapter::snapshot( 'BMP optimize #' . $attachment_id, array( $full_path ) );

        $original_size = filesize( $full_path );
        $new_path      = self::swap_extension( $full_path, $target_ext );
        $rel_path      = get_post_meta( $attachment_id, '_wp_attached_file', true );
        $new_rel_path  = self::swap_extension( $rel_path, $target_ext );

        $editor = wp_get_image_editor( $full_path );
        if ( is_wp_error( $editor ) ) {
            return new WP_Error( 'editor', __( 'WordPress image editor could not load this BMP image.', 'thisismyurl-bmp-support' ) );
        }

        if ( 'webp' === $target && method_exists( $editor, 'set_quality' ) ) {
            $editor->set_quality( $quality );
        }

        $saved = $editor->save( $new_path, $target_mime );
        if ( is_wp_error( $saved ) || ! $fs->exists( $new_path ) ) {
            return new WP_Error( 'encode', __( 'Failed to create the optimized image file.', 'thisismyurl-bmp-support' ) );
        }

        $backup_dir = self::get_backup_dir( $attachment_id );
        if ( ! wp_mkdir_p( $backup_dir ) ) {
            $fs->delete( $new_path );
            return new WP_Error( 'mkdir', __( 'Unable to create backup directory.', 'thisismyurl-bmp-support' ) );
        }

        $backup_path = $backup_dir . basename( $full_path );
        if ( ! $fs->move( $full_path, $backup_path, true ) ) {
            $fs->delete( $new_path );
            return new WP_Error( 'move', __( 'Failed to archive the original BMP file.', 'thisismyurl-bmp-support' ) );
        }

        update_post_meta( $attachment_id, self::BACKUP_META_KEY, $backup_path );
        update_post_meta( $attachment_id, self::SAVINGS_META_KEY, max( 0, (int) $original_size - (int) filesize( $new_path ) ) );
        update_post_meta( $attachment_id, self::CONVERTED_AT_KEY, time() );
        update_post_meta( $attachment_id, '_wp_attached_file', $new_rel_path );

        wp_update_post(
            array(
                'ID'             => $attachment_id,
                'post_mime_type' => $target_mime,
            )
        );

        self::regenerate_metadata( $attachment_id, $new_path );
        self::apply_metadata_to_output( $new_path, $attachment_id );

        return true;
    }

    /**
     * Apply metadata operations to a freshly-created output file.
     *
     * Strips harmful embedded data (EXIF, GPS, camera info) and/or writes
     * site-identifying XMP metadata, depending on plugin settings. Both
     * operations require Imagick and are skipped silently when it is absent.
     * Applies to PNG and WebP output alike.
     *
     * @param string $path          Absolute path to the output file.
     * @param int    $attachment_id Attachment ID used to populate XMP fields.
     *
     * @return void
     */
    private static function apply_metadata_to_output( $path, $attachment_id ) {
        if ( ! extension_loaded( 'imagick' ) || ! class_exists( 'Imagick' ) ) {
            return;
        }

        $options = self::get_options();
        $strip   = ! empty( $options['strip_metadata'] );
        $embed   = ! empty( $options['embed_metadata'] );

        if ( ! $strip && ! $embed ) {
            return;
        }

        try {
            $imagick = new \Imagick( $path );

            if ( $strip ) {
                // Removes all EXIF, IPTC, XMP profiles that may contain
                // GPS coordinates, camera serials, or personal details.
                $imagick->stripImage();
            }

            if ( $embed ) {
                $attachment = get_post( $attachment_id );
                $parent     = $attachment ? get_post( $attachment->post_parent ) : null;
                $title      = ( $parent && $parent->post_title ) ? $parent->post_title : get_the_title( $attachment_id );
                $site_name  = get_bloginfo( 'name' );
                $site_url   = home_url();
                $file_url   = (string) wp_get_attachment_url( $attachment_id );

                $xmp = '<?xpacket begin="" id="W5M0MpCehiHzreSzNTczkc9d"?>' . "\n"
                     . '<x:xmpmeta xmlns:x="adobe:ns:meta/" x:xmptk="BMP Support by thisismyurl.com">' . "\n"
                     . ' <rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">' . "\n"
                     . '  <rdf:Description rdf:about="' . htmlspecialchars( $file_url, ENT_XML1, 'UTF-8' ) . '"' . "\n"
                     . '   xmlns:dc="http://purl.org/dc/elements/1.1/"' . "\n"
                     . '   xmlns:xmp="http://ns.adobe.com/xap/1.0/"' . "\n"
                     . '   xmlns:photoshop="http://ns.adobe.com/photoshop/1.0/">' . "\n"
                     . '   <dc:title><rdf:Alt><rdf:li xml:lang="x-default">' . htmlspecialchars( $title, ENT_XML1, 'UTF-8' ) . '</rdf:li></rdf:Alt></dc:title>' . "\n"
                     . '   <dc:creator><rdf:Seq><rdf:li>thisismyurl.com</rdf:li></rdf:Seq></dc:creator>' . "\n"
                     . '   <dc:rights><rdf:Alt><rdf:li xml:lang="x-default">' . htmlspecialchars( $site_name, ENT_XML1, 'UTF-8' ) . '</rdf:li></rdf:Alt></dc:rights>' . "\n"
                     . '   <dc:source>' . htmlspecialchars( $site_url, ENT_XML1, 'UTF-8' ) . '</dc:source>' . "\n"
                     . '   <xmp:CreatorTool>BMP Support by thisismyurl.com</xmp:CreatorTool>' . "\n"
                     . '   <xmp:MetadataDate>' . gmdate( 'c' ) . '</xmp:MetadataDate>' . "\n"
                     . '   <photoshop:Credit>' . htmlspecialchars( $site_name . ' - ' . $site_url, ENT_XML1, 'UTF-8' ) . '</photoshop:Credit>' . "\n"
                     . '  </rdf:Description>' . "\n"
                     . ' </rdf:RDF>' . "\n"
                     . '</x:xmpmeta>' . "\n"
                     . '<?xpacket end="w"?>';

                $imagick->setImageProfile( 'xmp', $xmp );
            }

            $imagick->writeImage( $path );
            $imagick->destroy();
        } catch ( \Exception $e ) {
            if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log( 'timu-bmp-support: metadata error for attachment #' . $attachment_id . ': ' . $e->getMessage() );
            }
        }
    }

    /**
     * Restore an original BMP image from backup.
     *
     * @param int $attachment_id Attachment ID.
     *
     * @return bool
     */
    public static function restore_image( $attachment_id ) {
        $fs          = self::init_fs();
        $backup_path = get_post_meta( $attachment_id, self::BACKUP_META_KEY, true );

        if ( ! $fs || ! $backup_path || 'external' === $backup_path || ! $fs->exists( $backup_path ) ) {
            return false;
        }

        $current_path = get_attached_file( $attachment_id );
        if ( ! $current_path ) {
            return false;
        }

        // Snapshot the current optimized file before it is removed by restore.
        TIMU_BMP_Backup_Adapter::snapshot( 'BMP restore #' . $attachment_id, array( $current_path ) );

        $extension     = strtolower( pathinfo( $backup_path, PATHINFO_EXTENSION ) );
        $restored_path = self::swap_extension( $current_path, $extension );

        if ( ! $fs->move( $backup_path, $restored_path, true ) ) {
            return false;
        }

        if ( $restored_path !== $current_path && $fs->exists( $current_path ) ) {
            $fs->delete( $current_path );
        }

        $rel_path = get_post_meta( $attachment_id, '_wp_attached_file', true );
        $new_rel  = self::swap_extension( $rel_path, $extension );
        update_post_meta( $attachment_id, '_wp_attached_file', $new_rel );

        wp_update_post(
            array(
                'ID'             => $attachment_id,
                'post_mime_type' => self::SOURCE_MIME,
            )
        );

        self::regenerate_metadata( $attachment_id, $restored_path );

        delete_post_meta( $attachment_id, self::BACKUP_META_KEY );
        delete_post_meta( $attachment_id, self::SAVINGS_META_KEY );
        delete_post_meta( $attachment_id, self::CONVERTED_AT_KEY );

        return true;
    }

    /**
     * AJAX callback: convert one BMP image.
     *
     * @return void
     */
    public static function ajax_bulk_optimize() {
        check_ajax_referer( self::AJAX_NONCE_ACTION, 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Unauthorized request.', 'thisismyurl-bmp-support' ) );
        }

        $attachment_id = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;
        if ( ! $attachment_id ) {
            wp_send_json_error( __( 'Invalid attachment ID.', 'thisismyurl-bmp-support' ) );
        }

        $result = self::convert_bmp_to_target( $attachment_id, self::get_quality_setting() );

        if ( true === $result ) {
            wp_send_json_success(
                array(
                    'filename' => basename( (string) get_attached_file( $attachment_id ) ),
                    'thumb'    => wp_get_attachment_image( $attachment_id, array( 50, 50 ) ),
                )
            );
        }

        wp_send_json_error( is_wp_error( $result ) ? $result->get_error_message() : __( 'Unknown error.', 'thisismyurl-bmp-support' ) );
    }

    /**
     * AJAX callback: process a chunk of attachments.
     *
     * @return void
     */
    public static function ajax_process_batch() {
        check_ajax_referer( self::AJAX_NONCE_ACTION, 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Unauthorized request.', 'thisismyurl-bmp-support' ) );
        }

        $batch_limit = self::get_batch_size_setting();
        $ids         = isset( $_POST['attachment_ids'] ) ? (array) wp_unslash( $_POST['attachment_ids'] ) : array();
        $ids         = array_slice( array_values( array_filter( array_map( 'absint', $ids ) ) ), 0, $batch_limit );

        if ( empty( $ids ) ) {
            wp_send_json_error( __( 'No attachments were provided for batch processing.', 'thisismyurl-bmp-support' ) );
        }

        $processed_ids = array();
        $failed_ids    = array();
        $errors        = array();

        // Take one Vault/Shadow safety snapshot per short run window.
        // Re-running full backups for every AJAX chunk can cause long delays/timeouts.
        $backup_lock_key = self::BATCH_BACKUP_LOCK . '_' . get_current_user_id();
        if ( ! get_transient( $backup_lock_key ) ) {
            TIMU_BMP_Backup_Adapter::snapshot( 'BMP batch conversion', array() );
            set_transient( $backup_lock_key, 1, 15 * MINUTE_IN_SECONDS );
        }

        foreach ( $ids as $attachment_id ) {
            $result = self::convert_bmp_to_target( $attachment_id, self::get_quality_setting() );
            if ( true === $result ) {
                $processed_ids[] = $attachment_id;
            } else {
                $failed_ids[] = $attachment_id;
                $errors[]     = is_wp_error( $result ) ? $result->get_error_message() : __( 'Unknown conversion error.', 'thisismyurl-bmp-support' );
            }
        }

        wp_send_json_success(
            array(
                'processed_ids' => $processed_ids,
                'failed_ids'    => $failed_ids,
                'errors'        => array_values( array_unique( $errors ) ),
            )
        );
    }

    /**
     * AJAX callback: restore one optimized image.
     *
     * @return void
     */
    public static function ajax_restore_single() {
        check_ajax_referer( self::AJAX_NONCE_ACTION, 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Unauthorized request.', 'thisismyurl-bmp-support' ) );
        }

        $attachment_id = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;
        if ( ! $attachment_id ) {
            wp_send_json_error( __( 'Invalid attachment ID.', 'thisismyurl-bmp-support' ) );
        }

        if ( self::restore_image( $attachment_id ) ) {
            wp_send_json_success();
        }

        wp_send_json_error( __( 'Image could not be restored.', 'thisismyurl-bmp-support' ) );
    }

    /**
     * AJAX callback: re-encode a batch of already-converted images from their original BMP backups.
     *
     * Accepts a `batch_offset` param (integer). Queries up to 20 attachments that have
     * BACKUP_META_KEY set (i.e. originals are on disk), starting at the given offset.
     * For each attachment: reads the original BMP from the backup path, runs it through
     * the current conversion pipeline (PNG or WebP from settings), and replaces the
     * converted file in the uploads directory.
     *
     * Returns JSON: { processed, skipped, failed, next_offset, done }.
     *
     * @return void
     */
    public static function ajax_reencode_originals() {
        check_ajax_referer( self::AJAX_NONCE_ACTION, 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Unauthorized request.', 'thisismyurl-bmp-support' ) );
        }

        if ( ! self::has_supported_image_engine() ) {
            wp_send_json_error( __( 'No supported image engine found. Enable GD or Imagick.', 'thisismyurl-bmp-support' ) );
        }

        $batch_limit = 20;
        $offset      = isset( $_POST['batch_offset'] ) ? absint( $_POST['batch_offset'] ) : 0;

        $query = new WP_Query(
            array(
                'post_type'      => 'attachment',
                'post_status'    => 'inherit',
                'posts_per_page' => $batch_limit,
                'offset'         => $offset,
                'no_found_rows'  => false,
                'orderby'        => 'ID',
                'order'          => 'ASC',
                'meta_query'     => array(
                    array(
                        'key'     => self::BACKUP_META_KEY,
                        'compare' => 'EXISTS',
                    ),
                    array(
                        'key'     => self::BACKUP_META_KEY,
                        'value'   => 'external',
                        'compare' => '!=',
                    ),
                ),
            )
        );

        $fs        = self::init_fs();
        $processed = 0;
        $skipped   = 0;
        $failed    = 0;
        $quality   = self::get_quality_setting();
        $target    = self::get_target_setting();
        $format_map  = self::get_target_format_map();
        $target_mime = $format_map[ $target ]['mime'];
        $target_ext  = $format_map[ $target ]['extension'];

        if ( ! empty( $query->posts ) ) {
            foreach ( $query->posts as $post ) {
                $attachment_id = (int) $post->ID;
                $backup_path   = get_post_meta( $attachment_id, self::BACKUP_META_KEY, true );

                // Skip if backup path is missing or the file does not exist.
                if ( ! $backup_path || 'external' === $backup_path || ! $fs || ! $fs->exists( $backup_path ) ) {
                    $skipped++;
                    continue;
                }

                // Verify the backup is actually a BMP via imagesize.
                $info = wp_getimagesize( $backup_path );
                if ( false === $info || empty( $info['mime'] ) || self::SOURCE_MIME !== $info['mime'] ) {
                    $skipped++;
                    continue;
                }

                // Load the original BMP through the WP image editor.
                $editor = wp_get_image_editor( $backup_path );
                if ( is_wp_error( $editor ) ) {
                    $failed++;
                    continue;
                }

                if ( 'webp' === $target && method_exists( $editor, 'set_quality' ) ) {
                    $editor->set_quality( $quality );
                }

                // Determine where the converted file currently lives.
                $current_path = get_attached_file( $attachment_id );
                if ( ! $current_path ) {
                    $failed++;
                    continue;
                }

                // Build the destination path using the target extension.
                $new_path = self::swap_extension( $current_path, $target_ext );
                $saved    = $editor->save( $new_path, $target_mime );

                if ( is_wp_error( $saved ) || ! $fs->exists( $new_path ) ) {
                    $failed++;
                    continue;
                }

                // If the target extension changed (e.g. was PNG, now re-encoding to WebP),
                // remove the old converted file and update post meta.
                if ( $new_path !== $current_path && $fs->exists( $current_path ) ) {
                    $fs->delete( $current_path );
                }

                $rel_path     = get_post_meta( $attachment_id, '_wp_attached_file', true );
                $new_rel_path = self::swap_extension( (string) $rel_path, $target_ext );

                update_post_meta( $attachment_id, '_wp_attached_file', $new_rel_path );
                update_post_meta( $attachment_id, self::CONVERTED_AT_KEY, time() );
                update_post_meta( $attachment_id, self::SAVINGS_META_KEY, max( 0, (int) filesize( $backup_path ) - (int) filesize( $new_path ) ) );

                wp_update_post(
                    array(
                        'ID'             => $attachment_id,
                        'post_mime_type' => $target_mime,
                    )
                );

                self::regenerate_metadata( $attachment_id, $new_path );
                self::apply_metadata_to_output( $new_path, $attachment_id );

                $processed++;
            }
        }

        $total_managed = (int) $query->found_posts;
        $next_offset   = $offset + $batch_limit;
        $done          = ( $next_offset >= $total_managed ) || ( 0 === count( $query->posts ) );

        wp_send_json_success(
            array(
                'processed'   => $processed,
                'skipped'     => $skipped,
                'failed'      => $failed,
                'next_offset' => $next_offset,
                'done'        => $done,
            )
        );
    }

    /**
     * Render the admin page.
     *
     * @return void
     */
    public static function render_admin_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'thisismyurl-bmp-support' ) );
        }

        $allowed_tabs = array( 'optimize', 'settings', 'report' );
        $active_tab   = isset( $_GET['tab'] ) ? sanitize_key( (string) $_GET['tab'] ) : 'optimize'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ! in_array( $active_tab, $allowed_tabs, true ) ) {
            $active_tab = 'optimize';
        }

        $lists       = self::get_media_lists();
        $options     = self::get_options();
        $pending_ids = array_map(
            static function ( $post ) {
                return (int) $post->ID;
            },
            $lists['pending']
        );
        $restorable  = array();

        foreach ( $lists['media'] as $post ) {
            $orig = get_post_meta( $post->ID, self::BACKUP_META_KEY, true );
            if ( $orig && 'external' !== $orig ) {
                $restorable[] = (int) $post->ID;
            }
        }

        wp_add_inline_script(
            'timu-bmp-support-admin',
            'window.TIMUBmpSupportData = ' . wp_json_encode(
                array(
                    'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
                    'nonce'      => wp_create_nonce( self::AJAX_NONCE_ACTION ),
                    'actions'    => array(
                        'batch'    => 'timu_bmp_process_batch',
                        'restore'  => 'timu_bmp_restore_single',
                        'reencode' => 'timu_bmp_reencode_originals',
                    ),
                    'batchSize'  => self::get_batch_size_setting(),
                    'perPage'    => (int) $options['list_per_page'],
                    'pendingIds' => $pending_ids,
                    'strings'    => array(
                        'processing'          => __( 'Processing...', 'thisismyurl-bmp-support' ),
                        'restoring'           => __( 'Restoring...', 'thisismyurl-bmp-support' ),
                        'confirmRestoreAll'   => __( 'Restore all images? This cannot be undone.', 'thisismyurl-bmp-support' ),
                        'failedPrefix'        => __( 'Some images failed:', 'thisismyurl-bmp-support' ),
                        'reEncoding'          => __( 'Re-encoding...', 'thisismyurl-bmp-support' ),
                        'confirmReEncode'     => __( 'Re-encode all converted images from their original BMP backups using the current format setting? Existing converted files will be replaced.', 'thisismyurl-bmp-support' ),
                        'reEncodeComplete'    => __( 'Re-encode complete.', 'thisismyurl-bmp-support' ),
                        'reEncodeProgress'    => __( 'Re-encoding from originals', 'thisismyurl-bmp-support' ),
                    ),
                )
            ) . ';',
            'before'
        );

        $base_url        = admin_url( 'tools.php?page=bmp-optimizer' );
        $optimize_url    = $base_url . '&tab=optimize';
        $settings_url    = $base_url . '&tab=settings';
        $report_url      = $base_url . '&tab=report';
        $thisismyurl_url = self::get_thisismyurl_link( 'https://thisismyurl.com/', 'plugin_header' );
        $donate_url      = self::get_thisismyurl_link( 'https://github.com/sponsors/thisismyurl', 'plugin_sidebar_donate' );
        $target_label    = 'webp' === self::get_target_setting() ? __( 'WebP', 'thisismyurl-bmp-support' ) : __( 'PNG', 'thisismyurl-bmp-support' );

        ?>
        <div class="wrap">
            <h1>
                <?php esc_html_e( 'BMP Support', 'thisismyurl-bmp-support' ); ?>
                <span style="font-size:0.5em;font-weight:normal;vertical-align:middle;margin-left:10px;color:#646970;">
                    <?php
                    echo wp_kses_post(
                        sprintf(
                            /* translators: %s: link to thisismyurl.com */
                            __( 'by %s', 'thisismyurl-bmp-support' ),
                            '<a href="' . esc_url( $thisismyurl_url ) . '" target="_blank" rel="noopener noreferrer" style="text-decoration:none;color:inherit;">thisismyurl.com</a>'
                        )
                    );
                    ?>
                </span>
            </h1>

            <nav class="nav-tab-wrapper wp-clearfix">
                <a href="<?php echo esc_url( $optimize_url ); ?>" class="nav-tab<?php echo 'optimize' === $active_tab ? ' nav-tab-active' : ''; ?>">
                    <?php esc_html_e( 'Optimize', 'thisismyurl-bmp-support' ); ?>
                    <?php if ( ! empty( $pending_ids ) ) : ?>
                        <span class="awaiting-mod" style="margin-left:4px;"><?php echo esc_html( count( $pending_ids ) ); ?></span>
                    <?php endif; ?>
                </a>
                <a href="<?php echo esc_url( $settings_url ); ?>" class="nav-tab<?php echo 'settings' === $active_tab ? ' nav-tab-active' : ''; ?>">
                    <?php esc_html_e( 'Settings', 'thisismyurl-bmp-support' ); ?>
                </a>
                <a href="<?php echo esc_url( $report_url ); ?>" class="nav-tab<?php echo 'report' === $active_tab ? ' nav-tab-active' : ''; ?>">
                    <?php esc_html_e( 'Report', 'thisismyurl-bmp-support' ); ?>
                </a>
            </nav>

            <?php if ( 'optimize' === $active_tab ) : ?>

            <div id="poststuff">
                <div id="post-body" class="metabox-holder columns-2">
                    <div id="post-body-content">

                        <div class="postbox">
                            <h2 class="hndle"><span><?php esc_html_e( 'Optimization Dashboard', 'thisismyurl-bmp-support' ); ?></span></h2>
                            <div class="inside">
                                <div style="padding:10px 0;min-height:80px;">
                                    <div class="fwo-controls" style="display:flex;gap:10px;align-items:center;">
                                        <button id="btn-start" class="button button-primary button-large" <?php disabled( empty( $pending_ids ) ); ?>>
                                            <?php
                                            printf(
                                                /* translators: 1: number of pending images, 2: target format label */
                                                esc_html__( 'Optimize All %1$d Images to %2$s', 'thisismyurl-bmp-support' ),
                                                count( $pending_ids ),
                                                esc_html( $target_label )
                                            );
                                            ?>
                                        </button>
                                        <button id="btn-cancel" class="button button-secondary button-large" style="display:none;color:#d63638;">
                                            <?php esc_html_e( 'Cancel Batch', 'thisismyurl-bmp-support' ); ?>
                                        </button>
                                    </div>
                                    <div id="fwo-progress-container" style="display:none;margin-top:20px;background:#f0f0f1;height:30px;position:relative;border-radius:4px;overflow:hidden;border:1px solid #c3c4c7;">
                                        <div id="fwo-progress-bar" style="background:#2271b1;height:100%;width:0%;transition:width 0.2s;"></div>
                                        <div id="fwo-progress-text" style="position:absolute;width:100%;text-align:center;top:0;line-height:30px;font-weight:bold;color:#fff;mix-blend-mode:difference;">0%</div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="postbox">
                            <h2 class="hndle"><span><?php esc_html_e( 'Pending Optimizations', 'thisismyurl-bmp-support' ); ?> (<span id="p-cnt"><?php echo esc_html( count( $pending_ids ) ); ?></span>)</span></h2>
                            <div class="inside">
                                <table class="widefat striped" id="fwo-pending-table" style="border:none;box-shadow:none;">
                                    <thead>
                                        <tr>
                                            <th><?php esc_html_e( 'Preview', 'thisismyurl-bmp-support' ); ?></th>
                                            <th><?php esc_html_e( 'ID', 'thisismyurl-bmp-support' ); ?></th>
                                            <th><?php esc_html_e( 'File Name', 'thisismyurl-bmp-support' ); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if ( ! empty( $lists['pending'] ) ) : ?>
                                            <?php foreach ( $lists['pending'] as $post ) : ?>
                                                <tr id="fwo-row-<?php echo esc_attr( $post->ID ); ?>">
                                                    <td><?php echo wp_kses_post( wp_get_attachment_image( $post->ID, array( 50, 50 ) ) ); ?></td>
                                                    <td>#<?php echo esc_html( $post->ID ); ?></td>
                                                    <td><?php echo esc_html( basename( (string) get_attached_file( $post->ID ) ) ); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else : ?>
                                            <tr class="no-images"><td colspan="3"><?php esc_html_e( 'All BMP images optimized!', 'thisismyurl-bmp-support' ); ?></td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div class="postbox">
                            <h2 class="hndle"><span><?php esc_html_e( 'Managed Media', 'thisismyurl-bmp-support' ); ?> (<span id="m-cnt"><?php echo esc_html( count( $lists['media'] ) ); ?></span>)</span></h2>
                            <div class="inside">
                                <table class="widefat striped" id="fwo-media-table" style="border:none;box-shadow:none;">
                                    <thead>
                                        <tr>
                                            <th><?php esc_html_e( 'Preview', 'thisismyurl-bmp-support' ); ?></th>
                                            <th><?php esc_html_e( 'ID', 'thisismyurl-bmp-support' ); ?></th>
                                            <th><?php esc_html_e( 'File Name', 'thisismyurl-bmp-support' ); ?></th>
                                            <th><?php esc_html_e( 'Action', 'thisismyurl-bmp-support' ); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ( $lists['media'] as $post ) : ?>
                                            <?php
                                            $orig   = get_post_meta( $post->ID, self::BACKUP_META_KEY, true );
                                            $status = isset( $post->timu_bmp_status ) ? $post->timu_bmp_status : '';
                                            ?>
                                            <tr id="fwo-media-row-<?php echo esc_attr( $post->ID ); ?>">
                                                <td><?php echo wp_kses_post( wp_get_attachment_image( $post->ID, array( 50, 50 ) ) ); ?></td>
                                                <td>#<?php echo esc_html( $post->ID ); ?></td>
                                                <td><?php echo esc_html( basename( (string) get_attached_file( $post->ID ) ) ); ?></td>
                                                <td>
                                                    <?php if ( 'missing' === $status ) : ?>
                                                        <span style="color:#d63638;"><?php esc_html_e( 'File Missing', 'thisismyurl-bmp-support' ); ?></span>
                                                    <?php elseif ( 'external' === $status ) : ?>
                                                        <span class="description"><?php esc_html_e( 'Not converted here', 'thisismyurl-bmp-support' ); ?></span>
                                                    <?php elseif ( $orig && 'external' !== $orig ) : ?>
                                                        <button class="restore-btn button button-small" data-id="<?php echo esc_attr( $post->ID ); ?>">
                                                            <?php esc_html_e( 'Restore', 'thisismyurl-bmp-support' ); ?>
                                                        </button>
                                                    <?php else : ?>
                                                        <span class="description"><?php esc_html_e( 'Optimized', 'thisismyurl-bmp-support' ); ?></span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                    </div><!-- #post-body-content -->

                    <div id="postbox-container-1" class="postbox-container">
                        <div class="postbox">
                            <h2 class="hndle"><span><?php esc_html_e( 'About', 'thisismyurl-bmp-support' ); ?></span></h2>
                            <div class="inside">
                                <p><?php esc_html_e( 'Enables BMP uploads and re-encodes them to a web-safe format (PNG or WebP) using the WordPress image editor (GD or Imagick). Originals are backed up and can be restored any time.', 'thisismyurl-bmp-support' ); ?></p>
                                <?php if ( ! empty( $restorable ) ) : ?>
                                    <hr />
                                    <p><strong><?php esc_html_e( 'Bulk Actions', 'thisismyurl-bmp-support' ); ?></strong></p>
                                    <button id="btn-restore-all" class="button button-secondary" style="width:100%;text-align:center;margin-bottom:6px;" data-ids="<?php echo esc_attr( wp_json_encode( $restorable ) ); ?>">
                                        <?php esc_html_e( 'Restore All Originals', 'thisismyurl-bmp-support' ); ?>
                                    </button>
                                    <button id="btn-reencode-originals" class="button button-secondary" style="width:100%;text-align:center;" data-count="<?php echo esc_attr( count( $restorable ) ); ?>">
                                        <?php
                                        printf(
                                            /* translators: %s: target format label (PNG or WebP) */
                                            esc_html__( 'Re-encode from Originals to %s', 'thisismyurl-bmp-support' ),
                                            esc_html( $target_label )
                                        );
                                        ?>
                                    </button>
                                    <p id="timu-reencode-status" class="description" style="display:none;margin-top:6px;"></p>
                                <?php endif; ?>
                                <hr />
                                <p>
                                    <?php
                                    echo wp_kses_post(
                                        sprintf(
                                            /* translators: %s: link to thisismyurl.com */
                                            __( 'Provided free by %s.', 'thisismyurl-bmp-support' ),
                                            '<a href="' . esc_url( $thisismyurl_url ) . '" target="_blank" rel="noopener noreferrer">thisismyurl.com</a>'
                                        )
                                    );
                                    ?>
                                </p>
                                <p><a href="<?php echo esc_url( $donate_url ); ?>" class="button button-secondary" target="_blank" rel="noopener noreferrer" style="width:100%;text-align:center;"><?php esc_html_e( 'Donate to Development', 'thisismyurl-bmp-support' ); ?></a></p>
                            </div>
                        </div>
                    </div><!-- #postbox-container-1 -->

                </div><!-- #post-body -->
            </div><!-- #poststuff -->

            <?php elseif ( 'settings' === $active_tab ) : /* settings tab */ ?>

            <div id="poststuff" style="padding-top:10px;">
                <div id="post-body" class="metabox-holder columns-1">
                    <div id="post-body-content">

                        <div class="postbox">
                            <h2 class="hndle"><span><?php esc_html_e( 'Conversion Settings', 'thisismyurl-bmp-support' ); ?></span></h2>
                            <div class="inside">
                                <form method="post" action="options.php">
                                    <?php settings_fields( self::SETTINGS_GROUP ); ?>
                                    <table class="form-table" role="presentation">
                                        <tr>
                                            <th scope="row"><label for="timu-optimize-target"><?php esc_html_e( 'Optimize Target', 'thisismyurl-bmp-support' ); ?></label></th>
                                            <td>
                                                <select id="timu-optimize-target" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[optimize_target]">
                                                    <option value="png" <?php selected( 'png', $options['optimize_target'] ); ?>><?php esc_html_e( 'PNG — lossless, universally supported (recommended)', 'thisismyurl-bmp-support' ); ?></option>
                                                    <option value="webp" <?php selected( 'webp', $options['optimize_target'] ); ?>><?php esc_html_e( 'WebP — smaller files, broad modern support', 'thisismyurl-bmp-support' ); ?></option>
                                                </select>
                                                <p class="description"><?php esc_html_e( 'Format that BMP uploads are re-encoded to. PNG is lossless and the safe default; WebP produces smaller files and uses the quality setting below.', 'thisismyurl-bmp-support' ); ?></p>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th scope="row"><label for="timu-quality"><?php esc_html_e( 'WebP Quality', 'thisismyurl-bmp-support' ); ?></label></th>
                                            <td>
                                                <input id="timu-quality" type="number" min="0" max="100" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[quality]" value="<?php echo esc_attr( $options['quality'] ); ?>" class="small-text" />
                                                <p class="description"><?php esc_html_e( 'Compression quality from 0 (smallest file) to 100 (best quality). Used only when the target is WebP. Default: 82.', 'thisismyurl-bmp-support' ); ?></p>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th scope="row"><label for="timu-batch-size"><?php esc_html_e( 'Batch Size', 'thisismyurl-bmp-support' ); ?></label></th>
                                            <td>
                                                <input id="timu-batch-size" type="number" min="1" max="100" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[batch_size]" value="<?php echo esc_attr( $options['batch_size'] ); ?>" class="small-text" />
                                                <p class="description"><?php esc_html_e( 'Images processed per AJAX request. Lower this if you see timeouts. Default: 10.', 'thisismyurl-bmp-support' ); ?></p>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th scope="row"><?php esc_html_e( 'Optimize on Upload', 'thisismyurl-bmp-support' ); ?></th>
                                            <td>
                                                <label>
                                                    <input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[optimize_on_upload]" value="1" <?php checked( ! empty( $options['optimize_on_upload'] ) ); ?> />
                                                    <?php esc_html_e( 'Automatically re-encode BMP uploads to the target format right after upload.', 'thisismyurl-bmp-support' ); ?>
                                                </label>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th scope="row"><?php esc_html_e( 'Auto Optimize', 'thisismyurl-bmp-support' ); ?></th>
                                            <td>
                                                <fieldset>
                                                    <label style="display:block;margin-bottom:6px;">
                                                        <input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[auto_optimize_enabled]" value="1" <?php checked( ! empty( $options['auto_optimize_enabled'] ) ); ?> />
                                                        <?php esc_html_e( 'Enable automatic background optimization for pending BMP images.', 'thisismyurl-bmp-support' ); ?>
                                                    </label>
                                                    <label style="display:block;margin-bottom:6px;">
                                                        <input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[auto_optimize_admin]" value="1" <?php checked( ! empty( $options['auto_optimize_admin'] ) ); ?> />
                                                        <?php esc_html_e( 'Run a small optimization batch during wp-admin page visits.', 'thisismyurl-bmp-support' ); ?>
                                                    </label>
                                                    <label style="display:block;margin-bottom:10px;">
                                                        <input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[auto_optimize_cron]" value="1" <?php checked( ! empty( $options['auto_optimize_cron'] ) ); ?> />
                                                        <?php esc_html_e( 'Run optimization in WP-Cron.', 'thisismyurl-bmp-support' ); ?>
                                                    </label>
                                                    <p>
                                                        <label for="timu-auto-batch" style="margin-right:8px;"><?php esc_html_e( 'Images per auto run:', 'thisismyurl-bmp-support' ); ?></label>
                                                        <input id="timu-auto-batch" type="number" min="1" max="25" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[auto_optimize_batch]" value="<?php echo esc_attr( $options['auto_optimize_batch'] ); ?>" class="small-text" />
                                                    </p>
                                                    <p>
                                                        <label for="timu-auto-interval" style="margin-right:8px;"><?php esc_html_e( 'WP-Cron interval:', 'thisismyurl-bmp-support' ); ?></label>
                                                        <select id="timu-auto-interval" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[auto_optimize_interval]">
                                                            <option value="fifteen_minutes" <?php selected( 'fifteen_minutes', $options['auto_optimize_interval'] ); ?>><?php esc_html_e( 'Every 15 minutes', 'thisismyurl-bmp-support' ); ?></option>
                                                            <option value="hourly" <?php selected( 'hourly', $options['auto_optimize_interval'] ); ?>><?php esc_html_e( 'Hourly', 'thisismyurl-bmp-support' ); ?></option>
                                                            <option value="twicedaily" <?php selected( 'twicedaily', $options['auto_optimize_interval'] ); ?>><?php esc_html_e( 'Twice Daily', 'thisismyurl-bmp-support' ); ?></option>
                                                            <option value="daily" <?php selected( 'daily', $options['auto_optimize_interval'] ); ?>><?php esc_html_e( 'Daily', 'thisismyurl-bmp-support' ); ?></option>
                                                        </select>
                                                    </p>
                                                    <p class="description"><?php esc_html_e( 'Enable one or both triggers: admin traffic, cron, or both.', 'thisismyurl-bmp-support' ); ?></p>
                                                </fieldset>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th scope="row"><?php esc_html_e( 'Strip Harmful Metadata', 'thisismyurl-bmp-support' ); ?></th>
                                            <td>
                                                <fieldset>
                                                    <label>
                                                        <input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[strip_metadata]" value="1" <?php checked( ! empty( $options['strip_metadata'] ) ); ?> />
                                                        <?php esc_html_e( 'Remove EXIF, GPS, camera model, and other embedded data from converted files.', 'thisismyurl-bmp-support' ); ?>
                                                    </label>
                                                    <p class="description">
                                                        <?php esc_html_e( 'Recommended. Strips data that may expose device details, shooting location, or personal information. Requires Imagick.', 'thisismyurl-bmp-support' ); ?>
                                                        <?php if ( ! extension_loaded( 'imagick' ) ) : ?>
                                                            <br><strong style="color:#d63638;"><?php esc_html_e( 'Imagick is not available on this server &#8212; this setting will have no effect.', 'thisismyurl-bmp-support' ); ?></strong>
                                                        <?php endif; ?>
                                                    </p>
                                                </fieldset>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th scope="row"><?php esc_html_e( 'Embed Site Metadata', 'thisismyurl-bmp-support' ); ?></th>
                                            <td>
                                                <fieldset>
                                                    <label>
                                                        <input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[embed_metadata]" value="1" <?php checked( ! empty( $options['embed_metadata'] ) ); ?> />
                                                        <?php esc_html_e( 'Write site name, URL, and page title into each converted file as XMP metadata.', 'thisismyurl-bmp-support' ); ?>
                                                    </label>
                                                    <p class="description">
                                                        <?php
                                                        echo wp_kses_post(
                                                            sprintf(
                                                                /* translators: %s: link to thisismyurl.com */
                                                                __( 'Includes a <code>dc:creator</code> tag crediting %s. Requires Imagick.', 'thisismyurl-bmp-support' ),
                                                                '<a href="' . esc_url( self::get_thisismyurl_link( 'https://thisismyurl.com/', 'embed_metadata_help' ) ) . '" target="_blank" rel="noopener noreferrer">thisismyurl.com</a>'
                                                            )
                                                        );
                                                        ?>
                                                        <?php if ( ! extension_loaded( 'imagick' ) ) : ?>
                                                            <br><strong style="color:#d63638;"><?php esc_html_e( 'Imagick is not available on this server &#8212; this setting will have no effect.', 'thisismyurl-bmp-support' ); ?></strong>
                                                        <?php endif; ?>
                                                    </p>
                                                </fieldset>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th scope="row"><label for="timu-per-page"><?php esc_html_e( 'Items Per Page', 'thisismyurl-bmp-support' ); ?></label></th>
                                            <td>
                                                <input id="timu-per-page" type="number" min="5" max="500" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[list_per_page]" value="<?php echo esc_attr( $options['list_per_page'] ); ?>" class="small-text" />
                                                <p class="description"><?php esc_html_e( 'How many images to show per page in the Pending and Managed Media lists. Default: 25.', 'thisismyurl-bmp-support' ); ?></p>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th scope="row"><?php esc_html_e( 'Report Assumptions', 'thisismyurl-bmp-support' ); ?></th>
                                            <td>
                                                <p>
                                                    <label for="timu-monthly-hits" style="display:inline-block;min-width:240px;"><?php esc_html_e( 'Estimated monthly image requests', 'thisismyurl-bmp-support' ); ?></label>
                                                    <input id="timu-monthly-hits" type="number" min="0" max="100000000" step="1" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[report_monthly_image_hits]" value="<?php echo esc_attr( $options['report_monthly_image_hits'] ); ?>" class="regular-text" style="max-width:180px;" />
                                                </p>
                                                <p>
                                                    <label for="timu-cost-gb" style="display:inline-block;min-width:240px;"><?php esc_html_e( 'Bandwidth cost per GB (USD)', 'thisismyurl-bmp-support' ); ?></label>
                                                    <input id="timu-cost-gb" type="number" min="0" max="10" step="0.01" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[report_bandwidth_cost_gb]" value="<?php echo esc_attr( $options['report_bandwidth_cost_gb'] ); ?>" class="regular-text" style="max-width:180px;" />
                                                </p>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th scope="row"><?php esc_html_e( 'Outbound UTM Parameters', 'thisismyurl-bmp-support' ); ?></th>
                                            <td>
                                                <label>
                                                    <input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[track_outbound_utms]" value="1" <?php checked( ! empty( $options['track_outbound_utms'] ) ); ?> />
                                                    <?php esc_html_e( 'Add privacy-safe UTM parameters to links to thisismyurl.com.', 'thisismyurl-bmp-support' ); ?>
                                                </label>
                                                <p class="description"><?php esc_html_e( 'These UTMs include no site IDs, account IDs, user IDs, visitor data, or domain names. They only identify this plugin as the traffic source.', 'thisismyurl-bmp-support' ); ?></p>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th scope="row"><?php esc_html_e( 'On Uninstall', 'thisismyurl-bmp-support' ); ?></th>
                                            <td>
                                                <label>
                                                    <input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[delete_backups_uninstall]" value="1" <?php checked( ! empty( $options['delete_backups_uninstall'] ) ); ?> />
                                                    <?php esc_html_e( 'Delete all backup files when the plugin is uninstalled.', 'thisismyurl-bmp-support' ); ?>
                                                </label>
                                                <p class="description"><?php esc_html_e( 'Leave unchecked if you want to keep originals in the backup directory even after removing the plugin.', 'thisismyurl-bmp-support' ); ?></p>
                                            </td>
                                        </tr>
                                    </table>

                                    <?php submit_button( __( 'Save Settings', 'thisismyurl-bmp-support' ) ); ?>
                                </form>
                            </div>
                        </div>

                    </div><!-- #post-body-content -->
                </div><!-- #post-body -->
            </div><!-- #poststuff -->

            <?php else : /* report tab */ ?>

            <?php
            $report_range = isset( $_GET['range'] ) ? sanitize_key( (string) $_GET['range'] ) : '30d'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            if ( ! in_array( $report_range, array( '30d', '90d', '365d', 'all' ), true ) ) {
                $report_range = '30d';
            }
            $report_data = self::get_report_metrics( $report_range );
            ?>

            <div id="poststuff" style="padding-top:10px;">
                <div id="post-body" class="metabox-holder columns-1">
                    <div id="post-body-content">
                        <div class="postbox">
                            <h2 class="hndle"><span><?php esc_html_e( 'Business ROI Report', 'thisismyurl-bmp-support' ); ?></span></h2>
                            <div class="inside">
                                <p class="description"><?php esc_html_e( 'Use these metrics to show the measurable value this plugin has provided over business-friendly time windows.', 'thisismyurl-bmp-support' ); ?></p>
                                <p>
                                    <a class="button <?php echo '30d' === $report_range ? 'button-primary' : 'button-secondary'; ?>" href="<?php echo esc_url( add_query_arg( array( 'tab' => 'report', 'range' => '30d' ), $base_url ) ); ?>"><?php esc_html_e( 'Last 30 days', 'thisismyurl-bmp-support' ); ?></a>
                                    <a class="button <?php echo '90d' === $report_range ? 'button-primary' : 'button-secondary'; ?>" href="<?php echo esc_url( add_query_arg( array( 'tab' => 'report', 'range' => '90d' ), $base_url ) ); ?>"><?php esc_html_e( 'Last 90 days', 'thisismyurl-bmp-support' ); ?></a>
                                    <a class="button <?php echo '365d' === $report_range ? 'button-primary' : 'button-secondary'; ?>" href="<?php echo esc_url( add_query_arg( array( 'tab' => 'report', 'range' => '365d' ), $base_url ) ); ?>"><?php esc_html_e( 'Last 12 months', 'thisismyurl-bmp-support' ); ?></a>
                                    <a class="button <?php echo 'all' === $report_range ? 'button-primary' : 'button-secondary'; ?>" href="<?php echo esc_url( add_query_arg( array( 'tab' => 'report', 'range' => 'all' ), $base_url ) ); ?>"><?php esc_html_e( 'All time', 'thisismyurl-bmp-support' ); ?></a>
                                </p>

                                <table class="widefat striped" style="max-width:960px;">
                                    <tbody>
                                        <tr>
                                            <th style="width:340px;"><?php esc_html_e( 'Images Optimized in Period', 'thisismyurl-bmp-support' ); ?></th>
                                            <td><?php echo esc_html( number_format_i18n( (int) $report_data['converted_count'] ) ); ?></td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'Total Bandwidth Saved (if each image is requested once)', 'thisismyurl-bmp-support' ); ?></th>
                                            <td><?php echo esc_html( size_format( (int) $report_data['bytes_saved'], 2 ) ); ?></td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'Average Savings per Image', 'thisismyurl-bmp-support' ); ?></th>
                                            <td><?php echo esc_html( number_format_i18n( (float) $report_data['avg_saved_kb'], 2 ) . ' KB' ); ?></td>
                                        </tr>
                                        <tr>
                                            <th><?php esc_html_e( 'Estimated Monthly ROI', 'thisismyurl-bmp-support' ); ?></th>
                                            <td>
                                                <?php
                                                echo esc_html(
                                                    sprintf(
                                                        /* translators: 1: monthly savings, 2: annual savings */
                                                        __( '$%1$s / month (about $%2$s / year)', 'thisismyurl-bmp-support' ),
                                                        number_format_i18n( (float) $report_data['monthly_roi'], 2 ),
                                                        number_format_i18n( (float) $report_data['annual_roi'], 2 )
                                                    )
                                                );
                                                ?>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                                <p class="description" style="margin-top:10px;">
                                    <?php
                                    echo esc_html(
                                        sprintf(
                                            /* translators: 1: image hit count, 2: cost per GB */
                                            __( 'ROI estimate uses %1$s image requests/month and $%2$s bandwidth cost per GB from your settings.', 'thisismyurl-bmp-support' ),
                                            number_format_i18n( (int) $report_data['monthly_hits'] ),
                                            number_format_i18n( (float) $report_data['cost_per_gb'], 2 )
                                        )
                                    );
                                    ?>
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <?php endif; ?>

        </div><!-- .wrap -->
        <?php
    }
}

register_activation_hook( __FILE__, array( 'TIMU_BMP_Support', 'activate_plugin' ) );
register_deactivation_hook( __FILE__, array( 'TIMU_BMP_Support', 'deactivate_plugin' ) );

TIMU_BMP_Support::init();

// github-updater.php is excluded from directory submissions.
// Updates are delivered through the WordPress.org plugin repository.
