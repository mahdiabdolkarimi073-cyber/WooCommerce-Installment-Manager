<?php
/**
 * Global installment settings — adds a tab under WooCommerce → Settings.
 *
 * @package WC_Installment_Payment
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('WCIP_Settings')) {

    class WCIP_Settings
    {
        /**
         * @var WCIP_Settings|null
         */
        private static $instance = null;

        /**
         * @var string Settings option key.
         */
        const OPTION_KEY = 'wcip_global_settings';

        /**
         * Singleton instance.
         *
         * @return WCIP_Settings
         */
        public static function instance()
        {
            if (null === self::$instance) {
                self::$instance = new self();
            }
            return self::$instance;
        }

        /**
         * Constructor.
         */
        private function __construct()
        {
            add_filter('woocommerce_settings_tabs_array', array($this, 'add_settings_tab'), 50);
            add_action('woocommerce_settings_tabs_installment', array($this, 'render_settings_tab'));
            add_action('woocommerce_update_options_installment', array($this, 'save_settings'));
        }

        /**
         * Adds the "پرداخت اقساطی" tab to WooCommerce settings tabs.
         *
         * @param array $tabs Existing tabs.
         * @return array
         */
        public function add_settings_tab($tabs)
        {
            $tabs['installment'] = __('پرداخت اقساطی', 'wc-installment');
            return $tabs;
        }

        /**
         * Returns all setting field definitions.
         *
         * @return array
         */
        public function get_settings_fields()
        {
            $settings = array(

                array(
                    'title' => __('تنظیمات سراسری پرداخت اقساطی', 'wc-installment'),
                    'type'  => 'title',
                    'desc'  => __('از این بخش می‌توانید تنظیمات پیش‌فرض پرداخت اقساطی را برای تمام محصولات مشخص کنید.', 'wc-installment'),
                    'id'    => 'wcip_section_general',
                ),

                array(
                    'title'   => __('فعال‌سازی افزونه', 'wc-installment'),
                    'desc'    => __('فعال/غیرفعال کردن افزونه پرداخت اقساطی به‌صورت سراسری', 'wc-installment'),
                    'id'      => 'wcip_enabled',
                    'default' => 'no',
                    'type'    => 'checkbox',
                    'checkboxgroup' => 'start',
                ),

                array(
                    'title'   => __('نوع پیش‌پرداخت', 'wc-installment'),
                    'id'      => 'wcip_down_payment_type',
                    'default' => 'percentage',
                    'type'    => 'select',
                    'options' => array(
                        'percentage' => __('درصدی', 'wc-installment'),
                        'fixed'      => __('مبلغ ثابت', 'wc-installment'),
                    ),
                    'desc'    => __('نوع مبلغ پیش‌پرداخت را انتخاب کنید.', 'wc-installment'),
                ),

                array(
                    'title'       => __('مقدار پیش‌پرداخت پیش‌فرض', 'wc-installment'),
                    'id'          => 'wcip_down_payment_value',
                    'default'     => '30',
                    'type'        => 'text',
                    'desc'        => __('اگر درصدی باشد، عددی بین ۰ تا ۱۰۰ وارد کنید. اگر ثابت باشد، مبلغ را به تومان وارد کنید.', 'wc-installment'),
                ),

                array(
                    'title'   => __('روش قسط‌بندی', 'wc-installment'),
                    'id'      => 'wcip_installment_method',
                    'default' => 'installments',
                    'type'    => 'select',
                    'options' => array(
                        'installments' => __('تعداد اقساط', 'wc-installment'),
                        'months'       => __('تعداد ماه', 'wc-installment'),
                    ),
                    'desc'    => __('روش قسط‌بندی را انتخاب کنید.', 'wc-installment'),
                ),

                array(
                    'title'       => __('تعداد اقساط یا ماه پیش‌فرض', 'wc-installment'),
                    'id'          => 'wcip_installment_count',
                    'default'     => '3',
                    'type'        => 'text',
                    'desc'        => __('تعداد اقساط یا تعداد ماه را وارد کنید (مثلاً ۳، ۶ یا ۱۲).', 'wc-installment'),
                ),

                array(
                    'title'   => __('نوع کارمزد', 'wc-installment'),
                    'id'      => 'wcip_fee_type',
                    'default' => 'percentage',
                    'type'    => 'select',
                    'options' => array(
                        'percentage' => __('درصدی', 'wc-installment'),
                        'fixed'      => __('مبلغ ثابت', 'wc-installment'),
                    ),
                    'desc'    => __('نوع کارمزد اقساطی را انتخاب کنید.', 'wc-installment'),
                ),

                array(
                    'title'       => __('مقدار کارمزد پیش‌فرض', 'wc-installment'),
                    'id'          => 'wcip_fee_value',
                    'default'     => '0',
                    'type'        => 'text',
                    'desc'        => __('اگر درصدی باشد، عددی بین ۰ تا ۱۰۰ وارد کنید. اگر ثابت باشد، مبلغ را به تومان وارد کنید.', 'wc-installment'),
                ),

                array(
                    'type' => 'sectionend',
                    'id'   => 'wcip_section_general',
                ),

                // ===== SMS Panel Settings =====
                array(
                    'type' => 'sectionend',
                    'id'   => 'wcip_section_sms',
                ),
            );

            // Merge in the SMS settings from the SMS module.
            if (class_exists('SMS_Settings')) {
                $sms_fields = SMS_Settings::instance()->get_settings_fields();
                $settings = array_merge($settings, $sms_fields);
            }

            return $settings;
        }

        /**
         * Renders the settings tab content.
         */
        public function render_settings_tab()
        {
            WooCommerce::instance();
            woocommerce_admin_fields($this->get_settings_fields());
        }

        /**
         * Saves the settings using WooCommerce settings API.
         */
        public function save_settings()
        {
            woocommerce_update_options($this->get_settings_fields());

            // Save SMS settings via the SMS module.
            if (class_exists('SMS_Settings')) {
                SMS_Settings::instance()->save_settings();
            }
        }

        /**
         * Retrieves a single global setting value.
         *
         * @param string $key     Field id without the 'wcip_' prefix.
         * @param mixed  $default Default value.
         * @return mixed
         */
        public static function get($key, $default = '')
        {
            $full_key = 'wcip_' . $key;
            return get_option($full_key, $default);
        }

        /**
         * Returns the SMS panel options for the settings dropdown.
         * Detects installed SMS plugins and merges with the default list.
         *
         * @return array
         */
        public function get_sms_panel_options()
        {
            if (class_exists('SMS_Manager')) {
                return SMS_Manager::instance()->get_available_providers();
            }

            return array(
                'none'        => __('— انتخاب نکرده —', 'wc-installment'),
                'kavenegar'   => __('کاوه‌نگار', 'wc-installment'),
                'smsir'       => __('SMS.ir', 'wc-installment'),
                'melipayamak' => __('ملی‌پیامک', 'wc-installment'),
                'farazsms'    => __('فراز SMS', 'wc-installment'),
            );
        }
    }
}
