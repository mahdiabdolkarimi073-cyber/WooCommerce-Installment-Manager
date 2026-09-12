<?php
/**
 * Plugin Name: WooCommerce Installment Payment
 * Plugin URI: https://example.com/wc-installment-payment
 * Description: افزونه پرداخت اقساطی برای ووکامرس — امکان تعیین پیش‌پرداخت، طرح‌های اقساطی متعدد با سود اختصاصی و انتخاب روش پرداخت نقدی/اقساطی در صفحه محصول.
 * Version: 2.0.0
 * Author: Your Name
 * Author URI: https://example.com
 * Text Domain: wc-installment
 * Domain Path: /languages
 * Requires at least: 5.0
 * Requires PHP: 7.2
 * WC requires at least: 5.0
 * WC tested up to: 8.0
 * License: GPL v2 or later
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Plugin constants
 */
define('WCIP_VERSION', '2.0.0');
define('WCIP_PLUGIN_FILE', __FILE__);
define('WCIP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WCIP_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WCIP_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Check if WooCommerce is active.
 *
 * @return bool
 */
function wcip_is_woocommerce_active()
{
    $active_plugins = (array) get_option('active_plugins', array());
    if (is_multisite()) {
        $active_plugins = array_merge($active_plugins, get_site_option('active_sitewide_plugins', array()));
    }
    $wc_active = in_array('woocommerce/woocommerce.php', $active_plugins, true)
        || array_key_exists('woocommerce/woocommerce.php', $active_plugins);
    return $wc_active;
}

/**
 * Deactivate the plugin and show an admin notice if WooCommerce is missing.
 */
function wcip_dependency_check()
{
    if (!wcip_is_woocommerce_active()) {
        deactivate_plugins(WCIP_PLUGIN_BASENAME);
        add_action('admin_notices', 'wcip_missing_wc_notice');
        if (isset($_GET['activate'])) {
            unset($_GET['activate']);
        }
    }
}
add_action('admin_init', 'wcip_dependency_check');

/**
 * Admin notice displayed when WooCommerce is not installed/active.
 */
function wcip_missing_wc_notice()
{
    echo '<div class="notice notice-error is-dismissible"><p>';
    esc_html_e('افزونه «پرداخت اقساطی ووکامرس» برای فعالیت نیاز به نصب و فعال‌سازی ووکامرس دارد. لطفاً ابتدا ووکامرس را فعال کنید.', 'wc-installment');
    echo '</p></div>';
}

/**
 * Declare compatibility with WooCommerce High-Performance Order Storage (HPOS).
 */
add_action('before_woocommerce_init', function () {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', WCIP_PLUGIN_FILE, true);
    }
});

/**
 * Load plugin textdomain for Persian translation.
 */
function wcip_load_textdomain()
{
    load_plugin_textdomain('wc-installment', false, dirname(WCIP_PLUGIN_BASENAME) . '/languages');
}
add_action('init', 'wcip_load_textdomain');

/**
 * Initialize the plugin once all dependencies are confirmed.
 */
