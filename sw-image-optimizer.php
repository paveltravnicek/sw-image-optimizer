<?php
/*
Plugin Name: Optimalizace obrázků
Description: Automatické zmenšování obrázků, bezpečnější správa náhledů, přegenerování metadata a interní monitoring velikosti webu.
Version: 1.1
Author: Smart Websites
Author URI: https://smart-websites.cz
Update URI: https://github.com/paveltravnicek/sw-image-optimizer/
Text Domain: sw-image-optimizer
SW Plugin: yes
SW Service Type: passive
SW License Group: both
*/

if (!defined('ABSPATH')) {
    exit;
}

require __DIR__ . '/plugin-update-checker/plugin-update-checker.php';

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$swUpdateChecker = PucFactory::buildUpdateChecker(
	'https://github.com/paveltravnicek/sw-image-optimizer/',
	__FILE__,
	'sw-image-optimizer'
);

$swUpdateChecker->setBranch('main');
$swUpdateChecker->getVcsApi()->enableReleaseAssets('/\.zip$/i');

final class SW_Image_Optimizer {
    const VERSION = '1.1';
    const LICENSE_OPTION = 'swio_license';
    const LICENSE_CRON_HOOK = 'swio_license_daily_check';
    const HUB_BASE = 'https://smart-websites.cz';
    const PLUGIN_SLUG = 'sw-image-optimizer';
    const OPTION_SETTINGS = 'swio_settings';
    const OPTION_LOGS = 'swio_logs';
    const OPTION_NOTICE = 'swio_admin_notice';
    const CRON_SIZE_HOOK = 'swio_check_site_size_weekly';
    const CRON_LOGS_HOOK = 'swio_cleanup_logs_daily';
    const NONCE_ACTION = 'swio_admin_action';
    const PAGE_SLUG = 'sw-image-optimizer';
    const ALERT_EMAIL = 'pavel@travnicek.online';
    const ALERT_THRESHOLD_MB = 1024;
    const LOG_RETENTION_DAYS = 30;
    const DEFAULT_BATCH_SIZE = 50;
    const DETAIL_LIMIT = 20;
    const HTACCESS_MARKER = 'SWIO_MISSING_THUMBNAILS';

    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_filter('cron_schedules', [$this, 'register_cron_schedules']);
        add_action('admin_menu', [$this, 'register_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_ajax_swio_run_action', [$this, 'ajax_run_action']);
        add_action('wp_ajax_swio_get_logs', [$this, 'ajax_get_logs']);
        add_filter('wp_handle_upload_prefilter', [$this, 'prefilter_upload']);
        add_filter('wp_handle_upload', [$this, 'handle_uploaded_image']);
        add_filter('intermediate_image_sizes_advanced', [$this, 'filter_intermediate_image_sizes']);
        add_action(self::CRON_SIZE_HOOK, [$this, 'check_and_email_site_size']);
        add_action(self::CRON_LOGS_HOOK, [$this, 'cleanup_old_logs']);
        add_action(self::LICENSE_CRON_HOOK, [$this, 'cron_refresh_plugin_license']);

        if (is_admin()) {
            add_filter('plugin_action_links_' . plugin_basename(__FILE__), [$this, 'add_plugin_action_links']);
            add_action('admin_post_swio_verify_license', [$this, 'handle_verify_license']);
            add_action('admin_post_swio_remove_license', [$this, 'handle_remove_license']);
            add_action('admin_init', [$this, 'maybe_refresh_plugin_license']);
            add_action('admin_init', [$this, 'block_direct_deactivate']);
        }
    }

    public static function activate() {
        $instance = self::instance();
        if (!wp_next_scheduled(self::CRON_SIZE_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'weekly', self::CRON_SIZE_HOOK);
        }
        if (!wp_next_scheduled(self::CRON_LOGS_HOOK)) {
            wp_schedule_event(time() + 2 * HOUR_IN_SECONDS, 'daily', self::CRON_LOGS_HOOK);
        }
        if (!wp_next_scheduled(self::LICENSE_CRON_HOOK)) {
            wp_schedule_event(time() + 3 * HOUR_IN_SECONDS, 'twicedaily', self::LICENSE_CRON_HOOK);
        }
        if (!get_option(self::OPTION_SETTINGS)) {
            add_option(self::OPTION_SETTINGS, $instance->get_default_settings());
        }
    }

    public static function deactivate() {
        wp_clear_scheduled_hook(self::CRON_SIZE_HOOK);
        wp_clear_scheduled_hook(self::CRON_LOGS_HOOK);
        wp_clear_scheduled_hook(self::LICENSE_CRON_HOOK);
    }


    public function cron_refresh_plugin_license() {
        $this->refresh_plugin_license('cron');
    }

    private function default_license_state(): array {
        return [
            'key' => '',
            'status' => 'missing',
            'type' => '',
            'valid_to' => '',
            'domain' => '',
            'message' => '',
            'last_check' => 0,
            'last_success' => 0,
        ];
    }

    private function get_license_state(): array {
        $state = get_option(self::LICENSE_OPTION, []);
        if (!is_array($state)) {
            $state = [];
        }
        return wp_parse_args($state, $this->default_license_state());
    }

    private function update_license_state(array $data): void {
        $current = $this->get_license_state();
        $new = array_merge($current, $data);
        $new['key'] = sanitize_text_field((string) ($new['key'] ?? ''));
        $new['status'] = sanitize_key((string) ($new['status'] ?? 'missing'));
        $new['type'] = sanitize_key((string) ($new['type'] ?? ''));
        $new['valid_to'] = sanitize_text_field((string) ($new['valid_to'] ?? ''));
        $new['domain'] = sanitize_text_field((string) ($new['domain'] ?? ''));
        $new['message'] = sanitize_text_field((string) ($new['message'] ?? ''));
        $new['last_check'] = (int) ($new['last_check'] ?? 0);
        $new['last_success'] = (int) ($new['last_success'] ?? 0);
        update_option(self::LICENSE_OPTION, $new, false);
    }

    private function get_management_context(): array {
        $guard_present = function_exists('sw_guard_get_service_state');
        $management_status = $guard_present ? (string) get_option('swg_management_status', 'NONE') : 'NONE';
        $service_state = $guard_present ? (string) sw_guard_get_service_state(self::PLUGIN_SLUG) : 'off';
        $guard_last_success = $guard_present ? (int) get_option('swg_last_success_ts', 0) : 0;
        $connected_recently = $guard_last_success > 0 && (time() - $guard_last_success) <= (8 * DAY_IN_SECONDS);

        return [
            'guard_present' => $guard_present,
            'management_status' => $management_status,
            'service_state' => in_array($service_state, ['active', 'passive', 'off'], true) ? $service_state : 'off',
            'guard_last_success' => $guard_last_success,
            'connected_recently' => $connected_recently,
            'is_active' => $guard_present && $connected_recently && $management_status === 'ACTIVE' && $service_state === 'active',
        ];
    }

    private function has_active_standalone_license(): bool {
        $license = $this->get_license_state();
        return $license['key'] !== '' && $license['status'] === 'active' && $license['type'] === 'plugin_single';
    }

    private function plugin_is_operational(): bool {
        $management = $this->get_management_context();
        if ($management['is_active']) {
            return true;
        }
        return $this->has_active_standalone_license();
    }

    public function add_plugin_action_links($links) {
        $url = admin_url('tools.php?page=' . self::PAGE_SLUG);
        array_unshift($links, '<a href="' . esc_url($url) . '">' . esc_html__('Nastavení', 'sw-image-optimizer') . '</a>');

        $management = $this->get_management_context();
        if ($management['is_active']) {
            unset($links['deactivate']);
        }

        return $links;
    }


    public function register_cron_schedules($schedules) {
        if (!isset($schedules['weekly'])) {
            $schedules['weekly'] = [
                'interval' => WEEK_IN_SECONDS,
                'display'  => __('Jednou týdně', 'sw-image-optimizer'),
            ];
        }
        return $schedules;
    }

    private function get_default_settings() {
        return [
            'enabled'                    => 1,
            'max_dimension'              => 2000,
            'jpeg_quality'               => 70,
            'batch_size'                 => self::DEFAULT_BATCH_SIZE,
            'disable_sizes_mode'         => 'all',
            'disabled_sizes'             => [],
            'process_jpg'                => 1,
            'process_png'                => 1,
            'process_webp'               => 1,
            'process_avif'               => 1,
            'block_extreme_uploads'      => 0,
            'extreme_dimension_limit'    => 6000,
            'extreme_filesize_limit_mb'  => 15,
            'update_htaccess_fallback'   => 1,
        ];
    }

    private function get_settings() {
        $settings = get_option(self::OPTION_SETTINGS, []);
        return wp_parse_args($settings, $this->get_default_settings());
    }

    public function register_settings() {
        register_setting('swio_settings_group', self::OPTION_SETTINGS, [$this, 'sanitize_settings']);
    }

