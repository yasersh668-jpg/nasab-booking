<?php
/**
 * Plugin Name: مدیریت نوبت نصاب (Nasab Booking)
 * Description: سیستم ساده رزرو نوبت برای شرکت‌های نصب دوربین مداربسته و دزدگیر. مشتری فرم رو پر می‌کنه، درخواستش در دیتابیس ذخیره می‌شه و از پیشخوان وردپرس قابل مدیریت است.
 * Version: 1.8.0
 * Requires at least: 5.6
 * Requires PHP: 7.4
 * Author: Your Business
 * Text Domain: nasab-booking
 */

// جلوگیری از دسترسی مستقیم به فایل
if (!defined('ABSPATH')) {
    exit;
}

// ثابت‌های افزونه
define('NASAB_BOOKING_VERSION', '1.8.0');
define('NASAB_BOOKING_TABLE', 'nasab_booking_requests');
define('NASAB_BOOKING_FILE', __FILE__);

/**
 * ====================================================================
 * 1) فعال‌سازی افزونه: ساخت جدول اختصاصی در دیتابیس
 * ====================================================================
 */
register_activation_hook(__FILE__, 'nasab_booking_create_table');

function nasab_booking_create_table() {
    global $wpdb;

    $table_name      = $wpdb->prefix . NASAB_BOOKING_TABLE;
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        customer_name VARCHAR(150) NOT NULL,
        phone VARCHAR(30) NOT NULL,
        property_type VARCHAR(50) NOT NULL,
        camera_count VARCHAR(20) NOT NULL,
        address TEXT NULL,
        notes TEXT NULL,
        preferred_date DATE NULL,
        preferred_time VARCHAR(10) NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id)
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);

    update_option('nasab_booking_db_version', NASAB_BOOKING_VERSION);
}

/**
 * اجرای خودکار آپدیت ساختار جدول برای کسانی که از نسخه‌های قبلی آپدیت می‌کنند
 * (فعال‌سازی مجدد لازم نیست؛ dbDelta فقط ستون‌های جدید را اضافه می‌کند و داده‌ای پاک نمی‌شود)
 */
add_action('plugins_loaded', 'nasab_booking_maybe_upgrade_db');

function nasab_booking_maybe_upgrade_db() {
    if (get_option('nasab_booking_db_version') !== NASAB_BOOKING_VERSION) {
        nasab_booking_create_table();
    }
}

/**
 * ====================================================================
 * توابع کمکی تقویم شمسی (تبدیل میلادی به شمسی برای نمایش در ایمیل و پنل مدیریت)
 * الگوریتم مشابه فایل assets/nb-datepicker.js و تست‌شده با کتابخانه مرجع.
 * تاریخ در دیتابیس همیشه میلادی (YYYY-MM-DD) ذخیره می‌شود.
 * ====================================================================
 */
function nasab_booking_jmod($a, $b) {
    return $a - intdiv($a, $b) * $b;
}

function nasab_booking_jal_cal($jy) {
    $breaks = array(-61, 9, 38, 199, 426, 686, 756, 818, 1111, 1181, 1210, 1635, 2060, 2097, 2192, 2262, 2324, 2394, 2456, 3178);
    $bl     = count($breaks);
    $gy     = $jy + 621;
    $leap_j = -14;
    $jp     = $breaks[0];
    $jump   = 0;

    if ($jy < $jp || $jy >= $breaks[$bl - 1]) {
        return null;
    }

    for ($i = 1; $i < $bl; $i++) {
        $jm   = $breaks[$i];
        $jump = $jm - $jp;
        if ($jy < $jm) {
            break;
        }
        $leap_j += intdiv($jump, 33) * 8 + intdiv(nasab_booking_jmod($jump, 33), 4);
        $jp = $jm;
    }

    $n       = $jy - $jp;
    $leap_j += intdiv($n, 33) * 8 + intdiv(nasab_booking_jmod($n, 33) + 3, 4);
    if (nasab_booking_jmod($jump, 33) === 4 && $jump - $n === 4) {
        $leap_j += 1;
    }

    $leap_g = intdiv($gy, 4) - intdiv((intdiv($gy, 100) + 1) * 3, 4) - 150;
    $march  = 20 + $leap_j - $leap_g;

    if ($jump - $n < 6) {
        $n = $n - $jump + intdiv($jump + 4, 33) * 33;
    }
    $leap = nasab_booking_jmod(nasab_booking_jmod($n + 1, 33) - 1, 4);
    if ($leap === -1) {
        $leap = 4;
    }

    return array('leap' => $leap, 'gy' => $gy, 'march' => $march);
}

function nasab_booking_g2d($gy, $gm, $gd) {
    $d = intdiv(($gy + intdiv($gm - 8, 6) + 100100) * 1461, 4)
        + intdiv(153 * nasab_booking_jmod($gm + 9, 12) + 2, 5)
        + $gd - 34840408;
    $d = $d - intdiv(intdiv($gy + 100100 + intdiv($gm - 8, 6), 100) * 3, 4) + 752;
    return $d;
}

function nasab_booking_d2g_year($jdn) {
    $j  = 4 * $jdn + 139361631;
    $j  = $j + intdiv(intdiv(4 * $jdn + 183187720, 146097) * 3, 4) * 4 - 3908;
    $i  = intdiv(nasab_booking_jmod($j, 1461), 4) * 5 + 308;
    $gm = nasab_booking_jmod(intdiv($i, 153), 12) + 1;
    return intdiv($j, 1461) - 100100 + intdiv(8 - $gm, 6);
}

/**
 * تبدیل تاریخ میلادی به شمسی. خروجی: array(سال, ماه, روز) یا null در صورت خارج بودن از بازه
 */
function nasab_booking_gregorian_to_jalali($gy, $gm, $gd) {
    $jdn = nasab_booking_g2d($gy, $gm, $gd);
    $jy  = nasab_booking_d2g_year($jdn) - 621;
    $r   = nasab_booking_jal_cal($jy);
    if ($r === null) {
        return null;
    }

    $jdn1f = nasab_booking_g2d($r['gy'], 3, $r['march']);
    $k     = $jdn - $jdn1f;

    if ($k >= 0) {
        if ($k <= 185) {
            return array($jy, 1 + intdiv($k, 31), nasab_booking_jmod($k, 31) + 1);
        }
        $k -= 186;
    } else {
        $jy -= 1;
        $k  += 179;
        if ($r['leap'] === 1) {
            $k += 1;
        }
    }

    return array($jy, 7 + intdiv($k, 30), nasab_booking_jmod($k, 30) + 1);
}

/**
 * نمایش تاریخ میلادی ذخیره‌شده به‌صورت شمسی با ارقام فارسی، مثلاً «پنجشنبه ۱۴۰۵/۰۷/۱۰».
 * اگر ورودی خالی یا نامعتبر باشد، رشته خالی برمی‌گرداند.
 */
