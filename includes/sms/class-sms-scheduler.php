<?php
/**
 * SMS Scheduler — registers and handles the daily WP-Cron job that checks
 * all pending installments and dispatches the appropriate SMS notifications.
 *
 * @package WC_Installment_Payment
 */

if (!defined('ABSPATH')) {
    exit;
}

class SMS_Scheduler
{
    private static $instance = null;

    const CRON_HOOK = 'installment_sms_daily_check';

    public static function instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        add_action(self::CRON_HOOK, array($this, 'run_daily_checks'));
    }

    public static function schedule_cron()
    {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time(), 'daily', self::CRON_HOOK);
        }
    }

    public static function unschedule_cron()
    {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
        }
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    public function run_daily_checks()
    {
        $manager = SMS_Manager::instance();

        if (!$manager->is_enabled()) {
            return;
        }

        $settings = $manager->get_settings();
        $db = WCIP_DB::instance();

        // Mark overdue installments first.
        $db->mark_overdue_installments();

        $today = current_time('Y-m-d');
        $pre_due_days = $settings['pre_due_days'];
        if ($pre_due_days < 1) {
            $pre_due_days = 3;
        }

        $pre_due_date = date('Y-m-d', strtotime("+{$pre_due_days} days"));

        // Pre-due reminders: due within the pre-due window, not yet sent.
        $pre_due_installments = $db->get_installments_for_sms('pre_due', $today, $pre_due_date);
        foreach ($pre_due_installments as $installment) {
            $manager->send_pre_due_notification($installment);
        }

        // Due-date notifications: due exactly today.
        $due_today_installments = $db->get_installments_for_sms('due_date', $today, $today);
        foreach ($due_today_installments as $installment) {
            $manager->send_due_date_notification($installment);
        }

        // Overdue notifications: past due date, still unpaid.
        $overdue_installments = $db->get_installments_for_sms('overdue', '', $today);
        foreach ($overdue_installments as $installment) {
            $manager->send_overdue_notification($installment);
        }

        // Clean up old logs (90 days).
        SMS_Logger::instance()->delete_old_logs(90);
    }
}