    public function sanitize_settings($input) {
        if (!$this->plugin_is_operational()) {
            $this->add_notice('warning', 'Bez platné licence je nastavení pouze pro čtení.');
            return $this->get_settings();
        }

        $defaults = $this->get_default_settings();
        $sanitized = [];
        $sanitized['enabled'] = empty($input['enabled']) ? 0 : 1;
        $sanitized['max_dimension'] = max(300, min(6000, absint($input['max_dimension'] ?? $defaults['max_dimension'])));
        $sanitized['jpeg_quality'] = max(30, min(95, absint($input['jpeg_quality'] ?? $defaults['jpeg_quality'])));
        $sanitized['batch_size'] = max(10, min(200, absint($input['batch_size'] ?? $defaults['batch_size'])));

        $allowed_modes = ['none', 'selected', 'all'];
        $mode = sanitize_key($input['disable_sizes_mode'] ?? $defaults['disable_sizes_mode']);
        $sanitized['disable_sizes_mode'] = in_array($mode, $allowed_modes, true) ? $mode : $defaults['disable_sizes_mode'];

        $disabled_sizes = $input['disabled_sizes'] ?? [];
        $sanitized['disabled_sizes'] = array_values(array_filter(array_map('sanitize_key', (array) $disabled_sizes)));

        $sanitized['process_jpg'] = empty($input['process_jpg']) ? 0 : 1;
        $sanitized['process_png'] = empty($input['process_png']) ? 0 : 1;
        $sanitized['process_webp'] = empty($input['process_webp']) ? 0 : 1;
        $sanitized['process_avif'] = empty($input['process_avif']) ? 0 : 1;

        $sanitized['block_extreme_uploads'] = empty($input['block_extreme_uploads']) ? 0 : 1;
        $sanitized['extreme_dimension_limit'] = max(1000, min(20000, absint($input['extreme_dimension_limit'] ?? $defaults['extreme_dimension_limit'])));
        $sanitized['extreme_filesize_limit_mb'] = max(1, min(200, absint($input['extreme_filesize_limit_mb'] ?? $defaults['extreme_filesize_limit_mb'])));
        $sanitized['update_htaccess_fallback'] = empty($input['update_htaccess_fallback']) ? 0 : 1;

        return $sanitized;
    }

    public function enqueue_assets($hook) {
        if ($hook !== 'tools_page_' . self::PAGE_SLUG) {
            return;
        }

        $css_version = file_exists(plugin_dir_path(__FILE__) . 'assets/admin.css')
            ? (string) filemtime(plugin_dir_path(__FILE__) . 'assets/admin.css')
            : self::VERSION;
        $js_version = file_exists(plugin_dir_path(__FILE__) . 'assets/admin.js')
            ? (string) filemtime(plugin_dir_path(__FILE__) . 'assets/admin.js')
            : self::VERSION;

        wp_enqueue_style('swio-admin', plugin_dir_url(__FILE__) . 'assets/admin.css', [], $css_version);
        wp_enqueue_script('swio-admin', plugin_dir_url(__FILE__) . 'assets/admin.js', [], $js_version, true);
        wp_localize_script('swio-admin', 'swioAdmin', [
            'ajaxUrl'        => admin_url('admin-ajax.php'),
            'nonce'          => wp_create_nonce(self::NONCE_ACTION),
            'logsNonce'      => wp_create_nonce(self::NONCE_ACTION),
            'globalNoticeId' => 'swio-global-notices',
        ]);
    }

    public function register_admin_menu() {
        add_submenu_page(
            'tools.php',
            'Optimalizace obrázků',
            'Optimalizace obrázků',
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'render_admin_page']
        );
    }

    public function filter_intermediate_image_sizes($sizes) {
        if (!$this->plugin_is_operational()) {
            return $sizes;
        }

        $settings = $this->get_settings();
        $mode = $settings['disable_sizes_mode'];

        if ($mode === 'all') {
            return [];
        }

        if ($mode === 'selected' && !empty($settings['disabled_sizes'])) {
            foreach ($settings['disabled_sizes'] as $size_name) {
                unset($sizes[$size_name]);
            }
        }

        return $sizes;
    }

    public function prefilter_upload($file) {
        if (!$this->plugin_is_operational()) {
            return $file;
        }

        $settings = $this->get_settings();
        if (empty($settings['block_extreme_uploads'])) {
            return $file;
        }

        $type = $file['type'] ?? '';
        if (!$this->is_supported_mime_enabled($type, $settings)) {
            return $file;
        }

        $max_filesize_bytes = ((int) $settings['extreme_filesize_limit_mb']) * MB_IN_BYTES;
        $size = isset($file['size']) ? (int) $file['size'] : 0;
        if ($size > $max_filesize_bytes) {
            $file['error'] = sprintf(
                'Nahrání bylo zablokováno. Soubor má %s MB, limit je %s MB.',
                size_format($size, 2),
                (int) $settings['extreme_filesize_limit_mb']
            );
            return $file;
        }

        $tmp_name = $file['tmp_name'] ?? '';
        if ($tmp_name && is_file($tmp_name)) {
            $dimensions = @getimagesize($tmp_name);
            if (!empty($dimensions[0]) && !empty($dimensions[1])) {
                $max_side = max((int) $dimensions[0], (int) $dimensions[1]);
                if ($max_side > (int) $settings['extreme_dimension_limit']) {
                    $file['error'] = sprintf(
                        'Nahrání bylo zablokováno. Obrázek má maximální rozměr %d px, limit je %d px.',
                        $max_side,
                        (int) $settings['extreme_dimension_limit']
                    );
                }
            }
        }

        return $file;
    }

    public function handle_uploaded_image($upload) {
        if (!$this->plugin_is_operational()) {
            return $upload;
        }

        if (empty($upload['file']) || empty($upload['type'])) {
            return $upload;
        }

        $settings = $this->get_settings();
        if (empty($settings['enabled'])) {
            return $upload;
        }

        $file_path = $upload['file'];
        $mime_type = $upload['type'];

        if (!$this->is_supported_mime_enabled($mime_type, $settings)) {
            return $upload;
        }

        $result = $this->resize_image_if_needed($file_path, (int) $settings['max_dimension'], (int) $settings['jpeg_quality']);
        if ($result['changed']) {
            $this->log('info', sprintf('Automaticky zmenšen nový obrázek: %s (%spx → %spx).', basename($file_path), $result['before_max'], $result['after_max']));
        }

        return $upload;
    }

    private function is_supported_mime_enabled($mime_type, $settings) {
    if (in_array($mime_type, ['image/jpeg', 'image/jpg'], true)) {
        return !empty($settings['process_jpg']);
    }
    if ($mime_type === 'image/png') {
        return !empty($settings['process_png']);
    }
    if ($mime_type === 'image/webp') {
        return !empty($settings['process_webp']);
    }
    if ($mime_type === 'image/avif') {
        return !empty($settings['process_avif']);
    }
    return false;
}

    private function resize_image_if_needed($file_path, $max_dimension, $jpeg_quality) {
        $response = [
            'changed'    => false,
            'before_max' => 0,
            'after_max'  => 0,
            'error'      => '',
        ];

        if (!file_exists($file_path)) {
            $response['error'] = 'Soubor neexistuje.';
            return $response;
        }

        $editor = wp_get_image_editor($file_path);
        if (is_wp_error($editor)) {
            $response['error'] = $editor->get_error_message();
            return $response;
        }

        $size = $editor->get_size();
        if (empty($size['width']) || empty($size['height'])) {
            $response['error'] = 'Nepodařilo se zjistit rozměry obrázku.';
            return $response;
        }

        $response['before_max'] = max((int) $size['width'], (int) $size['height']);

        if ($size['width'] <= $max_dimension && $size['height'] <= $max_dimension) {
            $response['after_max'] = $response['before_max'];
            return $response;
        }

        if (method_exists($editor, 'set_quality')) {
            $editor->set_quality($jpeg_quality);
        }

        $editor->resize($max_dimension, $max_dimension, false);
        $saved = $editor->save($file_path);

        if (is_wp_error($saved)) {
            $response['error'] = $saved->get_error_message();
            return $response;
        }

        $response['changed'] = true;
        $response['after_max'] = max((int) ($saved['width'] ?? 0), (int) ($saved['height'] ?? 0));
        return $response;
    }

    public function check_and_email_site_size() {
        $size_data = $this->get_site_size_report();
        $size_mb = $size_data['wp_content_mb'];

        if ($size_mb > self::ALERT_THRESHOLD_MB) {
            $subject = sprintf('Varování: %s překročil %d GB', wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES), 1);
            $message = "Web: " . home_url('/') . "\n"
                . "Název: " . wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES) . "\n"
                . "Velikost wp-content: {$size_mb} MB\n"
                . "Uploads: {$size_data['uploads_mb']} MB\n"
                . "Cache: {$size_data['cache_mb']} MB\n"
                . "Zálohy: {$size_data['backups_mb']} MB\n";

            wp_mail(self::ALERT_EMAIL, $subject, $message);
            $this->log('warning', sprintf('Odesláno interní upozornění na velikost webu (%s MB).', $size_mb));
        }
    }

    private function get_directory_size_mb($directory) {
        if (empty($directory) || !is_dir($directory)) {
            return 0;
        }

        $size = 0;
        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $size += $file->getSize();
                }
            }
        } catch (UnexpectedValueException $e) {
            // ignore inaccessible directories
        }

        return round($size / 1024 / 1024, 2);
    }

    private function get_site_size_report() {
        $uploads = wp_get_upload_dir();
        $uploads_dir = $uploads['basedir'] ?? '';
        $wp_content_dir = WP_CONTENT_DIR;
        $cache_dir = WP_CONTENT_DIR . '/cache';
        $backups_dirs = [
            WP_CONTENT_DIR . '/ai1wm-backups',
            WP_CONTENT_DIR . '/updraft',
            WP_CONTENT_DIR . '/backups',
            WP_CONTENT_DIR . '/backupbuddy_backups',
        ];

        $backups_size = 0;
        foreach ($backups_dirs as $dir) {
            $backups_size += $this->get_directory_size_mb($dir);
        }

        return [
            'wp_content_mb' => $this->get_directory_size_mb($wp_content_dir),
            'uploads_mb'    => $this->get_directory_size_mb($uploads_dir),
            'cache_mb'      => $this->get_directory_size_mb($cache_dir),
            'backups_mb'    => round($backups_size, 2),
        ];
    }

    private function get_registered_image_sizes() {
        global $_wp_additional_image_sizes;

        $default_sizes = ['thumbnail', 'medium', 'medium_large', 'large', '1536x1536', '2048x2048'];
        $all_sizes = get_intermediate_image_sizes();
        $output = [];

        foreach ($all_sizes as $size_name) {
            $width = 0;
            $height = 0;
            $crop = false;

            if (in_array($size_name, $default_sizes, true)) {
                $width  = (int) get_option($size_name . '_size_w');
                $height = (int) get_option($size_name . '_size_h');
                $crop   = (bool) get_option($size_name . '_crop');
            } elseif (isset($_wp_additional_image_sizes[$size_name])) {
                $width  = (int) ($_wp_additional_image_sizes[$size_name]['width'] ?? 0);
                $height = (int) ($_wp_additional_image_sizes[$size_name]['height'] ?? 0);
                $crop   = !empty($_wp_additional_image_sizes[$size_name]['crop']);
            }

            $output[$size_name] = [
                'width'  => $width,
                'height' => $height,
                'crop'   => $crop,
            ];
        }

        return $output;
    }

    private function get_image_attachment_ids($limit = 50, $offset = 0) {
        return get_posts([
            'post_type'      => 'attachment',
            'post_mime_type' => 'image',
            'post_status'    => 'inherit',
            'fields'         => 'ids',
            'posts_per_page' => $limit,
            'offset'         => $offset,
            'orderby'        => 'ID',
            'order'          => 'ASC',
        ]);
    }

    private function get_total_attachment_count() {
        global $wpdb;
        return (int) $wpdb->get_var("SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%' AND post_status = 'inherit'");
    }

    
