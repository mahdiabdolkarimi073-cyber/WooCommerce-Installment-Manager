<?php
/**
 * Checkout & order handling — installment breakdown on cart/checkout and order meta.
 * Reads the selected payment method and plan from cart item session data.
 *
 * @package WC_Installment_Payment
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('WCIP_Checkout')) {

    class WCIP_Checkout
    {
        /**
         * @var WCIP_Checkout|null
         */
        private static $instance = null;

        /**
         * Singleton instance.
         *
         * @return WCIP_Checkout
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
            add_action('woocommerce_after_cart_item_name', array($this, 'display_cart_item_breakdown'), 10, 2);
            add_filter('woocommerce_cart_item_name', array($this, 'display_checkout_item_breakdown'), 10, 3);
            add_action('woocommerce_before_calculate_totals', array($this, 'adjust_cart_item_prices'), 99, 1);
            add_action('woocommerce_checkout_create_order', array($this, 'save_order_installment_meta'), 10, 2);
            add_action('woocommerce_checkout_create_order_line_item', array($this, 'save_order_line_item_meta'), 10, 4);
            add_action('woocommerce_order_details_after_order_table', array($this, 'display_order_received_breakdown'));
        }

        /**
         * Adjusts installment-mode cart items so the customer only pays the
         * down payment at checkout. The remaining balance is stored as
         * installment records after the order is placed.
         *
         * @param WC_Cart $cart Cart object.
         */
        public function adjust_cart_item_prices($cart)
        {
            if (is_admin() && !defined('DOING_AJAX')) {
                return;
            }
            if (did_action('woocommerce_before_calculate_totals') > 1) {
                return;
            }

            foreach ($cart->get_cart() as $cart_item) {
                if (!empty($cart_item['wcip_payment_method']) && $cart_item['wcip_payment_method'] === 'installment') {
                    $down = isset($cart_item['wcip_down_payment']) ? floatval($cart_item['wcip_down_payment']) : 0;
                    if (isset($cart_item['data']) && is_object($cart_item['data'])) {
                        $cart_item['data']->set_price($down);
                    }
                }
            }
        }

        /**
         * Displays installment breakdown for installment-mode cart items.
         *
         * @param array  $cart_item     Cart item array.
         * @param string $cart_item_key Cart item key.
         */
        public function display_cart_item_breakdown($cart_item, $cart_item_key)
        {
            if (empty($cart_item['wcip_payment_method']) || $cart_item['wcip_payment_method'] !== 'installment') {
                return;
            }

            $this->render_mini_breakdown($cart_item);
        }

        /**
         * Displays installment breakdown in checkout review.
         *
         * @param string $product_name Product name HTML.
         * @param array  $cart_item    Cart item array.
         * @param string $cart_item_key Cart item key.
         * @return string
         */
        public function display_checkout_item_breakdown($product_name, $cart_item, $cart_item_key)
        {
            if (!is_checkout()) {
                return $product_name;
            }

            if (empty($cart_item['wcip_payment_method']) || $cart_item['wcip_payment_method'] !== 'installment') {
                return $product_name;
            }

            ob_start();
            $this->render_mini_breakdown($cart_item);
            return $product_name . ob_get_clean();
        }

        /**
         * Renders a compact breakdown from cart item data.
         *
         * @param array $cart_item Cart item with installment data.
         */
        private function render_mini_breakdown($cart_item)
        {
            $down   = isset($cart_item['wcip_down_payment']) ? $cart_item['wcip_down_payment'] : 0;
            $monthly= isset($cart_item['wcip_monthly_installment']) ? $cart_item['wcip_monthly_installment'] : 0;
            $months = isset($cart_item['wcip_plan_months']) ? $cart_item['wcip_plan_months'] : 0;
            $total  = isset($cart_item['wcip_total_payable']) ? $cart_item['wcip_total_payable'] : 0;
            ?>
            <div class="wcip-cart-installment-info">
                <small>
                    <strong><?php esc_html_e('پرداخت اقساطی:', 'wc-installment'); ?></strong>
                    <?php
                    echo esc_html(sprintf(
                        __('پیش‌پرداخت %1$s — %2$s ماه × %3$s | مجموع: %4$s', 'wc-installment'),
                        wcip_format_toman($down),
                        number_to_persian($months),
                        wcip_format_toman($monthly),
                        wcip_format_toman($total)
                    ));
                    ?>
                </small>
            </div>
            <?php
        }

        /**
         * Saves installment meta to the order from cart item session data.
         *
         * @param WC_Order $order Order object.
         * @param array    $data  Posted checkout data.
         */
        public function save_order_installment_meta($order, $data)
        {
            $cart = WC()->cart;
            if (!$cart) {
                return;
            }

            $has_installment = false;
            $total_down = 0;

            foreach ($cart->get_cart() as $cart_item) {
                if (!empty($cart_item['wcip_payment_method']) && $cart_item['wcip_payment_method'] === 'installment') {
                    $has_installment = true;

                    $down     = isset($cart_item['wcip_down_payment']) ? $cart_item['wcip_down_payment'] : 0;
                    $monthly  = isset($cart_item['wcip_monthly_installment']) ? $cart_item['wcip_monthly_installment'] : 0;
                    $months   = isset($cart_item['wcip_plan_months']) ? $cart_item['wcip_plan_months'] : 0;
                    $interest = isset($cart_item['wcip_plan_interest']) ? $cart_item['wcip_plan_interest'] : 0;
                    $total    = isset($cart_item['wcip_total_payable']) ? $cart_item['wcip_total_payable'] : 0;

                    $remaining = $total - $down;

                    $qty = $cart_item['quantity'] ? $cart_item['quantity'] : 1;
                    $total_down += floatval($down) * $qty;

                    $order->update_meta_data('_installment_enabled', 'true');
                    $order->update_meta_data('_installment_down_payment', $down);
                    $order->update_meta_data('_installment_per_month', $monthly);
                    $order->update_meta_data('_installment_total_installments', $months);
                    $order->update_meta_data('_installment_interest_rate', $interest);
                    $order->update_meta_data('_installment_total_payable', $total);
                    $order->update_meta_data('_installment_remaining_amount', $remaining);
                    $order->update_meta_data('_installment_method', 'months');
                }
            }

            if ($has_installment) {
                $order->add_order_note(__('سفارش شامل پرداخت اقساطی است.', 'wc-installment'));
            }
        }

        /**
         * Saves installment meta on individual order line items.
         *
         * @param WC_Order_Item_Product $item          Order item object.
         * @param string                $cart_item_key Cart item key.
         * @param array                 $values        Cart item values.
         * @param WC_Order              $order         Order object.
         */
        public function save_order_line_item_meta($item, $cart_item_key, $values, $order)
        {
            if (empty($values['wcip_payment_method']) || $values['wcip_payment_method'] !== 'installment') {
                return;
            }

            $months = isset($values['wcip_plan_months']) ? $values['wcip_plan_months'] : 0;
            $monthly = isset($values['wcip_monthly_installment']) ? $values['wcip_monthly_installment'] : 0;
            $down = isset($values['wcip_down_payment']) ? $values['wcip_down_payment'] : 0;
            $total = isset($values['wcip_total_payable']) ? $values['wcip_total_payable'] : 0;

            $item->add_meta_data(__('روش پرداخت', 'wc-installment'), __('اقساطی', 'wc-installment'));
            $item->add_meta_data(__('پیش‌پرداخت', 'wc-installment'), wcip_format_toman($down));
            $item->add_meta_data(__('مبلغ هر قسط', 'wc-installment'), wcip_format_toman($monthly));
            $item->add_meta_data(__('تعداد اقساط', 'wc-installment'), number_to_persian($months) . ' ' . __('ماه', 'wc-installment'));
            $item->add_meta_data(__('مجموع قابل پرداخت', 'wc-installment'), wcip_format_toman($total));
        }

        /**
         * Displays the installment breakdown on the order-received (thank you) page.
         *
         * @param WC_Order $order Order object.
         */
        public function display_order_received_breakdown($order)
        {
            $enabled = $order->get_meta('_installment_enabled');

            if ($enabled !== 'true') {
                return;
            }

            $down_payment     = $order->get_meta('_installment_down_payment');
            $per_installment  = $order->get_meta('_installment_per_month');
            $total_installs   = $order->get_meta('_installment_total_installments');
            $total_payable    = $order->get_meta('_installment_total_payable');
            $interest_rate    = $order->get_meta('_installment_interest_rate');
            $remaining_amount = $order->get_meta('_installment_remaining_amount');

            $interest_label = ($interest_rate > 0)
                ? sprintf('%s%%', number_to_persian($interest_rate))
                : __('بدون سود', 'wc-installment');

            $remaining_calc = floatval($per_installment) * intval($total_installs);
            $fee_amount = max(0, floatval($total_payable) - floatval($down_payment) - $remaining_calc);
            ?>
            <div class="wcip-order-installment-summary">
                <h3><?php esc_html_e('جزئیات پرداخت اقساطی', 'wc-installment'); ?></h3>
                <table class="wcip-installment-table">
                    <tbody>
                        <tr>
                            <th><?php esc_html_e('مبلغ پیش‌پرداخت', 'wc-installment'); ?></th>
                            <td><?php echo esc_html(wcip_format_toman($down_payment)); ?></td>
                        </tr>
                        <?php if (floatval($down_payment) > 0) : ?>
                        <tr>
                            <th><?php esc_html_e('پرداخت شده در زمان خرید', 'wc-installment'); ?></th>
                            <td><?php echo esc_html(wcip_format_toman($down_payment)); ?></td>
                        </tr>
                        <?php else : ?>
                        <tr>
                            <th><?php esc_html_e('پیش‌پرداخت', 'wc-installment'); ?></th>
                            <td><?php esc_html_e('بدون پیش‌پرداخت — کل مبلغ به‌صورت اقساطی', 'wc-installment'); ?></td>
                        </tr>
                        <?php endif; ?>
                        <tr>
                            <th><?php esc_html_e('مبلغ باقی‌مانده', 'wc-installment'); ?></th>
                            <td><?php echo esc_html(wcip_format_toman($remaining_amount)); ?></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e('مبلغ هر قسط', 'wc-installment'); ?></th>
                            <td><?php echo esc_html(wcip_format_toman($per_installment)); ?></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e('تعداد اقساط', 'wc-installment'); ?></th>
                            <td><?php echo esc_html(number_to_persian($total_installs) . ' ' . __('ماه', 'wc-installment')); ?></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e('درصد سود', 'wc-installment'); ?></th>
                            <td><?php echo esc_html($interest_label); ?></td>
                        </tr>
                        <tr class="wcip-total-row">
                            <th><?php esc_html_e('مجموع مبلغ قابل پرداخت', 'wc-installment'); ?></th>
                            <td><?php echo esc_html(wcip_format_toman($total_payable)); ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <?php
        }
    }
}
