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
            add_filter('woocommerce_cart_item_price', array($this, 'filter_cart_item_price'), 10, 3);
            add_filter('woocommerce_cart_item_subtotal', array($this, 'filter_cart_item_subtotal'), 10, 3);
            add_action('woocommerce_before_calculate_totals', array($this, 'adjust_cart_item_prices'), 10, 1);
            add_action('woocommerce_cart_loaded_from_session', array($this, 'log_loaded_cart'), 20, 1);
            add_action('woocommerce_cart_totals_before_order_total', array($this, 'render_installment_total_rows'));
            add_action('woocommerce_review_order_before_order_total', array($this, 'render_installment_total_rows'));
            add_action('woocommerce_checkout_create_order', array($this, 'save_order_installment_meta'), 10, 2);
            add_action('woocommerce_checkout_create_order_line_item', array($this, 'save_order_line_item_meta'), 10, 4);
            add_action('woocommerce_order_details_after_order_table', array($this, 'display_order_received_breakdown'));

            // Checkout review: render installment toggle for eligible products.
            add_action('woocommerce_review_order_after_cart_contents', array($this, 'render_checkout_installment_selector'));
            add_action('woocommerce_cart_collaterals', array($this, 'render_cart_installment_selector'));

            // AJAX: apply installment mode to a cart item from checkout/cart.
            add_action('wp_ajax_wcip_toggle_installment', array($this, 'ajax_toggle_installment'));
            add_action('wp_ajax_nopriv_wcip_toggle_installment', array($this, 'ajax_toggle_installment'));
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

            foreach ($cart->cart_contents as $cart_item_key => &$cart_item) {
                if (!empty($cart_item['wcip_payment_method']) && $cart_item['wcip_payment_method'] === 'installment') {
                    $down = isset($cart_item['wcip_down_payment']) ? floatval($cart_item['wcip_down_payment']) : 0;
                    $original_price = '';
                    if (isset($cart_item['data']) && is_object($cart_item['data'])) {
                        $original_price = $cart_item['data']->get_price();
                        $cart_item['data']->set_price($down);
                    }

                    wcip_debug_log('Cart price adjusted for installment item', array(
                        'cart_item_key' => $cart_item_key,
                        'product_id' => isset($cart_item['product_id']) ? $cart_item['product_id'] : 0,
                        'down_payment' => $down,
                        'price_before' => $original_price,
                        'price_after' => $down,
                        'months' => isset($cart_item['wcip_plan_months']) ? $cart_item['wcip_plan_months'] : 0,
                        'monthly' => isset($cart_item['wcip_monthly_installment']) ? $cart_item['wcip_monthly_installment'] : 0,
                        'total_payable' => isset($cart_item['wcip_total_payable']) ? $cart_item['wcip_total_payable'] : 0,
                    ));
                }
            }
            unset($cart_item);
        }

        /**
         * Logs cart contents after WooCommerce restores them from the session.
         *
         * @param WC_Cart $cart Cart object.
         */
        public function log_loaded_cart($cart)
        {
            $items = array();
            foreach ($cart->cart_contents as $cart_item_key => $cart_item) {
                $items[] = array(
                    'cart_item_key' => $cart_item_key,
                    'product_id' => isset($cart_item['product_id']) ? $cart_item['product_id'] : 0,
                    'quantity' => isset($cart_item['quantity']) ? $cart_item['quantity'] : 0,
                    'payment_method' => isset($cart_item['wcip_payment_method']) ? $cart_item['wcip_payment_method'] : 'missing',
                    'selected_plan' => isset($cart_item['wcip_selected_plan']) ? $cart_item['wcip_selected_plan'] : 'missing',
                    'months' => isset($cart_item['wcip_plan_months']) ? $cart_item['wcip_plan_months'] : 'missing',
                    'down_payment' => isset($cart_item['wcip_down_payment']) ? $cart_item['wcip_down_payment'] : 'missing',
                    'monthly' => isset($cart_item['wcip_monthly_installment']) ? $cart_item['wcip_monthly_installment'] : 'missing',
                    'total_payable' => isset($cart_item['wcip_total_payable']) ? $cart_item['wcip_total_payable'] : 'missing',
                );
            }

            wcip_debug_log('Cart loaded from WooCommerce session', array(
                'item_count' => count($items),
                'items' => $items,
            ));
        }

        /**
         * Replaces the per-line price column on the cart page with the
         * installment breakdown (down payment + monthly) for installment items.
         *
         * @param string $price_html     Original price HTML.
         * @param array  $cart_item      Cart item array.
         * @param string $cart_item_key   Cart item key.
         * @return string
         */
        public function filter_cart_item_price($price_html, $cart_item, $cart_item_key)
        {
            if (empty($cart_item['wcip_payment_method']) || $cart_item['wcip_payment_method'] !== 'installment') {
                return $price_html;
            }

            return $this->build_price_breakdown_html($cart_item);
        }

        /**
         * Replaces the per-line subtotal column on the cart page for
         * installment items with the same breakdown.
         *
         * @param string $subtotal_html  Original subtotal HTML.
         * @param array  $cart_item      Cart item array.
         * @param string $cart_item_key   Cart item key.
         * @return string
         */
        public function filter_cart_item_subtotal($subtotal_html, $cart_item, $cart_item_key)
        {
            if (empty($cart_item['wcip_payment_method']) || $cart_item['wcip_payment_method'] !== 'installment') {
                return $subtotal_html;
            }

            return $this->build_price_breakdown_html($cart_item);
        }

        /**
         * Builds the HTML breakdown shown in the price/subtotal column.
         *
         * @param array $cart_item Cart item with installment data.
         * @return string
         */
        private function build_price_breakdown_html($cart_item)
        {
            $down    = isset($cart_item['wcip_down_payment'])         ? $cart_item['wcip_down_payment']         : 0;
            $monthly = isset($cart_item['wcip_monthly_installment']) ? $cart_item['wcip_monthly_installment'] : 0;
            $months  = isset($cart_item['wcip_plan_months'])         ? $cart_item['wcip_plan_months']         : 0;
            $total   = isset($cart_item['wcip_total_payable'])        ? $cart_item['wcip_total_payable']        : 0;

            wcip_debug_log('build_price_breakdown_html called', array(
                'product_id'    => isset($cart_item['product_id']) ? $cart_item['product_id'] : 0,
                'down_payment'  => $down,
                'monthly'       => $monthly,
                'months'        => $months,
                'total_payable' => $total,
                'has_data'      => ($down > 0 || $monthly > 0 || $total > 0) ? 'yes' : 'no',
            ));

            ob_start();
            ?>
            <div class="wcip-price-breakdown">
                <div class="wcip-price-row wcip-price-down">
                    <span class="wcip-price-label"><?php esc_html_e('پیش‌پرداخت', 'wc-installment'); ?></span>
                    <span class="wcip-price-value"><?php echo esc_html(wcip_format_toman($down)); ?></span>
                </div>
                <div class="wcip-price-row wcip-price-monthly">
                    <span class="wcip-price-label"><?php echo esc_html(number_to_persian($months) . ' ' . __('قسط ماهانه', 'wc-installment')); ?></span>
                    <span class="wcip-price-value"><?php echo esc_html(wcip_format_toman($monthly)); ?></span>
                </div>
                <div class="wcip-price-row wcip-price-total">
                    <span class="wcip-price-label"><?php esc_html_e('جمع کل قابل پرداخت', 'wc-installment'); ?></span>
                    <span class="wcip-price-value"><?php echo esc_html(wcip_format_toman($total)); ?></span>
                </div>
            </div>
            <?php
            return ob_get_clean();
        }

        /**
         * Renders extra rows (down payment, monthly, total payable) inside the
         * cart-totals and checkout-review totals tables so the customer sees the
         * full installment summary alongside the amount due today.
         */
        public function render_installment_total_rows()
        {
            if (!WC()->cart) {
                return;
            }

            $items = array();
            foreach (WC()->cart->get_cart() as $cart_item) {
                if (!empty($cart_item['wcip_payment_method']) && $cart_item['wcip_payment_method'] === 'installment') {
                    $items[] = $cart_item;
                }
            }

            if (empty($items)) {
                return;
            }

            foreach ($items as $item) {
                $down    = isset($item['wcip_down_payment'])         ? $item['wcip_down_payment']         : 0;
                $monthly = isset($item['wcip_monthly_installment']) ? $item['wcip_monthly_installment'] : 0;
                $months  = isset($item['wcip_plan_months'])         ? $item['wcip_plan_months']         : 0;
                $total   = isset($item['wcip_total_payable'])        ? $item['wcip_total_payable']        : 0;
                ?>
                <tr class="wcip-totals-row wcip-totals-down-payment">
                    <th><?php esc_html_e('پیش‌پرداخت (امروز)', 'wc-installment'); ?></th>
                    <td><?php echo esc_html(wcip_format_toman($down)); ?></td>
                </tr>
                <tr class="wcip-totals-row wcip-totals-monthly">
                    <th><?php echo esc_html(number_to_persian($months) . ' ' . __('قسط ماهانه', 'wc-installment')); ?></th>
                    <td><?php echo esc_html(wcip_format_toman($monthly) . ' / ' . __('ماه', 'wc-installment')); ?></td>
                </tr>
                <tr class="wcip-totals-row wcip-totals-total-payable">
                    <th><?php esc_html_e('جمع کل قابل پرداخت', 'wc-installment'); ?></th>
                    <td><?php echo esc_html(wcip_format_toman($total)); ?></td>
                </tr>
                <?php
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

            if (function_exists('wcip_debug_log')) {
                wcip_debug_log('Checkout: save_order_installment_meta fired', array(
                    'cart_items' => count($cart->get_cart()),
                ));
            }

            foreach ($cart->get_cart() as $cart_item) {
                wcip_debug_log('Checkout: cart item check', array(
                    'product_id'      => isset($cart_item['product_id']) ? $cart_item['product_id'] : 0,
                    'payment_method'  => isset($cart_item['wcip_payment_method']) ? $cart_item['wcip_payment_method'] : 'cash',
                    'months'          => isset($cart_item['wcip_plan_months']) ? $cart_item['wcip_plan_months'] : 'missing',
                    'down_payment'    => isset($cart_item['wcip_down_payment']) ? $cart_item['wcip_down_payment'] : 'missing',
                    'monthly'         => isset($cart_item['wcip_monthly_installment']) ? $cart_item['wcip_monthly_installment'] : 'missing',
                    'total_payable'   => isset($cart_item['wcip_total_payable']) ? $cart_item['wcip_total_payable'] : 'missing',
                ));
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

                    wcip_debug_log('Checkout: installment meta saved to order', array(
                        'product_id'      => isset($cart_item['product_id']) ? $cart_item['product_id'] : 0,
                        'down_payment'    => $down,
                        'monthly'         => $monthly,
                        'months'          => $months,
                        'total_payable'   => $total,
                        'remaining'       => $remaining,
                    ));
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
         * Renders the installment toggle selector on the checkout review page.
         * Shows a "pay in installments" option for each eligible cart item that
         * is not already in installment mode.
         */
        public function render_checkout_installment_selector()
        {
            if (!WC()->cart) {
                return;
            }

            $eligible_items = $this->get_eligible_cart_items();

            if (empty($eligible_items)) {
                return;
            }

            $nonce = wp_create_nonce('wcip-toggle-installment');
            ?>
            <tr class="wcip-checkout-installment-row">
                <td colspan="2">
                    <div class="wcip-checkout-installment-selector">
                        <h4><?php esc_html_e('پرداخت اقساطی', 'wc-installment'); ?></h4>
                        <p class="wcip-toggle-desc"><?php esc_html_e('برای هر کالای eligible می‌توانید پرداخت اقساطی را انتخاب کنید:', 'wc-installment'); ?></p>
                        <?php foreach ($eligible_items as $item) :
                            $plans = wcip_get_product_plans($item['product_id']);
                            ?>
                            <div class="wcip-toggle-item" data-cart-key="<?php echo esc_attr($item['cart_item_key']); ?>">
                                <label class="wcip-toggle-label">
                                    <input type="checkbox"
                                           class="wcip-installment-toggle"
                                           data-cart-key="<?php echo esc_attr($item['cart_item_key']); ?>"
                                           data-product-id="<?php echo esc_attr($item['product_id']); ?>"
                                           data-nonce="<?php echo esc_attr($nonce); ?>"
                                           <?php checked($item['is_installment'], true); ?> />
                                    <span><?php echo esc_html($item['product_name']); ?></span>
                                </label>
                                <select class="wcip-plan-select"
                                        data-cart-key="<?php echo esc_attr($item['cart_item_key']); ?>"
                                        data-product-id="<?php echo esc_attr($item['product_id']); ?>"
                                        data-nonce="<?php echo esc_attr($nonce); ?>"
                                        <?php echo $item['is_installment'] ? '' : 'style="display:none;"'; ?>>
                                    <?php foreach ($plans as $idx => $plan) : ?>
                                        <option value="<?php echo esc_attr($idx); ?>"
                                            <?php echo ($item['is_installment'] && isset($item['plan_idx']) && $item['plan_idx'] == $idx) ? 'selected' : ''; ?>>
                                            <?php echo esc_html(number_to_persian($plan['months']) . ' ' . __('ماه', 'wc-installment')); ?>
                                            <?php if ($plan['interest_rate'] > 0) : ?>
                                                — <?php echo esc_html(sprintf(__('سود %s%%', 'wc-installment'), number_to_persian($plan['interest_rate']))); ?>
                                            <?php else : ?>
                                                — <?php esc_html_e('بدون سود', 'wc-installment'); ?>
                                            <?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <span class="wcip-toggle-breakdown" data-cart-key="<?php echo esc_attr($item['cart_item_key']); ?>">
                                    <?php if ($item['is_installment']) :
                                        $this->render_toggle_breakdown($item);
                                    endif; ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </td>
            </tr>
            <?php
        }

        /**
         * Renders the installment toggle on the cart page (below cart collaterals).
         */
        public function render_cart_installment_selector()
        {
            if (!is_cart() || !WC()->cart) {
                return;
            }

            $this->render_checkout_installment_selector();
        }

        /**
         * Returns cart items eligible for installment mode.
         *
         * @return array List of eligible items with product info.
         */
        private function get_eligible_cart_items()
        {
            $eligible = array();

            foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
                $product_id = isset($cart_item['product_id']) ? $cart_item['product_id'] : 0;
                if (!$product_id) {
                    continue;
                }

                if (!wcip_is_installment_enabled_for_product($product_id)) {
                    continue;
                }

                $product = wc_get_product($product_id);
                if (!$product) {
                    continue;
                }

                $is_installment = !empty($cart_item['wcip_payment_method']) && $cart_item['wcip_payment_method'] === 'installment';
                $plan_idx = isset($cart_item['wcip_selected_plan']) ? intval($cart_item['wcip_selected_plan']) : 0;

                $eligible[] = array(
                    'cart_item_key'   => $cart_item_key,
                    'product_id'      => $product_id,
                    'product_name'    => $product->get_name(),
                    'is_installment'  => $is_installment,
                    'plan_idx'        => $plan_idx,
                    'cart_item'       => $cart_item,
                );
            }

            if (function_exists('wcip_debug_log')) {
                wcip_debug_log('Checkout selector: eligible cart items', array(
                    'count'         => count($eligible),
                    'product_ids'   => wp_list_pluck($eligible, 'product_id'),
                ));
            }

            return $eligible;
        }

        /**
         * Renders a breakdown preview for a toggled item.
         *
         * @param array $item Eligible item data.
         */
        private function render_toggle_breakdown($item)
        {
            $cart_item = $item['cart_item'];
            $down = isset($cart_item['wcip_down_payment']) ? $cart_item['wcip_down_payment'] : 0;
            $monthly = isset($cart_item['wcip_monthly_installment']) ? $cart_item['wcip_monthly_installment'] : 0;
            $months = isset($cart_item['wcip_plan_months']) ? $cart_item['wcip_plan_months'] : 0;
            $total = isset($cart_item['wcip_total_payable']) ? $cart_item['wcip_total_payable'] : 0;
            ?>
            <small class="wcip-toggle-breakdown-text">
                <strong><?php esc_html_e('پیش‌پرداخت:', 'wc-installment'); ?></strong> <?php echo esc_html(wcip_format_toman($down)); ?>
                | <strong><?php esc_html_e('هر قسط:', 'wc-installment'); ?></strong> <?php echo esc_html(wcip_format_toman($monthly)); ?>
                | <strong><?php esc_html_e('تعداد:', 'wc-installment'); ?></strong> <?php echo esc_html(number_to_persian($months) . ' ' . __('ماه', 'wc-installment')); ?>
                | <strong><?php esc_html_e('مجموع:', 'wc-installment'); ?></strong> <?php echo esc_html(wcip_format_toman($total)); ?>
            </small>
            <?php
        }

        /**
         * AJAX handler: toggles installment mode for a cart item from checkout/cart.
         * Applies or removes installment data on the cart item and recalculates totals.
         */
        public function ajax_toggle_installment()
        {
            check_ajax_referer('wcip-toggle-installment', 'nonce');

            $cart_item_key = isset($_POST['cart_item_key']) ? sanitize_text_field(wp_unslash($_POST['cart_item_key'])) : '';
            $product_id    = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
            $enable        = isset($_POST['enable']) ? ($_POST['enable'] === 'true' || $_POST['enable'] === '1') : false;
            $plan_idx      = isset($_POST['plan_idx']) ? intval($_POST['plan_idx']) : 0;

            if (empty($cart_item_key) || $product_id < 1) {
                wp_send_json_error(array('message' => __('پارامتر نامعتبر.', 'wc-installment')));
            }

            if (!wcip_is_installment_enabled_for_product($product_id)) {
                wp_send_json_error(array('message' => __('اقساط برای این محصول فعال نیست.', 'wc-installment')));
            }

            $cart = WC()->cart;
            if (!$cart) {
                wp_send_json_error(array('message' => __('سبد در دسترس نیست.', 'wc-installment')));
            }

            $cart_item = $cart->get_cart_item($cart_item_key);
            if (!$cart_item) {
                wp_send_json_error(array('message' => __('آیتم سبد یافت نشد.', 'wc-installment')));
            }

            if (function_exists('wcip_debug_log')) {
                wcip_debug_log('AJAX toggle: request received', array(
                    'cart_item_key' => $cart_item_key,
                    'product_id'    => $product_id,
                    'enable'        => $enable ? 'yes' : 'no',
                    'plan_idx'      => $plan_idx,
                ));
            }

            if ($enable) {
                $plans = wcip_get_product_plans($product_id);
                if (!isset($plans[$plan_idx])) {
                    $plan_idx = 0;
                }
                if (!isset($plans[$plan_idx])) {
                    wp_send_json_error(array('message' => __('طرح اقساطی موجود نیست.', 'wc-installment')));
                }

                $plan = $plans[$plan_idx];
                $calc = WCIP_Calculator::instance()->calculate_product_plan(
                    $product_id,
                    $plan['months'],
                    $plan['interest_rate']
                );

                if (!$calc) {
                    wp_send_json_error(array('message' => __('خطا در محاسبه اقساط.', 'wc-installment')));
                }

                $cart->cart_contents[$cart_item_key]['wcip_payment_method']      = 'installment';
                $cart->cart_contents[$cart_item_key]['wcip_selected_plan']       = strval($plan_idx);
                $cart->cart_contents[$cart_item_key]['wcip_plan_months']         = $plan['months'];
                $cart->cart_contents[$cart_item_key]['wcip_plan_interest']       = $plan['interest_rate'];
                $cart->cart_contents[$cart_item_key]['wcip_down_payment']        = $calc['down_payment'];
                $cart->cart_contents[$cart_item_key]['wcip_monthly_installment'] = $calc['monthly_installment'];
                $cart->cart_contents[$cart_item_key]['wcip_total_payable']       = $calc['total_payable'];

                if (function_exists('wcip_debug_log')) {
                    wcip_debug_log('AJAX toggle: installment enabled', array(
                        'cart_item_key'  => $cart_item_key,
                        'plan_idx'       => $plan_idx,
                        'months'         => $plan['months'],
                        'down_payment'   => $calc['down_payment'],
                        'monthly'        => $calc['monthly_installment'],
                        'total_payable'  => $calc['total_payable'],
                    ));
                }
            } else {
                unset(
                    $cart->cart_contents[$cart_item_key]['wcip_payment_method'],
                    $cart->cart_contents[$cart_item_key]['wcip_selected_plan'],
                    $cart->cart_contents[$cart_item_key]['wcip_plan_months'],
                    $cart->cart_contents[$cart_item_key]['wcip_plan_interest'],
                    $cart->cart_contents[$cart_item_key]['wcip_down_payment'],
                    $cart->cart_contents[$cart_item_key]['wcip_monthly_installment'],
                    $cart->cart_contents[$cart_item_key]['wcip_total_payable']
                );

                if (function_exists('wcip_debug_log')) {
                    wcip_debug_log('AJAX toggle: installment disabled', array(
                        'cart_item_key' => $cart_item_key,
                    ));
                }
            }

            $cart->calculate_totals();
            $cart->set_session();

            $breakdown_html = '';
            if ($enable) {
                ob_start();
                $this->render_toggle_breakdown(array(
                    'cart_item' => $cart->get_cart_item($cart_item_key),
                ));
                $breakdown_html = ob_get_clean();
            }

            wcip_debug_log('AJAX toggle: completed, session saved', array(
                'cart_item_key' => $cart_item_key,
                'enable'        => $enable ? 'yes' : 'no',
                'cart_total'    => $cart->get_cart_contents_total(),
                'breakdown_len' => strlen($breakdown_html),
            ));

            wp_send_json_success(array(
                'breakdown_html' => $breakdown_html,
                'cart_total'     => $cart->get_cart_contents_total(),
            ));
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