private function get_supported_image_extensions() {
    return ['jpg', 'jpeg', 'png', 'webp', 'avif'];
}

private function get_image_files_in_uploads($limit = 50, $offset = 0) {
    $uploads = wp_get_upload_dir();
    $base_dir = $uploads['basedir'] ?? '';
    if (!$base_dir || !is_dir($base_dir)) {
        return [];
    }

    $supported = $this->get_supported_image_extensions();
    $files = [];

    try {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base_dir, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            if (in_array(strtolower((string) $file->getExtension()), $supported, true)) {
                $files[] = $file->getPathname();
            }
        }
    } catch (UnexpectedValueException $e) {
        return [];
    }

    sort($files, SORT_NATURAL | SORT_FLAG_CASE);
    return array_slice($files, $offset, $limit);
}

private function get_total_upload_image_file_count() {
    $uploads = wp_get_upload_dir();
    $base_dir = $uploads['basedir'] ?? '';
    if (!$base_dir || !is_dir($base_dir)) {
        return 0;
    }

    $count = 0;
    $supported = $this->get_supported_image_extensions();

    try {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base_dir, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && in_array(strtolower((string) $file->getExtension()), $supported, true)) {
                $count++;
            }
        }
    } catch (UnexpectedValueException $e) {
        return 0;
    }

    return $count;
}

private function detect_file_mime_type($file_path) {
    if (!$file_path || !file_exists($file_path)) {
        return '';
    }

    $checked = wp_check_filetype_and_ext($file_path, $file_path);
    if (!empty($checked['type'])) {
        return (string) $checked['type'];
    }

    $by_name = wp_check_filetype($file_path);
    if (!empty($by_name['type'])) {
        return (string) $by_name['type'];
    }

    if (function_exists('mime_content_type')) {
        $mime = @mime_content_type($file_path);
        if (is_string($mime) && strpos($mime, 'image/') === 0) {
            return $mime;
        }
    }

    if (function_exists('exif_imagetype')) {
        $type = @exif_imagetype($file_path);
        $map = [
            IMAGETYPE_JPEG => 'image/jpeg',
            IMAGETYPE_PNG  => 'image/png',
        ];
        if (defined('IMAGETYPE_WEBP')) {
            $map[IMAGETYPE_WEBP] = 'image/webp';
        }
        if (defined('IMAGETYPE_AVIF')) {
            $map[IMAGETYPE_AVIF] = 'image/avif';
        }
        if (!empty($map[$type])) {
            return $map[$type];
        }
    }

    $size = @getimagesize($file_path);
    if (!empty($size['mime']) && strpos((string) $size['mime'], 'image/') === 0) {
        return (string) $size['mime'];
    }

    $ext = strtolower((string) pathinfo($file_path, PATHINFO_EXTENSION));
    $map = [
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'webp' => 'image/webp',
        'avif' => 'image/avif',
    ];

    return $map[$ext] ?? '';
}

private function get_attachment_processing_files($attachment_id) {
    $files = [];
    $file = get_attached_file($attachment_id);
    if ($file) {
        $files[] = $file;
    }

    $meta = wp_get_attachment_metadata($attachment_id);
    if (!empty($meta['sizes']) && is_array($meta['sizes']) && $file) {
        $base_dir = trailingslashit(dirname($file));
        foreach ($meta['sizes'] as $size_data) {
            if (!empty($size_data['file'])) {
                $files[] = $base_dir . $size_data['file'];
            }
        }
    }

    $files = array_values(array_unique(array_filter($files, static function($path) {
        return is_string($path) && $path !== '';
    })));

    return $files;
}

private function get_legitimate_upload_files_map() {
    $ids = get_posts([
        'post_type'      => 'attachment',
        'post_mime_type' => 'image',
        'post_status'    => 'inherit',
        'fields'         => 'ids',
        'posts_per_page' => -1,
        'orderby'        => 'ID',
        'order'          => 'ASC',
    ]);

    $map = [];
    foreach ($ids as $attachment_id) {
        foreach ($this->get_attachment_processing_files((int) $attachment_id) as $file_path) {
            $relative = $this->normalize_upload_relative_path($file_path);
            $map[$relative] = (int) $attachment_id;
        }
    }

    return $map;
}

private function classify_untracked_upload_file($file_path, array $legitimate_map = []) {
    $relative = $this->normalize_upload_relative_path($file_path);
    if (isset($legitimate_map[$relative])) {
        return 'legitimate';
    }

    $filename = strtolower((string) basename($file_path));
    $extension = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
    $stem = strtolower((string) pathinfo($filename, PATHINFO_FILENAME));

    $looks_resized = (bool) preg_match('/-\d+x\d+$/', $stem);
    $looks_hashed = (bool) preg_match('/^[a-f0-9]{24,}$/', preg_replace('/-\d+x\d+$/', '', $stem));
    $is_modern_format = in_array($extension, ['webp', 'avif'], true);

    if ($looks_resized || $looks_hashed || $is_modern_format) {
        return 'external';
    }

    return 'manual';
}

private function create_batch_response($action, $offset, $processed, $changed, $finished, $label, $details = [], $errors = [], $stats = []) {
        return [
            'action'      => $action,
            'offset'      => $offset,
            'processed'   => $processed,
            'changed'     => $changed,
            'next_offset' => $offset + $processed,
            'finished'    => (bool) $finished,
            'label'       => $label,
            'details'     => array_values($details),
            'errors'      => array_values($errors),
            'stats'       => $stats,
        ];
    }

    
