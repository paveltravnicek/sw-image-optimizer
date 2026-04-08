<?php
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('swio_settings');
delete_option('swio_logs');
delete_transient('swio_admin_notice');
wp_clear_scheduled_hook('swio_check_site_size_weekly');
wp_clear_scheduled_hook('swio_cleanup_logs_daily');
