<?php
// این فایل فقط توسط خود وردپرس هنگام حذف کامل افزونه اجرا می‌شود
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$delete_data = get_option('nasab_booking_delete_data_on_uninstall', '0');

if ($delete_data === '1') {
    global $wpdb;
    $table_name = $wpdb->prefix . 'nasab_booking_requests';
    $wpdb->query("DROP TABLE IF EXISTS $table_name");

    delete_option('nasab_booking_admin_email');
    delete_option('nasab_booking_sms_enabled');
    delete_option('nasab_booking_sms_api_key');
    delete_option('nasab_booking_sms_admin_phone');
    delete_option('nasab_booking_delete_data_on_uninstall');
}