private function resize_existing_images_batch($offset = 0) {
    $settings = $this->get_settings();
    $batch_size = (int) $settings['batch_size'];
    $ids = $this->get_image_attachment_ids($batch_size, $offset);
    $processed = 0;
    $resized = 0;
    $details = [];
    $errors = [];
    $skipped = 0;
    $total = $this->get_total_attachment_count();

    foreach ($ids as $attachment_id) {
        $processed++;
        $files = $this->get_attachment_processing_files((int) $attachment_id);

        if (empty($files)) {
            $skipped++;
            if (count($details) < self::DETAIL_LIMIT) {
                $details[] = $this->get_attachment_label((int) $attachment_id) . ' — přeskočeno, příloha nemá dohledatelné originální soubory ani standardní velikosti WordPressu.';
            }
            continue;
        }

        foreach ($files as $file_path) {
            if (!file_exists($file_path) || !is_readable($file_path)) {
                $errors[] = $this->normalize_upload_relative_path($file_path) . ' — chyba: soubor neexistuje nebo není čitelný.';
                continue;
            }

            $mime = $this->detect_file_mime_type($file_path);
            if (!$this->is_supported_mime_enabled($mime, $settings)) {
                $skipped++;
                if (count($details) < self::DETAIL_LIMIT) {
                    $details[] = $this->normalize_upload_relative_path($file_path) . ' — přeskočeno, formát není v nastavení pluginu povolený.';
                }
                continue;
            }

            $result = $this->resize_image_if_needed($file_path, (int) $settings['max_dimension'], (int) $settings['jpeg_quality']);
            if (!empty($result['error'])) {
                $errors[] = $this->normalize_upload_relative_path($file_path) . ' — chyba: ' . $result['error'];
                continue;
            }

            if ($result['changed']) {
                $resized++;
                if (count($details) < self::DETAIL_LIMIT) {
                    $details[] = sprintf('%s — zmenšeno z %spx na %spx.', $this->normalize_upload_relative_path($file_path), $result['before_max'], $result['after_max']);
                }
            } else {
                $skipped++;
                if (count($details) < self::DETAIL_LIMIT) {
                    $details[] = sprintf('%s — ponecháno, rozměr %spx je už v limitu.', $this->normalize_upload_relative_path($file_path), $result['before_max']);
                }
            }
        }
    }

    $finished = $processed < $batch_size || ($offset + $processed) >= $total;
    $summary = sprintf('Dávka změny rozměrů: zkontrolováno %d příloh, zmenšeno %d souborů, přeskočeno %d, chyby %d.', $processed, $resized, $skipped, count($errors));
    $this->log('info', $summary);

    return $this->create_batch_response('resize_existing', $offset, $processed, $resized, $finished, 'Změna rozměrů existujících obrázků', $details, $errors, [
        'checked' => $processed,
        'changed' => $resized,
        'skipped' => $skipped,
        'errors'  => count($errors),
        'total'   => $total,
        'why'     => 'Zpracovávají se pouze originální soubory a standardní velikosti evidované WordPressem. Externí varianty mimo metadata WordPressu se do této akce nezahrnují.',
    ]);
}

private function simulate_thumbnail_cleanup_batch($offset = 0) {
        $settings = $this->get_settings();
        $batch_size = (int) $settings['batch_size'];
        $ids = $this->get_image_attachment_ids($batch_size, $offset);
        $would_delete = 0;
        $checked = 0;
        $details = [];
        $reasons = [];
        $total = $this->get_total_attachment_count();

        foreach ($ids as $attachment_id) {
            $checked++;
            $data = $this->collect_generated_files_for_attachment($attachment_id);
            $files = $data['generated_files'];
            $would_delete += count($files);

            if (!empty($files) && count($details) < self::DETAIL_LIMIT) {
                $reason = 'evidované generované velikosti v metadata přílohy';
                $reasons[] = $reason;
                $relative = array_map([$this, 'normalize_upload_relative_path'], $files);
                $details[] = sprintf(
                    '%s — %d soubor(y): %s. Důvod: %s.',
                    $this->get_attachment_label($attachment_id),
                    count($files),
                    implode(', ', array_slice($relative, 0, 4)),
                    $reason
                );
            }
        }

        $finished = $checked < $batch_size || ($offset + $checked) >= $total;
        $this->log('info', sprintf('Simulace čištění náhledů: zkontrolováno %d příloh, k odstranění by bylo %d souborů.', $checked, $would_delete));

        return $this->create_batch_response('simulate_cleanup', $offset, $checked, $would_delete, $finished, 'Simulace mazání evidovaných náhledů', $details, [], [
            'checked' => $checked,
            'changed' => $would_delete,
            'total'   => $total,
            'why'     => 'Odstraňují se jen soubory evidované v attachment metadata jako vygenerované velikosti WordPressu nebo pluginů.',
        ]);
    }

    private function delete_thumbnail_cleanup_batch($offset = 0) {
        $settings = $this->get_settings();
        $batch_size = (int) $settings['batch_size'];
        $ids = $this->get_image_attachment_ids($batch_size, $offset);
        $deleted = 0;
        $checked = 0;
        $errors = [];
        $details = [];
        $meta_cleared = 0;
        $total = $this->get_total_attachment_count();

        foreach ($ids as $attachment_id) {
            $checked++;
            $data = $this->collect_generated_files_for_attachment($attachment_id);
            $deleted_for_attachment = 0;
            $failed_for_attachment = 0;

            foreach ($data['generated_files'] as $file_path) {
                if (!file_exists($file_path)) {
                    continue;
                }
                if (@unlink($file_path)) {
                    $deleted++;
                    $deleted_for_attachment++;
                } else {
                    $failed_for_attachment++;
                    $errors[] = $this->normalize_upload_relative_path($file_path) . ' — nepodařilo se smazat soubor.';
                }
            }

            if (!empty($data['meta']) && !empty($data['meta']['sizes'])) {
                $meta = $data['meta'];
                $meta['sizes'] = [];
                wp_update_attachment_metadata($attachment_id, $meta);
                $meta_cleared++;
            }

            if (($deleted_for_attachment || $failed_for_attachment) && count($details) < self::DETAIL_LIMIT) {
                $details[] = sprintf(
                    '%s — odstraněno %d, chyby %d. Důvod: smazány pouze evidované generované velikosti z metadata.',
                    $this->get_attachment_label($attachment_id),
                    $deleted_for_attachment,
                    $failed_for_attachment
                );
            }
        }

        $finished = $checked < $batch_size || ($offset + $checked) >= $total;
        $this->log('warning', sprintf('Mazání náhledů: zkontrolováno %d příloh, odstraněno %d souborů, chyby %d.', $checked, $deleted, count($errors)));

        $response = $this->create_batch_response('delete_cleanup', $offset, $checked, $deleted, $finished, 'Mazání evidovaných náhledů', $details, $errors, [
            'checked'      => $checked,
            'changed'      => $deleted,
            'meta_cleared' => $meta_cleared,
            'errors'       => count($errors),
            'total'        => $total,
        ]);

        if ($finished && !empty($settings['update_htaccess_fallback'])) {
            $htaccess = $this->update_missing_thumbnails_htaccess();
            $response['stats']['htaccess'] = $htaccess['status'];
            if (!empty($htaccess['message'])) {
                $response['details'][] = 'Fallback pro chybějící náhledy: ' . $htaccess['message'];
            }
        }

        return $response;
    }

    private function regenerate_metadata_batch($offset = 0) {
        $settings = $this->get_settings();
        $batch_size = (int) $settings['batch_size'];
        $ids = $this->get_image_attachment_ids($batch_size, $offset);
        $updated = 0;
        $checked = 0;
        $errors = [];
        $details = [];
        $total = $this->get_total_attachment_count();

        foreach ($ids as $attachment_id) {
            $checked++;
            $file = get_attached_file($attachment_id);
            if (!$file || !file_exists($file)) {
                $errors[] = $this->get_attachment_label($attachment_id) . ' — originál souboru nebyl nalezen.';
                continue;
            }

            $metadata = wp_generate_attachment_metadata($attachment_id, $file);
            if (is_wp_error($metadata) || empty($metadata)) {
                $errors[] = $this->get_attachment_label($attachment_id) . ' — metadata se nepodařilo vygenerovat.';
                continue;
            }

            wp_update_attachment_metadata($attachment_id, $metadata);
            $updated++;
            if (count($details) < self::DETAIL_LIMIT) {
                $sizes_count = !empty($metadata['sizes']) ? count($metadata['sizes']) : 0;
                $details[] = sprintf('%s — metadata aktualizována, vygenerováno %d velikostí.', $this->get_attachment_label($attachment_id), $sizes_count);
            }
        }

        $finished = $checked < $batch_size || ($offset + $checked) >= $total;
        $this->log('info', sprintf('Přegenerování metadata: zkontrolováno %d příloh, aktualizováno %d, chyby %d.', $checked, $updated, count($errors)));

        return $this->create_batch_response('regenerate_metadata', $offset, $checked, $updated, $finished, 'Přegenerování metadata a náhledů', $details, $errors, [
            'checked' => $checked,
            'changed' => $updated,
            'errors'  => count($errors),
            'total'   => $total,
        ]);
    }

    private function get_attachment_label($attachment_id) {
        $file = get_attached_file($attachment_id);
        return $file ? basename($file) : ('Příloha #' . (int) $attachment_id);
    }

    private function normalize_upload_relative_path($file_path) {
        $uploads = wp_get_upload_dir();
        $base_dir = wp_normalize_path(trailingslashit($uploads['basedir'] ?? ''));
        $path = wp_normalize_path($file_path);
        if ($base_dir && strpos($path, $base_dir) === 0) {
            return ltrim(substr($path, strlen($base_dir)), '/');
        }
        return basename($path);
    }

    private function collect_generated_files_for_attachment($attachment_id) {
        $meta = wp_get_attachment_metadata($attachment_id);
        $file = get_attached_file($attachment_id);
        $generated_files = [];

        if (!$meta || empty($meta['sizes']) || !$file) {
            return [
                'meta' => $meta,
                'generated_files' => $generated_files,
            ];
        }

        $base_dir = trailingslashit(dirname($file));
        foreach ($meta['sizes'] as $size_data) {
            if (!empty($size_data['file'])) {
                $generated_files[] = $base_dir . $size_data['file'];
            }
        }

        return [
            'meta' => $meta,
            'generated_files' => array_unique($generated_files),
        ];
    }