function wcip_init_plugin()
{
    if (!wcip_is_woocommerce_active()) {
        return;
    }

    // Core helper functions
    require_once WCIP_PLUGIN_DIR . 'includes/class-wcip-helper.php';

    // Database management (installments table + migrations)
    require_once WCIP_PLUGIN_DIR . 'includes/class-wcip-db.php';

    // Global settings (WooCommerce settings tab)
    require_once WCIP_PLUGIN_DIR . 'includes/class-wcip-settings.php';

    // Per-product settings (product data tab + plans builder)
    require_once WCIP_PLUGIN_DIR . 'includes/class-wcip-product-settings.php';

    // Calculation engine
    require_once WCIP_PLUGIN_DIR . 'includes/class-wcip-calculator.php';

    // REST API endpoints
    require_once WCIP_PLUGIN_DIR . 'includes/class-wcip-rest-api.php';

    // Frontend product page display
    require_once WCIP_PLUGIN_DIR . 'includes/class-wcip-frontend.php';

    // Checkout & order handling
    require_once WCIP_PLUGIN_DIR . 'includes/class-wcip-checkout.php';

    // Admin order meta box
    require_once WCIP_PLUGIN_DIR . 'includes/class-wcip-admin-order.php';

    // SMS notification system (auto-detects active SMS plugins)
    require_once WCIP_PLUGIN_DIR . 'includes/class-wcip-sms.php';

    // Payment gateway auto-detector
    require_once WCIP_PLUGIN_DIR . 'includes/class-wcip-gateway-detector.php';

    // Custom installment payment gateway
    require_once WCIP_PLUGIN_DIR . 'includes/class-wcip-gateway.php';

    // Payment gateway integration
    require_once WCIP_PLUGIN_DIR . 'includes/class-wcip-payment.php';

    // Installments list table (WP_List_Table)
    require_once WCIP_PLUGIN_DIR . 'includes/class-wcip-installments-list-table.php';

    // Admin installments management page
    require_once WCIP_PLUGIN_DIR . 'includes/class-wcip-admin-installments.php';

    // In-app reminder system
    require_once WCIP_PLUGIN_DIR . 'includes/class-wcip-reminders.php';

    // Reports list table (WP_List_Table)
    require_once WCIP_PLUGIN_DIR . 'includes/class-wcip-reports-list-table.php';

    // Admin reports dashboard (8 report types + period comparison)
    require_once WCIP_PLUGIN_DIR . 'includes/class-wcip-reports.php';

    // Early settlement (customer request + admin approval with discount)
    require_once WCIP_PLUGIN_DIR . 'includes/class-wcip-settlement.php';

    // Register the installment payment gateway with WooCommerce.
    add_filter('woocommerce_payment_gateways', 'wcip_register_installment_gateway');

    // Run database upgrade/migration check.
    WCIP_DB::instance()->maybe_upgrade();

    // Instantiate singletons
    WCIP_Settings::instance();
    WCIP_Product_Settings::instance();
    WCIP_Calculator::instance();
    WCIP_REST_API::instance();
    WCIP_Frontend::instance();
    WCIP_Checkout::instance();
    WCIP_Admin_Order::instance();
    WCIP_SMS::instance();
    WCIP_Gateway_Detector::instance();
    WCIP_Payment::instance();
    WCIP_Admin_Installments::instance();
    WCIP_Reminders::instance();
    WCIP_Reports::instance();
    WCIP_Settlement::instance();
}
add_action('plugins_loaded', 'wcip_init_plugin');

/**
 * Activation hook — creates the database table and schedules cron.
 */
function wcip_activate_plugin()
{
    if (!wcip_is_woocommerce_active()) {
        return;
    }

    require_once WCIP_PLUGIN_DIR . 'includes/class-wcip-db.php';
    require_once WCIP_PLUGIN_DIR . 'includes/class-wcip-sms.php';

    WCIP_DB::instance()->create_table();
    SMS_Logger::instance()->create_table();
    SMS_Scheduler::schedule_cron();

    // Flush rewrite rules for the My Account endpoint.
    flush_rewrite_rules();
}
register_activation_hook(__FILE__, 'wcip_activate_plugin');

/**
 * Deactivation hook — removes the scheduled cron event.
 */
function wcip_deactivate_plugin()
{
    if (!class_exists('WCIP_SMS')) {
        require_once WCIP_PLUGIN_DIR . 'includes/class-wcip-sms.php';
    }
    SMS_Scheduler::unschedule_cron();
}
register_deactivation_hook(__FILE__, 'wcip_deactivate_plugin');

/**
 * Enqueue admin assets.
 */
function wcip_admin_assets($hook)
{
    wp_enqueue_style(
        'wcip-admin-style',
        WCIP_PLUGIN_URL . 'assets/css/admin.css',
        array(),
        WCIP_VERSION
    );
    wp_enqueue_script(
        'wcip-admin-script',
        WCIP_PLUGIN_URL . 'assets/js/admin.js',
        array('jquery'),
        WCIP_VERSION,
        true
    );

    // Localize admin script for nonce and AJAX on installments page.
    wp_localize_script('wcip-admin-script', 'wcipAdmin', array(
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce'   => wp_create_nonce('wcip-admin'),
        'confirmDelete' => __('آیا از حذف این قسط مطمئن هستید؟', 'wc-installment'),
    ));
}
add_action('admin_enqueue_scripts', 'wcip_admin_assets');

