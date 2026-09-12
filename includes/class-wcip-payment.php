<?php
/**
 * Payment gateway integration — connects installment payments to the
 * site's active WooCommerce payment gateway.
 * Includes the full "اقساط من" account page, invoice download, retry logic,
 * and reminder integration.
 *
 * @package WC_Installment_Payment
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('WCIP_Payment')) {

    class WCIP_Payment
    {
        /**
         * @var WCIP_Payment|null
         */
        private static $instance = null;

        /**
         * @var int Maximum automatic retry attempts.
         */
        const MAX_RETRIES = 3;

        /**
         * @var int Base delay between retries in seconds (exponential backoff base).
         */
        const RETRY_BASE_DELAY = 5;

        /**
         * Singleton instance.
         *
         * @return WCIP_Payment
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
            // My Account endpoints.
            add_action('init', array($this, 'add_pay_installment_endpoint'));
            add_filter('query_vars', array($this, 'add_query_vars'));
            add_filter('woocommerce_account_menu_items', array($this, 'add_account_menu_item'));
            add_action('woocommerce_account_wcip-pay-installment_endpoint', array($this, 'render_installments_page'));
            add_action('woocommerce_account_wcip-installments_endpoint', array($this, 'render_installments_page'));

            // Handle the installment payment form submission.
            add_action('template_redirect', array($this, 'handle_installment_payment'));

            // Handle payment gateway callback/completion.
            add_action('woocommerce_payment_complete', array($this, 'handle_payment_complete'), 10, 1);
            add_action('woocommerce_order_status_completed', array($this, 'handle_order_completed'), 10, 1);
            add_action('woocommerce_order_status_failed', array($this, 'handle_order_failed'), 10, 1);

            // AJAX handlers for payment status polling.
            add_action('wp_ajax_wcip_check_payment_status', array($this, 'ajax_check_payment_status'));
            add_action('wp_ajax_nopriv_wcip_check_payment_status', array($this, 'ajax_check_payment_status'));

            // AJAX handler for manual retry.
            add_action('wp_ajax_wcip_retry_payment', array($this, 'ajax_retry_payment'));

            // Invoice download handler.
            add_action('init', array($this, 'handle_invoice_download'));
        }

        /**
         * Adds the "pay-installment" endpoint to WooCommerce My Account.
         */
        public function add_pay_installment_endpoint()
        {
            add_rewrite_endpoint('wcip-pay-installment', EP_ROOT | EP_PAGES);
            add_rewrite_endpoint('wcip-installments', EP_ROOT | EP_PAGES);
        }

        /**
         * Registers custom query variables.
         *
         * @param array $vars Existing query vars.
         * @return array
         */
        public function add_query_vars($vars)
        {
            $vars[] = 'wcip-pay-installment';
            $vars[] = 'wcip-installments';
            $vars[] = 'wcip-invoice';
            return $vars;
        }

        /**
         * Adds the "اقساط من" menu item to the WooCommerce My Account menu.
         *
         * @param array $items Existing menu items.
         * @return array
         */
        public function add_account_menu_item($items)
        {
            $new_items = array();
            $inserted = false;

            foreach ($items as $key => $label) {
                $new_items[$key] = $label;
                if ($key === 'orders' && !$inserted) {
                    $new_items['wcip-installments'] = __('اقساط من', 'wc-installment');
                    $inserted = true;
                }
            }

            if (!$inserted) {
                $new_items['wcip-installments'] = __('اقساط من', 'wc-installment');
            }

            return $new_items;
        }

        /**
         * Returns the site's active WooCommerce payment gateways.
         *
         * @return array
         */
        public function get_active_gateways()
        {
            $available_gateways = WC()->payment_gateways()->get_available_payment_gateways();
            $gateways = array();

            foreach ($available_gateways as $gateway_id => $gateway) {
                if ($gateway->enabled === 'yes') {
                    $gateways[$gateway_id] = $gateway->get_title();
                }
            }

            return $gateways;
        }

        /**
         * Returns the default (primary) active payment gateway ID.
         *
         * @return string|null
         */
        public function get_default_gateway_id()
        {
            $gateways = $this->get_active_gateways();
            if (empty($gateways)) {
                return null;
            }
            return array_key_first($gateways);
        }

        /**
         * Creates a WooCommerce order for an installment payment and redirects
         * the customer through the active payment gateway.
         *
         * @param int $installment_id Installment ID.
         * @return int|WP_Error Created order ID or WP_Error on failure.
         */
        public function create_payment_order($installment_id)
        {
            $db = WCIP_DB::instance();
            $installment = $db->get_installment($installment_id);

            if (!$installment) {
                return new WP_Error(
                    'wcip_invalid_installment',
                    __('رکورد قسط یافت نشد.', 'wc-installment')
                );
            }

            if ($installment->status === 'paid') {
                return new WP_Error(
                    'wcip_already_paid',
                    __('این قسط قبلاً پرداخت شده است.', 'wc-installment')
                );
            }

            $original_order = wc_get_order($installment->order_id);
            if (!$original_order) {
                return new WP_Error(
                    'wcip_invalid_order',
                    __('سفارش اصلی یافت نشد.', 'wc-installment')
                );
            }

            $order = wc_create_order();

            $product = wc_get_product($installment->product_id);
            $product_name = $product ? $product->get_name() : __('محصول', 'wc-installment');

            $item = new WC_Order_Item_Fee();
            $item->set_name(sprintf(
                __('قسط %s از %s — %s', 'wc-installment'),
                $installment->installment_number,
                $installment->total_installments,
                $product_name
            ));
            $item->set_amount(floatval($installment->amount));
            $item->set_total(floatval($installment->amount));
            $item->set_tax_class('');
            $item->set_tax_status('none');
            $order->add_item($item);

            $order->set_address($original_order->get_address('billing'), 'billing');
            $order->set_address($original_order->get_address('shipping'), 'shipping');
            $order->set_customer_id($original_order->get_customer_id());

            $gateway_id = $this->get_default_gateway_id();
            if ($gateway_id) {
                $order->set_payment_method($gateway_id);
            }

            $order->update_meta_data('_wcip_installment_id', $installment_id);
            $order->update_meta_data('_wcip_installment_order_id', $installment->order_id);
            $order->update_meta_data('_wcip_is_installment_payment', 'yes');

            $order->calculate_totals();
            $order->save();

            $order->add_order_note(sprintf(
                __('سفارش پرداخت قسط شماره %s (شناسه: %s)', 'wc-installment'),
                $installment->installment_number,
                $installment_id
            ));

            return $order->get_id();
        }

        /**
         * Redirects the customer to the checkout/payment page for the installment order.
         *
         * @param int $order_id Order ID created for the installment payment.
         */
        public function redirect_to_payment($order_id)
        {
            $order = wc_get_order($order_id);
            if (!$order) {
                return;
            }

            $checkout_url = $order->get_checkout_payment_url();
            wp_safe_redirect($checkout_url);
            exit;
        }

        /**
         * Handles the installment payment form submission from the My Account page.
         */
        public function handle_installment_payment()
        {
            if (!isset($_POST['wcip_pay_installment']) || !isset($_POST['wcip_installment_id'])) {
                return;
            }

            if (!is_user_logged_in()) {
                return;
            }

            if (!isset($_POST['wcip_pay_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['wcip_pay_nonce'])), 'wcip_pay_installment')) {
                wc_add_notice(__('خطای امنیتی. لطفاً دوباره تلاش کنید.', 'wc-installment'), 'error');
                return;
            }

            $installment_id = absint($_POST['wcip_installment_id']);
            if ($installment_id <= 0) {
                wc_add_notice(__('شناسه قسط نامعتبر است.', 'wc-installment'), 'error');
                return;
            }

            $db = WCIP_DB::instance();
            $installment = $db->get_installment($installment_id);
            if (!$installment || (int) $installment->user_id !== get_current_user_id()) {
                wc_add_notice(__('این قسط متعلق به شما نیست.', 'wc-installment'), 'error');
                return;
            }

            if ($installment->status === 'paid') {
                wc_add_notice(__('این قسط قبلاً پرداخت شده است.', 'wc-installment'), 'error');
                return;
            }

            // Reset retry count on new payment attempt.
            $db->reset_retry_count($installment_id);

            $order_id = $this->create_payment_order($installment_id);
            if (is_wp_error($order_id)) {
                wc_add_notice($order_id->get_error_message(), 'error');
                return;
            }

            $this->redirect_to_payment($order_id);
        }

        /**
         * Handles payment completion — marks the installment as paid.
         *
         * @param int $order_id WooCommerce order ID.
         */
        public function handle_payment_complete($order_id)
        {
            $this->process_installment_payment($order_id);
        }

        /**
         * Handles order status "completed" — also marks installment as paid.
         *
         * @param int $order_id WooCommerce order ID.
         */
        public function handle_order_completed($order_id)
        {
            $this->process_installment_payment($order_id);
        }

        /**
         * Handles order status "failed" — increments retry count and triggers retry logic.
         *
         * @param int $order_id WooCommerce order ID.
         */
        public function handle_order_failed($order_id)
        {
            $order = wc_get_order($order_id);
            if (!$order) {
                return;
            }

            $is_installment_payment = $order->get_meta('_wcip_is_installment_payment');
            if ($is_installment_payment !== 'yes') {
                return;
            }

            $installment_id = (int) $order->get_meta('_wcip_installment_id');
            if ($installment_id <= 0) {
                return;
            }

            $db = WCIP_DB::instance();
            $installment = $db->get_installment($installment_id);
            if (!$installment || $installment->status === 'paid') {
                return;
            }

            $retry_count = $db->increment_retry_count($installment_id);

            if ($retry_count < self::MAX_RETRIES) {
                // Schedule a retry via cron — exponential backoff.
                $delay = self::RETRY_BASE_DELAY * pow(2, $retry_count);
                wp_schedule_single_event(time() + $delay, 'wcip_retry_payment', array($installment_id));
            }
        }

        /**
         * Processes the installment payment upon order completion.
         *
         * @param int $order_id WooCommerce order ID.
         */
        protected function process_installment_payment($order_id)
        {
            $order = wc_get_order($order_id);
            if (!$order) {
                return;
            }

            $is_installment_payment = $order->get_meta('_wcip_is_installment_payment');
            if ($is_installment_payment !== 'yes') {
                return;
            }

            $installment_id = (int) $order->get_meta('_wcip_installment_id');
            if ($installment_id <= 0) {
                return;
            }

            $db = WCIP_DB::instance();
            $installment = $db->get_installment($installment_id);
            if (!$installment) {
                return;
            }

            if ($installment->status === 'paid') {
                return;
            }

            $transaction_id = $order->get_transaction_id();
            if (empty($transaction_id)) {
                $transaction_id = (string) $order_id;
            }

            $paid_date = current_time('mysql');

            // Generate invoice URL.
            $invoice_url = $this->generate_invoice_url($installment_id);

            $db->update_installment($installment_id, array(
                'status'         => 'paid',
                'paid_date'      => $paid_date,
                'transaction_id' => $transaction_id,
                'invoice_url'    => $invoice_url,
                'retry_count'    => 0,
            ));

            $updated = $db->get_installment($installment_id);
            if ($updated) {
                do_action('wcip_installment_paid', $installment_id, $updated);
            }
        }

        /**
         * Generates the invoice download URL for an installment.
         *
         * @param int $installment_id Installment ID.
         * @return string
         */
        public function generate_invoice_url($installment_id)
        {
            return add_query_arg(array(
                'wcip-invoice' => absint($installment_id),
                'nonce'        => wp_create_nonce('wcip_invoice_' . $installment_id),
            ), home_url('/'));
        }

        /**
         * Handles invoice download requests.
         */
        public function handle_invoice_download()
        {
            if (!isset($_GET['wcip-invoice'])) {
                return;
            }

            $installment_id = absint($_GET['wcip-invoice']);
            if ($installment_id <= 0) {
                return;
            }

            if (!isset($_GET['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['nonce'])), 'wcip_invoice_' . $installment_id)) {
                wp_die(esc_html__('خطای امنیتی. لینک فاکتور نامعتبر است.', 'wc-installment'));
            }

            if (!is_user_logged_in()) {
                wp_die(esc_html__('لطفاً وارد شوید.', 'wc-installment'));
            }

            $db = WCIP_DB::instance();
            $installment = $db->get_installment($installment_id);
            if (!$installment || (int) $installment->user_id !== get_current_user_id()) {
                wp_die(esc_html__('فاکتور مورد نظر یافت نشد.', 'wc-installment'));
            }

            if ($installment->status !== 'paid') {
                wp_die(esc_html__('این قسط هنوز پرداخت نشده است.', 'wc-installment'));
            }

            $this->output_invoice_pdf($installment);
        }

        /**
         * Outputs the invoice as an HTML document styled for printing/PDF.
         *
         * @param object $installment Installment record.
         */
        protected function output_invoice_pdf($installment)
        {
            $user = get_userdata($installment->user_id);
            $customer_name = $user ? $user->display_name : __('کاربر', 'wc-installment');

            $product = wc_get_product($installment->product_id);
            $product_name = $product ? $product->get_name() : '—';

            $order = wc_get_order($installment->order_id);
            $billing_phone = $order ? $order->get_billing_phone() : '';

            $paid_date_jalali = wcip_gregorian_to_jalali(substr($installment->paid_date, 0, 10), 'Y/m/d');
            $paid_time = substr($installment->paid_date, 11, 5);
            $due_date_jalali = wcip_gregorian_to_jalali($installment->due_date, 'Y/m/d');

            $invoice_number = 'INV-' . str_pad($installment->id, 6, '0', STR_PAD_LEFT);
            $site_name = get_bloginfo('name');

            header('Content-Type: text/html; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $invoice_number . '.html"');

            echo '<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="utf-8">';
            echo '<title>' . esc_html($invoice_number) . '</title>';
            echo '<style>
                body { font-family: Tahoma, Arial, sans-serif; direction: rtl; padding: 40px; color: #333; }
                .invoice-header { text-align: center; border-bottom: 3px solid #2a8a2a; padding-bottom: 20px; margin-bottom: 30px; }
                .invoice-header h1 { font-size: 24px; color: #2a8a2a; margin: 0 0 8px 0; }
                .invoice-header .site-name { font-size: 14px; color: #777; }
                .invoice-meta { display: flex; justify-content: space-between; margin-bottom: 30px; }
                .invoice-meta div { font-size: 13px; }
                .invoice-meta strong { color: #555; }
                .invoice-table { width: 100%; border-collapse: collapse; margin-bottom: 30px; }
                .invoice-table th, .invoice-table td { padding: 12px 16px; border: 1px solid #e0e0e0; font-size: 13px; text-align: right; }
                .invoice-table th { background: #f5f5f5; font-weight: 600; color: #555; }
                .invoice-total { text-align: left; font-size: 18px; font-weight: 700; color: #2a8a2a; padding: 16px 0; border-top: 2px solid #2a8a2a; }
                .invoice-footer { text-align: center; font-size: 11px; color: #999; margin-top: 40px; padding-top: 20px; border-top: 1px solid #eee; }
                @media print { body { padding: 0; } }
            </style></head><body>';

            echo '<div class="invoice-header">';
            echo '<h1>' . esc_html__('فاکتور پرداخت قسط', 'wc-installment') . '</h1>';
            echo '<div class="site-name">' . esc_html($site_name) . '</div>';
            echo '</div>';

            echo '<div class="invoice-meta">';
            echo '<div>';
            echo '<p><strong>' . esc_html__('شماره فاکتور:', 'wc-installment') . '</strong> ' . esc_html($invoice_number) . '</p>';
            echo '<p><strong>' . esc_html__('تاریخ پرداخت:', 'wc-installment') . '</strong> ' . esc_html($paid_date_jalali . ' ' . __('ساعت', 'wc-installment') . ' ' . number_to_persian($paid_time)) . '</p>';
            echo '</div>';
            echo '<div>';
            echo '<p><strong>' . esc_html__('نام مشتری:', 'wc-installment') . '</strong> ' . esc_html($customer_name) . '</p>';
            if ($billing_phone) {
                echo '<p><strong>' . esc_html__('تلفن:', 'wc-installment') . '</strong> ' . esc_html($billing_phone) . '</p>';
            }
            echo '</div>';
            echo '</div>';

            echo '<table class="invoice-table">';
            echo '<thead><tr>';
            echo '<th>' . esc_html__('شرح', 'wc-installment') . '</th>';
            echo '<th>' . esc_html__('شماره قسط', 'wc-installment') . '</th>';
            echo '<th>' . esc_html__('تاریخ سررسید', 'wc-installment') . '</th>';
            echo '<th>' . esc_html__('مبلغ', 'wc-installment') . '</th>';
            echo '</tr></thead>';
            echo '<tbody><tr>';
            echo '<td>' . esc_html($product_name) . '</td>';
            echo '<td>' . esc_html(number_to_persian($installment->installment_number) . ' ' . __('از', 'wc-installment') . ' ' . number_to_persian($installment->total_installments)) . '</td>';
            echo '<td>' . esc_html($due_date_jalali) . '</td>';
            echo '<td>' . esc_html(wcip_format_toman($installment->amount)) . '</td>';
            echo '</tr></tbody>';
            echo '</table>';

            echo '<div class="invoice-total">';
            echo esc_html__('مبلغ پرداخت‌شده:', 'wc-installment') . ' ' . esc_html(wcip_format_toman($installment->amount));
            echo '</div>';

            echo '<div style="margin-top:20px;font-size:13px;">';
            echo '<p><strong>' . esc_html__('کد تراکنش:', 'wc-installment') . '</strong> ' . esc_html($installment->transaction_id) . '</p>';
            echo '</div>';

            echo '<div class="invoice-footer">';
            echo esc_html__('این فاکتور توسط سیستم پرداخت اقساطی تولید شده است.', 'wc-installment');
            echo '</div>';

            echo '</body></html>';
            exit;
        }

        /**
         * AJAX handler — checks the payment status of an installment.
         */
        public function ajax_check_payment_status()
        {
            check_ajax_referer('wcip-frontend', 'nonce');

            $installment_id = isset($_POST['installment_id']) ? absint($_POST['installment_id']) : 0;
            if ($installment_id <= 0) {
                wp_send_json_error(array('message' => __('شناسه نامعتبر.', 'wc-installment')));
            }

            if (!is_user_logged_in()) {
                wp_send_json_error(array('message' => __('لطفاً وارد شوید.', 'wc-installment')));
            }

            $db = WCIP_DB::instance();
            $installment = $db->get_installment($installment_id);
            if (!$installment || (int) $installment->user_id !== get_current_user_id()) {
                wp_send_json_error(array('message' => __('قسط یافت نشد.', 'wc-installment')));
            }

            $invoice_url = '';
            if ($installment->status === 'paid' && !empty($installment->invoice_url)) {
                $invoice_url = $installment->invoice_url;
            } elseif ($installment->status === 'paid') {
                $invoice_url = $this->generate_invoice_url($installment_id);
            }

            wp_send_json_success(array(
                'status'      => $installment->status,
                'paid_date'   => $installment->paid_date,
                'retry_count' => (int) $installment->retry_count,
                'max_retries' => self::MAX_RETRIES,
                'invoice_url' => $invoice_url,
                'message'     => $this->get_status_message($installment),
            ));
        }

        /**
         * AJAX handler — manually retries a failed payment.
         */
        public function ajax_retry_payment()
        {
            check_ajax_referer('wcip-frontend', 'nonce');

            if (!is_user_logged_in()) {
                wp_send_json_error(array('message' => __('لطفاً وارد شوید.', 'wc-installment')));
            }

            $installment_id = isset($_POST['installment_id']) ? absint($_POST['installment_id']) : 0;
            if ($installment_id <= 0) {
                wp_send_json_error(array('message' => __('شناسه نامعتبر.', 'wc-installment')));
            }

            $db = WCIP_DB::instance();
            $installment = $db->get_installment($installment_id);
            if (!$installment || (int) $installment->user_id !== get_current_user_id()) {
                wp_send_json_error(array('message' => __('قسط یافت نشد.', 'wc-installment')));
            }

            if ($installment->status === 'paid') {
                wp_send_json_error(array('message' => __('این قسط قبلاً پرداخت شده است.', 'wc-installment')));
            }

            $retry_count = (int) $installment->retry_count;
            if ($retry_count >= self::MAX_RETRIES) {
                $db->reset_retry_count($installment_id);
            }

            $order_id = $this->create_payment_order($installment_id);
            if (is_wp_error($order_id)) {
                wp_send_json_error(array('message' => $order_id->get_error_message()));
            }

            $order = wc_get_order($order_id);
            $payment_url = $order ? $order->get_checkout_payment_url() : '';

            wp_send_json_success(array(
                'payment_url' => $payment_url,
                'message'     => __('در حال انتقال به درگاه پرداخت...', 'wc-installment'),
            ));
        }

        /**
         * Returns a user-facing status message for an installment.
         *
         * @param object $installment Installment record.
         * @return string
         */
        protected function get_status_message($installment)
        {
            switch ($installment->status) {
                case 'paid':
                    return __('پرداخت با موفقیت انجام شد.', 'wc-installment');
                case 'overdue':
                    return __('این قسط معوق شده است.', 'wc-installment');
                case 'unpaid':
                default:
                    if ((int) $installment->retry_count > 0) {
                        return sprintf(
                            __('تلاش مجدد #%s از %s', 'wc-installment'),
                            number_to_persian($installment->retry_count),
                            number_to_persian(self::MAX_RETRIES)
                        );
                    }
                    return __('در انتظار پرداخت', 'wc-installment');
            }
        }

        /**
         * Renders the "اقساط من" page on the My Account section.
         * Shows all installments with status badges, next-upcoming highlight,
         * payment buttons, and invoice download links.
         */
        public function render_installments_page()
        {
            if (!is_user_logged_in()) {
                echo '<p>' . esc_html__('لطفاً وارد شوید.', 'wc-installment') . '</p>';
                return;
            }

            $user_id = get_current_user_id();
            $db = WCIP_DB::instance();
            $installments = $db->get_user_installments($user_id);
            $next_upcoming = $db->get_next_upcoming_installment($user_id);
            $next_upcoming_id = $next_upcoming ? (int) $next_upcoming->id : 0;

            $gateways = $this->get_active_gateways();
            $gateway_name = !empty($gateways) ? reset($gateways) : __('درگاه پیش‌فرض', 'wc-installment');

            wp_enqueue_script('wcip-account-script', WCIP_PLUGIN_URL . 'assets/js/frontend.js', array('jquery'), WCIP_VERSION, true);
            wp_localize_script('wcip-account-script', 'wcipAccount', array(
                'ajaxUrl'     => admin_url('admin-ajax.php'),
                'nonce'       => wp_create_nonce('wcip-frontend'),
                'maxRetries'  => self::MAX_RETRIES,
                'payingText'  => __('در حال پرداخت...', 'wc-installment'),
                'successText' => __('پرداخت موفق!', 'wc-installment'),
                'failText'    => __('پرداخت ناموفق', 'wc-installment'),
                'retryText'   => __('تلاش مجدد', 'wc-installment'),
                'invoiceText' => __('دانلود فاکتور', 'wc-installment'),
                'statusLabels' => array(
                    'paid'      => WCIP_DB::get_status_label('paid'),
                    'due_today' => WCIP_DB::get_status_label('due_today'),
                    'overdue'   => WCIP_DB::get_status_label('overdue'),
                    'due_soon'  => WCIP_DB::get_status_label('due_soon'),
                    'unpaid'    => WCIP_DB::get_status_label('unpaid'),
                ),
            ));

            wp_enqueue_style('wcip-account-style', WCIP_PLUGIN_URL . 'assets/css/frontend.css', array(), WCIP_VERSION);
            ?>
            <div class="wcip-account-installments" id="wcip-account-installments">
                <h2><?php esc_html_e('اقساط من', 'wc-installment'); ?></h2>

                <?php if (empty($installments)) : ?>
                    <div class="wcip-empty-state">
                        <p><?php esc_html_e('شما هیچ قسطی ندارید.', 'wc-installment'); ?></p>
                    </div>
                <?php else : ?>
                    <p class="wcip-gateway-info">
                        <?php
                        echo esc_html(sprintf(
                            __('پرداخت از طریق درگاه فعال سایت (%s) انجام می‌شود.', 'wc-installment'),
                            $gateway_name
                        ));
                        ?>
                    </p>

                    <div class="wcip-installments-grid">
                        <?php foreach ($installments as $inst) : 
                            $is_next = ((int) $inst->id === $next_upcoming_id);
                            $dyn_status = $db->compute_dynamic_status($inst);
                            $status_label = WCIP_DB::get_status_label($dyn_status);
                            $status_class = WCIP_DB::get_status_class($dyn_status);

                            $due_jalali = wcip_gregorian_to_jalali($inst->due_date, 'Y/m/d');
                            $paid_jalali = !empty($inst->paid_date) ? wcip_gregorian_to_jalali(substr($inst->paid_date, 0, 10), 'Y/m/d') : '';
                            $invoice_url = (!empty($inst->invoice_url)) ? $inst->invoice_url : $this->generate_invoice_url((int) $inst->id);

                            $today = current_time('Y-m-d');
                            $days_diff = (int) round((strtotime($inst->due_date) - strtotime($today)) / 86400);
                        ?>
                        <div class="wcip-installment-card <?php echo $is_next ? 'wcip-next-upcoming' : ''; ?> <?php echo esc_attr($status_class); ?>" 
                             data-installment-id="<?php echo esc_attr($inst->id); ?>"
                             data-status="<?php echo esc_attr($dyn_status); ?>">
                            <?php if ($is_next) : ?>
                                <span class="wcip-next-badge"><?php esc_html_e('پرداخت بعدی', 'wc-installment'); ?></span>
                            <?php endif; ?>

                            <div class="wcip-card-header">
                                <span class="wcip-card-title">
                                    <?php echo esc_html(sprintf(__('قسط %s از %s', 'wc-installment'), number_to_persian($inst->installment_number), number_to_persian($inst->total_installments))); ?>
                                </span>
                                <span class="wcip-status-badge <?php echo esc_attr($status_class); ?>"><?php echo esc_html($status_label); ?></span>
                            </div>

                            <div class="wcip-card-body">
                                <div class="wcip-card-row">
                                    <span class="wcip-card-label"><?php esc_html_e('مبلغ قسط', 'wc-installment'); ?></span>
                                    <span class="wcip-card-value"><?php echo esc_html(wcip_format_toman($inst->amount)); ?></span>
                                </div>
                                <div class="wcip-card-row">
                                    <span class="wcip-card-label"><?php esc_html_e('تاریخ سررسید', 'wc-installment'); ?></span>
                                    <span class="wcip-card-value"><?php echo esc_html($due_jalali); ?></span>
                                </div>
                                <?php if ($dyn_status !== 'paid') : ?>
                                <div class="wcip-card-row wcip-days-row">
                                    <span class="wcip-card-label"><?php esc_html_e('وضعیت زمانی', 'wc-installment'); ?></span>
                                    <span class="wcip-card-value wcip-days-value wcip-days-<?php echo esc_attr($dyn_status); ?>">
                                        <?php
                                        if ($dyn_status === 'due_today') {
                                            esc_html_e('امروز سررسید است', 'wc-installment');
                                        } elseif ($dyn_status === 'overdue') {
                                            echo esc_html(sprintf(__('%s روز معوق', 'wc-installment'), number_to_persian(abs($days_diff))));
                                        } elseif ($dyn_status === 'due_soon') {
                                            echo esc_html(sprintf(__('%s روز باقی‌مانده', 'wc-installment'), number_to_persian($days_diff)));
                                        } else {
                                            esc_html_e('در انتظار پرداخت', 'wc-installment');
                                        }
                                        ?>
                                    </span>
                                </div>
                                <?php endif; ?>
                                <?php if ($inst->status === 'paid' && !empty($paid_jalali)) : ?>
                                <div class="wcip-card-row">
                                    <span class="wcip-card-label"><?php esc_html_e('تاریخ پرداخت', 'wc-installment'); ?></span>
                                    <span class="wcip-card-value"><?php echo esc_html($paid_jalali); ?></span>
                                </div>
                                <?php endif; ?>
                                <?php if ($inst->status === 'paid' && !empty($inst->transaction_id)) : ?>
                                <div class="wcip-card-row">
                                    <span class="wcip-card-label"><?php esc_html_e('کد تراکنش', 'wc-installment'); ?></span>
                                    <span class="wcip-card-value"><?php echo esc_html($inst->transaction_id); ?></span>
                                </div>
                                <?php endif; ?>
                            </div>

                            <div class="wcip-card-actions">
                                <?php if ($inst->status !== 'paid') : ?>
                                    <form method="post" class="wcip-pay-form" style="display:inline;">
                                        <?php wp_nonce_field('wcip_pay_installment', 'wcip_pay_nonce'); ?>
                                        <input type="hidden" name="wcip_pay_installment" value="1" />
                                        <input type="hidden" name="wcip_installment_id" value="<?php echo esc_attr($inst->id); ?>" />
                                        <button type="submit" class="button wcip-pay-btn">
                                            <?php esc_html_e('پرداخت آنلاین', 'wc-installment'); ?>
                                        </button>
                                    </form>
                                    <button type="button" class="button wcip-retry-btn" 
                                            data-installment-id="<?php echo esc_attr($inst->id); ?>" 
                                            style="display:none;">
                                        <?php esc_html_e('تلاش مجدد', 'wc-installment'); ?>
                                    </button>
                                <?php else : ?>
                                    <a href="<?php echo esc_url($invoice_url); ?>" class="button wcip-invoice-btn" target="_blank">
                                        <?php esc_html_e('دانلود فاکتور', 'wc-installment'); ?>
                                    </a>
                                <?php endif; ?>
                            </div>

                            <div class="wcip-card-status-msg" style="display:none;"></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Success Modal -->
            <div id="wcip-success-modal" class="wcip-modal" style="display:none;">
                <div class="wcip-modal-content">
                    <span class="wcip-modal-close">&times;</span>
                    <div class="wcip-modal-icon wcip-modal-success-icon">&#10003;</div>
                    <h3 class="wcip-modal-title"><?php esc_html_e('پرداخت با موفقیت انجام شد!', 'wc-installment'); ?></h3>
                    <p class="wcip-modal-body"><?php esc_html_e('قسط شما با موفقیت پرداخت شد.', 'wc-installment'); ?></p>
                    <div class="wcip-modal-actions">
                        <a href="#" class="button wcip-modal-invoice-btn" target="_blank"><?php esc_html_e('دانلود فاکتور', 'wc-installment'); ?></a>
                        <button type="button" class="button wcip-modal-close-btn"><?php esc_html_e('بستن', 'wc-installment'); ?></button>
                    </div>
                </div>
            </div>

            <!-- Failure Modal -->
            <div id="wcip-fail-modal" class="wcip-modal" style="display:none;">
                <div class="wcip-modal-content">
                    <span class="wcip-modal-close">&times;</span>
                    <div class="wcip-modal-icon wcip-modal-fail-icon">&#10007;</div>
                    <h3 class="wcip-modal-title"><?php esc_html_e('پرداخت ناموفق بود', 'wc-installment'); ?></h3>
                    <p class="wcip-modal-body"><?php esc_html_e('متأسفانه پرداخت ناموفق بود. می‌توانید دوباره تلاش کنید.', 'wc-installment'); ?></p>
                    <div class="wcip-modal-actions">
                        <button type="button" class="button wcip-modal-retry-btn"><?php esc_html_e('تلاش مجدد', 'wc-installment'); ?></button>
                        <button type="button" class="button wcip-modal-close-btn"><?php esc_html_e('بستن', 'wc-installment'); ?></button>
                    </div>
                </div>
            </div>
            <?php
        }
    }
}