private function audit_external_variants_batch($offset = 0) {
    $settings = $this->get_settings();
    $batch_size = (int) $settings['batch_size'];
    $files = $this->get_image_files_in_uploads($batch_size, $offset);
    $processed = 0;
    $details = [];
    $errors = [];
    $total = $this->get_total_upload_image_file_count();
    $legitimate_map = $this->get_legitimate_upload_files_map();

    $legitimate = 0;
    $external = 0;
    $manual = 0;
    $external_bytes = 0;
    $manual_bytes = 0;

    foreach ($files as $file_path) {
        $processed++;
        $relative = $this->normalize_upload_relative_path($file_path);
        $class = $this->classify_untracked_upload_file($file_path, $legitimate_map);
        $size = file_exists($file_path) ? (int) filesize($file_path) : 0;

        if ($class === 'legitimate') {
            $legitimate++;
            continue;
        }

        if ($class === 'external') {
            $external++;
            $external_bytes += $size;
            if (count($details) < self::DETAIL_LIMIT) {
                $details[] = $relative . ' — nalezena externí varianta mimo metadata WordPressu.';
            }
            continue;
        }

        $manual++;
        $manual_bytes += $size;
        if (count($details) < self::DETAIL_LIMIT) {
            $details[] = $relative . ' — soubor není ve WordPress metadata, doporučena ruční kontrola.';
        }
    }

    $finished = $processed < $batch_size || ($offset + $processed) >= $total;
    $this->log('info', sprintf('Audit externích variant: zkontrolováno %d souborů, externí varianty %d, ruční kontrola %d.', $processed, $external, $manual));

    return $this->create_batch_response('audit_external_variants', $offset, $processed, $external + $manual, $finished, 'Audit externích variant obrázků', $details, $errors, [
        'checked'        => $processed,
        'changed'        => $external + $manual,
        'total'          => $total,
        'legitimate'     => $legitimate,
        'external'       => $external,
        'manual'         => $manual,
        'external_bytes' => $external_bytes,
        'manual_bytes'   => $manual_bytes,
        'why'            => 'Audit porovnává skutečné soubory v uploads s originály a standardními velikostmi evidovanými ve WordPress metadata. Vše mimo tento seznam je označeno buď jako externí varianta, nebo jako soubor k ruční kontrole.',
    ]);
}

private function delete_external_variants_batch($offset = 0, array $options = []) {
    $settings = $this->get_settings();
    $batch_size = (int) $settings['batch_size'];
    $files = $this->get_image_files_in_uploads($batch_size, $offset);
    $processed = 0;
    $details = [];
    $errors = [];
    $total = $this->get_total_upload_image_file_count();
    $legitimate_map = $this->get_legitimate_upload_files_map();
    $include_manual = !empty($options['include_manual']);

    $deleted = 0;
    $kept = 0;
    $manual_deleted = 0;
    $saved_bytes = 0;

    foreach ($files as $file_path) {
        $processed++;
        $relative = $this->normalize_upload_relative_path($file_path);
        $class = $this->classify_untracked_upload_file($file_path, $legitimate_map);

        if ($class === 'legitimate') {
            $kept++;
            continue;
        }

        if ($class === 'manual' && !$include_manual) {
            $kept++;
            if (count($details) < self::DETAIL_LIMIT) {
                $details[] = $relative . ' — ponecháno, tento soubor je označený k ruční kontrole.';
            }
            continue;
        }

        $size = file_exists($file_path) ? (int) filesize($file_path) : 0;
        if (file_exists($file_path) && @unlink($file_path)) {
            $deleted++;
            $saved_bytes += $size;
            if ($class === 'manual') {
                $manual_deleted++;
            }
            if (count($details) < self::DETAIL_LIMIT) {
                $details[] = $relative . ' — smazáno, soubor není evidovaný ve WordPress metadata.';
            }
        } else {
            $errors[] = $relative . ' — nepodařilo se smazat soubor.';
        }
    }

    $finished = $processed < $batch_size || ($offset + $processed) >= $total;
    $this->log('warning', sprintf('Mazání externích variant: zkontrolováno %d souborů, smazáno %d, ponecháno %d, chyby %d.', $processed, $deleted, $kept, count($errors)));

    return $this->create_batch_response('delete_external_variants', $offset, $processed, $deleted, $finished, 'Mazání externích variant obrázků', $details, $errors, [
        'checked'        => $processed,
        'changed'        => $deleted,
        'deleted'        => $deleted,
        'kept'           => $kept,
        'manual_deleted' => $manual_deleted,
        'saved_bytes'    => $saved_bytes,
        'total'          => $total,
        'why'            => $include_manual
            ? 'Mazají se externí varianty mimo WordPress metadata včetně souborů označených k ruční kontrole.'
            : 'Mazají se pouze externí varianty mimo WordPress metadata. Soubory označené k ruční kontrole zůstávají zachované.',
    ]);
}


private function run_single_action($action, $offset = 0, array $options = []) {
    switch ($action) {
        case 'resize_existing':
            return $this->resize_existing_images_batch($offset);
        case 'simulate_cleanup':
            return $this->simulate_thumbnail_cleanup_batch($offset);
        case 'delete_cleanup':
            return $this->delete_thumbnail_cleanup_batch($offset);
        case 'audit_external_variants':
            return $this->audit_external_variants_batch($offset);
        case 'delete_external_variants':
            return $this->delete_external_variants_batch($offset, $options);
        case 'regenerate_metadata':
            return $this->regenerate_metadata_batch($offset);
        case 'check_sizes':
            $report = $this->get_site_size_report();
            $this->log('info', sprintf('Ruční kontrola velikosti webu: wp-content %s MB, uploads %s MB, cache %s MB, zálohy %s MB.', $report['wp_content_mb'], $report['uploads_mb'], $report['cache_mb'], $report['backups_mb']));
            return [
                'message' => 'Kontrola velikosti webu proběhla úspěšně.',
                'details' => [
                    'wp-content: ' . $report['wp_content_mb'] . ' MB',
                    'Uploads: ' . $report['uploads_mb'] . ' MB',
                    'Cache: ' . $report['cache_mb'] . ' MB',
                    'Zálohy: ' . $report['backups_mb'] . ' MB',
                ],
            ];
        case 'update_htaccess':
            return $this->update_missing_thumbnails_htaccess();
        case 'clear_logs':
            delete_option(self::OPTION_LOGS);
            $this->add_notice('success', 'Logy byly smazány.');
            return ['message' => 'Logy byly smazány.', 'details' => [], 'clearNotice' => true];
        default:
            return new WP_Error('unknown_action', 'Neznámá akce.');
    }
}

public function ajax_run_action() {

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Nemáte dostatečná oprávnění.'], 403);
        }

        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        if (!$this->plugin_is_operational()) {
            wp_send_json_error(['message' => 'Plugin momentálně nemá platnou licenci. Akce jsou zablokované.'], 403);
        }

        $action = sanitize_key($_POST['swio_action'] ?? '');
        $offset = max(0, absint($_POST['swio_offset'] ?? 0));
        $options = [
            'include_manual' => !empty($_POST['swio_include_manual']),
        ];
        $result = $this->run_single_action($action, $offset, $options);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()], 400);
        }

        wp_send_json_success($result);
    }


public function ajax_get_logs() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Nemáte dostatečná oprávnění.'], 403);
    }

    check_ajax_referer(self::NONCE_ACTION, 'nonce');

    wp_send_json_success([
        'html' => $this->get_logs_html(),
    ]);
}

