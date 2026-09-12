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

            $localized = array(
                'productId'       => $product_id,
                'productPrice'    => $price,
                'enabled'         => $installment_enabled,
                'downPaymentType' => isset($settings['down_payment_type']) ? $settings['down_payment_type'] : 'percentage',
                'downPaymentValue'=> isset($settings['down_payment_value']) ? floatval($settings['down_payment_value']) : 0,
                'feeType'         => isset($settings['fee_type']) ? $settings['fee_type'] : 'percentage',
                'feeValue'        => isset($settings['fee_value']) ? floatval($settings['fee_value']) : 0,
                'plans'           => $js_plans,
                'ajaxUrl'         => admin_url('admin-ajax.php'),
                'nonce'           => wp_create_nonce('wcip-frontend'),
            );

            wp_localize_script('wcip-frontend-script', 'wcipData', $localized);
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

                    <!-- Dynamic installment breakdown -->
                    <div class="wcip-installment-breakdown">
                        <div class="wcip-breakdown-row">
                            <span class="wcip-breakdown-label"><?php esc_html_e('مبلغ پیش‌پرداخت', 'wc-installment'); ?></span>
                            <span class="wcip-breakdown-value" id="wcip-down-payment">—</span>
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