function nasab_booking_format_jalali($date, $with_weekday = true) {
    if (!is_string($date) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m)) {
        return '';
    }
    $gy = (int) $m[1];
    $gm = (int) $m[2];
    $gd = (int) $m[3];
    if (!checkdate($gm, $gd, $gy)) {
        return '';
    }

    $j = nasab_booking_gregorian_to_jalali($gy, $gm, $gd);
    if ($j === null) {
        return '';
    }

    $text = strtr(sprintf('%04d/%02d/%02d', $j[0], $j[1], $j[2]), array(
        '0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴',
        '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹',
    ));

    if ($with_weekday) {
        $weekdays = array('یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنجشنبه', 'جمعه', 'شنبه');
        $w        = (int) gmdate('w', gmmktime(0, 0, 0, $gm, $gd, $gy));
        $text     = $weekdays[$w] . ' ' . $text;
    }

    return $text;
}

/**
 * بارگذاری فایل‌های CSS/JS پنجره انتخاب تاریخ (فقط وقتی لازم است)
 */
function nasab_booking_enqueue_datepicker() {
    $base = plugin_dir_url(NASAB_BOOKING_FILE) . 'assets/';
    wp_enqueue_style('nasab-booking-datepicker', $base . 'nb-datepicker.css', array(), NASAB_BOOKING_VERSION);
    wp_enqueue_script('nasab-booking-datepicker', $base . 'nb-datepicker.js', array(), NASAB_BOOKING_VERSION, true);
}

add_action('admin_enqueue_scripts', 'nasab_booking_admin_enqueue');

function nasab_booking_admin_enqueue($hook) {
    if (isset($_GET['page']) && $_GET['page'] === 'nasab-booking-requests') {
        nasab_booking_enqueue_datepicker();
    }
}

/**
 * ====================================================================
 * 2) شورت‌کد فرم رزرو نوبت — استفاده: [nasab_booking_form]
 * ====================================================================
 */
add_shortcode('nasab_booking_form', 'nasab_booking_render_form');

/**
 * پردازش ارسال فرم باید خیلی زودتر از رندر شدن محتوای صفحه انجام شود،
 * چون ریدایرکت (wp_safe_redirect) فقط قبل از ارسال هر خروجی به مرورگر کار می‌کند.
 * اگر این کار داخل خود شورت‌کد انجام می‌شد (که وسط رندر صفحه اجرا می‌شود)،
 * تم سایت قبلاً بخشی از HTML را فرستاده و ریدایرکت با شکست مواجه می‌شود
 * (همان چیزی که باعث صفحه سفید می‌شد).
 */
add_action('template_redirect', 'nasab_booking_handle_form_submission');

function nasab_booking_handle_form_submission() {
    if (!isset($_POST['nasab_booking_submit'])) {
        return;
    }

    if (!isset($_POST['nasab_booking_nonce']) ||
        !wp_verify_nonce($_POST['nasab_booking_nonce'], 'nasab_booking_form_action')) {
        $GLOBALS['nasab_booking_form_error'] = 'خطای امنیتی، لطفاً صفحه را رفرش کرده و دوباره تلاش کنید.';
        return;
    }

    $name          = sanitize_text_field($_POST['customer_name'] ?? '');
    $phone_raw     = sanitize_text_field($_POST['phone'] ?? '');
    $phone         = preg_replace('/[^0-9+\-\s]/', '', $phone_raw); // فقط اعداد، +، خط تیره و فاصله مجاز است
    $property_type = sanitize_text_field($_POST['property_type'] ?? '');
    $camera_count  = sanitize_text_field($_POST['camera_count'] ?? '');
    $address       = sanitize_textarea_field($_POST['address'] ?? '');
    $notes         = sanitize_textarea_field($_POST['notes'] ?? '');
    $preferred_date = sanitize_text_field($_POST['preferred_date'] ?? '');
    $preferred_time = sanitize_text_field($_POST['preferred_time'] ?? '');

    $allowed_property_types = array('residential', 'commercial', 'industrial');
    if (!in_array($property_type, $allowed_property_types, true)) {
        $property_type = 'residential';
    }

    // اعتبارسنجی تاریخ: باید فرمت درست YYYY-MM-DD داشته باشد و در گذشته نباشد
    if (!empty($preferred_date)) {
        $date_obj = DateTime::createFromFormat('Y-m-d', $preferred_date);
        $is_valid_date = $date_obj && $date_obj->format('Y-m-d') === $preferred_date;
        $today = current_time('Y-m-d');

        if (!$is_valid_date || $preferred_date < $today) {
            $GLOBALS['nasab_booking_form_error'] = 'تاریخ انتخاب‌شده معتبر نیست. لطفاً یک روز از امروز به بعد انتخاب کنید.';
            return;
        }
    }

    $allowed_times = array('morning', 'afternoon', 'evening');
    if (!in_array($preferred_time, $allowed_times, true)) {
        $preferred_time = '';
    }

    if (empty($name) || empty($phone)) {
        $GLOBALS['nasab_booking_form_error'] = 'لطفاً نام و شماره تماس را وارد کنید.';
        return;
    }

    global $wpdb;
    $table_name = $wpdb->prefix . NASAB_BOOKING_TABLE;

    $inserted = $wpdb->insert(
        $table_name,
        array(
            'customer_name'  => $name,
            'phone'          => $phone,
            'property_type'  => $property_type,
            'camera_count'   => $camera_count,
            'address'        => $address,
            'notes'          => $notes,
            'preferred_date' => $preferred_date ?: null,
            'preferred_time' => $preferred_time ?: null,
            'status'         => 'pending',
            'created_at'     => current_time('mysql'),
        ),
        array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s')
    );

    if (!$inserted) {
        $GLOBALS['nasab_booking_form_error'] = 'مشکلی در ثبت درخواست پیش آمد. لطفاً دوباره تلاش کنید.';
        return;
    }

    // ارسال ایمیل اطلاع‌رسانی (بدون توقف کار در صورت بروز خطا در ارسال ایمیل)
    nasab_booking_send_notifications(array(
        'customer_name'  => $name,
        'phone'          => $phone,
        'property_type'  => $property_type,
        'preferred_date' => $preferred_date,
        'preferred_time' => $preferred_time,
        'camera_count'  => $camera_count,
        'address'       => $address,
        'notes'         => $notes,
    ));

    // اینجا هنوز هیچ خروجی‌ای فرستاده نشده، پس ریدایرکت درست کار می‌کند.
    // به‌جای تکیه بر هدر Referer (که بعضی هاست‌ها/افزونه‌های امنیتی حذفش می‌کنند)،
    // از آدرس واقعی همین درخواست (REQUEST_URI) استفاده می‌کنیم که همیشه دقیق است.
    $current_url   = home_url(add_query_arg(null, null));
    $redirect_url  = add_query_arg('nasab_booking_success', '1', $current_url);
    wp_safe_redirect($redirect_url);
    exit;
}

