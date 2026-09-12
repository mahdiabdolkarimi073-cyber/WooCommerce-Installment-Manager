<?php
/**
 * SMS notification system — handles due-soon, overdue, and paid triggers.
 * Structured for future SMS panel integration (no external API calls yet).
 *
 * @package WC_Installment_Payment
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('WCIP_SMS')) {

    class WCIP_SMS
    {
        /**
         * @var WCIP_SMS|null
         */
        private static $instance = null;

        /**
         * Singleton instance.
         *
         * @return WCIP_SMS
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
            // Scheduled cron hook for due-soon and overdue checks.
            add_action('wcip_sms_daily_check', array($this, 'run_daily_checks'));

            // Trigger when an installment is marked as paid.
            add_action('wcip_installment_paid', array($this, 'send_paid_notification'), 10, 2);
        }

        /**
         * Returns the SMS settings from the WooCommerce settings tab.
         *
         * @return array
         */
        public static function get_settings()
        {
            return array(
                'enabled'        => get_option('wcip_sms_enabled', 'no'),
                'api_url'        => get_option('wcip_sms_api_url', ''),
                'api_key'        => get_option('wcip_sms_api_key', ''),
                'sender_number'  => get_option('wcip_sms_sender_number', ''),
                'panel_type'     => get_option('wcip_sms_panel_type', 'none'),
                'due_soon_days'  => get_option('wcip_sms_due_soon_days', '3'),
            );
        }

        /**
         * Checks whether SMS notifications are enabled.
         *
         * @return bool
         */
        public static function is_enabled()
        {
            return get_option('wcip_sms_enabled', 'no') === 'yes';
        }

        /**
         * Detects installed SMS plugins on the site.
         *
         * @return array Associative array of plugin_slug => plugin_name.
         */
        public function detect_installed_sms_plugins()
        {
            $detected = array();

            $known_plugins = array(
                'melipayamak/melipayamak.php'                 => __('ملی‌پیامک', 'wc-installment'),
                'kavenegar/kavenegar.php'                     => __('کاوه‌نگار', 'wc-installment'),
                'sms-pro/sms-pro.php'                         => __('SMS Pro', 'wc-installment'),
                'wp-sms/wp-sms.php'                           => __('WP SMS', 'wc-installment'),
                'persian-woocommerce-sms/persian-woocommerce-sms.php' => __('پیامک فارسی ووکامرس', 'wc-installment'),
            );

            $active_plugins = (array) get_option('active_plugins', array());
            if (is_multisite()) {
                $active_plugins = array_merge($active_plugins, get_site_option('active_sitewide_plugins', array()));
            }

            foreach ($known_plugins as $plugin_path => $plugin_name) {
                if (in_array($plugin_path, $active_plugins, true) || array_key_exists($plugin_path, $active_plugins)) {
                    $detected[$plugin_path] = $plugin_name;
                }
            }

            return $detected;
        }

        /**
         * Returns the available SMS panel options for the settings dropdown.
         *
         * @return array
         */
        public function get_panel_options()
        {
            $options = array(
                'none'        => __('— انتخاب نکرده —', 'wc-installment'),
                'sms_pro'     => __('SMS Pro', 'wc-installment'),
                'melipayamak' => __('ملی‌پیامک', 'wc-installment'),
                'kavenegar'   => __('کاوه‌نگار', 'wc-installment'),
            );

            $detected = $this->detect_installed_sms_plugins();
            foreach ($detected as $slug => $name) {
                $key = sanitize_title($slug);
                $options[$key] = sprintf(__('%s (نصب‌شده)', 'wc-installment'), $name);
            }

            return $options;
        }

        /**
         * Sends an SMS message to a given phone number.
         * This is the central send method — ready for future SMS panel integration.
         *
         * @param string $phone   Recipient phone number.
         * @param string $message Message body.
         * @return bool|WP_Error True on success, WP_Error on failure.
         */
        public function send_sms($phone, $message)
        {
            if (!self::is_enabled()) {
                return new WP_Error(
                    'wcip_sms_disabled',
                    __('ارسال پیامک غیرفعال است.', 'wc-installment')
                );
            }

            $phone = $this->sanitize_phone($phone);
            if (empty($phone)) {
                return new WP_Error(
                    'wcip_sms_invalid_phone',
                    __('شماره تلفن گیرنده نامعتبر است.', 'wc-installment')
                );
            }

            $settings = self::get_settings();
            $panel_type = $settings['panel_type'];

            // Route to the appropriate panel handler.
            switch ($panel_type) {
                case 'melipayamak':
                    return $this->send_via_melipayamak($phone, $message, $settings);
                case 'kavenegar':
                    return $this->send_via_kavenegar($phone, $message, $settings);
                case 'sms_pro':
                    return $this->send_via_sms_pro($phone, $message, $settings);
                default:
                    // Generic handler — uses the configured API URL and key.
                    return $this->send_via_generic_api($phone, $message, $settings);
            }
        }

        /**
         * Sends SMS via Melipayamak API.
         *
         * @param string $phone    Recipient phone.
         * @param string $message  Message body.
         * @param array  $settings SMS settings array.
         * @return bool|WP_Error
         */
        protected function send_via_melipayamak($phone, $message, $settings)
        {
            // Ready for integration — structure in place, no external call yet.
            $api_url = $settings['api_url'];
            $api_key = $settings['api_key'];
            $sender  = $settings['sender_number'];

            if (empty($api_url) || empty($api_key)) {
                return new WP_Error(
                    'wcip_sms_not_configured',
                    __('تنظیمات پنل پیامک کامل نیست.', 'wc-installment')
                );
            }

            // Future: wp_remote_post() to Melipayamak API endpoint.
            // For now, log the intended message.
            $this->log_sms($phone, $message, 'melipayamak', 'pending');
            return true;
        }

        /**
         * Sends SMS via Kavenegar API.
         *
         * @param string $phone    Recipient phone.
         * @param string $message  Message body.
         * @param array  $settings SMS settings array.
         * @return bool|WP_Error
         */
        protected function send_via_kavenegar($phone, $message, $settings)
        {
            $api_url = $settings['api_url'];
            $api_key = $settings['api_key'];
            $sender  = $settings['sender_number'];

            if (empty($api_url) || empty($api_key)) {
                return new WP_Error(
                    'wcip_sms_not_configured',
                    __('تنظیمات پنل پیامک کامل نیست.', 'wc-installment')
                );
            }

            // Future: wp_remote_post() to Kavenegar API endpoint.
            $this->log_sms($phone, $message, 'kavenegar', 'pending');
            return true;
        }

        /**
         * Sends SMS via SMS Pro plugin (if installed).
         *
         * @param string $phone    Recipient phone.
         * @param string $message  Message body.
         * @param array  $settings SMS settings array.
         * @return bool|WP_Error
         */
        protected function send_via_sms_pro($phone, $message, $settings)
        {
            // If the SMS Pro plugin is active, use its sending function.
            if (function_exists('sms_pro_send')) {
                // Future integration: sms_pro_send($phone, $message, $settings['sender_number']);
                $this->log_sms($phone, $message, 'sms_pro', 'pending');
                return true;
            }

            $this->log_sms($phone, $message, 'sms_pro', 'pending');
            return true;
        }

        /**
         * Sends SMS via a generic API endpoint.
         *
         * @param string $phone    Recipient phone.
         * @param string $message  Message body.
         * @param array  $settings SMS settings array.
         * @return bool|WP_Error
         */
        protected function send_via_generic_api($phone, $message, $settings)
        {
            $api_url = $settings['api_url'];
            $api_key = $settings['api_key'];
            $sender  = $settings['sender_number'];

            if (empty($api_url) || empty($api_key)) {
                return new WP_Error(
                    'wcip_sms_not_configured',
                    __('تنظیمات پنل پیامک کامل نیست.', 'wc-installment')
                );
            }

            // Future: wp_remote_post() to the configured API URL.
            $this->log_sms($phone, $message, 'generic', 'pending');
            return true;
        }

        /**
         * Logs an SMS message to the database (custom log or order note).
         *
         * @param string $phone      Recipient phone.
         * @param string $message    Message body.
         * @param string $panel_type Panel type.
         * @param string $status     Status: pending, sent, failed.
         */
        protected function log_sms($phone, $message, $panel_type, $status = 'pending')
        {
            $log_entry = array(
                'time'       => current_time('mysql'),
                'phone'      => $phone,
                'message'    => $message,
                'panel'      => $panel_type,
                'status'     => $status,
            );

            $logs = get_option('wcip_sms_logs', array());
            if (!is_array($logs)) {
                $logs = array();
            }

            // Keep only the last 200 entries.
            array_unshift($logs, $log_entry);
            if (count($logs) > 200) {
                $logs = array_slice($logs, 0, 200);
            }

            update_option('wcip_sms_logs', $logs);
        }

        /**
         * Sanitizes a phone number to Iranian format.
         *
         * @param string $phone Raw phone number.
         * @return string
         */
        protected function sanitize_phone($phone)
        {
            $phone = preg_replace('/[^0-9+]/', '', $phone);
            // Convert +98 to 0.
            if (strpos($phone, '+98') === 0) {
                $phone = '0' . substr($phone, 3);
            } elseif (strpos($phone, '98') === 0 && strlen($phone) === 12) {
                $phone = '0' . substr($phone, 2);
            }
            return $phone;
        }

        /**
         * Retrieves the customer's phone number from their user ID or order.
         *
         * @param int $user_id  User ID.
         * @param int $order_id Optional. Order ID for billing phone fallback.
         * @return string
         */
        public function get_customer_phone($user_id, $order_id = 0)
        {
            $phone = '';

            if ($user_id > 0) {
                $phone = get_user_meta($user_id, 'billing_phone', true);
            }

            if (empty($phone) && $order_id > 0) {
                $order = wc_get_order($order_id);
                if ($order) {
                    $phone = $order->get_billing_phone();
                }
            }

            return $phone;
        }

        /**
         * Runs the daily checks for due-soon and overdue installments.
         * Triggered by the WordPress cron event.
         */
        public function run_daily_checks()
        {
            if (!self::is_enabled()) {
                return;
            }

            $db = WCIP_DB::instance();

            // Mark overdue installments first.
            $db->mark_overdue_installments();

            $due_soon_days = (int) get_option('wcip_sms_due_soon_days', '3');
            if ($due_soon_days < 1) {
                $due_soon_days = 3;
            }

            // Send due-soon reminders only to those that haven't been reminded yet.
            $needing_reminder = $db->get_installments_needing_reminder($due_soon_days);
            foreach ($needing_reminder as $installment) {
                $this->send_due_soon_notification($installment);
                $db->mark_reminder_sent((int) $installment->id);
            }

            // Send overdue notifications.
            $overdue = $db->get_overdue_installments();
            foreach ($overdue as $installment) {
                $this->send_overdue_notification($installment);
            }
        }

        /**
         * Sends a "due soon" SMS notification.
         *
         * @param object $installment Installment record.
         */
        public function send_due_soon_notification($installment)
        {
            $phone = $this->get_customer_phone($installment->user_id, $installment->order_id);
            if (empty($phone)) {
                return;
            }

            $due_date_jalali = wcip_gregorian_to_jalali($installment->due_date);
            $amount = wcip_format_toman($installment->amount);

            $pay_link = wc_get_account_endpoint_url('wcip-installments');

            $message = sprintf(
                __('کاربر گرامی، قسط شماره %1$s از %2$s به مبلغ %3$s تا تاریخ %4$s سررسید می‌رسد. برای پرداخت آنلاین به لینک زیر مراجعه کنید: %5$s', 'wc-installment'),
                number_to_persian($installment->installment_number),
                number_to_persian($installment->total_installments),
                $amount,
                $due_date_jalali,
                $pay_link
            );

            $this->send_sms($phone, $message);
        }

        /**
         * Sends an "overdue" SMS notification.
         *
         * @param object $installment Installment record.
         */
        public function send_overdue_notification($installment)
        {
            $phone = $this->get_customer_phone($installment->user_id, $installment->order_id);
            if (empty($phone)) {
                return;
            }

            $due_date_jalali = wcip_gregorian_to_jalali($installment->due_date);
            $amount = wcip_format_toman($installment->amount);

            $message = sprintf(
                __('کاربر گرامی، قسط شماره %1$s از %2$s به مبلغ %3$s با تاریخ سررسید %4$s معوق شده است. لطفاً در اسرع وقت پرداخت را انجام دهید.', 'wc-installment'),
                number_to_persian($installment->installment_number),
                number_to_persian($installment->total_installments),
                $amount,
                $due_date_jalali
            );

            $this->send_sms($phone, $message);
        }

        /**
         * Sends a "payment successful" SMS notification.
         * Hooked to the wcip_installment_paid action.
         *
         * @param int    $installment_id Installment ID.
         * @param object $installment    Installment record.
         */
        public function send_paid_notification($installment_id, $installment)
        {
            $phone = $this->get_customer_phone($installment->user_id, $installment->order_id);
            if (empty($phone)) {
                return;
            }

            $amount = wcip_format_toman($installment->amount);

            $message = sprintf(
                __('کاربر گرامی، پرداخت قسط شماره %1$s از %2$s به مبلغ %3$s با موفقیت ثبت شد. با تشکر از پرداخت به‌موقع شما.', 'wc-installment'),
                number_to_persian($installment->installment_number),
                number_to_persian($installment->total_installments),
                $amount
            );

            $this->send_sms($phone, $message);
        }

        /**
         * Schedules the daily SMS check cron event.
         */
        public static function schedule_cron()
        {
            if (!wp_next_scheduled('wcip_sms_daily_check')) {
                wp_schedule_event(time(), 'daily', 'wcip_sms_daily_check');
            }
        }

        /**
         * Removes the scheduled cron event.
         */
        public static function unschedule_cron()
        {
            $timestamp = wp_next_scheduled('wcip_sms_daily_check');
            if ($timestamp) {
                wp_unschedule_event($timestamp, 'wcip_sms_daily_check');
            }
        }
    }
}
