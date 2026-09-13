<?php
/**
 * Frontend display — payment method selector and installment details
 * on the single product page.
 *
 * @package WC_Installment_Payment
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('WCIP_Frontend')) {

    class WCIP_Frontend
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
            // Hook AFTER the add-to-cart button (priority 35) so our wrapper
            // appears below the form, avoiding conflicts with form.cart scope.
            add_action('woocommerce_after_add_to_cart_form', array($this, 'display_payment_selector'), 10);

            add_filter('woocommerce_add_cart_item_data', array($this, 'add_cart_item_data'), 10, 3);
            add_filter('woocommerce_get_cart_item_from_session', array($this, 'get_cart_item_from_session'), 10, 3);
            add_filter('woocommerce_get_item_data', array($this, 'get_item_data'), 10, 2);
            add_action('wp_ajax_wcip_add_installment_to_cart', array($this, 'ajax_add_installment_to_cart'));
            add_action('wp_ajax_nopriv_wcip_add_installment_to_cart', array($this, 'ajax_add_installment_to_cart'));
        }

        /**
         * Renders the payment method selector (and installment details) below the add-to-cart form.
         */
        public function display_payment_selector()
        {
            global $product;

            if (!$product) {
                return;
            }

            $product_id          = $product->get_id();
            $installment_enabled = wcip_is_installment_enabled_for_product($product_id);
            $price               = floatval($product->get_price());

            if ($price <= 0) {
                return;
            }

            $plans    = $installment_enabled ? wcip_get_product_plans($product_id) : array();
            $settings = $installment_enabled ? wcip_get_product_installment_settings($product_id) : array();

            $js_plans = array();
            $max_months = 0;
            foreach ($plans as $plan) {
                $months = intval($plan['months']);
                $ir     = floatval($plan['interest_rate']);
                $js_plans[] = array('months' => $months, 'interestRate' => $ir);
                if ($months > $max_months) {
                    $max_months = $months;
                }
            }

            $down_payment_type  = isset($settings['down_payment_type'])  ? $settings['down_payment_type']        : 'percentage';
            $down_payment_value = isset($settings['down_payment_value'])  ? floatval($settings['down_payment_value']) : 0;
            $fee_type           = isset($settings['fee_type'])            ? $settings['fee_type']                 : 'percentage';
            $fee_value          = isset($settings['fee_value'])           ? floatval($settings['fee_value'])      : 0;

            $nonce_frontend = wp_create_nonce('wcip-frontend');
            $nonce_cart     = wp_create_nonce('wcip-add-to-cart');
            $ajax_url       = admin_url('admin-ajax.php');
            ?>
            <!-- WCIP: Payment selector (rendered after the add-to-cart form) -->
            <div class="wcip-payment-selector-wrapper" data-product-id="<?php echo esc_attr($product_id); ?>">
                <h3 class="wcip-section-title"><?php esc_html_e('روش پرداخت', 'wc-installment'); ?></h3>

                <div class="wcip-payment-methods">
                    <label class="wcip-payment-method-option" data-method="cash">
                        <input type="radio" name="wcip_payment_method" value="cash" checked />
                        <span class="wcip-payment-method-icon">نقدی</span>
                        <span class="wcip-payment-method-label"><?php esc_html_e('پرداخت نقدی', 'wc-installment'); ?></span>
                        <span class="wcip-payment-method-price"><?php echo esc_html(wcip_format_toman($price)); ?></span>
                    </label>

                    <?php if ($installment_enabled && count($js_plans) > 0) : ?>
                    <label class="wcip-payment-method-option" data-method="installment">
                        <input type="radio" name="wcip_payment_method" value="installment" />
                        <span class="wcip-payment-method-icon">اقساط</span>
                        <span class="wcip-payment-method-label"><?php esc_html_e('پرداخت اقساطی', 'wc-installment'); ?></span>
                        <span class="wcip-payment-method-price"><?php echo esc_html(wcip_format_toman($price)); ?>+</span>
                    </label>
                    <?php endif; ?>
                </div>

                <?php if ($installment_enabled && count($js_plans) > 0) : ?>
                <div class="wcip-installment-details" style="display:none;">
                    <h4 class="wcip-details-title"><?php esc_html_e('انتخاب طرح اقساطی', 'wc-installment'); ?></h4>

                    <div class="wcip-plan-selection">
                        <?php if (count($js_plans) > 1) : ?>
                            <div class="wcip-plan-radios">
                                <?php foreach ($js_plans as $idx => $plan) : ?>
                                    <label class="wcip-plan-option">
                                        <input type="radio" name="wcip_installment_plan" value="<?php echo esc_attr($idx); ?>" <?php checked($idx, 0); ?> />
                                        <span><?php echo esc_html(number_to_persian($plan['months']) . ' ' . __('ماه', 'wc-installment')); ?></span>
                                        <?php if ($plan['interestRate'] > 0) : ?>
                                            <small class="wcip-plan-interest"><?php echo esc_html(sprintf('سود %s%%', number_to_persian($plan['interestRate']))); ?></small>
                                        <?php endif; ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        <?php else : ?>
                            <input type="hidden" name="wcip_installment_plan" value="0" />
                            <p class="wcip-single-plan-info">
                                <?php echo esc_html(sprintf(
                                    __('این محصول با %s ماه اقساط قابل خرید است.', 'wc-installment'),
                                    number_to_persian($js_plans[0]['months'])
                                )); ?>
                            </p>
                        <?php endif; ?>
                    </div>

                    <div class="wcip-installment-info-bar">
                        <div class="wcip-info-badge">
                            <span class="wcip-info-badge-label"><?php esc_html_e('حداکثر تعداد اقساط', 'wc-installment'); ?></span>
                            <span class="wcip-info-badge-value"><?php echo esc_html(number_to_persian($max_months) . ' ' . __('ماه', 'wc-installment')); ?></span>
                        </div>
                        <div class="wcip-info-badge">
                            <span class="wcip-info-badge-label"><?php esc_html_e('نوع کارمزد', 'wc-installment'); ?></span>
                            <span class="wcip-info-badge-value"><?php echo esc_html($fee_type === 'percentage' ? __('درصدی', 'wc-installment') : __('مبلغ ثابت', 'wc-installment')); ?></span>
                        </div>
                        <div class="wcip-info-badge">
                            <span class="wcip-info-badge-label"><?php esc_html_e('مقدار کارمزد', 'wc-installment'); ?></span>
                            <span class="wcip-info-badge-value"><?php echo esc_html($fee_type === 'percentage' ? number_to_persian($fee_value) . '٪' : wcip_format_toman($fee_value)); ?></span>
                        </div>
                    </div>

                    <div class="wcip-installment-breakdown">
                        <div class="wcip-breakdown-row">
                            <span class="wcip-breakdown-label"><?php esc_html_e('مبلغ پیش‌پرداخت', 'wc-installment'); ?></span>
                            <span class="wcip-breakdown-value" id="wcip-down-payment">—</span>
                        </div>
                        <div class="wcip-breakdown-row wcip-down-payment-notice" id="wcip-down-payment-notice" style="display:none;">
                            <span class="wcip-notice-text" id="wcip-down-payment-notice-text"></span>
                        </div>
                        <div class="wcip-breakdown-row">
                            <span class="wcip-breakdown-label"><?php esc_html_e('مبلغ باقی‌مانده', 'wc-installment'); ?></span>
                            <span class="wcip-breakdown-value" id="wcip-remaining-amount">—</span>
                        </div>
                        <div class="wcip-breakdown-row">
                            <span class="wcip-breakdown-label"><?php esc_html_e('مبلغ کارمزد/سود', 'wc-installment'); ?></span>
                            <span class="wcip-breakdown-value" id="wcip-fee-amount">—</span>
                        </div>
                        <div class="wcip-breakdown-row">
                            <span class="wcip-breakdown-label"><?php esc_html_e('مبلغ هر قسط', 'wc-installment'); ?></span>
                            <span class="wcip-breakdown-value" id="wcip-monthly-installment">—</span>
                        </div>
                        <div class="wcip-breakdown-row">
                            <span class="wcip-breakdown-label"><?php esc_html_e('تعداد اقساط', 'wc-installment'); ?></span>
                            <span class="wcip-breakdown-value" id="wcip-installment-count">—</span>
                        </div>
                        <div class="wcip-breakdown-row wcip-breakdown-total">
                            <span class="wcip-breakdown-label"><?php esc_html_e('مجموع مبلغ قابل پرداخت', 'wc-installment'); ?></span>
                            <span class="wcip-breakdown-value" id="wcip-total-payable">—</span>
                        </div>
                    </div>

                    <div class="wcip-validation-error" id="wcip-plan-error" style="display:none;">
                        <?php esc_html_e('لطفاً یک طرح اقساطی انتخاب کنید.', 'wc-installment'); ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Add-to-cart buttons for this widget -->
                <div class="wcip-action-buttons" style="margin-top:16px; display:flex; gap:10px; flex-wrap:wrap;">
                    <button type="button" class="button alt wcip-btn-cash single_add_to_cart_button" id="wcip-add-cash-btn" data-product-id="<?php echo esc_attr($product_id); ?>">
                        <?php esc_html_e('افزودن نقدی به سبد', 'wc-installment'); ?>
                    </button>
                    <?php if ($installment_enabled && count($js_plans) > 0) : ?>
                    <button type="button" class="button alt wcip-btn-installment" id="wcip-add-installment-btn" data-product-id="<?php echo esc_attr($product_id); ?>">
                        <?php esc_html_e('خرید اقساطی', 'wc-installment'); ?>
                    </button>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Installment confirmation modal -->
            <div id="wcip-confirm-modal" class="wcip-modal" style="display:none;">
                <div class="wcip-modal-overlay"></div>
                <div class="wcip-modal-box wcip-confirm-box">
                    <button type="button" class="wcip-modal-close">&times;</button>
                    <h3 class="wcip-confirm-title"><?php esc_html_e('تأیید خرید اقساطی', 'wc-installment'); ?></h3>
                    <div class="wcip-confirm-body">
                        <div class="wcip-confirm-row">
                            <span class="wcip-confirm-label"><?php esc_html_e('مبلغ پیش‌پرداخت', 'wc-installment'); ?></span>
                            <span class="wcip-confirm-value" id="wcip-confirm-down">—</span>
                        </div>
                        <div class="wcip-confirm-row">
                            <span class="wcip-confirm-label"><?php esc_html_e('مبلغ باقی‌مانده', 'wc-installment'); ?></span>
                            <span class="wcip-confirm-value" id="wcip-confirm-remaining">—</span>
                        </div>
                        <div class="wcip-confirm-row">
                            <span class="wcip-confirm-label"><?php esc_html_e('مبلغ کارمزد/سود', 'wc-installment'); ?></span>
                            <span class="wcip-confirm-value" id="wcip-confirm-fee">—</span>
                        </div>
                        <div class="wcip-confirm-row">
                            <span class="wcip-confirm-label"><?php esc_html_e('مبلغ هر قسط', 'wc-installment'); ?></span>
                            <span class="wcip-confirm-value" id="wcip-confirm-monthly">—</span>
                        </div>
                        <div class="wcip-confirm-row">
                            <span class="wcip-confirm-label"><?php esc_html_e('تعداد اقساط', 'wc-installment'); ?></span>
                            <span class="wcip-confirm-value" id="wcip-confirm-months">—</span>
                        </div>
                        <div class="wcip-confirm-row wcip-confirm-total">
                            <span class="wcip-confirm-label"><?php esc_html_e('مجموع قابل پرداخت', 'wc-installment'); ?></span>
                            <span class="wcip-confirm-value" id="wcip-confirm-total">—</span>
                        </div>
                        <p class="wcip-confirm-notice" id="wcip-confirm-notice"></p>
                    </div>
                    <div class="wcip-confirm-actions">
                        <button type="button" class="button wcip-modal-close-btn" id="wcip-confirm-cancel"><?php esc_html_e('انصراف', 'wc-installment'); ?></button>
                        <button type="button" class="button wcip-confirm-proceed" id="wcip-confirm-proceed"><?php esc_html_e('تأیید و ادامه', 'wc-installment'); ?></button>
                    </div>
                </div>
            </div>

            <!-- Inline JS data — avoids wp_localize_script timing issues -->
            <script type="text/javascript">
            window.wcipData = <?php echo wp_json_encode(array(
                'productId'        => $product_id,
                'productPrice'     => $price,
                'enabled'          => $installment_enabled,
                'downPaymentType'  => $down_payment_type,
                'downPaymentValue' => $down_payment_value,
                'feeType'          => $fee_type,
                'feeValue'         => $fee_value,
                'plans'            => $js_plans,
                'maxMonths'        => $max_months,
            )); ?>;
            window.wcipAjax = <?php echo wp_json_encode(array(
                'ajaxUrl' => $ajax_url,
                'nonce'   => $nonce_cart,
            )); ?>;
            </script>
            <?php
        }

        /**
         * Adds installment selection data to cart item when added via normal form submit.
         */
        public function add_cart_item_data($cart_item_data, $product_id, $variation_id)
        {
            $payment_method = isset($_POST['wcip_selected_method']) ? sanitize_text_field(wp_unslash($_POST['wcip_selected_method'])) : 'cash';
            $selected_plan  = isset($_POST['wcip_selected_plan'])  ? sanitize_text_field(wp_unslash($_POST['wcip_selected_plan']))  : '';

            if ($payment_method === 'installment' && $selected_plan !== '') {
                $cart_item_data['wcip_payment_method'] = 'installment';
                $cart_item_data['wcip_selected_plan']  = $selected_plan;

                $plans    = wcip_get_product_plans($product_id);
                $plan_idx = intval($selected_plan);
                if (isset($plans[$plan_idx])) {
                    $plan = $plans[$plan_idx];
                    $calc = WCIP_Calculator::instance()->calculate_product_plan(
                        $product_id,
                        $plan['months'],
                        $plan['interest_rate']
                    );
                    if ($calc) {
                        $cart_item_data['wcip_plan_months']          = $plan['months'];
                        $cart_item_data['wcip_plan_interest']        = $plan['interest_rate'];
                        $cart_item_data['wcip_down_payment']         = $calc['down_payment'];
                        $cart_item_data['wcip_monthly_installment']  = $calc['monthly_installment'];
                        $cart_item_data['wcip_total_payable']        = $calc['total_payable'];
                    }
                }
            } else {
                $cart_item_data['wcip_payment_method'] = 'cash';
            }

            if (!isset($cart_item_data['unique_key'])) {
                $cart_item_data['unique_key'] = md5(microtime() . rand());
            }

            return $cart_item_data;
        }

        /**
         * Restores custom installment cart-item data when the cart is loaded
         * from the WooCommerce session. Without this, data saved by
         * add_cart_item_data is lost on the next page load (e.g. checkout),
         * so the installment breakdown never appears.
         */
        public function get_cart_item_from_session($cart_item, $values, $key)
        {
            $session_keys = array(
                'wcip_payment_method',
                'wcip_selected_plan',
                'wcip_plan_months',
                'wcip_plan_interest',
                'wcip_down_payment',
                'wcip_monthly_installment',
                'wcip_total_payable',
            );

            foreach ($session_keys as $sk) {
                if (isset($values[$sk])) {
                    $cart_item[$sk] = $values[$sk];
                }
            }

            return $cart_item;
        }

        /**
         * Displays installment info in cart item data.
         */
        public function get_item_data($item_data, $cart_item)
        {
            if (empty($cart_item['wcip_payment_method']) || $cart_item['wcip_payment_method'] !== 'installment') {
                return $item_data;
            }

            $months  = isset($cart_item['wcip_plan_months'])         ? $cart_item['wcip_plan_months']         : 0;
            $monthly = isset($cart_item['wcip_monthly_installment'])  ? $cart_item['wcip_monthly_installment']  : 0;
            $down    = isset($cart_item['wcip_down_payment'])         ? $cart_item['wcip_down_payment']         : 0;
            $total   = isset($cart_item['wcip_total_payable'])        ? $cart_item['wcip_total_payable']        : 0;

            $item_data[] = array('key' => __('روش پرداخت', 'wc-installment'),       'value' => __('اقساطی', 'wc-installment'));
            $item_data[] = array('key' => __('پیش‌پرداخت', 'wc-installment'),        'value' => wcip_format_toman($down));
            $item_data[] = array('key' => __('مبلغ هر قسط', 'wc-installment'),      'value' => wcip_format_toman($monthly));
            $item_data[] = array('key' => __('تعداد اقساط', 'wc-installment'),       'value' => number_to_persian($months) . ' ' . __('ماه', 'wc-installment'));
            $item_data[] = array('key' => __('مجموع قابل پرداخت', 'wc-installment'), 'value' => wcip_format_toman($total));

            return $item_data;
        }

        /**
         * AJAX: add an installment-mode product to cart and return checkout URL.
         */
        public function ajax_add_installment_to_cart()
        {
            check_ajax_referer('wcip-add-to-cart', 'nonce');

            $product_id   = isset($_POST['product_id'])   ? intval($_POST['product_id'])   : 0;
            $variation_id = isset($_POST['variation_id']) ? intval($_POST['variation_id']) : 0;
            $quantity     = isset($_POST['quantity'])      ? intval($_POST['quantity'])      : 1;
            $plan_idx     = isset($_POST['plan_idx'])      ? intval($_POST['plan_idx'])      : 0;

            if ($product_id < 1) {
                wp_send_json_error(array('message' => __('محصول نامعتبر.', 'wc-installment')));
            }

            $plans = wcip_get_product_plans($product_id);
            if (!isset($plans[$plan_idx])) {
                wp_send_json_error(array('message' => __('طرح اقساطی نامعتبر.', 'wc-installment')));
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

            $cart_item_data = array(
                'wcip_payment_method'      => 'installment',
                'wcip_selected_plan'       => strval($plan_idx),
                'wcip_plan_months'         => $plan['months'],
                'wcip_plan_interest'       => $plan['interest_rate'],
                'wcip_down_payment'        => $calc['down_payment'],
                'wcip_monthly_installment' => $calc['monthly_installment'],
                'wcip_total_payable'       => $calc['total_payable'],
                'unique_key'               => md5(microtime() . rand()),
            );

            if (function_exists('wcip_debug_log')) {
                wcip_debug_log('AJAX add-to-cart: adding installment item', array(
                    'product_id'    => $product_id,
                    'plan_idx'      => $plan_idx,
                    'months'        => $plan['months'],
                    'down_payment'  => $calc['down_payment'],
                    'monthly'       => $calc['monthly_installment'],
                    'total_payable' => $calc['total_payable'],
                ));
            }

            $cart_item_key = WC()->cart->add_to_cart(
                $product_id,
                $quantity,
                $variation_id,
                array(),
                $cart_item_data
            );

            if (!$cart_item_key) {
                if (function_exists('wcip_debug_log')) {
                    wcip_debug_log('AJAX add-to-cart: add_to_cart failed', array('product_id' => $product_id));
                }
                wp_send_json_error(array('message' => __('خطا در افزودن به سبد.', 'wc-installment')));
            }

            WC()->cart->calculate_totals();

            wp_send_json_success(array('redirect_url' => wc_get_checkout_url()));
        }
    }
}

/**
 * Converts a number string to Persian digits.
 */
function number_to_persian($number)
{
    $persian_digits = array('۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹');
    return str_replace(range(0, 9), $persian_digits, (string) $number);
}