/**
 * Enqueue frontend assets.
 */
function wcip_frontend_assets()
{
    if (is_product() || is_cart() || is_checkout()) {
        wp_enqueue_style(
            'wcip-frontend-style',
            WCIP_PLUGIN_URL . 'assets/css/frontend.css',
            array(),
            WCIP_VERSION
        );
    }

    if (is_product()) {
        wp_enqueue_script(
            'wcip-frontend-script',
            WCIP_PLUGIN_URL . 'assets/js/frontend.js',
            array('jquery'),
            WCIP_VERSION,
            true
        );
    }

    // Enqueue on cart and checkout for the installment toggle selector.
    if (is_cart() || is_checkout()) {
        wp_enqueue_script(
            'wcip-frontend-script',
            WCIP_PLUGIN_URL . 'assets/js/frontend.js',
            array('jquery'),
            WCIP_VERSION,
            true
        );

        wp_localize_script('wcip-frontend-script', 'wcipToggle', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('wcip-toggle-installment'),
        ));
    }

    // Enqueue on My Account page for the installments feature.
    if (is_account_page()) {
        wp_enqueue_style(
            'wcip-frontend-style',
            WCIP_PLUGIN_URL . 'assets/css/frontend.css',
            array(),
            WCIP_VERSION
        );
        wp_enqueue_script(
            'wcip-frontend-script',
            WCIP_PLUGIN_URL . 'assets/js/frontend.js',
            array('jquery'),
            WCIP_VERSION,
            true
        );
    }
}
add_action('wp_enqueue_scripts', 'wcip_frontend_assets');

/**
 * Registers the installment payment gateway with WooCommerce.
 *
 * @param array $gateways Existing payment gateways.
 * @return array
 */
function wcip_register_installment_gateway($gateways)
{
    $gateways[] = 'WC_Gateway_WCIP_Installment';
    return $gateways;
}

/**
 * Adds a top-level admin menu for the installment plugin with quick links
 * to settings, installments management, and reports.
 */
function wcip_add_admin_top_menu()
{
    add_menu_page(
        __('پرداخت اقساطی', 'wc-installment'),
        __('پرداخت اقساطی', 'wc-installment'),
        'manage_woocommerce',
        'wcip-dashboard',
        'wcip_render_dashboard_page',
        'dashicons-calendar-alt',
        56
    );

    add_submenu_page(
        'wcip-dashboard',
        __('داشبورد', 'wc-installment'),
        __('داشبورد', 'wc-installment'),
        'manage_woocommerce',
        'wcip-dashboard',
        'wcip_render_dashboard_page'
    );

    add_submenu_page(
        'wcip-dashboard',
        __('تنظیمات اقساط', 'wc-installment'),
        __('تنظیمات اقساط', 'wc-installment'),
        'manage_woocommerce',
        'wcip-settings',
        'wcip_render_settings_redirect_page'
    );

    add_submenu_page(
        'wcip-dashboard',
        __('مدیریت اقساط', 'wc-installment'),
        __('مدیریت اقساط', 'wc-installment'),
        'manage_woocommerce',
        'wcip-installments-link',
        'wcip_render_installments_redirect_page'
    );

    add_submenu_page(
        'wcip-dashboard',
        __('گزارشات و تسویه', 'wc-installment'),
        __('گزارشات و تسویه', 'wc-installment'),
        'manage_woocommerce',
        'wcip-reports-link',
        'wcip_render_reports_redirect_page'
    );

    add_submenu_page(
        'wcip-dashboard',
        __('درخواست‌های تسویه', 'wc-installment'),
        __('درخواست‌های تسویه', 'wc-installment'),
        'manage_woocommerce',
        'wcip-settlement-link',
        'wcip_render_settlement_redirect_page'
    );
}
add_action('admin_menu', 'wcip_add_admin_top_menu');

/**
 * Renders the dashboard page with summary cards and quick links.
 */