function nasab_booking_render_form() {
    ob_start();

    $error   = isset($GLOBALS['nasab_booking_form_error']) ? $GLOBALS['nasab_booking_form_error'] : '';
    $success = isset($_GET['nasab_booking_success']);

    nasab_booking_enqueue_datepicker();

    // بارگذاری استایل فقط یک‌بار در هر صفحه (حتی اگر شورت‌کد چند بار استفاده شود)
    static $styles_printed = false;
    if (!$styles_printed) {
        $styles_printed = true;
        ?>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;500;600;700&family=Roboto+Mono:wght@500&display=swap" rel="stylesheet">
        <style>
            .nasab-booking-form-wrap {
                --nb-bg: #101B26;
                --nb-panel: #16222E;
                --nb-panel-2: #1C2A38;
                --nb-accent: #E8A33D;
                --nb-accent-dim: rgba(232, 163, 61, 0.16);
                --nb-text: #EAF0F4;
                --nb-text-dim: #8CA2B3;
                --nb-border: #29394A;
                --nb-success: #4FAE83;
                --nb-error: #E0665C;
                font-family: 'Vazirmatn', Tahoma, sans-serif;
                max-width: 520px;
                margin: 0 auto;
            }
            .nasab-booking-card {
                position: relative;
                background: var(--nb-bg);
                border: 1px solid var(--nb-border);
                border-radius: 4px;
                padding: 32px 28px;
                box-sizing: border-box;
            }
            /* گوشه‌های شبیه قاب نمای دوربین */
            .nasab-booking-card::before,
            .nasab-booking-card::after,
            .nasab-corner-tl, .nasab-corner-br {
                content: "";
                position: absolute;
                width: 18px;
                height: 18px;
                border: 2px solid var(--nb-accent);
            }
            .nasab-booking-card::before {
                top: -1px; right: -1px;
                border-left: none; border-bottom: none;
            }
            .nasab-booking-card::after {
                bottom: -1px; left: -1px;
                border-right: none; border-top: none;
            }
            .nasab-corner-tl {
                top: -1px; left: -1px;
                border-right: none; border-bottom: none;
            }
            .nasab-corner-br {
                bottom: -1px; right: -1px;
                border-left: none; border-top: none;
            }
            .nasab-booking-eyebrow {
                display: flex;
                align-items: center;
                gap: 8px;
                margin-bottom: 6px;
                color: var(--nb-accent);
                font-family: 'Roboto Mono', monospace;
                font-size: 12px;
                letter-spacing: 0.5px;
            }
            .nasab-booking-eyebrow span.dot {
                width: 7px; height: 7px;
                border-radius: 50%;
                background: var(--nb-accent);
                box-shadow: 0 0 6px var(--nb-accent);
            }
            .nasab-booking-title {
                color: var(--nb-text);
                font-size: 20px;
                font-weight: 700;
                margin: 0 0 24px 0;
            }
            .nasab-field {
                display: flex;
                flex-direction: column;
                gap: 6px;
                margin-bottom: 16px;
            }
            .nasab-field label {
                color: var(--nb-text-dim);
                font-size: 13px;
                font-weight: 500;
            }
            .nasab-field input,
            .nasab-field select,
            .nasab-field textarea {
                background: var(--nb-panel-2);
                border: 1px solid var(--nb-border);
                border-radius: 3px;
                color: var(--nb-text);
                padding: 11px 12px;
                font-family: 'Vazirmatn', Tahoma, sans-serif;
                font-size: 14px;
                width: 100%;
                box-sizing: border-box;
                transition: border-color 0.15s ease;
            }
            .nasab-field input:focus,
            .nasab-field select:focus,
            .nasab-field textarea:focus {
                outline: none;
                border-color: var(--nb-accent);
            }
            .nasab-field-row {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 14px;
            }
            @media (max-width: 480px) {
                .nasab-field-row { grid-template-columns: 1fr; }
                .nasab-booking-card { padding: 24px 18px; }
            }
            .nasab-booking-submit {
                width: 100%;
                background: var(--nb-accent);
                color: #14202B;
                border: none;
                border-radius: 3px;
                padding: 13px 16px;
                font-family: 'Vazirmatn', Tahoma, sans-serif;
                font-size: 15px;
                font-weight: 700;
                cursor: pointer;
                margin-top: 6px;
                transition: filter 0.15s ease;
            }
            .nasab-booking-submit:hover {
                filter: brightness(1.08);
            }
            .nasab-booking-notice {
                border-radius: 3px;
                padding: 14px 16px;
                font-size: 14px;
                margin-bottom: 18px;
                border: 1px solid;
            }
            .nasab-booking-notice.success {
                background: rgba(79, 174, 131, 0.12);
                border-color: var(--nb-success);
                color: #BFE6D5;
            }
            .nasab-booking-notice.error {
                background: rgba(224, 102, 92, 0.12);
                border-color: var(--nb-error);
                color: #F3C2BD;
            }
        </style>
        <?php
    }
    ?>

    <div class="nasab-booking-form-wrap" dir="rtl">
        <div class="nasab-booking-card">
            <?php if ($success): ?>
                <div class="nasab-booking-eyebrow"><span class="dot"></span>ثبت درخواست</div>
                <div class="nasab-booking-notice success">
                    درخواست شما با موفقیت ثبت شد. به‌زودی با شما تماس گرفته می‌شود.
                </div>
            <?php else: ?>

                <div class="nasab-booking-eyebrow"><span class="dot"></span>رزرو نوبت نصب</div>
                <h3 class="nasab-booking-title">درخواست نصب دوربین و دزدگیر</h3>

                <?php if ($error): ?>
                    <div class="nasab-booking-notice error"><?php echo esc_html($error); ?></div>
                <?php endif; ?>

                <form method="post">
                    <?php wp_nonce_field('nasab_booking_form_action', 'nasab_booking_nonce'); ?>

                    <div class="nasab-field-row">
                        <div class="nasab-field">
                            <label for="nasab_customer_name">نام و نام خانوادگی</label>
                            <input type="text" id="nasab_customer_name" name="customer_name" required>
                        </div>
                        <div class="nasab-field">
                            <label for="nasab_phone">شماره تماس</label>
                            <input type="tel" id="nasab_phone" name="phone" required>
                        </div>
                    </div>

                    <div class="nasab-field-row">
                        <div class="nasab-field">
                            <label for="nasab_property_type">نوع ملک</label>
                            <select id="nasab_property_type" name="property_type">
                                <option value="residential">مسکونی</option>
                                <option value="commercial">تجاری</option>
                                <option value="industrial">صنعتی</option>
                            </select>
                        </div>
                        <div class="nasab-field">
                            <label for="nasab_camera_count">تعداد دوربین (تقریبی)</label>
                            <input type="text" id="nasab_camera_count" name="camera_count" placeholder="مثلاً 4 عدد">
                        </div>
                    </div>

                    <div class="nasab-field-row">
                        <div class="nasab-field">
                            <label for="nasab_preferred_date_display">تاریخ دلخواه نصب</label>
                            <div class="nb-jdate"
                                 data-today="<?php echo esc_attr(current_time('Y-m-d')); ?>"
                                 data-min="<?php echo esc_attr(current_time('Y-m-d')); ?>">
                                <input type="text" id="nasab_preferred_date_display" class="nb-jdate-display"
                                       readonly autocomplete="off" placeholder="انتخاب تاریخ">
                                <input type="hidden" name="preferred_date" value="">
                            </div>
                        </div>
                        <div class="nasab-field">
                            <label for="nasab_preferred_time">بازه زمانی</label>
                            <select id="nasab_preferred_time" name="preferred_time">
                                <option value="">فرقی نمی‌کند</option>
                                <option value="morning">صبح (۸ تا ۱۲)</option>
                                <option value="afternoon">بعدازظهر (۱۲ تا ۱۷)</option>
                                <option value="evening">عصر (۱۷ تا ۲۰)</option>
                            </select>
                        </div>
                    </div>

                    <div class="nasab-field">
                        <label for="nasab_address">آدرس</label>
                        <textarea id="nasab_address" name="address" rows="2"></textarea>
                    </div>

                    <div class="nasab-field">
                        <label for="nasab_notes">توضیحات تکمیلی</label>
                        <textarea id="nasab_notes" name="notes" rows="2"></textarea>
                    </div>

                    <button type="submit" name="nasab_booking_submit" class="nasab-booking-submit">
                        ثبت درخواست نصب
                    </button>
                </form>

            <?php endif; ?>
        </div>
    </div>

    <?php
    return ob_get_clean();
}

