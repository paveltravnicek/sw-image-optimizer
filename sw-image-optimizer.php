<?php
/*
Plugin Name: Optimalizace obrázků
Description: Automatické zmenšování obrázků, bezpečnější správa náhledů, přegenerování metadata a interní monitoring velikosti webu.
Version: 1.0
Author: Smart Websites
Author URI: https://smart-websites.cz
Update URI: https://github.com/paveltravnicek/sw-image-optimizer/
Text Domain: sw-image-optimizer
*/

if (!defined('ABSPATH')) {
    exit;
}

final class SW_Image_Optimizer {
    const VERSION = '1.0';
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
        add_filter('wp_handle_upload_prefilter', [$this, 'prefilter_upload']);
        add_filter('wp_handle_upload', [$this, 'handle_uploaded_image']);
        add_filter('intermediate_image_sizes_advanced', [$this, 'filter_intermediate_image_sizes']);
        add_action(self::CRON_SIZE_HOOK, [$this, 'check_and_email_site_size']);
        add_action(self::CRON_LOGS_HOOK, [$this, 'cleanup_old_logs']);
    }

    public static function activate() {
        $instance = self::instance();
        if (!wp_next_scheduled(self::CRON_SIZE_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'weekly', self::CRON_SIZE_HOOK);
        }
        if (!wp_next_scheduled(self::CRON_LOGS_HOOK)) {
            wp_schedule_event(time() + 2 * HOUR_IN_SECONDS, 'daily', self::CRON_LOGS_HOOK);
        }
        if (!get_option(self::OPTION_SETTINGS)) {
            add_option(self::OPTION_SETTINGS, $instance->get_default_settings());
        }
    }

    public static function deactivate() {
        wp_clear_scheduled_hook(self::CRON_SIZE_HOOK);
        wp_clear_scheduled_hook(self::CRON_LOGS_HOOK);
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

        $sanitized['block_extreme_uploads'] = empty($input['block_extreme_uploads']) ? 0 : 1;
        $sanitized['extreme_dimension_limit'] = max(1000, min(20000, absint($input['extreme_dimension_limit'] ?? $defaults['extreme_dimension_limit'])));
        $sanitized['extreme_filesize_limit_mb'] = max(1, min(200, absint($input['extreme_filesize_limit_mb'] ?? $defaults['extreme_filesize_limit_mb'])));
        $sanitized['update_htaccess_fallback'] = empty($input['update_htaccess_fallback']) ? 0 : 1;

        $this->add_notice('success', 'Nastavení pluginu bylo uloženo.');
        return $sanitized;
    }

    public function enqueue_assets($hook) {
        if ($hook !== 'tools_page_' . self::PAGE_SLUG) {
            return;
        }

        wp_enqueue_style('swio-admin', plugin_dir_url(__FILE__) . 'assets/admin.css', [], self::VERSION);
        wp_enqueue_script('swio-admin', plugin_dir_url(__FILE__) . 'assets/admin.js', [], self::VERSION, true);
        wp_localize_script('swio-admin', 'swioAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce(self::NONCE_ACTION),
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

    private function get_image_files_in_uploads($limit = 50, $offset = 0) {
        $uploads = wp_get_upload_dir();
        $base_dir = $uploads['basedir'] ?? '';
        if (!$base_dir || !is_dir($base_dir)) {
            return [];
        }

        $supported = ['jpg', 'jpeg', 'png', 'webp'];
        $files = [];

        try {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base_dir, FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if (!$file->isFile()) {
                    continue;
                }
                if (in_array(strtolower($file->getExtension()), $supported, true)) {
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
        try {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base_dir, FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->isFile() && in_array(strtolower($file->getExtension()), ['jpg', 'jpeg', 'png', 'webp'], true)) {
                    $count++;
                }
            }
        } catch (UnexpectedValueException $e) {
            return 0;
        }

        return $count;
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
        $files = $this->get_image_files_in_uploads($batch_size, $offset);
        $processed = 0;
        $resized = 0;
        $details = [];
        $errors = [];
        $skipped = 0;
        $total = $this->get_total_upload_image_file_count();

        foreach ($files as $file_path) {
            $processed++;
            $mime = wp_check_filetype($file_path)['type'] ?? '';
            if (!$this->is_supported_mime_enabled($mime, $settings)) {
                $skipped++;
                if (count($details) < self::DETAIL_LIMIT) {
                    $details[] = basename($file_path) . ' — přeskočeno, formát není povolený.';
                }
                continue;
            }

            $result = $this->resize_image_if_needed($file_path, (int) $settings['max_dimension'], (int) $settings['jpeg_quality']);
            if (!empty($result['error'])) {
                $errors[] = basename($file_path) . ' — chyba: ' . $result['error'];
                continue;
            }

            if ($result['changed']) {
                $resized++;
                if (count($details) < self::DETAIL_LIMIT) {
                    $details[] = sprintf('%s — zmenšeno z %spx na %spx.', basename($file_path), $result['before_max'], $result['after_max']);
                }
            } else {
                $skipped++;
                if (count($details) < self::DETAIL_LIMIT) {
                    $details[] = sprintf('%s — ponecháno, rozměr %spx je už v limitu.', basename($file_path), $result['before_max']);
                }
            }
        }

        $finished = $processed < $batch_size || ($offset + $processed) >= $total;
        $summary = sprintf('Dávka změny rozměrů: zkontrolováno %d, zmenšeno %d, přeskočeno %d, chyby %d.', $processed, $resized, $skipped, count($errors));
        $this->log('info', $summary);

        return $this->create_batch_response('resize_existing', $offset, $processed, $resized, $finished, 'Změna rozměrů existujících obrázků', $details, $errors, [
            'checked' => $processed,
            'changed' => $resized,
            'skipped' => $skipped,
            'errors'  => count($errors),
            'total'   => $total,
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

    private function run_single_action($action, $offset = 0) {
        switch ($action) {
            case 'resize_existing':
                return $this->resize_existing_images_batch($offset);
            case 'simulate_cleanup':
                return $this->simulate_thumbnail_cleanup_batch($offset);
            case 'delete_cleanup':
                return $this->delete_thumbnail_cleanup_batch($offset);
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
                return ['message' => 'Logy byly smazány.', 'details' => []];
            default:
                return new WP_Error('unknown_action', 'Neznámá akce.');
        }
    }

    public function ajax_run_action() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Nemáte dostatečná oprávnění.'], 403);
        }

        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        $action = sanitize_key($_POST['swio_action'] ?? '');
        $offset = max(0, absint($_POST['swio_offset'] ?? 0));
        $result = $this->run_single_action($action, $offset);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()], 400);
        }

        wp_send_json_success($result);
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

    public function render_admin_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $settings = $this->get_settings();
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
                    <p><?php echo esc_html__('Automatická optimalizace obrázků pro rychlejší načítání webu, menší datovou zátěž a lepší kontrolu nad generovanými velikostmi.', 'sw-image-optimizer'); ?></p>
                </div>
                <div class="swio-hero__meta">
                    <div class="swio-stat">
                        <strong><?php echo esc_html(self::VERSION); ?></strong>
                        <span><?php echo esc_html__('Verze pluginu', 'sw-image-optimizer'); ?></span>
                    </div>
                </div>
            </div>

            <?php $this->render_notice(); ?>

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
                    <button type="button" class="swio-accordion-toggle" aria-expanded="false">
                        <span>Automatická optimalizace nových obrázků</span>
                        <span class="swio-accordion-icon">+</span>
                    </button>
                    <div class="swio-accordion-panel" hidden>
                        <form method="post" action="options.php" class="swio-settings-form">
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
                                        <p class="description">Volitelná ochrana pro klientské weby, kde uživatelé nahrávají obrovské fotky.</p>
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
                        </form>
                    </div>
                </div>

                <div class="swio-accordion-item">
                    <button type="button" class="swio-accordion-toggle" aria-expanded="false">
                        <span>Správa generovaných velikostí</span>
                        <span class="swio-accordion-icon">+</span>
                    </button>
                    <div class="swio-accordion-panel" hidden>
                        <form method="post" action="options.php" class="swio-settings-form">
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
                        </form>
                    </div>
                </div>

                <div class="swio-accordion-item">
                    <button type="button" class="swio-accordion-toggle" aria-expanded="false">
                        <span>Servisní nástroje</span>
                        <span class="swio-accordion-icon">+</span>
                    </button>
                    <div class="swio-accordion-panel" hidden>
                        <div class="swio-actions-grid">
                            <?php $this->render_action_card('check_sizes', 'Zkontrolovat velikost webu', 'Provede ruční kontrolu velikosti složek a uloží výsledek do logu.', false); ?>
                            <?php $this->render_action_card('resize_existing', 'Změnit rozměry existujících obrázků', 'Dávkově projde soubory v uploads a automaticky pokračuje až do konce.', true); ?>
                            <?php $this->render_action_card('simulate_cleanup', 'Simulovat mazání evidovaných náhledů', 'Ukáže, které soubory by šly pryč a proč. Prochází dávkově až do konce.', true); ?>
                            <?php $this->render_action_card('delete_cleanup', 'Smazat evidované náhledy', 'Odstraní jen velikosti evidované v metadata příloh a případně aktualizuje uploads/.htaccess fallback.', true, 'danger'); ?>
                            <?php $this->render_action_card('regenerate_metadata', 'Přegenerovat metadata a náhledy', 'Přegeneruje metadata a aktuálně povolené velikosti pro všechny přílohy.', true); ?>
                            <?php $this->render_action_card('update_htaccess', 'Aktualizovat fallback v .htaccess', 'Ruční přegenerování pravidel pro chybějící thumbnail URL v uploads/.htaccess.', false); ?>
                        </div>
                        <p class="description">Aktuální počet obrazových příloh v databázi: <?php echo esc_html((string) $attachments_count); ?></p>
                    </div>
                </div>

                <div class="swio-accordion-item">
                    <button type="button" class="swio-accordion-toggle" aria-expanded="false">
                        <span>Logy a údržba</span>
                        <span class="swio-accordion-icon">+</span>
                    </button>
                    <div class="swio-accordion-panel" hidden>
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
}

register_activation_hook(__FILE__, ['SW_Image_Optimizer', 'activate']);
register_deactivation_hook(__FILE__, ['SW_Image_Optimizer', 'deactivate']);
SW_Image_Optimizer::instance();