function wcip_render_dashboard_page()
{
    if (!current_user_can('manage_woocommerce') && !current_user_can('manage_options')) {
        echo '<p>' . esc_html__('شما اجازه دسترسی به این صفحه را ندارید.', 'wc-installment') . '</p>';
        return;
    }

    $db = WCIP_DB::instance();
    $all_installments = $db->get_installments(array('per_page' => 10000, 'page' => 1));
    $total_count = count($all_installments);
    $paid_count = 0;
    $unpaid_count = 0;
    $overdue_count = 0;
    $total_amount = 0;
    $paid_amount = 0;

    foreach ($all_installments as $inst) {
        $dyn = $db->compute_dynamic_status($inst);
        $total_amount += floatval($inst->amount);
        if ($dyn === 'paid') {
            $paid_count++;
            $paid_amount += floatval($inst->amount);
        } elseif ($dyn === 'overdue') {
            $overdue_count++;
            $unpaid_count++;
        } else {
            $unpaid_count++;
        }
    }

    $remaining_amount = $total_amount - $paid_amount;
    ?>
    <div class="wrap wcip-dashboard-wrap">
        <h1><?php esc_html_e('پرداخت اقساطی — داشبورد', 'wc-installment'); ?></h1>

        <div class="wcip-dashboard-cards">
            <div class="wcip-dashboard-card">
                <span class="wcip-card-icon dashicons dashicons-calendar-alt"></span>
                <span class="wcip-card-number"><?php echo esc_html(number_to_persian($total_count)); ?></span>
                <span class="wcip-card-label"><?php esc_html_e('کل اقساط', 'wc-installment'); ?></span>
            </div>
            <div class="wcip-dashboard-card wcip-card-paid">
                <span class="wcip-card-icon dashicons dashicons-yes-alt"></span>
                <span class="wcip-card-number"><?php echo esc_html(number_to_persian($paid_count)); ?></span>
                <span class="wcip-card-label"><?php esc_html_e('پرداخت‌شده', 'wc-installment'); ?></span>
            </div>
            <div class="wcip-dashboard-card wcip-card-unpaid">
                <span class="wcip-card-icon dashicons dashicons-clock"></span>
                <span class="wcip-card-number"><?php echo esc_html(number_to_persian($unpaid_count)); ?></span>
                <span class="wcip-card-label"><?php esc_html_e('در انتظار پرداخت', 'wc-installment'); ?></span>
            </div>
            <div class="wcip-dashboard-card wcip-card-overdue">
                <span class="wcip-card-icon dashicons dashicons-warning"></span>
                <span class="wcip-card-number"><?php echo esc_html(number_to_persian($overdue_count)); ?></span>
                <span class="wcip-card-label"><?php esc_html_e('معوق', 'wc-installment'); ?></span>
            </div>
        </div>

        <div class="wcip-dashboard-amounts">
            <div class="wcip-amount-row">
                <span class="wcip-amount-label"><?php esc_html_e('مبلغ کل اقساط:', 'wc-installment'); ?></span>
                <span class="wcip-amount-value"><?php echo esc_html(wcip_format_toman($total_amount)); ?></span>
            </div>
            <div class="wcip-amount-row">
                <span class="wcip-amount-label"><?php esc_html_e('مبلغ پرداخت‌شده:', 'wc-installment'); ?></span>
                <span class="wcip-amount-value"><?php echo esc_html(wcip_format_toman($paid_amount)); ?></span>
            </div>
            <div class="wcip-amount-row">
                <span class="wcip-amount-label"><?php esc_html_e('مبلغ باقی‌مانده:', 'wc-installment'); ?></span>
                <span class="wcip-amount-value"><?php echo esc_html(wcip_format_toman($remaining_amount)); ?></span>
            </div>
        </div>

        <div class="wcip-dashboard-actions">
            <a href="<?php echo esc_url(admin_url('admin.php?page=wcip-installments')); ?>" class="button button-primary">
                <?php esc_html_e('مدیریت اقساط', 'wc-installment'); ?>
            </a>
            <a href="<?php echo esc_url(admin_url('admin.php?page=wcip-reports')); ?>" class="button button-primary">
                <?php esc_html_e('گزارشات و تسویه', 'wc-installment'); ?>
            </a>
            <a href="<?php echo esc_url(admin_url('admin.php?page=wcip-settings')); ?>" class="button button-secondary">
                <?php esc_html_e('تنظیمات اقساط', 'wc-installment'); ?>
            </a>
            <a href="<?php echo esc_url(admin_url('admin.php?page=wcip-settlement-requests')); ?>" class="button button-secondary">
                <?php esc_html_e('درخواست‌های تسویه', 'wc-installment'); ?>
            </a>
        </div>
    </div>
    <?php
}