/**
 * ====================================================================
 * 3) ارسال ایمیل اطلاع‌رسانی (به ادمین + تاییدیه به مشتری)
 * ====================================================================
 */
function nasab_booking_send_notifications($data) {

    $property_labels = array(
        'residential' => 'مسکونی',
        'commercial'  => 'تجاری',
        'industrial'  => 'صنعتی',
    );
    $property_label = $property_labels[$data['property_type']] ?? $data['property_type'];

    $time_labels = array(
        'morning'   => 'صبح (۸ تا ۱۲)',
        'afternoon' => 'بعدازظهر (۱۲ تا ۱۷)',
        'evening'   => 'عصر (۱۷ تا ۲۰)',
    );
    $preferred_date  = $data['preferred_date'] ?? '';
    $preferred_time  = $data['preferred_time'] ?? '';
    $time_label      = $time_labels[$preferred_time] ?? '';
    $jalali_date     = nasab_booking_format_jalali($preferred_date);
    $date_text       = $jalali_date !== '' ? $jalali_date : $preferred_date;
    $when_parts      = array_filter(array($date_text, $time_label));
    $when_text       = $when_parts ? implode(' - ', $when_parts) : 'مشخص نشده';

    // ------- ۱) ایمیل به ادمین (خودت) -------
    $admin_email = get_option('nasab_booking_admin_email', get_option('admin_email'));

    if (!empty($admin_email)) {
        $subject = 'درخواست نصب جدید ثبت شد';
        $body  = "یک درخواست جدید ثبت شد:\n\n";
        $body .= "نام: {$data['customer_name']}\n";
        $body .= "تماس: {$data['phone']}\n";
        $body .= "نوع ملک: {$property_label}\n";
        $body .= "تعداد دوربین: {$data['camera_count']}\n";
        $body .= "زمان دلخواه نصب: {$when_text}\n";
        $body .= "آدرس: {$data['address']}\n";
        $body .= "توضیحات: {$data['notes']}\n\n";
        $body .= "برای مدیریت به پیشخوان مراجعه کنید: " . admin_url('admin.php?page=nasab-booking-requests');

        wp_mail($admin_email, $subject, $body);
    }

    // ------- ۲) پیامک به ادمین (در صورت فعال بودن و تنظیم شدن) -------
    nasab_booking_maybe_send_sms($data, $property_label);
}

/**
 * ارسال پیامک با استفاده از یک سرویس پیامکی ایرانی.
 * این تابع فقط یک اسکلت (Skeleton) است — باید با API سرویس پیامکی
 * انتخابی خودت (مانند کاوه‌نگار، ملی‌پیامک و ...) تکمیلش کنی.
 */
function nasab_booking_maybe_send_sms($data, $property_label) {
    $sms_enabled = get_option('nasab_booking_sms_enabled', '0');
    if ($sms_enabled !== '1') {
        return; // پیامک غیرفعاله، کاری انجام نده
    }

    $api_key     = get_option('nasab_booking_sms_api_key', '');
    $admin_phone = get_option('nasab_booking_sms_admin_phone', '');

    if (empty($api_key) || empty($admin_phone)) {
        return;
    }

    $message = "درخواست نصب جدید:\n{$data['customer_name']} - {$data['phone']}\nنوع ملک: {$property_label}";

    // نمونه فراخوانی با کاوه‌نگار (Kavenegar) - باید API Key واقعی جایگزین بشه
    // این بخش را با مستندات سرویس پیامکی خودت هماهنگ کن
    /*
    wp_remote_post('https://api.kavenegar.com/v1/' . $api_key . '/sms/send.json', array(
        'body' => array(
            'receptor' => $admin_phone,
            'message'  => $message,
        ),
        'timeout' => 15,
    ));
    */
}

/**
 * ====================================================================
 * 4) صفحه تنظیمات افزونه (ایمیل ادمین + تنظیمات پیامک)
 * ====================================================================
 */
function nasab_booking_settings_page() {
    if (!current_user_can('manage_options')) {
        wp_die('شما اجازه دسترسی به این صفحه را ندارید.');
    }

    if (isset($_POST['nasab_settings_submit']) &&
        isset($_POST['nasab_settings_nonce']) &&
        wp_verify_nonce($_POST['nasab_settings_nonce'], 'nasab_settings_save')) {

        update_option('nasab_booking_admin_email', sanitize_email($_POST['admin_email'] ?? ''));
        update_option('nasab_booking_sms_enabled', isset($_POST['sms_enabled']) ? '1' : '0');
        update_option('nasab_booking_sms_api_key', sanitize_text_field($_POST['sms_api_key'] ?? ''));
        update_option('nasab_booking_sms_admin_phone', sanitize_text_field($_POST['sms_admin_phone'] ?? ''));
        update_option('nasab_booking_delete_data_on_uninstall', isset($_POST['delete_data_on_uninstall']) ? '1' : '0');

        echo '<div class="notice notice-success"><p>تنظیمات ذخیره شد.</p></div>';
    }

    $admin_email      = get_option('nasab_booking_admin_email', get_option('admin_email'));
    $sms_enabled      = get_option('nasab_booking_sms_enabled', '0');
    $sms_api_key      = get_option('nasab_booking_sms_api_key', '');
    $sms_admin_phone  = get_option('nasab_booking_sms_admin_phone', '');
    $delete_on_uninst = get_option('nasab_booking_delete_data_on_uninstall', '0');
    ?>
    <div class="wrap" dir="rtl">
        <h1>تنظیمات اطلاع‌رسانی</h1>
        <form method="post" style="max-width:500px;">
            <?php wp_nonce_field('nasab_settings_save', 'nasab_settings_nonce'); ?>

            <h2>ایمیل</h2>
            <table class="form-table">
                <tr>
                    <th><label for="admin_email">ایمیل دریافت‌کننده</label></th>
                    <td>
                        <input type="email" id="admin_email" name="admin_email"
                               value="<?php echo esc_attr($admin_email); ?>"
                               style="width:100%;padding:8px;">
                        <p class="description">هر درخواست جدید به این ایمیل ارسال می‌شود.</p>
                    </td>
                </tr>
            </table>

            <h2>پیامک (اختیاری)</h2>
            <table class="form-table">
                <tr>
                    <th><label for="sms_enabled">فعال‌سازی پیامک</label></th>
                    <td>
                        <input type="checkbox" id="sms_enabled" name="sms_enabled" value="1"
                               <?php checked($sms_enabled, '1'); ?>>
                        <label for="sms_enabled">ارسال پیامک به ازای هر درخواست</label>
                    </td>
                </tr>
                <tr>
                    <th><label for="sms_api_key">API Key سرویس پیامکی</label></th>
                    <td>
                        <input type="text" id="sms_api_key" name="sms_api_key"
                               value="<?php echo esc_attr($sms_api_key); ?>"
                               style="width:100%;padding:8px;">
                    </td>
                </tr>
                <tr>
                    <th><label for="sms_admin_phone">شماره موبایل دریافت‌کننده</label></th>
                    <td>
                        <input type="text" id="sms_admin_phone" name="sms_admin_phone"
                               value="<?php echo esc_attr($sms_admin_phone); ?>"
                               style="width:100%;padding:8px;">
                    </td>
                </tr>
            </table>

            <p class="description" style="margin-bottom:15px;">
                توجه: بخش پیامک فعلاً یک اسکلت آماده است. برای فعال شدن واقعی باید کد فراخوانی سرویس پیامکی
                (مثل کاوه‌نگار یا ملی‌پیامک) در فایل افزونه تکمیل شود.
            </p>

            <h2>حذف افزونه</h2>
            <table class="form-table">
                <tr>
                    <th><label for="delete_data_on_uninstall">پاک کردن اطلاعات</label></th>
                    <td>
                        <input type="checkbox" id="delete_data_on_uninstall" name="delete_data_on_uninstall" value="1"
                               <?php checked($delete_on_uninst, '1'); ?>>
                        <label for="delete_data_on_uninstall">
                            در صورت حذف کامل افزونه، تمام درخواست‌های ثبت‌شده هم پاک شوند
                        </label>
                        <p class="description">اگر تیک نخورد، حتی بعد از حذف افزونه، اطلاعات در دیتابیس باقی می‌ماند.</p>
                    </td>
                </tr>
            </table>

            <button type="submit" name="nasab_settings_submit" class="button button-primary">
                ذخیره تنظیمات
            </button>
        </form>
    </div>
    <?php
}

