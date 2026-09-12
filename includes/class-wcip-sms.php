<?php
/**
 * SMS notification system — facade that loads the modular SMS subsystem
 * and provides backward-compatible static methods for the rest of the plugin.
 * Now uses auto-detection instead of direct API integrations.
 *
 * @package WC_Installment_Payment
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once WCIP_PLUGIN_DIR . 'includes/class-wcip-sms-detector.php';
require_once WCIP_PLUGIN_DIR . 'includes/sms/class-sms-logger.php';
require_once WCIP_PLUGIN_DIR . 'includes/sms/class-sms-manager.php';
require_once WCIP_PLUGIN_DIR . 'includes/sms/class-sms-scheduler.php';
require_once WCIP_PLUGIN_DIR . 'includes/sms/class-sms-settings.php';

if (!class_exists('WCIP_SMS')) {

    class WCIP_SMS
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
            // Trigger when an installment is marked as paid.
            add_action('wcip_installment_paid', array($this, 'send_paid_notification'), 10, 2);
        }

        public static function get_settings()
        {
            return SMS_Manager::instance()->get_settings();
        }

        public static function is_enabled()
        {
            return SMS_Manager::instance()->is_enabled();
        }

        public function send_sms($phone, $message)
        {
            $result = SMS_Manager::instance()->send_sms($phone, $message);
            return $result['success'] ? true : new WP_Error('wcip_sms_error', __('ارسال پیامک ناموفق بود.', 'wc-installment'));
        }

        public function get_customer_phone($user_id, $order_id = 0)
        {
            return SMS_Manager::instance()->get_customer_phone($user_id, $order_id);
        }

        public function send_paid_notification($installment_id, $installment)
        {
            return SMS_Manager::instance()->send_paid_notification($installment_id, $installment);
        }

        public function send_due_soon_notification($installment)
        {
            return SMS_Manager::instance()->send_pre_due_notification($installment);
        }

        public function send_overdue_notification($installment)
        {
            return SMS_Manager::instance()->send_overdue_notification($installment);
        }

        public function run_daily_checks()
        {
            SMS_Scheduler::instance()->run_daily_checks();
        }

        public static function schedule_cron()
        {
            SMS_Scheduler::schedule_cron();
        }

        public static function unschedule_cron()
        {
            SMS_Scheduler::unschedule_cron();
        }
    }
}
