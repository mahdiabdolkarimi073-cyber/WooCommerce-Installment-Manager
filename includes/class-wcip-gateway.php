<?php
/**
 * Custom WooCommerce payment gateway for installment payments.
 *
 * Registers "پرداخت اقساطی" as a selectable payment method at checkout.
 * Only appears when the cart contains at least one installment-mode item.
 * On order completion, generates the installment schedule in the database.
 *
 * @package WC_Installment_Payment
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('WC_Gateway_WCIP_Installment')) {

    class WC_Gateway_WCIP_Installment extends WC_Payment_Gateway
    {
        /**
         * Gateway ID constant.
         */
        const GATEWAY_ID = 'wcip_installment';

        /**
         * Constructor.
         */
        public function __construct()
        {
            $this->id                 = self::GATEWAY_ID;
            $this->plugin_id          = 'woocommerce_';
            $this->method_title       = __('پرداخت اقساطی', 'wc-installment');
            $this->method_description = __('درگاه پرداخت اقساطی — پیش‌پرداخت در زمان خرید و مابقی اقساط طبق زمان‌بندی تعیین‌شده.', 'wc-installment');
            $this->has_fields         = false;
            $this->supports           = array('products');

            // Initialize gateway settings before reading options.
            $this->init_form_fields();
            $this->init_settings();

            // Read options (will use saved settings or defaults).
            $this->title              = $this->get_option('title', __('پرداخت اقساطی', 'wc-installment'));
            $this->description        = $this->get_option('description', __('با انتخاب این روش، مبلغ پیش‌پرداخت در زمان خرید پرداخت می‌شود و اقساط بعدی طبق تاریخ سررسید قابل پرداخت خواهند بود.', 'wc-installment'));
            $this->enabled            = $this->get_option('enabled', 'yes');

            // Save settings via WooCommerce settings API.
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));

            // Only show this gateway when the cart has installment items.
            add_filter('woocommerce_available_payment_gateways', array($this, 'filter_available_gateways'));

            // Generate installment records after the order is placed.
            add_action('woocommerce_checkout_order_processed', array($this, 'maybe_generate_installments'), 20, 1);
            add_action('woocommerce_order_status_processing', array($this, 'maybe_generate_installments'), 20, 1);
            add_action('woocommerce_order_status_completed', array($this, 'maybe_generate_installments'), 20, 1);
        }

        /**
         * Gateway-specific settings fields (shown under WooCommerce → Settings → Payments).
         */
        public function init_form_fields()
        {
            $this->form_fields = array(
                'enabled'     => array(
                    'title'   => __('فعال‌سازی', 'wc-installment'),
                    'type'    => 'checkbox',
                    'label'   => __('فعال‌سازی درگاه پرداخت اقساطی', 'wc-installment'),
                    'default' => 'yes',
                ),
                'title'       => array(
                    'title'       => __('عنوان درگاه', 'wc-installment'),
                    'type'        => 'text',
                    'description' => __('عنوانی که مشتری در صفحه پرداخت می‌بیند.', 'wc-installment'),
                    'default'     => __('پرداخت اقساطی', 'wc-installment'),
                    'desc_tip'    => true,
                ),
                'description' => array(
                    'title'       => __('توضیحات', 'wc-installment'),
                    'type'        => 'textarea',
                    'description' => __('توضیحاتی که مشتری در صفحه پرداخت می‌بیند.', 'wc-installment'),
                    'default'     => __('با انتخاب این روش، مبلغ پیش‌پرداخت در زمان خرید پرداخت می‌شود و اقساط بعدی طبق تاریخ سررسید قابل پرداخت خواهند بود.', 'wc-installment'),
                    'desc_tip'    => true,
                ),
            );
        }

        /**
         * Removes this gateway from the checkout unless the cart has installment items.
         * When the cart has installment items, all real payment gateways remain
         * available so the customer can pay the down payment through any gateway.
         * The custom installment gateway is removed in favor of real gateways.
         *
         * @param array $gateways Available payment gateways.
         * @return array
         */
        public function filter_available_gateways($gateways)
        {
            if (!is_checkout() || !WC()->cart) {
                return $gateways;
            }

            $has_installment = false;

            foreach (WC()->cart->get_cart() as $cart_item) {
                if (!empty($cart_item['wcip_payment_method']) && $cart_item['wcip_payment_method'] === 'installment') {
                    $has_installment = true;
                    break;
                }
            }

            if (function_exists('wcip_debug_log')) {
                wcip_debug_log('Gateway filter: checkout payment gateways', array(
                    'has_installment' => $has_installment ? 'yes' : 'no',
                    'gateway_ids'     => array_keys($gateways),
                    'custom_gateway_present' => isset($gateways[self::GATEWAY_ID]) ? 'yes' : 'no',
                    'enabled_setting' => $this->enabled,
                ));
            }

            if (!$has_installment) {
                // No installment items in cart — remove the custom gateway.
                if (isset($gateways[self::GATEWAY_ID])) {
                    unset($gateways[self::GATEWAY_ID]);
                }
                return $gateways;
            }

            // Cart has installment items — keep the custom gateway so the
            // customer sees "پرداخت اقساطی" as a payment option. Real gateways
            // remain available too, so the customer can also pay the down
            // payment through any active gateway.
            return $gateways;
        }

        /**
         * Processes the payment for an installment order.
         * The down payment is the order total; remaining installments are generated as DB records.
         *
         * @param int $order_id Order ID.
         * @return array
         */
        public function process_payment($order_id)
        {
            $order = wc_get_order($order_id);
            if (!$order) {
                return array(
                    'result'   => 'failure',
                    'messages' => array(__('خطا در پردازش سفارش.', 'wc-installment')),
                );
            }

            // Mark as processing (down payment received).
            $order->update_status('processing', __('پیش‌پرداخت اقساط دریافت شد.', 'wc-installment'));

            // Empty the cart.
            if (WC()->cart) {
                WC()->cart->empty_cart();
            }

            // Return redirect URL to the thank-you page.
            return array(
                'result'   => 'success',
                'redirect' => $this->get_return_url($order),
            );
        }

        /**
         * Generates installment records in the database for an order if it has installment meta.
         *
         * @param int $order_id Order ID.
         */
        public function maybe_generate_installments($order_id)
        {
            $order = wc_get_order($order_id);
            if (!$order) {
                return;
            }

            // Only generate once.
            $generated = $order->get_meta('_wcip_installments_generated');
            if ($generated === 'yes') {
                return;
            }

            $is_installment = $order->get_meta('_installment_enabled');
            if ($is_installment !== 'true') {
                return;
            }

            $down_payment    = floatval($order->get_meta('_installment_down_payment'));
            $per_installment = floatval($order->get_meta('_installment_per_month'));
            $total_months    = intval($order->get_meta('_installment_total_installments'));
            $user_id         = $order->get_customer_id();
            $product_id      = 0;

            // Find the product from order items.
            foreach ($order->get_items() as $item) {
                $product_id = $item->get_product_id();
                if ($product_id) {
                    break;
                }
            }

            if ($total_months < 1 || $per_installment <= 0) {
                return;
            }

            $db = WCIP_DB::instance();
            $created = $db->generate_installments(
                $order_id,
                $user_id,
                $product_id,
                $per_installment,
                $total_months,
                30,
                30
            );

            if ($created > 0) {
                $order->update_meta_data('_wcip_installments_generated', 'yes');
                $order->save();
                $order->add_order_note(sprintf(
                    __('%s قسط برای این سفارش ایجاد شد.', 'wc-installment'),
                    number_to_persian($created)
                ));
            }
        }
    }
}