/**
 * Renders a redirect page that sends the user to the WooCommerce settings tab.
 */
function wcip_render_settings_redirect_page()
{
    if (!current_user_can('manage_woocommerce') && !current_user_can('manage_options')) {
        echo '<p>' . esc_html__('شما اجازه دسترسی به این صفحه را ندارید.', 'wc-installment') . '</p>';
        return;
    }

    $settings_url = admin_url('admin.php?page=wc-settings&tab=installment');
    echo '<div class="wrap">';
    echo '<h1>' . esc_html__('تنظیمات اقساط', 'wc-installment') . '</h1>';
    echo '<p>' . esc_html__('در حال انتقال به صفحه تنظیمات...', 'wc-installment') . '</p>';
    echo '<script type="text/javascript">window.location.href = "' . esc_js($settings_url) . '";</script>';
    echo '<p><a href="' . esc_url($settings_url) . '" class="button button-primary">' . esc_html__('رفتن به تنظیمات', 'wc-installment') . '</a></p>';
    echo '</div>';
}

/**
 * Renders a redirect page that sends the user to the WooCommerce installments management page.
 */
function wcip_render_installments_redirect_page()
{
    if (!current_user_can('manage_woocommerce') && !current_user_can('manage_options')) {
        echo '<p>' . esc_html__('شما اجازه دسترسی به این صفحه را ندارید.', 'wc-installment') . '</p>';
        return;
    }

    $url = admin_url('admin.php?page=wcip-installments');
    echo '<div class="wrap">';
    echo '<h1>' . esc_html__('مدیریت اقساط', 'wc-installment') . '</h1>';
    echo '<p>' . esc_html__('در حال انتقال...', 'wc-installment') . '</p>';
    echo '<script type="text/javascript">window.location.href = "' . esc_js($url) . '";</script>';
    echo '<p><a href="' . esc_url($url) . '" class="button button-primary">' . esc_html__('رفتن به مدیریت اقساط', 'wc-installment') . '</a></p>';
    echo '</div>';
}

/**
 * Renders a redirect page that sends the user to the WooCommerce reports page.
 */
function wcip_render_reports_redirect_page()
{
    if (!current_user_can('manage_woocommerce') && !current_user_can('manage_options')) {
        echo '<p>' . esc_html__('شما اجازه دسترسی به این صفحه را ندارید.', 'wc-installment') . '</p>';
        return;
    }

    $url = admin_url('admin.php?page=wcip-reports');
    echo '<div class="wrap">';
    echo '<h1>' . esc_html__('گزارشات و تسویه', 'wc-installment') . '</h1>';
    echo '<p>' . esc_html__('در حال انتقال...', 'wc-installment') . '</p>';
    echo '<script type="text/javascript">window.location.href = "' . esc_js($url) . '";</script>';
    echo '<p><a href="' . esc_url($url) . '" class="button button-primary">' . esc_html__('رفتن به گزارشات', 'wc-installment') . '</a></p>';
    echo '</div>';
}

/**
 * Renders a redirect page that sends the user to the settlement requests page.
 */
function wcip_render_settlement_redirect_page()
{
    if (!current_user_can('manage_woocommerce') && !current_user_can('manage_options')) {
        echo '<p>' . esc_html__('شما اجازه دسترسی به این صفحه را ندارید.', 'wc-installment') . '</p>';
        return;
    }

    $url = admin_url('admin.php?page=wcip-settlement-requests');
    echo '<div class="wrap">';
    echo '<h1>' . esc_html__('درخواست‌های تسویه', 'wc-installment') . '</h1>';
    echo '<p>' . esc_html__('در حال انتقال...', 'wc-installment') . '</p>';
    echo '<script type="text/javascript">window.location.href = "' . esc_js($url) . '";</script>';
    echo '<p><a href="' . esc_url($url) . '" class="button button-primary">' . esc_html__('رفتن به درخواست‌های تسویه', 'wc-installment') . '</a></p>';
    echo '</div>';
}
