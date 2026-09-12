<?php
/**
 * Calculation engine for installment payments.
 *
 * @package WC_Installment_Payment
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('WCIP_Calculator')) {

    class WCIP_Calculator
    {
        /**
         * @var WCIP_Calculator|null
         */
        private static $instance = null;

        /**
         * Singleton instance.
         *
         * @return WCIP_Calculator
         */
        public static function instance()
        {
            if (null === self::$instance) {
                self::$instance = new self();
            }
            return self::$instance;
        }

        /**
         * Calculates the full installment breakdown for a given product
         * using the base settings (no per-plan interest).
         *
         * @param float $product_price  Product price in Toman.
         * @param array $settings       Settings array from wcip_get_product_installment_settings().
         * @return array|false Breakdown array or false if invalid.
         */
        public function calculate($product_price, $settings)
        {
            if (empty($settings['enabled']) || $product_price <= 0) {
                return false;
            }

            $down_payment_type = $settings['down_payment_type'];
            $down_payment_value= floatval($settings['down_payment_value']);
            $installment_count = intval($settings['installment_count']);
            $fee_type          = $settings['fee_type'];
            $fee_value         = floatval($settings['fee_value']);

            if ($installment_count < 1) {
                $installment_count = 1;
            }

            if ($down_payment_type === 'percentage') {
                $down_payment = $product_price * ($down_payment_value / 100);
            } else {
                $down_payment = $down_payment_value;
            }

            if ($down_payment > $product_price) {
                $down_payment = $product_price;
            }
            if ($down_payment < 0) {
                $down_payment = 0;
            }

            $remaining = $product_price - $down_payment;

            if ($fee_type === 'percentage') {
                $fee = $remaining * ($fee_value / 100);
            } else {
                $fee = $fee_value;
            }
            if ($fee < 0) {
                $fee = 0;
            }

            $total_installment_amount = $remaining + $fee;
            $per_installment = $total_installment_amount / $installment_count;
            $total_payable = $down_payment + $total_installment_amount;

            return array(
                'product_price'           => $product_price,
                'down_payment'            => $down_payment,
                'remaining'               => $remaining,
                'fee'                     => $fee,
                'total_installment_amount'=> $total_installment_amount,
                'per_installment'         => $per_installment,
                'installment_count'       => $installment_count,
                'installment_method'      => $settings['installment_method'],
                'total_payable'           => $total_payable,
            );
        }

        /**
         * Calculates installment breakdown for a specific plan (with per-plan interest).
         *
         * @param float $product_price     Product price in Toman.
         * @param array $settings           Base settings (down payment type/value, fee type/value).
         * @param int   $months             Number of months for this plan.
         * @param float $interest_rate      Per-plan interest rate (percentage). Can be 0.
         * @return array|false
         */
        public function calculate_for_plan($product_price, $settings, $months, $interest_rate = 0)
        {
            if (empty($settings['enabled']) || $product_price <= 0) {
                return false;
            }

            $months = max(1, intval($months));
            $interest_rate = max(0, floatval($interest_rate));

            $down_payment_type = $settings['down_payment_type'];
            $down_payment_value = floatval($settings['down_payment_value']);

            // Down payment
            if ($down_payment_type === 'percentage') {
                $down_payment = $product_price * ($down_payment_value / 100);
            } else {
                $down_payment = $down_payment_value;
            }
            if ($down_payment > $product_price) {
                $down_payment = $product_price;
            }
            if ($down_payment < 0) {
                $down_payment = 0;
            }

            // Remaining after down payment
            $remaining_after_dp = $product_price - $down_payment;

            // Per-plan interest/markup (added to remaining)
            $interest_amount = $remaining_after_dp * ($interest_rate / 100);

            // Total amount to be paid in installments (remaining + interest)
            $total_installment_amount = $remaining_after_dp + $interest_amount;

            // Monthly installment (simple division)
            $monthly_installment = $total_installment_amount / $months;

            // Total payable
            $total_payable = $down_payment + $total_installment_amount;

            return array(
                'product_price'      => $product_price,
                'down_payment'       => $down_payment,
                'remaining'          => $remaining_after_dp,
                'interest_rate'      => $interest_rate,
                'interest_amount'    => $interest_amount,
                'months'             => $months,
                'monthly_installment'=> $monthly_installment,
                'total_installment_amount' => $total_installment_amount,
                'total_payable'      => $total_payable,
                'installment_method' => 'months',
            );
        }

        /**
         * Convenience method: get the breakdown for a product ID (base settings, no plan).
         *
         * @param int $product_id Product ID.
         * @return array|false
         */
        public function calculate_for_product($product_id)
        {
            if (!wcip_is_installment_enabled_for_product($product_id)) {
                return false;
            }

            $product = wc_get_product($product_id);
            if (!$product) {
                return false;
            }

            $price = floatval($product->get_price());
            if ($price <= 0) {
                return false;
            }

            $settings = wcip_get_product_installment_settings($product_id);
            return $this->calculate($price, $settings);
        }

        /**
         * Convenience method: get breakdown for a product + specific plan.
         *
         * @param int   $product_id    Product ID.
         * @param int   $months        Number of months.
         * @param float $interest_rate Interest rate percentage.
         * @return array|false
         */
        public function calculate_product_plan($product_id, $months, $interest_rate = 0)
        {
            if (!wcip_is_installment_enabled_for_product($product_id)) {
                return false;
            }

            $product = wc_get_product($product_id);
            if (!$product) {
                return false;
            }

            $price = floatval($product->get_price());
            if ($price <= 0) {
                return false;
            }

            $settings = wcip_get_product_installment_settings($product_id);
            return $this->calculate_for_plan($price, $settings, $months, $interest_rate);
        }
    }
}
