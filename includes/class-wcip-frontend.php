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
        /**
         * @var WCIP_Frontend|null
         */
        private static $instance = null;

        /**
         * Singleton instance.
         *
         * @return WCIP_Frontend
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
            add_action('woocommerce_single_product_summary', array($this, 'display_payment_selector'), 15);
            add_filter('woocommerce_add_cart_item_data', array($this, 'add_cart_item_data'), 10, 3);
            add_filter('woocommerce_get_item_data', array($this, 'get_item_data'), 10, 2);
            add_action('wp_ajax_wcip_add_installment_to_cart', array($this, 'ajax_add_installment_to_cart'));
            add_action('wp_ajax_nopriv_wcip_add_installment_to_cart', array($this, 'ajax_add_installment_to_cart'));
            add_filter('woocommerce_add_to_cart_redirect', array($this, 'installment_checkout_redirect'), 10, 2);
        }

        /**
         * Renders the payment method selector and installment details section.
         */
        public function display_payment_selector()
        {
            global $product;

            if (!$product) {
                return;
            }

            $product_id = $product->get_id();
            $installment_enabled = wcip_is_installment_enabled_for_product($product_id);

            $price = floatval($product->get_price());
            $plans = $installment_enabled ? wcip_get_product_plans($product_id) : array();
            $settings = $installment_enabled ? wcip_get_product_installment_settings($product_id) : array();

            // Prepare localized data for JS.
            $js_plans = array();
            foreach ($plans as $plan) {
                $js_plans[] = array(
                    'months'       => intval($plan['months']),
                    'interestRate' => floatval($plan['interest_rate']),
                );
            }

            $max_months = 0;
            foreach ($js_plans as $p) {
                if ($p['months'] > $max_months) {
                    $max_months = $p['months'];
                }
            }

            $localized = array(
                'productId'       => $product_id,
                'productPrice'    => $price,
                'enabled'         => $installment_enabled,
                'downPaymentType' => isset($settings['down_payment_type']) ? $settings['down_payment_type'] : 'percentage',
                'downPaymentValue'=> isset($settings['down_payment_value']) ? floatval($settings['down_payment_value']) : 0,
                'feeType'         => isset($settings['fee_type']) ? $settings['fee_type'] : 'percentage',
                'feeValue'        => isset($settings['fee_value']) ? floatval($settings['fee_value']) : 0,
                'plans'           => $js_plans,
                'maxMonths'       => $max_months,
                'ajaxUrl'         => admin_url('admin-ajax.php'),
                'nonce'           => wp_create_nonce('wcip-frontend'),
            );

            wp_localize_script('wcip-frontend-script', 'wcipData', $localized);
            wp_localize_script('wcip-frontend-script', 'wcipAjax', array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce'   => wp_create_nonce('wcip-add-to-cart'),
            ));
            ?>
            <div class="wcip-payment-selector-wrapper" data-product-id="<?php echo esc_attr($product_id); ?>">
                <h3 class="wcip-section-title"><?php esc_html_e('روش پرداخت', 'wc-installment'); ?></h3>

                <div class="wcip-payment-methods">
                    <label class="wcip-payment-method-option" data-method="cash">
                        <input type="radio" name="wcip_payment_method" value="cash" checked />
                        <span class="wcip-payment-method-icon">ردیف</span>
                        <span class="wcip-payment-method-label"><?php esc_html_e('پرداخت نقدی', 'wc-installment'); ?></span>
                        <span class="wcip-payment-method-price"><?php echo esc_html(wcip_format_toman($price)); ?></span>
                    </label>

                    <?php if ($installment_enabled) : ?>
                    <label class="wcip-payment-method-option" data-method="installment">
                        <input type="radio" name="wcip_payment_method" value="installment" />
                        <span class="wcip-payment-method-icon">اقساط</span>
                        <span class="wcip-payment-method-label"><?php esc_html_e('پرداخت اقساطی', 'wc-installment'); ?></span>
                        <span class="wcip-payment-method-price"><?php echo esc_html(wcip_format_toman($price)); ?>+</span>
                    </label>
                    <?php endif; ?>
                </div>

                <?php if ($installment_enabled) : ?>
                <!-- Installment details section (hidden until installment is selected) -->
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
                        <?php elseif (count($js_plans) === 1) : ?>
                            <input type="hidden" name="wcip_installment_plan" value="0" />
                            <p class="wcip-single-plan-info">
                                <?php echo esc_html(sprintf(
                                    __('این محصول با %s ماه اقساط قابل خرید است.', 'wc-installment'),
                                    number_to_persian($js_plans[0]['months'])
                                )); ?>
                            </p>
                        <?php endif; ?>
                    </div>

                    <!-- Installment info badges -->
                    <div class="wcip-installment-info-bar">
                        <div class="wcip-info-badge">
                            <span class="wcip-info-badge-label"><?php esc_html_e('حداکثر تعداد اقساط', 'wc-installment'); ?></span>
                            <span class="wcip-info-badge-value" id="wcip-max-months"><?php echo $installment_enabled && $max_months > 0 ? esc_html(number_to_persian($max_months) . ' ' . __('ماه', 'wc-installment')) : '—'; ?></span>
                        </div>
                        <div class="wcip-info-badge">
                            <span class="wcip-info-badge-label"><?php esc_html_e('نوع کارمزد', 'wc-installment'); ?></span>
                            <span class="wcip-info-badge-value" id="wcip-fee-type"><?php echo $installment_enabled ? esc_html(isset($settings['fee_type']) && $settings['fee_type'] === 'percentage' ? __('درصدی', 'wc-installment') : __('مبلغ ثابت', 'wc-installment')) : '—'; ?></span>
                        </div>
                        <div class="wcip-info-badge">
                            <span class="wcip-info-badge-label"><?php esc_html_e('مقدار کارمزد', 'wc-installment'); ?></span>
                            <span class="wcip-info-badge-value" id="wcip-fee-value"><?php echo $installment_enabled ? esc_html(isset($settings['fee_type']) && $settings['fee_type'] === 'percentage' ? number_to_persian(floatval(isset($settings['fee_value']) ? $settings['fee_value'] : 0)) . '٪' : wcip_format_toman(isset($settings['fee_value']) ? $settings['fee_value'] : 0)) : '—'; ?></span>
                        </div>
                    </div>

                    <!-- Dynamic installment breakdown -->
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

                    <!-- Validation error message -->
                    <div class="wcip-validation-error" id="wcip-plan-error" style="display:none;">
                        <?php esc_html_e('لطفاً یک طرح اقساطی انتخاب کنید.', 'wc-installment'); ?>
                    </div>

                    <!-- Hidden field for cart data -->
                    <input type="hidden" name="wcip_selected_plan" id="wcip-selected-plan" value="" />
                    <input type="hidden" name="wcip_selected_method" id="wcip-selected-method" value="cash" />
                </div>
                <?php endif; ?>
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
            <?php
        }

        /**
         * Adds installment selection data to cart item when added to cart.
         *
         * @param array $cart_item_data Cart item data.
         * @param int   $product_id     Product ID.
         * @param int   $variation_id   Variation ID.
         * @return array
         */
        public function add_cart_item_data($cart_item_data, $product_id, $variation_id)
        {
            $payment_method = isset($_POST['wcip_selected_method']) ? sanitize_text_field(wp_unslash($_POST['wcip_selected_method'])) : 'cash';
            $selected_plan = isset($_POST['wcip_selected_plan']) ? sanitize_text_field(wp_unslash($_POST['wcip_selected_plan'])) : '';

            if ($payment_method === 'installment' && $selected_plan !== '') {
                $cart_item_data['wcip_payment_method'] = 'installment';
                $cart_item_data['wcip_selected_plan'] = $selected_plan;

                $plans = wcip_get_product_plans($product_id);
                $plan_idx = intval($selected_plan);
                if (isset($plans[$plan_idx])) {
                    $plan = $plans[$plan_idx];
                    $calc = WCIP_Calculator::instance()->calculate_product_plan(
                        $product_id,
                        $plan['months'],
                        $plan['interest_rate']
                    );
                    if ($calc) {
                        $cart_item_data['wcip_plan_months'] = $plan['months'];
                        $cart_item_data['wcip_plan_interest'] = $plan['interest_rate'];
                        $cart_item_data['wcip_down_payment'] = $calc['down_payment'];
                        $cart_item_data['wcip_monthly_installment'] = $calc['monthly_installment'];
                        $cart_item_data['wcip_total_payable'] = $calc['total_payable'];
                    }
                }
            } else {
                $cart_item_data['wcip_payment_method'] = 'cash';
            }

            // Ensure unique cart item.
            if (!isset($cart_item_data['unique_key'])) {
                $cart_item_data['unique_key'] = md5(microtime() . rand());
            }

            return $cart_item_data;
        }

        /**
         * Displays installment info in cart item data (the meta line below product name).
         *
         * @param array $item_data Existing item data.
         * @param array $cart_item  Cart item array.
         * @return array
         */
        public function get_item_data($item_data, $cart_item)
        {
            if (empty($cart_item['wcip_payment_method']) || $cart_item['wcip_payment_method'] !== 'installment') {
                return $item_data;
            }

            $months = isset($cart_item['wcip_plan_months']) ? $cart_item['wcip_plan_months'] : 0;
            $monthly = isset($cart_item['wcip_monthly_installment']) ? $cart_item['wcip_monthly_installment'] : 0;
            $down = isset($cart_item['wcip_down_payment']) ? $cart_item['wcip_down_payment'] : 0;
            $total = isset($cart_item['wcip_total_payable']) ? $cart_item['wcip_total_payable'] : 0;

            $item_data[] = array(
                'key'   => __('روش پرداخت', 'wc-installment'),
                'value' => __('اقساطی', 'wc-installment'),
            );
            $item_data[] = array(
                'key'   => __('پیش‌پرداخت', 'wc-installment'),
                'value' => wcip_format_toman($down),
            );
            $item_data[] = array(
                'key'   => __('مبلغ هر قسط', 'wc-installment'),
                'value' => wcip_format_toman($monthly),
            );
            $item_data[] = array(
                'key'   => __('تعداد اقساط', 'wc-installment'),
                'value' => number_to_persian($months) . ' ' . __('ماه', 'wc-installment'),
            );
            $item_data[] = array(
                'key'   => __('مجموع قابل پرداخت', 'wc-installment'),
                'value' => wcip_format_toman($total),
            );

            return $item_data;
        }

        /**
         * AJAX handler: adds an installment-mode product to cart and returns
         * the checkout URL (so the customer skips the cart page entirely).
         */
        public function ajax_add_installment_to_cart()
        {
            check_ajax_referer('wcip-add-to-cart', 'nonce');

            $product_id  = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
            $variation_id = isset($_POST['variation_id']) ? intval($_POST['variation_id']) : 0;
            $quantity    = isset($_POST['quantity']) ? intval($_POST['quantity']) : 1;
            $plan_idx    = isset($_POST['plan_idx']) ? intval($_POST['plan_idx']) : 0;

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

            $cart_item_key = WC()->cart->add_to_cart(
                $product_id,
                $quantity,
                $variation_id,
                array(),
                $cart_item_data
            );

            if (!$cart_item_key) {
                wp_send_json_error(array('message' => __('خطا در افزودن به سبد.', 'wc-installment')));
            }

            WC()->cart->calculate_totals();

            wp_send_json_success(array(
                'redirect_url' => wc_get_checkout_url(),
            ));
        }

        /**
         * Redirects installment-mode add-to-cart directly to checkout
         * (so the customer never lands on the cart page for installment items).
         *
         * @param string     $url      Current redirect URL.
         * @param int|string $product_id Product ID being added.
         * @return string
         */
        public function installment_checkout_redirect($url, $product_id)
        {
            if (!WC()->cart) {
                return $url;
            }

            foreach (WC()->cart->get_cart() as $cart_item) {
                if (!empty($cart_item['wcip_payment_method']) && $cart_item['wcip_payment_method'] === 'installment') {
                    return wc_get_checkout_url();
                }
            }

            return $url;
        }
    }
}

/**
 * Converts a number string to Persian digits.
 *
 * @param int|float|string $number Number to convert.
 * @return string
 */
function number_to_persian($number)
{
    $persian_digits = array('۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹');
    return str_replace(range(0, 9), $persian_digits, (string) $number);
}
