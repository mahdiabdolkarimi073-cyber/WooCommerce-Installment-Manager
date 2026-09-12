<?php
/**
 * SMS Settings — registers the SMS settings section within the WooCommerce
 * installment settings tab, handles form saving, and the test SMS AJAX endpoint.
 *
 * @package WC_Installment_Payment
 */

if (!defined('ABSPATH')) {
    exit;
}

class SMS_Settings
{
    private static $instance = null;

    public static function instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        add_action('wp_ajax_wcip_send_test_sms', array($this, 'ajax_send_test_sms'));
    }

    public function get_settings_fields()
    {
        $manager = SMS_Manager::instance();
        $providers = $manager->get_available_providers();

        return array(

            // ===== SMS Notification Settings =====
            array(
                'title' => __('تنظیمات پیامک خودکار', 'wc-installment'),
                'type'  => 'title',
                'desc'  => __('سیستم اطلاع‌رسانی پیامک خودکار برای اقساط. پنل پیامک خود را انتخاب و پیکربندی کنید.', 'wc-installment'),
                'id'    => 'wcip_section_sms_auto',
            ),

            array(
                'title'   => __('فعال‌سازی اطلاع‌رسانی پیامک', 'wc-installment'),
                'desc'    => __('فعال/غیرفعال کردن ارسال خودکار پیامک', 'wc-installment'),
                'id'      => 'wcip_sms_enabled',
                'default' => 'no',
                'type'    => 'checkbox',
                'checkboxgroup' => 'start',
            ),

            array(
                'title'   => __('پنل پیامک', 'wc-installment'),
                'id'      => 'wcip_sms_provider',
                'default' => 'none',
                'type'    => 'select',
                'options' => $providers,
                'desc'    => __('پنل پیامک مورد استفاده را انتخاب کنید.', 'wc-installment'),
            ),

            array(
                'title'   => __('کلید / توکن API', 'wc-installment'),
                'id'      => 'wcip_sms_api_key',
                'default' => '',
                'type'    => 'text',
                'desc'    => __('کلید یا توکن دریافتی از پنل پیامک', 'wc-installment'),
            ),

            array(
                'title'   => __('نام کاربری (برای ملی‌پیامک)', 'wc-installment'),
                'id'      => 'wcip_sms_username',
                'default' => '',
                'type'    => 'text',
                'desc'    => __('نام کاربری پنل پیامک (فقط برای ملی‌پیامک)', 'wc-installment'),
            ),

            array(
                'title'   => __('شماره ارسال‌کننده', 'wc-installment'),
                'id'      => 'wcip_sms_sender_number',
                'default' => '',
                'type'    => 'text',
                'desc'    => __('شماره فرستنده پیامک (خط اختصاصی)', 'wc-installment'),
            ),

            array(
                'title'       => __('تعداد روز قبل از سررسید', 'wc-installment'),
                'id'          => 'wcip_sms_pre_due_days',
                'default'     => '3',
                'type'        => 'number',
                'custom_attributes' => array('min' => '1', 'max' => '30', 'step' => '1'),
                'desc'        => __('چند روز قبل از سررسید قسط، پیامک یادآوری ارسال شود.', 'wc-installment'),
            ),

            array(
                'title'   => __('پیامک قبل از سررسید', 'wc-installment'),
                'desc'    => __('فعال‌سازی ارسال پیامک یادآوری قبل از سررسید', 'wc-installment'),
                'id'      => 'wcip_sms_pre_due_enabled',
                'default' => 'yes',
                'type'    => 'checkbox',
            ),

            array(
                'title'   => __('پیامک روز سررسید', 'wc-installment'),
                'desc'    => __('فعال‌سازی ارسال پیامک در روز سررسید', 'wc-installment'),
                'id'      => 'wcip_sms_due_date_enabled',
                'default' => 'yes',
                'type'    => 'checkbox',
            ),

            array(
                'title'   => __('پیامک معوق', 'wc-installment'),
                'desc'    => __('فعال‌سازی ارسال پیامک هشدار معوق شدن', 'wc-installment'),
                'id'      => 'wcip_sms_overdue_enabled',
                'default' => 'yes',
                'type'    => 'checkbox',
            ),

            array(
                'title'   => __('قالب پیامک قبل از سررسید', 'wc-installment'),
                'id'      => 'wcip_sms_template_pre_due',
                'default' => 'مشتری گرامی، قسط شماره {installment_number} سفارش #{order_id} به مبلغ {amount} تومان در تاریخ {due_date} سررسید می‌شود.',
                'type'    => 'textarea',
                'desc'    => __('متغیرها: {installment_number}, {order_id}, {amount}, {due_date}, {customer_name}, {shop_name}', 'wc-installment'),
                'css'     => 'min-width:500px; min-height:80px;',
            ),

            array(
                'title'   => __('قالب پیامک روز سررسید', 'wc-installment'),
                'id'      => 'wcip_sms_template_due_date',
                'default' => 'مشتری گرامی، امروز موعد پرداخت قسط شما به مبلغ {amount} تومان است.',
                'type'    => 'textarea',
                'desc'    => __('متغیرها: {installment_number}, {order_id}, {amount}, {due_date}, {customer_name}, {shop_name}', 'wc-installment'),
                'css'     => 'min-width:500px; min-height:80px;',
            ),

            array(
                'title'   => __('قالب پیامک معوق', 'wc-installment'),
                'id'      => 'wcip_sms_template_overdue',
                'default' => 'مشتری گرامی، قسط شماره {installment_number} شما به مبلغ {amount} تومان پرداخت نشده و معوق شده است. لطفاً نسبت به پرداخت آن اقدام فرمایید.',
                'type'    => 'textarea',
                'desc'    => __('متغیرها: {installment_number}, {order_id}, {amount}, {due_date}, {customer_name}, {shop_name}', 'wc-installment'),
                'css'     => 'min-width:500px; min-height:80px;',
            ),

            array(
                'type' => 'sectionend',
                'id'   => 'wcip_section_sms_auto',
            ),
        );
    }

    public function save_settings()
    {
        $fields = $this->get_settings_fields();

        foreach ($fields as $field) {
            if (!isset($field['id']) || $field['type'] === 'title' || $field['type'] === 'sectionend') {
                continue;
            }

            $option_key = $field['id'];
            $default = isset($field['default']) ? $field['default'] : '';

            if ($field['type'] === 'checkbox') {
                $value = isset($_POST[$option_key]) ? 'yes' : 'no';
            } elseif ($field['type'] === 'number') {
                $value = isset($_POST[$option_key]) ? absint($_POST[$option_key]) : $default;
            } elseif ($field['type'] === 'textarea') {
                $value = isset($_POST[$option_key]) ? sanitize_textarea_field(wp_unslash($_POST[$option_key])) : $default;
            } elseif ($field['type'] === 'select') {
                $value = isset($_POST[$option_key]) ? sanitize_text_field($_POST[$option_key]) : $default;
            } else {
                $value = isset($_POST[$option_key]) ? sanitize_text_field($_POST[$option_key]) : $default;
            }

            update_option($option_key, $value);
        }
    }

    public function ajax_send_test_sms()
    {
        check_ajax_referer('wcip-admin', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('شما دسترسی لازم را ندارید.', 'wc-installment')));
        }

        $phone = isset($_POST['phone']) ? sanitize_text_field($_POST['phone']) : '';
        if (empty($phone)) {
            wp_send_json_error(array('message' => __('شماره تلفن را وارد کنید.', 'wc-installment')));
        }

        $manager = SMS_Manager::instance();

        if (!$manager->is_enabled()) {
            wp_send_json_error(array('message' => __('سیستم پیامک فعال نیست یا پنل انتخاب نشده است.', 'wc-installment')));
        }

        $provider = $manager->get_provider();
        if (!$provider || !$provider->validate_config()) {
            wp_send_json_error(array('message' => __('تنظیمات پنل پیامک کامل نیست.', 'wc-installment')));
        }

        $result = $manager->send_test_sms($phone);

        if ($result['success']) {
            wp_send_json_success(array('message' => __('پیامک آزمایشی با موفقیت ارسال شد.', 'wc-installment')));
        } else {
            wp_send_json_error(array('message' => __('ارسال پیامک ناموفق بود. تنظیمات را بررسی کنید.', 'wc-installment')));
        }
    }
}