private function update_missing_thumbnails_htaccess() {

        $uploads = wp_get_upload_dir();
        $base_dir = $uploads['basedir'] ?? '';
        if (!$base_dir || !is_dir($base_dir)) {
            return ['message' => 'Uploads složka nebyla nalezena.', 'details' => [], 'status' => 'neprovedeno'];
        }

        $htaccess_path = trailingslashit($base_dir) . '.htaccess';
        $rules = [
            '<IfModule mod_rewrite.c>',
            'RewriteEngine On',
            'RewriteCond %{REQUEST_FILENAME} !-f',
            'RewriteRule ^(.+/)?([^/]+)-([0-9]+)x([0-9]+)(\.[A-Za-z0-9]+)$ $1$2$5 [L]',
            '</IfModule>',
        ];

        $result = insert_with_markers($htaccess_path, self::HTACCESS_MARKER, $rules);
        if ($result) {
            $this->log('info', 'Byla aktualizována fallback pravidla v uploads/.htaccess pro chybějící náhledy.');
            return [
                'message' => 'Pravidla byla zapsána do uploads/.htaccess. Pokud někde zůstane URL náhledu, Apache ho zkusí obsloužit originálem bez rozměrového suffixu.',
                'details' => ['Soubor: ' . $htaccess_path],
                'status'  => 'aktualizováno',
            ];
        }

        $this->log('error', 'Nepodařilo se zapsat fallback pravidla do uploads/.htaccess.');
        return [
            'message' => 'Do uploads/.htaccess se nepodařilo zapsat pravidla. Zkontroluj oprávnění souboru nebo hostingu.',
            'details' => ['Soubor: ' . $htaccess_path],
            'status'  => 'chyba',
        ];
    }

    private function add_notice($type, $message) {
        set_transient(self::OPTION_NOTICE, ['type' => $type, 'message' => $message], 60);
    }

    private function render_notice() {
        $notice = get_transient(self::OPTION_NOTICE);
        if (empty($notice['message'])) {
            return;
        }
        delete_transient(self::OPTION_NOTICE);
        $class = 'notice notice-info';
        if ($notice['type'] === 'success') {
            $class = 'notice notice-success';
        } elseif ($notice['type'] === 'warning') {
            $class = 'notice notice-warning';
        } elseif ($notice['type'] === 'error') {
            $class = 'notice notice-error';
        }
        echo '<div class="' . esc_attr($class) . '"><p>' . esc_html($notice['message']) . '</p></div>';
    }

    private function log($level, $message) {
        $logs = get_option(self::OPTION_LOGS, []);
        if (!is_array($logs)) {
            $logs = [];
        }

        $logs[] = [
            'time'    => current_time('mysql'),
            'level'   => sanitize_key($level),
            'message' => sanitize_text_field($message),
        ];

        if (count($logs) > 500) {
            $logs = array_slice($logs, -500);
        }

        update_option(self::OPTION_LOGS, $logs, false);
    }

    public function cleanup_old_logs() {
        $logs = get_option(self::OPTION_LOGS, []);
        if (!is_array($logs) || empty($logs)) {
            return;
        }

        $cutoff = strtotime('-' . self::LOG_RETENTION_DAYS . ' days', current_time('timestamp'));
        $filtered = array_filter($logs, static function($log) use ($cutoff) {
            $time = isset($log['time']) ? strtotime($log['time']) : false;
            return $time && $time >= $cutoff;
        });

        update_option(self::OPTION_LOGS, array_values($filtered), false);
    }

    private function get_logs() {
        $logs = get_option(self::OPTION_LOGS, []);
        if (!is_array($logs)) {
            return [];
        }
        return array_reverse($logs);
    }

    private function get_logs_html() {
        $logs = $this->get_logs();
        ob_start();
        if (empty($logs)) : ?>
            <p>Žádné logy.</p>
        <?php else : ?>
            <?php foreach (array_slice($logs, 0, 50) as $log) : ?>
                <div class="swio-log-item swio-log-<?php echo esc_attr($log['level']); ?>">
                    <div class="swio-log-meta"><?php echo esc_html($log['time']); ?> • <?php echo esc_html(strtoupper($log['level'])); ?></div>
                    <div class="swio-log-message"><?php echo esc_html($log['message']); ?></div>
                </div>
            <?php endforeach; ?>
        <?php endif;

        return trim((string) ob_get_clean());
    }

    private function render_action_card($action, $label, $description, $is_batch = false, $variant = 'primary') {
        $button_class = 'button button-primary';
        if ($variant === 'secondary') {
            $button_class = 'button';
        } elseif ($variant === 'danger') {
            $button_class = 'button button-primary swio-button-danger';
        }
        ?>
        <div class="swio-action-card">
            <h3><?php echo esc_html($label); ?></h3>
            <p><?php echo esc_html($description); ?></p>
            <?php if ($action === 'delete_external_variants') : ?>
                <p class="swio-action-option">
                    <label><input type="checkbox" class="swio-include-manual" value="1"> Zahrnout i soubory označené k ruční kontrole</label>
                </p>
            <?php endif; ?>
            <p>
                <button
                    type="button"
                    class="<?php echo esc_attr($button_class); ?> swio-run-action"
                    data-swio-action="<?php echo esc_attr($action); ?>"
                    data-swio-batch="<?php echo $is_batch ? '1' : '0'; ?>"
                ><?php echo esc_html($label); ?></button>
            </p>
            <div class="swio-action-status" hidden></div>
        </div>
        <?php
    }

    public function render_admin_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $settings = $this->get_settings();
        $license = $this->get_license_state();
        $management = $this->get_management_context();
        $is_operational = $this->plugin_is_operational();
        $status_payload = $this->get_license_panel_data($license, $management, $is_operational);
        $sizes = $this->get_registered_image_sizes();
        $report = $this->get_site_size_report();
        $logs = $this->get_logs();
        $attachments_count = $this->get_total_attachment_count();
        ?>
        <div class="wrap swio-wrap">
            <div class="swio-hero">
                <div class="swio-hero__content">
                    <span class="swio-badge"><?php echo esc_html__('Smart Websites', 'sw-image-optimizer'); ?></span>
                    <h1><?php echo esc_html__('Optimalizace obrázků', 'sw-image-optimizer'); ?></h1>
                    <p><?php echo esc_html__('Automatické zmenšování obrázků, bezpečnější správa náhledů, přegenerování metadata a interní monitoring velikosti webu.', 'sw-image-optimizer'); ?></p>
                </div>
                <div class="swio-hero__meta">
                    <div class="swio-stat">
                        <strong><?php echo esc_html(self::VERSION); ?></strong>
                        <span><?php echo esc_html__('Verze pluginu', 'sw-image-optimizer'); ?></span>
                    </div>
                </div>
            </div>

            <?php $this->render_notice(); ?>
            <div id="swio-global-notices"></div>
            <?php if (!empty($_GET['swio_license_message'])) : ?>
                <div class="notice notice-success"><p><?php echo esc_html(sanitize_text_field((string) $_GET['swio_license_message'])); ?></p></div>
            <?php endif; ?>

            <div class="swio-license-card">
                <div class="swio-license-card__header">
                    <div>
                        <h2><?php echo esc_html__('Licence pluginu', 'sw-image-optimizer'); ?></h2>
                        <p class="swio-license-intro"><?php echo esc_html__('Plugin může běžet buď v rámci platné správy webu, nebo přes samostatnou licenci.', 'sw-image-optimizer'); ?></p>
                    </div>
                    <span class="swio-license-badge swio-license-badge--<?php echo esc_attr($status_payload['badge_class']); ?>"><?php echo esc_html($status_payload['badge_label']); ?></span>
                </div>

                <div class="swio-license-grid">
                    <div class="swio-license-item">
                        <span class="swio-license-label"><?php echo esc_html__('Režim', 'sw-image-optimizer'); ?></span>
                        <strong><?php echo esc_html($status_payload['mode']); ?></strong>
                        <?php if ($status_payload['subline'] !== '') : ?><span><?php echo esc_html($status_payload['subline']); ?></span><?php endif; ?>
                    </div>
                    <div class="swio-license-item">
                        <span class="swio-license-label"><?php echo esc_html__('Platnost do', 'sw-image-optimizer'); ?></span>
                        <strong><?php echo esc_html($status_payload['valid_to']); ?></strong>
                        <?php if ($status_payload['domain'] !== '') : ?><span><?php echo esc_html($status_payload['domain']); ?></span><?php endif; ?>
                    </div>
                    <div class="swio-license-item">
                        <span class="swio-license-label"><?php echo esc_html__('Poslední ověření', 'sw-image-optimizer'); ?></span>
                        <strong><?php echo esc_html($status_payload['last_check']); ?></strong>
                        <?php if ($status_payload['message'] !== '') : ?><span><?php echo esc_html($status_payload['message']); ?></span><?php endif; ?>
                    </div>
                </div>

                <?php if (!$management['is_active']) : ?>
                    <div class="swio-license-form-wrap">
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="swio-license-form">
                            <?php wp_nonce_field('swio_verify_license'); ?>
                            <input type="hidden" name="action" value="swio_verify_license">
                            <label for="swio_license_key"><strong><?php echo esc_html__('Licenční kód pluginu', 'sw-image-optimizer'); ?></strong></label>
                            <input type="text" id="swio_license_key" name="license_key" value="<?php echo esc_attr($license['key']); ?>" class="regular-text" placeholder="SWLIC-..." />
                            <p class="description"><?php echo esc_html__('Použijte pouze pro samostatnou licenci pluginu. Pokud máte Správu webu, kód vyplňovat nemusíte.', 'sw-image-optimizer'); ?></p>
                            <div class="swio-license-actions">
                                <button type="submit" class="button button-primary"><?php echo esc_html__('Ověřit a uložit licenci', 'sw-image-optimizer'); ?></button>
                                <?php if ($license['key'] !== '') : ?>
                                    <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=swio_remove_license'), 'swio_remove_license')); ?>" class="button button-secondary"><?php echo esc_html__('Odebrat licenční kód', 'sw-image-optimizer'); ?></a>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>
                <?php else : ?>
                    <div class="swio-note"><?php echo esc_html__('Plugin je provozován v rámci Správy webu. Samostatný licenční kód není potřeba.', 'sw-image-optimizer'); ?></div>
                <?php endif; ?>
            </div>

            <?php if (!$is_operational) : ?>
                <div class="notice notice-warning"><p><?php echo esc_html__('Plugin momentálně nemá platnou licenci. Nastavení zůstává pouze pro čtení, automatická optimalizace se neprovádí a servisní akce jsou zablokované.', 'sw-image-optimizer'); ?></p></div>
            <?php endif; ?>

            <div class="swio-cards">
                <div class="swio-card">
                    <div class="swio-card-label">Velikost wp-content</div>
                    <div class="swio-card-value"><?php echo esc_html($report['wp_content_mb']); ?> MB</div>
                </div>
                <div class="swio-card">
                    <div class="swio-card-label">Uploads</div>
                    <div class="swio-card-value"><?php echo esc_html($report['uploads_mb']); ?> MB</div>
                </div>
                <div class="swio-card">
                    <div class="swio-card-label">Cache</div>
                    <div class="swio-card-value"><?php echo esc_html($report['cache_mb']); ?> MB</div>
                </div>
                <div class="swio-card">
                    <div class="swio-card-label">Zálohy</div>
                    <div class="swio-card-value"><?php echo esc_html($report['backups_mb']); ?> MB</div>
                </div>
            </div>

            <div class="swio-accordion" data-swio-accordion>
                <div class="swio-accordion-item">
                    <button type="button" class="swio-accordion-toggle" aria-expanded="true">
                        <span>Automatická optimalizace nových obrázků</span>
                        <span class="swio-accordion-icon">+</span>
                    </button>
                    <div class="swio-accordion-panel">
                        <form method="post" action="options.php" class="swio-settings-form">
                            <fieldset <?php disabled(!$is_operational); ?>>
                            <?php settings_fields('swio_settings_group'); ?>
                            <table class="form-table" role="presentation">
                                <tr>
                                    <th scope="row">Automatická optimalizace</th>
                                    <td><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[enabled]" value="1" <?php checked($settings['enabled'], 1); ?>> Zapnout automatické zmenšení po nahrání</label></td>
                                </tr>
                                <tr>
                                    <th scope="row">Maximální rozměr</th>
                                    <td><input type="number" min="300" max="6000" name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[max_dimension]" value="<?php echo esc_attr($settings['max_dimension']); ?>"> px</td>
                                </tr>
                                <tr>
                                    <th scope="row">JPEG kvalita</th>
                                    <td><input type="number" min="30" max="95" name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[jpeg_quality]" value="<?php echo esc_attr($settings['jpeg_quality']); ?>"> <p class="description">Výchozí hodnota je 70 %.</p></td>
                                </tr>
                                <tr>
                                    <th scope="row">Velikost dávky</th>
                                    <td><input type="number" min="10" max="200" name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[batch_size]" value="<?php echo esc_attr($settings['batch_size']); ?>"> obrázků / příloh na jednu dávku</td>
                                </tr>
                                <tr>
                                    <th scope="row">Podporované formáty</th>
                                    <td>
                                        <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[process_jpg]" value="1" <?php checked($settings['process_jpg'], 1); ?>> JPG/JPEG</label><br>
                                        <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[process_png]" value="1" <?php checked($settings['process_png'], 1); ?>> PNG</label><br>
                                        <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[process_webp]" value="1" <?php checked($settings['process_webp'], 1); ?>> WebP</label>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">Blokace extrémně velkých uploadů</th>
                                    <td>
                                        <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[block_extreme_uploads]" value="1" <?php checked($settings['block_extreme_uploads'], 1); ?>> Zablokovat extrémně velké obrázky ještě před uložením</label>
                                        <p class="description">Volitelná ochrana pro weby, kde uživatelé nahrávají obrovské fotky.</p>
                                        <p>
                                            Limit maximálního rozměru:
                                            <input type="number" min="1000" max="20000" name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[extreme_dimension_limit]" value="<?php echo esc_attr($settings['extreme_dimension_limit']); ?>"> px
                                        </p>
                                        <p>
                                            Limit velikosti souboru:
                                            <input type="number" min="1" max="200" name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[extreme_filesize_limit_mb]" value="<?php echo esc_attr($settings['extreme_filesize_limit_mb']); ?>"> MB
                                        </p>
                                    </td>
                                </tr>
                            </table>
                            <?php submit_button('Uložit nastavení'); ?>
                            </fieldset>
                        </form>
                    </div>
                </div>

                <div class="swio-accordion-item">
                    <button type="button" class="swio-accordion-toggle" aria-expanded="true">
                        <span>Správa generovaných velikostí</span>
                        <span class="swio-accordion-icon">+</span>
                    </button>
                    <div class="swio-accordion-panel">
                        <form method="post" action="options.php" class="swio-settings-form">
                            <fieldset <?php disabled(!$is_operational); ?>>
                            <?php settings_fields('swio_settings_group'); ?>
                            <table class="form-table" role="presentation">
                                <tr>
                                    <th scope="row">Režim</th>
                                    <td>
                                        <label><input type="radio" name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[disable_sizes_mode]" value="none" <?php checked($settings['disable_sizes_mode'], 'none'); ?>> Nic nevypínat</label><br>
                                        <label><input type="radio" name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[disable_sizes_mode]" value="selected" <?php checked($settings['disable_sizes_mode'], 'selected'); ?>> Vypnout jen vybrané velikosti</label><br>
                                        <label><input type="radio" name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[disable_sizes_mode]" value="all" <?php checked($settings['disable_sizes_mode'], 'all'); ?>> Vypnout všechny generované velikosti</label>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">Dostupné velikosti</th>
                                    <td>
                                        <div class="swio-size-grid">
                                            <?php foreach ($sizes as $size_name => $size_data) : ?>
                                                <label class="swio-size-item">
                                                    <input type="checkbox" name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[disabled_sizes][]" value="<?php echo esc_attr($size_name); ?>" <?php checked(in_array($size_name, $settings['disabled_sizes'], true)); ?>>
                                                    <span><strong><?php echo esc_html($size_name); ?></strong><br><?php echo esc_html($size_data['width']); ?> × <?php echo esc_html($size_data['height']); ?><?php echo !empty($size_data['crop']) ? ' • crop' : ''; ?></span>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                        <p class="description">Výchozí nastavení je „Vypnout všechny generované velikosti“.</p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">Fallback po smazání náhledů</th>
                                    <td>
                                        <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[update_htaccess_fallback]" value="1" <?php checked($settings['update_htaccess_fallback'], 1); ?>> Po smazání náhledů automaticky aktualizovat uploads/.htaccess fallback pro chybějící velikosti</label>
                                        <p class="description">Pomáhá hlavně na Apache. Pokud někde zůstane URL náhledu, server zkusí obsloužit originál bez suffixu typu -300x200.</p>
                                    </td>
                                </tr>
                            </table>
                            <?php submit_button('Uložit nastavení velikostí'); ?>
                            </fieldset>
                        </form>
                    </div>
                </div>

                <div class="swio-accordion-item">
                    <button type="button" class="swio-accordion-toggle" aria-expanded="true">
                        <span>Servisní nástroje</span>
                        <span class="swio-accordion-icon">+</span>
                    </button>
                    <div class="swio-accordion-panel">
                        <fieldset <?php disabled(!$is_operational); ?>>
                        <div class="swio-actions-grid">
                            <?php $this->render_action_card('check_sizes', 'Zkontrolovat velikost webu', 'Provede ruční kontrolu velikosti složek a uloží výsledek do logu.', false); ?>
                            <?php $this->render_action_card('resize_existing', 'Změnit rozměry existujících obrázků', 'Projde pouze originální obrázky a standardní velikosti evidované WordPressem. Externí varianty mimo metadata přeskočí.', true); ?>
                            <?php $this->render_action_card('simulate_cleanup', 'Simulovat mazání evidovaných náhledů', 'Ukáže, které soubory by šly pryč a proč. Prochází dávkově až do konce.', true); ?>
                            <?php $this->render_action_card('delete_cleanup', 'Smazat evidované náhledy', 'Odstraní jen velikosti evidované v metadata příloh a případně aktualizuje uploads/.htaccess fallback.', true, 'danger'); ?>
                            <?php $this->render_action_card('regenerate_metadata', 'Přegenerovat metadata a náhledy', 'Přegeneruje metadata a aktuálně povolené velikosti pro všechny přílohy.', true); ?>
                            <?php $this->render_action_card('audit_external_variants', 'Audit externích variant obrázků', 'Vyhledá soubory v uploads, které nejsou evidované jako originály ani standardní WordPress velikosti.', true, 'secondary'); ?>
                            <?php $this->render_action_card('delete_external_variants', 'Vyčistit externí varianty obrázků', 'Smaže soubory nalezené auditem jako externí varianty mimo metadata WordPressu.', true, 'danger'); ?>
                            <?php $this->render_action_card('update_htaccess', 'Aktualizovat fallback v .htaccess', 'Ruční přegenerování pravidel pro chybějící thumbnail URL v uploads/.htaccess.', false); ?>
                        </div>
                        <p class="description">Aktuální počet obrazových příloh v databázi: <?php echo esc_html((string) $attachments_count); ?></p>
                        <p class="description">U mazání externích variant doporučujeme nejprve spustit audit. Pokud si přejete být důraznější, můžete při mazání zahrnout i soubory označené k ruční kontrole.</p>
                        </fieldset>
                    </div>
                </div>

                <div class="swio-accordion-item">
                    <button type="button" class="swio-accordion-toggle" aria-expanded="true">
                        <span>Logy a údržba</span>
                        <span class="swio-accordion-icon">+</span>
                    </button>
                    <div class="swio-accordion-panel">
                        <fieldset <?php disabled(!$is_operational); ?>>
                        <div class="swio-log-toolbar">
                            <p>Logy se automaticky čistí po <?php echo esc_html((string) self::LOG_RETENTION_DAYS); ?> dnech.</p>
                            <div class="swio-inline-action">
                                <button
                                    type="button"
                                    class="button swio-run-action"
                                    data-swio-action="clear_logs"
                                    data-swio-batch="0"
                                >Smazat logy</button>
                                <div class="swio-action-status" hidden></div>
                            </div>
                        </div>
                        </fieldset>
                        <div class="swio-log-list">
                            <?php if (empty($logs)) : ?>
                                <p>Žádné logy.</p>
                            <?php else : ?>
                                <?php foreach (array_slice($logs, 0, 50) as $log) : ?>
                                    <div class="swio-log-item swio-log-<?php echo esc_attr($log['level']); ?>">
                                        <div class="swio-log-meta"><?php echo esc_html($log['time']); ?> • <?php echo esc_html(strtoupper($log['level'])); ?></div>
                                        <div class="swio-log-message"><?php echo esc_html($log['message']); ?></div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
    private function get_license_panel_data(array $license, array $management, bool $is_operational): array {
        $format_dt = static function(int $ts): string {
            return $ts > 0 ? wp_date('j. n. Y H:i', $ts) : '—';
        };
        $format_date = static function(string $ymd): string {
            if ($ymd === '') {
                return '—';
            }
            $ts = strtotime($ymd . ' 12:00:00');
            return $ts ? wp_date('j. n. Y', $ts) : $ymd;
        };

        $base = [
            'badge_class' => 'inactive',
            'badge_label' => 'Licence chybí',
            'mode'        => 'Samostatná licence pluginu',
            'subline'     => '',
            'valid_to'    => '—',
            'domain'      => '',
            'last_check'  => '—',
            'message'     => '',
        ];

        if ($management['guard_present']) {
            if ($management['is_active']) {
                return array_merge($base, [
                    'badge_class' => 'active',
                    'badge_label' => 'Platná licence',
                    'mode'        => 'Správa webu',
                    'valid_to'    => $format_date((string) get_option('swg_managed_until', '')),
                    'domain'      => (string) get_option('swg_licence_domain', ''),
                    'last_check'  => $format_dt((int) $management['guard_last_success']),
                    'message'     => 'Plugin je provozován v rámci Správy webu.',
                ]);
            }
            if ($management['management_status'] !== 'NONE') {
                return array_merge($base, [
                    'badge_class' => 'inactive',
                    'badge_label' => 'Licence neplatná',
                    'mode'        => 'Správa webu',
                    'subline'     => 'Správa webu je po expiraci nebo omezená. Optimalizace obrázků se neprovádí.',
                    'valid_to'    => $format_date((string) get_option('swg_managed_until', '')),
                    'domain'      => (string) get_option('swg_licence_domain', ''),
                    'last_check'  => $format_dt((int) $management['guard_last_success']),
                    'message'     => 'Po expiraci lze plugin deaktivovat nebo smazat.',
                ]);
            }
        }

        if ($license['status'] === 'active') {
            return array_merge($base, [
                'badge_class' => 'active',
                'badge_label' => 'Platná licence',
                'mode'        => 'Samostatná licence pluginu',
                'subline'     => $license['key'] !== '' ? 'Licenční kód: ' . $license['key'] : '',
                'valid_to'    => $format_date((string) $license['valid_to']),
                'domain'      => (string) $license['domain'],
                'last_check'  => $format_dt((int) $license['last_success']),
                'message'     => $license['message'] !== '' ? $license['message'] : 'Plugin běží přes samostatnou licenci.',
            ]);
        }

        return array_merge($base, [
            'badge_class' => $is_operational ? 'active' : 'inactive',
            'badge_label' => $is_operational ? 'Platná licence' : 'Licence chybí',
            'mode'        => 'Samostatná licence pluginu',
            'subline'     => $license['key'] !== '' ? 'Licenční kód: ' . $license['key'] : 'Zatím nebyl uložen žádný licenční kód.',
            'valid_to'    => $format_date((string) $license['valid_to']),
            'domain'      => (string) $license['domain'],
            'last_check'  => $format_dt((int) $license['last_check']),
            'message'     => $license['message'] !== '' ? $license['message'] : 'Bez platné licence plugin přestává optimalizovat obrázky a servisní akce jsou zablokované.',
        ]);
    }

    public function maybe_refresh_plugin_license() {
        $management = $this->get_management_context();
        if ($management['is_active']) {
            return;
        }

        $license = $this->get_license_state();
        if ($license['key'] === '') {
            return;
        }
        if (!current_user_can('manage_options')) {
            return;
        }
        if (!empty($_POST['license_key'])) {
            return;
        }
        if ($license['last_check'] > 0 && (time() - (int) $license['last_check']) < (12 * HOUR_IN_SECONDS)) {
            return;
        }
        $this->refresh_plugin_license('admin-auto');
    }

    private function refresh_plugin_license(string $reason = 'manual', string $override_key = ''): array {
        $key = $override_key !== '' ? sanitize_text_field($override_key) : (string) $this->get_license_state()['key'];
        if ($key === '') {
            $this->update_license_state([
                'key' => '',
                'status' => 'missing',
                'type' => '',
                'valid_to' => '',
                'domain' => '',
                'message' => 'Licenční kód zatím není uložený.',
                'last_check' => time(),
            ]);
            return ['ok' => false, 'error' => 'missing_key'];
        }

        $site_id = (string) get_option('swg_site_id', '');
        $payload = [
            'license_key' => $key,
            'plugin_slug' => self::PLUGIN_SLUG,
            'site_id' => $site_id,
            'site_url' => home_url('/'),
            'reason' => $reason,
            'plugin_version' => self::VERSION,
        ];

        $res = wp_remote_post(rtrim(self::HUB_BASE, '/') . '/wp-json/swlic/v2/plugin-license', [
            'timeout' => 20,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => wp_json_encode($payload, JSON_UNESCAPED_SLASHES),
        ]);

        if (is_wp_error($res)) {
            $this->update_license_state([
                'key' => $key,
                'status' => 'error',
                'message' => $res->get_error_message(),
                'last_check' => time(),
            ]);
            return ['ok' => false, 'error' => $res->get_error_message()];
        }

        $code = (int) wp_remote_retrieve_response_code($res);
        $body = (string) wp_remote_retrieve_body($res);
        $data = json_decode($body, true);
        if ($code < 200 || $code >= 300 || !is_array($data)) {
            $api_message = 'Nepodařilo se ověřit licenci.';
            if (is_array($data) && !empty($data['message'])) {
                $api_message = sanitize_text_field((string) $data['message']);
            } elseif ($code > 0) {
                $api_message = 'Hub vrátil neočekávanou odpověď (HTTP ' . $code . ').';
            }

            $this->update_license_state([
                'key' => $key,
                'status' => 'error',
                'message' => $api_message,
                'last_check' => time(),
            ]);
            return [
                'ok' => false,
                'error' => 'bad_response',
                'message' => $api_message,
                'http_code' => $code,
            ];
        }

        $this->update_license_state([
            'key' => $key,
            'status' => sanitize_key((string) ($data['status'] ?? 'missing')),
            'type' => sanitize_key((string) ($data['licence_type'] ?? 'plugin_single')),
            'valid_to' => sanitize_text_field((string) ($data['valid_to'] ?? '')),
            'domain' => sanitize_text_field((string) ($data['assigned_domain'] ?? '')),
            'message' => sanitize_text_field((string) ($data['message'] ?? '')),
            'last_check' => time(),
            'last_success' => !empty($data['ok']) ? time() : 0,
        ]);

        return $data;
    }

    public function handle_verify_license() {
        if (!current_user_can('manage_options')) {
            wp_die('Zakázáno.', 'Zakázáno', ['response' => 403]);
        }
        check_admin_referer('swio_verify_license');
        $key = sanitize_text_field((string) ($_POST['license_key'] ?? ''));
        $result = $this->refresh_plugin_license('manual', $key);
        $message = !empty($result['message']) ? (string) $result['message'] : (!empty($result['ok']) ? 'Licence byla ověřena.' : 'Licenci se nepodařilo ověřit.');
        wp_safe_redirect(add_query_arg('swio_license_message', rawurlencode($message), admin_url('tools.php?page=' . self::PAGE_SLUG)));
        exit;
    }

    public function handle_remove_license() {
        if (!current_user_can('manage_options')) {
            wp_die('Zakázáno.', 'Zakázáno', ['response' => 403]);
        }
        check_admin_referer('swio_remove_license');
        delete_option(self::LICENSE_OPTION);
        wp_safe_redirect(add_query_arg('swio_license_message', rawurlencode('Licenční kód byl odebrán.'), admin_url('tools.php?page=' . self::PAGE_SLUG)));
        exit;
    }

    public function block_direct_deactivate() {
        $management = $this->get_management_context();
        if (!$management['is_active']) {
            return;
        }

        $action = isset($_GET['action']) ? sanitize_key((string) $_GET['action']) : '';
        $plugin = isset($_GET['plugin']) ? sanitize_text_field((string) $_GET['plugin']) : '';
        if ($action === 'deactivate' && $plugin === plugin_basename(__FILE__)) {
            wp_die('Tento plugin nelze deaktivovat při aktivní správě webu.', 'Chráněný plugin', ['response' => 403]);
        }
    }





}



add_filter('plugin_action_links', function($actions, $plugin_file) {
    if ($plugin_file !== plugin_basename(__FILE__)) {
        return $actions;
    }

    if (function_exists('sw_guard_get_management_status')) {
        $status = sw_guard_get_management_status();
        if ($status === 'ACTIVE') {
            unset($actions['deactivate']);
        }
    }

    return $actions;
}, 10, 2);

register_activation_hook(__FILE__, ['SW_Image_Optimizer', 'activate']);
register_deactivation_hook(__FILE__, ['SW_Image_Optimizer', 'deactivate']);
SW_Image_Optimizer::instance();