/**
 * ====================================================================
 * 5) صفحه مدیریت در پیشخوان وردپرس (منوی اصلی + زیرمنوی تنظیمات)
 * هر دو منو در یک تابع واحد ثبت می‌شوند تا ترتیب ثبت همیشه درست باشد
 * (اول والد، بعد زیرمنو) و مشکل «عدم دسترسی» به‌خاطر ترتیب اجرا پیش نیاید.
 * ====================================================================
 */
add_action('admin_menu', 'nasab_booking_admin_menu');

function nasab_booking_admin_menu() {
    add_menu_page(
        'درخواست‌های نصب',
        'درخواست‌های نصب',
        'manage_options',
        'nasab-booking-requests',
        'nasab_booking_admin_page',
        'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCA2NCA2NCIgd2lkdGg9IjY0IiBoZWlnaHQ9IjY0Ij4KICA8cmVjdCB3aWR0aD0iNjQiIGhlaWdodD0iNjQiIHJ4PSIxMCIgZmlsbD0iIzEwMUIyNiIvPgogIDwhLS0g2q/ZiNi02YfigIzZh9in24wg2YLYp9ioINiv2YjYsdio24zZhtiMINmH2YXYp9mH2YbaryDYqNinINmB2LHZhSDZiCDZvtmG2YQg2YXYr9uM2LHbjNiqIC0tPgogIDxnIHN0cm9rZT0iI0U4QTMzRCIgc3Ryb2tlLXdpZHRoPSIzLjQiIGZpbGw9Im5vbmUiIHN0cm9rZS1saW5lY2FwPSJyb3VuZCI+CiAgICA8cGF0aCBkPSJNMTQgMjIgVjE0IEgyMiIvPgogICAgPHBhdGggZD0iTTUwIDIyIFYxNCBINDIiLz4KICAgIDxwYXRoIGQ9Ik0xNCA0MiBWNTAgSDIyIi8+CiAgICA8cGF0aCBkPSJNNTAgNDIgVjUwIEg0MiIvPgogIDwvZz4KICA8IS0tINmE2YbYsiDYr9mI2LHYqNuM2YYgLS0+CiAgPGNpcmNsZSBjeD0iMzIiIGN5PSIzMiIgcj0iMTEiIGZpbGw9Im5vbmUiIHN0cm9rZT0iI0VBRjBGNCIgc3Ryb2tlLXdpZHRoPSIzIi8+CiAgPGNpcmNsZSBjeD0iMzIiIGN5PSIzMiIgcj0iNC4yIiBmaWxsPSIjRThBMzNEIi8+Cjwvc3ZnPgo=',
        26
    );

    add_submenu_page(
        'nasab-booking-requests',
        'تنظیمات اطلاع‌رسانی',
        'تنظیمات',
        'manage_options',
        'nasab-booking-settings',
        'nasab_booking_settings_page'
    );
}

