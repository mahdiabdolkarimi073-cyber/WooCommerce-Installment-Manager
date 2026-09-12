<?php
/**
 * In-app reminder system — notification center, bell icon, AJAX endpoints.
 *
 * @package WC_Installment_Payment
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('WCIP_Reminders')) {

    class WCIP_Reminders
    {
        /**
         * @var WCIP_Reminders|null
         */
        private static $instance = null;

        /**
         * @var int Reminder window in days before due date.
         */
        const REMINDER_WINDOW = 3;

        /**
         * Singleton instance.
         *
         * @return WCIP_Reminders
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
            // AJAX: get reminders for the current user.
            add_action('wp_ajax_wcip_get_reminders', array($this, 'ajax_get_reminders'));
            add_action('wp_ajax_nopriv_wcip_get_reminders', array($this, 'ajax_get_reminders'));

            // AJAX: dismiss a reminder (per-day, stored client-side — this is a no-op server stub).
            add_action('wp_ajax_wcip_dismiss_reminder', array($this, 'ajax_dismiss_reminder'));

            // Inject the notification bell into wp_footer.
            add_action('wp_footer', array($this, 'render_notification_bell'), 99);

            // Enqueue assets for the notification center.
            add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'));
        }

        /**
         * Enqueues the notification center assets on all pages.
         */
        public function enqueue_assets()
        {
            if (!is_user_logged_in()) {
                return;
            }

            wp_enqueue_style('wcip-frontend-style', WCIP_PLUGIN_URL . 'assets/css/frontend.css', array(), WCIP_VERSION);
            wp_enqueue_script('wcip-frontend-script', WCIP_PLUGIN_URL . 'assets/js/frontend.js', array('jquery'), WCIP_VERSION, true);

            wp_localize_script('wcip-frontend-script', 'wcipReminders', array(
                'ajaxUrl'     => admin_url('admin-ajax.php'),
                'nonce'       => wp_create_nonce('wcip-frontend'),
                'installmentsUrl' => wc_get_account_endpoint_url('wcip-installments'),
                'reminderWindow' => self::REMINDER_WINDOW,
                'i18n'        => array(
                    'dueSoon'    => __('نزدیک سررسید', 'wc-installment'),
                    'dueToday'   => __('سررسید امروز', 'wc-installment'),
                    'overdue'    => __('معوق', 'wc-installment'),
                    'paid'       => __('پرداخت شده', 'wc-installment'),
                    'daysLeft'   => __('روز باقی‌مانده', 'wc-installment'),
                    'daysOverdue'=> __('روز معوق', 'wc-installment'),
                    'dueTodayLabel' => __('امروز سررسید است', 'wc-installment'),
                    'noReminders'=> __('یادآوری فعالی وجود ندارد.', 'wc-installment'),
                    'reminders'  => __('یادآوری‌ها', 'wc-installment'),
                    'dismiss'     => __('بستن', 'wc-installment'),
                    'payNow'     => __('پرداخت', 'wc-installment'),
                ),
            ));
        }

        /**
         * AJAX handler — returns all active reminders for the current user.
         */
        public function ajax_get_reminders()
        {
            check_ajax_referer('wcip-frontend', 'nonce');

            if (!is_user_logged_in()) {
                wp_send_json_error(array('message' => __('لطفاً وارد شوید.', 'wc-installment')));
            }

            $db = WCIP_DB::instance();
            $reminders = $db->get_user_reminders(get_current_user_id(), self::REMINDER_WINDOW);

            $data = array();
            foreach ($reminders as $inst) {
                $product = wc_get_product($inst->product_id);
                $product_name = $product ? $product->get_name() : __('محصول', 'wc-installment');

                $data[] = array(
                    'id'             => (int) $inst->id,
                    'installment_num'=> (int) $inst->installment_number,
                    'total_installments' => (int) $inst->total_installments,
                    'amount'         => wcip_format_toman($inst->amount),
                    'due_date'       => wcip_gregorian_to_jalali($inst->due_date, 'Y/m/d'),
                    'days_diff'      => (int) $inst->days_diff,
                    'dynamic_status' => $inst->dynamic_status,
                    'status_label'   => WCIP_DB::get_status_label($inst->dynamic_status),
                    'product_name'   => $product_name,
                    'pay_url'        => wc_get_account_endpoint_url('wcip-installments'),
                );
            }

            wp_send_json_success(array(
                'reminders'    => $data,
                'count'        => count($data),
            ));
        }

        /**
         * AJAX handler — stub for dismissing a reminder.
         * The actual dismiss tracking is client-side in localStorage (per-day).
         * This endpoint exists for future server-side tracking if needed.
         */
        public function ajax_dismiss_reminder()
        {
            check_ajax_referer('wcip-frontend', 'nonce');

            if (!is_user_logged_in()) {
                wp_send_json_error();
            }

            wp_send_json_success();
        }

        /**
         * Renders the notification bell icon and dropdown in the site footer.
         * Visible only to logged-in users.
         */
        public function render_notification_bell()
        {
            if (!is_user_logged_in()) {
                return;
            }
            ?>
            <div id="wcip-notification-center" class="wcip-notification-center" style="display:none;">
                <button type="button" id="wcip-bell-btn" class="wcip-bell-btn" aria-label="<?php esc_attr_e('یادآوری‌ها', 'wc-installment'); ?>">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>
                        <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
                    </svg>
                    <span id="wcip-reminder-count" class="wcip-reminder-count" style="display:none;">0</span>
                </button>
                <div id="wcip-reminder-dropdown" class="wcip-reminder-dropdown" style="display:none;">
                    <div class="wcip-reminder-dropdown-header">
                        <span class="wcip-reminder-dropdown-title"><?php esc_html_e('یادآوری‌ها', 'wc-installment'); ?></span>
                        <button type="button" class="wcip-reminder-close">&times;</button>
                    </div>
                    <div id="wcip-reminder-list" class="wcip-reminder-list">
                        <p class="wcip-reminder-loading"><?php esc_html_e('در حال بارگذاری...', 'wc-installment'); ?></p>
                    </div>
                </div>
            </div>
            <?php
        }
    }
}