function nasab_booking_admin_page() {
    if (!current_user_can('manage_options')) {
        wp_die('شما اجازه دسترسی به این صفحه را ندارید.');
    }

    global $wpdb;
    $table_name = $wpdb->prefix . NASAB_BOOKING_TABLE;

    // تغییر وضعیت درخواست
    if (isset($_GET['nasab_action']) && isset($_GET['id']) && isset($_GET['_wpnonce'])) {
        if (wp_verify_nonce($_GET['_wpnonce'], 'nasab_status_change')) {
            $id     = intval($_GET['id']);
            $status = sanitize_text_field($_GET['nasab_action']);
            $allowed_statuses = array('pending', 'confirmed', 'done', 'cancelled');

            if (in_array($status, $allowed_statuses, true)) {
                $wpdb->update(
                    $table_name,
                    array('status' => $status),
                    array('id' => $id),
                    array('%s'),
                    array('%d')
                );
            }
        }
    }

    // حذف درخواست
    $deleted_notice = false;
    if (isset($_GET['nasab_delete']) && isset($_GET['id']) && isset($_GET['_wpnonce'])) {
        if (wp_verify_nonce($_GET['_wpnonce'], 'nasab_delete_request')) {
            $id = intval($_GET['id']);
            $wpdb->delete($table_name, array('id' => $id), array('%d'));
            $deleted_notice = true;
        }
    }

    // ذخیره ویرایش درخواست
    $updated_notice = false;
    if (isset($_POST['nasab_edit_submit']) && isset($_POST['nasab_edit_nonce'])) {
        if (wp_verify_nonce($_POST['nasab_edit_nonce'], 'nasab_edit_request')) {
            $edit_id       = intval($_POST['edit_id'] ?? 0);
            $name          = sanitize_text_field($_POST['customer_name'] ?? '');
            $phone_raw     = sanitize_text_field($_POST['phone'] ?? '');
            $phone         = preg_replace('/[^0-9+\-\s]/', '', $phone_raw);
            $property_type = sanitize_text_field($_POST['property_type'] ?? '');
            $camera_count  = sanitize_text_field($_POST['camera_count'] ?? '');
            $address       = sanitize_textarea_field($_POST['address'] ?? '');
            $notes         = sanitize_textarea_field($_POST['notes'] ?? '');
            $preferred_date = sanitize_text_field($_POST['preferred_date'] ?? '');
            $preferred_time = sanitize_text_field($_POST['preferred_time'] ?? '');

            $allowed_property_types = array('residential', 'commercial', 'industrial');
            if (!in_array($property_type, $allowed_property_types, true)) {
                $property_type = 'residential';
            }

            // برای ویرایش توسط ادمین، تاریخ گذشته هم مجاز است (مثلاً ثبت نوبت قدیمی)، فقط فرمت چک می‌شود
            if (!empty($preferred_date)) {
                $date_obj = DateTime::createFromFormat('Y-m-d', $preferred_date);
                if (!$date_obj || $date_obj->format('Y-m-d') !== $preferred_date) {
                    $preferred_date = '';
                }
            }
            $allowed_times = array('morning', 'afternoon', 'evening');
            if (!in_array($preferred_time, $allowed_times, true)) {
                $preferred_time = '';
            }

            if ($edit_id && !empty($name) && !empty($phone)) {
                $wpdb->update(
                    $table_name,
                    array(
                        'customer_name'  => $name,
                        'phone'          => $phone,
                        'property_type'  => $property_type,
                        'camera_count'   => $camera_count,
                        'address'        => $address,
                        'notes'          => $notes,
                        'preferred_date' => $preferred_date ?: null,
                        'preferred_time' => $preferred_time ?: null,
                    ),
                    array('id' => $edit_id),
                    array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s'),
                    array('%d')
                );
                $updated_notice = true;
            }
        }
    }

    // اگر در حالت ویرایش هستیم، اطلاعات همان درخواست را برای پر کردن فرم واکشی کن
    $editing_request = null;
    if (isset($_GET['edit'])) {
        $edit_id = intval($_GET['edit']);
        $editing_request = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $edit_id)
        );
    }

    // ---------------------------------------------------------------
    // خواندن و پاکسازی پارامترهای جستجو/فیلتر از URL
    // ---------------------------------------------------------------
    $search_term   = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
    $filter_status = isset($_GET['nb_status']) ? sanitize_text_field($_GET['nb_status']) : '';
    $filter_from   = isset($_GET['nb_from']) ? sanitize_text_field($_GET['nb_from']) : '';
    $filter_to     = isset($_GET['nb_to']) ? sanitize_text_field($_GET['nb_to']) : '';

    $valid_statuses = array('pending', 'confirmed', 'done', 'cancelled');
    if (!in_array($filter_status, $valid_statuses, true)) {
        $filter_status = '';
    }
    // فقط تاریخ با فرمت درست میلادی (YYYY-MM-DD) پذیرفته می‌شود؛ در غیر این صورت نادیده گرفته می‌شود
    if ($filter_from && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter_from)) {
        $filter_from = '';
    }
    if ($filter_to && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter_to)) {
        $filter_to = '';
    }

    $has_filters = ($search_term !== '' || $filter_status !== '' || $filter_from !== '' || $filter_to !== '');

    // پارامترهایی که باید بعد از هر عملیات (تغییر وضعیت/ویرایش/حذف) حفظ شوند تا کاربر از لیست فیلترشده بیرون نیفتد
    $carry_filters = array_filter(array(
        's'         => $search_term,
        'nb_status' => $filter_status,
        'nb_from'   => $filter_from,
        'nb_to'     => $filter_to,
    ), function ($v) { return $v !== ''; });

    // ---------------------------------------------------------------
    // ساخت کوئری بر اساس فیلترها
    // ---------------------------------------------------------------
    $where  = array('1=1');
    $params = array();

    if ($search_term !== '') {
        $like     = '%' . $wpdb->esc_like($search_term) . '%';
        $where[]  = '(customer_name LIKE %s OR phone LIKE %s OR address LIKE %s)';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }
    if ($filter_status !== '') {
        $where[]  = 'status = %s';
        $params[] = $filter_status;
    }
    if ($filter_from !== '') {
        $where[]  = 'preferred_date >= %s';
        $params[] = $filter_from;
    }
    if ($filter_to !== '') {
        $where[]  = 'preferred_date <= %s';
        $params[] = $filter_to;
    }

    $sql = "SELECT * FROM $table_name WHERE " . implode(' AND ', $where) . ' ORDER BY created_at DESC';
    if ($params) {
        $sql = $wpdb->prepare($sql, $params);
    }
    $requests = $wpdb->get_results($sql);

    $status_labels = array(
        'pending'   => 'در انتظار',
        'confirmed' => 'تایید شده',
        'done'      => 'انجام شده',
        'cancelled' => 'لغو شده',
    );
    $status_classes = array(
        'pending'   => 'nb-badge-pending',
        'confirmed' => 'nb-badge-confirmed',
        'done'      => 'nb-badge-done',
        'cancelled' => 'nb-badge-cancelled',
    );
    $property_labels = array(
        'residential' => 'مسکونی',
        'commercial'  => 'تجاری',
        'industrial'  => 'صنعتی',
    );
    $time_labels = array(
        'morning'   => 'صبح (۸ تا ۱۲)',
        'afternoon' => 'بعدازظهر (۱۲ تا ۱۷)',
        'evening'   => 'عصر (۱۷ تا ۲۰)',
    );

    // شمارش برای خلاصه بالای صفحه (همیشه بر اساس کل رکوردها، مستقل از فیلتر جستجو)
    $counts = array('pending' => 0, 'confirmed' => 0, 'done' => 0, 'cancelled' => 0);
    $all_status_rows = $wpdb->get_col("SELECT status FROM $table_name");
    foreach ($all_status_rows as $st) {
        if (isset($counts[$st])) {
            $counts[$st]++;
        }
    }
    ?>
    <style>
        .nb-admin-wrap { --nb-accent:#E8A33D; --nb-blue:#5B8DEF; --nb-green:#4FAE83; --nb-red:#E0665C;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Tahoma, sans-serif; }
        .nb-page-head { display:flex; align-items:center; gap:10px; }
        .nb-page-head h1 { margin:0; }
        .nb-brand-mark { border-radius:6px; flex-shrink:0; }
        .nb-summary { display:flex; gap:14px; flex-wrap:wrap; margin: 18px 0 22px; }
        .nb-summary-card { background:#fff; border:1px solid #e2e6ea; border-radius:6px;
            padding:14px 18px; min-width:120px; border-top:3px solid var(--nb-c, #ccc); }
        .nb-summary-card .nb-num { font-size:24px; font-weight:700; line-height:1.2; }
        .nb-summary-card .nb-lbl { font-size:12px; color:#666; margin-top:4px; }

        .nb-badge { display:inline-block; padding:3px 10px; border-radius:12px; font-size:12px; font-weight:600; }
        .nb-badge-pending   { background:rgba(232,163,61,0.15); color:#8a5b13; }
        .nb-badge-confirmed { background:rgba(91,141,239,0.15); color:#2955a3; }
        .nb-badge-done      { background:rgba(79,174,131,0.15); color:#2a7a56; }
        .nb-badge-cancelled { background:rgba(224,102,92,0.15); color:#a33a32; }

        .nb-table { width:100%; border-collapse: collapse; background:#fff; }
        .nb-table th { text-align:right; font-size:12px; color:#666; text-transform:none;
            padding:10px 12px; border-bottom:2px solid #e2e6ea; }
        .nb-table td { padding:12px; border-bottom:1px solid #eef0f2; font-size:13px; vertical-align:top; }
        .nb-status-links a { display:inline-block; margin-inline-end:8px; font-size:12px; color:#555; text-decoration:none; }
        .nb-status-links a:hover { color:var(--nb-accent); text-decoration:underline; }
        .nb-status-links a.nb-edit-link { color:var(--nb-blue); }
        .nb-status-links a.nb-edit-link:hover { color:#2955a3; }
        .nb-status-links a.nb-delete-link { color:var(--nb-red); }
        .nb-status-links a.nb-delete-link:hover { color:#a33a32; }

        /* کارت‌ها به‌جای جدول: مستقل از عرض صفحه، همیشه رفتار قابل‌پیش‌بینی دارند */
        .nb-cards { display:flex; flex-direction:column; gap:12px; }
        .nb-card { background:#fff; border:1px solid #e2e6ea; border-radius:6px; padding:16px; }
        .nb-card-head { display:flex; align-items:center; justify-content:space-between;
            gap:10px; margin-bottom:12px; padding-bottom:12px; border-bottom:1px solid #eef0f2; }
        .nb-card-name { font-size:15px; font-weight:700; color:#222; }
        .nb-card-grid { display:grid; grid-template-columns: repeat(2, 1fr); gap:10px 16px; margin-bottom:14px; }
        .nb-card-item { display:flex; flex-direction:column; gap:2px; min-width:0; }
        .nb-card-item-wide { grid-column: 1 / -1; }
        .nb-card-lbl { font-size:11px; color:#888; }
        .nb-card-val { font-size:13px; color:#222; word-break:break-word; }
        .nb-card .nb-status-links { border-top:1px solid #eef0f2; padding-top:12px; margin-top:2px; }

        @media (max-width: 480px) {
            .nb-card-grid { grid-template-columns: 1fr; }
        }

        .nb-search-box { background:#fff; border:1px solid #e2e6ea; border-radius:6px; padding:16px; margin-bottom:16px; }
        .nb-search-row { display:flex; flex-wrap:wrap; align-items:end; gap:12px; }
        .nb-search-field { display:flex; flex-direction:column; gap:5px; min-width:150px; }
        .nb-search-field label { font-size:12px; color:#666; }
        .nb-search-field input[type="text"], .nb-search-field select {
            padding:7px 10px; border:1px solid #d6dade; border-radius:4px; font-size:13px;
        }
        .nb-search-wide { flex:2 1 220px; }
        .nb-search-actions { flex-direction:row; gap:8px; }
        .nb-search-result-count { font-size:13px; color:#666; margin:0 0 10px; }

        /* پنجره‌ی تقویم در فرم جستجو هم از همان استایل fields.nb-jdate استفاده می‌کند،
           فقط پس‌زمینه‌ی روشن پنل ادمین را در نظر می‌گیریم */
        .nb-search-box .nb-jdate-display { width:100%; box-sizing:border-box; }
    </style>

    <div class="wrap nb-admin-wrap" dir="rtl">
        <div class="nb-page-head">
            <img src="<?php echo esc_url(plugin_dir_url(NASAB_BOOKING_FILE) . 'assets/logo.svg'); ?>"
                 alt="" class="nb-brand-mark" width="30" height="30">
            <h1>درخواست‌های نصب</h1>
        </div>

        <?php if ($deleted_notice): ?>
            <div class="notice notice-success is-dismissible" style="margin-top:12px;">
                <p>درخواست با موفقیت حذف شد.</p>
            </div>
        <?php endif; ?>

        <?php if ($updated_notice): ?>
            <div class="notice notice-success is-dismissible" style="margin-top:12px;">
                <p>تغییرات با موفقیت ذخیره شد.</p>
            </div>
        <?php endif; ?>

        <?php if ($editing_request): ?>
            <div class="nb-edit-box" style="background:#fff;border:1px solid #e2e6ea;border-radius:6px;padding:20px;margin:18px 0;max-width:520px;">
                <h2 style="margin-top:0;">ویرایش درخواست</h2>
                <form method="post">
                    <?php wp_nonce_field('nasab_edit_request', 'nasab_edit_nonce'); ?>
                    <input type="hidden" name="edit_id" value="<?php echo esc_attr($editing_request->id); ?>">

                    <table class="form-table">
                        <tr>
                            <th><label for="edit_customer_name">نام و نام خانوادگی</label></th>
                            <td><input type="text" id="edit_customer_name" name="customer_name"
                                       value="<?php echo esc_attr($editing_request->customer_name); ?>"
                                       style="width:100%;padding:8px;" required></td>
                        </tr>
                        <tr>
                            <th><label for="edit_phone">شماره تماس</label></th>
                            <td><input type="text" id="edit_phone" name="phone"
                                       value="<?php echo esc_attr($editing_request->phone); ?>"
                                       style="width:100%;padding:8px;" required></td>
                        </tr>
                        <tr>
                            <th><label for="edit_property_type">نوع ملک</label></th>
                            <td>
                                <select id="edit_property_type" name="property_type" style="width:100%;padding:8px;">
                                    <?php foreach ($property_labels as $key => $label): ?>
                                        <option value="<?php echo esc_attr($key); ?>"
                                            <?php selected($editing_request->property_type, $key); ?>>
                                            <?php echo esc_html($label); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="edit_camera_count">تعداد دوربین</label></th>
                            <td><input type="text" id="edit_camera_count" name="camera_count"
                                       value="<?php echo esc_attr($editing_request->camera_count); ?>"
                                       style="width:100%;padding:8px;"></td>
                        </tr>
                        <tr>
                            <th><label for="edit_preferred_date_display">تاریخ دلخواه نصب</label></th>
                            <td>
                                <div class="nb-jdate" data-today="<?php echo esc_attr(current_time('Y-m-d')); ?>">
                                    <input type="text" id="edit_preferred_date_display" class="nb-jdate-display"
                                           readonly autocomplete="off" placeholder="انتخاب تاریخ"
                                           style="width:100%;padding:8px;">
                                    <input type="hidden" name="preferred_date"
                                           value="<?php echo esc_attr($editing_request->preferred_date); ?>">
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="edit_preferred_time">بازه زمانی</label></th>
                            <td>
                                <select id="edit_preferred_time" name="preferred_time" style="width:100%;padding:8px;">
                                    <option value="">فرقی نمی‌کند</option>
                                    <?php foreach ($time_labels as $key => $label): ?>
                                        <option value="<?php echo esc_attr($key); ?>"
                                            <?php selected($editing_request->preferred_time, $key); ?>>
                                            <?php echo esc_html($label); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="edit_address">آدرس</label></th>
                            <td><textarea id="edit_address" name="address" rows="2"
                                          style="width:100%;padding:8px;"><?php echo esc_textarea($editing_request->address); ?></textarea></td>
                        </tr>
                        <tr>
                            <th><label for="edit_notes">توضیحات</label></th>
                            <td><textarea id="edit_notes" name="notes" rows="2"
                                          style="width:100%;padding:8px;"><?php echo esc_textarea($editing_request->notes); ?></textarea></td>
                        </tr>
                    </table>

                    <button type="submit" name="nasab_edit_submit" class="button button-primary">ذخیره تغییرات</button>
                    <a href="<?php echo esc_url(add_query_arg(array_merge($carry_filters, array('page' => 'nasab-booking-requests')), admin_url('admin.php'))); ?>" class="button" style="margin-inline-start:8px;">انصراف</a>
                </form>
            </div>
        <?php endif; ?>

        <div class="nb-summary">
            <div class="nb-summary-card" style="--nb-c:#E8A33D;">
                <div class="nb-num"><?php echo (int) $counts['pending']; ?></div>
                <div class="nb-lbl">در انتظار</div>
            </div>
            <div class="nb-summary-card" style="--nb-c:#5B8DEF;">
                <div class="nb-num"><?php echo (int) $counts['confirmed']; ?></div>
                <div class="nb-lbl">تایید شده</div>
            </div>
            <div class="nb-summary-card" style="--nb-c:#4FAE83;">
                <div class="nb-num"><?php echo (int) $counts['done']; ?></div>
                <div class="nb-lbl">انجام شده</div>
            </div>
            <div class="nb-summary-card" style="--nb-c:#E0665C;">
                <div class="nb-num"><?php echo (int) $counts['cancelled']; ?></div>
                <div class="nb-lbl">لغو شده</div>
            </div>
        </div>

        <form method="get" class="nb-search-box">
            <input type="hidden" name="page" value="nasab-booking-requests">

            <div class="nb-search-row">
                <div class="nb-search-field nb-search-wide">
                    <label for="nb_search_s">جستجو</label>
                    <input type="text" id="nb_search_s" name="s"
                           value="<?php echo esc_attr($search_term); ?>"
                           placeholder="نام، شماره تماس یا آدرس">
                </div>

                <div class="nb-search-field">
                    <label for="nb_search_status">وضعیت</label>
                    <select id="nb_search_status" name="nb_status">
                        <option value="">همه</option>
                        <?php foreach ($status_labels as $key => $label): ?>
                            <option value="<?php echo esc_attr($key); ?>" <?php selected($filter_status, $key); ?>>
                                <?php echo esc_html($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="nb-search-field">
                    <label for="nb_search_from_display">از تاریخ</label>
                    <div class="nb-jdate" data-today="<?php echo esc_attr(current_time('Y-m-d')); ?>">
                        <input type="text" id="nb_search_from_display" class="nb-jdate-display"
                               readonly autocomplete="off" placeholder="انتخاب تاریخ">
                        <input type="hidden" name="nb_from" value="<?php echo esc_attr($filter_from); ?>">
                    </div>
                </div>

                <div class="nb-search-field">
                    <label for="nb_search_to_display">تا تاریخ</label>
                    <div class="nb-jdate" data-today="<?php echo esc_attr(current_time('Y-m-d')); ?>">
                        <input type="text" id="nb_search_to_display" class="nb-jdate-display"
                               readonly autocomplete="off" placeholder="انتخاب تاریخ">
                        <input type="hidden" name="nb_to" value="<?php echo esc_attr($filter_to); ?>">
                    </div>
                </div>

                <div class="nb-search-field nb-search-actions">
                    <button type="submit" class="button button-primary">جستجو</button>
                    <?php if ($has_filters): ?>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=nasab-booking-requests')); ?>" class="button">پاک کردن</a>
                    <?php endif; ?>
                </div>
            </div>
        </form>

        <?php if ($has_filters): ?>
            <p class="nb-search-result-count">
                <?php echo count($requests); ?> نتیجه یافت شد.
            </p>
        <?php endif; ?>

        <?php if (empty($requests)): ?>
            <p><?php echo $has_filters ? 'با این فیلتر، درخواستی پیدا نشد.' : 'هنوز درخواستی ثبت نشده است.'; ?></p>
        <?php else: ?>
            <div class="nb-cards">
                <?php foreach ($requests as $r):
                    $status_key   = $r->status;
                    $status_label = $status_labels[$status_key] ?? $status_key;
                    $badge_class  = $status_classes[$status_key] ?? '';
                    $property_label = $property_labels[$r->property_type] ?? $r->property_type;

                    $delete_url = wp_nonce_url(
                        add_query_arg(array_merge($carry_filters, array(
                            'page'         => 'nasab-booking-requests',
                            'nasab_delete' => '1',
                            'id'           => $r->id,
                        )), admin_url('admin.php')),
                        'nasab_delete_request'
                    );
                    $edit_url = add_query_arg(array_merge($carry_filters, array(
                        'page' => 'nasab-booking-requests',
                        'edit' => $r->id,
                    )), admin_url('admin.php'));
                    ?>
                    <div class="nb-card">
                        <div class="nb-card-head">
                            <span class="nb-card-name"><?php echo esc_html($r->customer_name); ?></span>
                            <span class="nb-badge <?php echo esc_attr($badge_class); ?>">
                                <?php echo esc_html($status_label); ?>
                            </span>
                        </div>

                        <div class="nb-card-grid">
                            <div class="nb-card-item">
                                <span class="nb-card-lbl">تماس</span>
                                <span class="nb-card-val"><?php echo esc_html($r->phone); ?></span>
                            </div>
                            <div class="nb-card-item">
                                <span class="nb-card-lbl">نوع ملک</span>
                                <span class="nb-card-val"><?php echo esc_html($property_label); ?></span>
                            </div>
                            <div class="nb-card-item">
                                <span class="nb-card-lbl">تعداد دوربین</span>
                                <span class="nb-card-val"><?php echo esc_html($r->camera_count); ?></span>
                            </div>
                            <div class="nb-card-item">
                                <span class="nb-card-lbl">تاریخ ثبت</span>
                                <span class="nb-card-val"><?php echo esc_html($r->created_at); ?></span>
                            </div>
                            <div class="nb-card-item nb-card-item-wide nb-card-when">
                                <span class="nb-card-lbl">زمان دلخواه نصب</span>
                                <span class="nb-card-val">
                                    <?php
                                    $when_parts = array();
                                    if (!empty($r->preferred_date)) {
                                        $jalali_date = nasab_booking_format_jalali($r->preferred_date);
                                        $when_parts[] = $jalali_date !== '' ? $jalali_date : $r->preferred_date;
                                    }
                                    if (!empty($r->preferred_time) && isset($time_labels[$r->preferred_time])) {
                                        $when_parts[] = $time_labels[$r->preferred_time];
                                    }
                                    echo esc_html($when_parts ? implode(' — ', $when_parts) : 'مشخص نشده');
                                    ?>
                                </span>
                            </div>
                            <div class="nb-card-item nb-card-item-wide">
                                <span class="nb-card-lbl">آدرس</span>
                                <span class="nb-card-val"><?php echo esc_html($r->address); ?></span>
                            </div>
                            <?php if (!empty($r->notes)): ?>
                            <div class="nb-card-item nb-card-item-wide">
                                <span class="nb-card-lbl">توضیحات</span>
                                <span class="nb-card-val"><?php echo esc_html($r->notes); ?></span>
                            </div>
                            <?php endif; ?>
                        </div>

                        <div class="nb-status-links">
                            <?php foreach ($status_labels as $key => $label):
                                if ($key === $r->status) continue;
                                $url = wp_nonce_url(
                                    add_query_arg(array_merge($carry_filters, array(
                                        'page'         => 'nasab-booking-requests',
                                        'nasab_action' => $key,
                                        'id'           => $r->id,
                                    )), admin_url('admin.php')),
                                    'nasab_status_change'
                                );
                                ?>
                                <a href="<?php echo esc_url($url); ?>"><?php echo esc_html($label); ?></a>
                            <?php endforeach; ?>
                            <a href="<?php echo esc_url($edit_url); ?>" class="nb-edit-link">ویرایش</a>
                            <a href="<?php echo esc_url($delete_url); ?>"
                               class="nb-delete-link"
                               onclick="return confirm('این درخواست برای همیشه حذف می‌شود. مطمئنید؟');">
                                حذف
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php
}
