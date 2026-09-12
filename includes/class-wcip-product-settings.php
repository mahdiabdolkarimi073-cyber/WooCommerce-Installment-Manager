<?php
/**
 * Per-product installment settings — adds a tab inside WooCommerce Product Data.
 * Includes the plans builder for defining multiple installment durations with
 * per-plan interest rates.
 *
 * @package WC_Installment_Payment
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('WCIP_Product_Settings')) {

    class WCIP_Product_Settings
    {
        /**
         * @var WCIP_Product_Settings|null
         */
        private static $instance = null;

        /**
         * Singleton instance.
         *
         * @return WCIP_Product_Settings
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
            add_filter('woocommerce_product_data_tabs', array($this, 'add_product_data_tab'));
            add_action('woocommerce_product_data_panels', array($this, 'render_product_data_panel'));
            add_action('woocommerce_process_product_meta', array($this, 'save_product_meta'));
        }

        /**
         * Adds the "پرداخت اقساطی" tab to the product data tabs.
         *
         * @param array $tabs Existing product data tabs.
         * @return array
         */
        public function add_product_data_tab($tabs)
        {
            $tabs['installment'] = array(
                'label'    => __('پرداخت اقساطی', 'wc-installment'),
                'target'   => 'wcip_product_installment_data',
                'class'    => array('show_if_simple', 'show_if_variable'),
                'priority' => 80,
            );
            return $tabs;
        }

        /**
         * Renders the product data panel content for installment settings.
         */
        public function render_product_data_panel()
        {
            global $post;

            $enabled           = get_post_meta($post->ID, '_wcip_enabled', true);
            $use_global        = get_post_meta($post->ID, '_wcip_use_global', true);
            $down_payment_type = get_post_meta($post->ID, '_wcip_down_payment_type', true) ?: 'percentage';
            $down_payment_value= get_post_meta($post->ID, '_wcip_down_payment_value', true) ?: '';
            $fee_type          = get_post_meta($post->ID, '_wcip_fee_type', true) ?: 'percentage';
            $fee_value         = get_post_meta($post->ID, '_wcip_fee_value', true) ?: '';
            $plans             = get_post_meta($post->ID, '_wcip_plans', true);
            if (!is_array($plans)) {
                $plans = array();
            }
            ?>
            <div id="wcip_product_installment_data" class="panel woocommerce_options_panel">
                <div class="options_group">
                    <p class="form-field">
                        <label for="_wcip_enabled">
                            <input type="checkbox" name="_wcip_enabled" id="_wcip_enabled" value="yes" <?php checked($enabled, 'yes'); ?> />
                            <?php esc_html_e('فعال‌سازی فروش اقساطی برای این محصول', 'wc-installment'); ?>
                        </label>
                    </p>
                    <p class="form-field">
                        <label for="_wcip_use_global">
                            <input type="checkbox" name="_wcip_use_global" id="_wcip_use_global" value="yes" <?php checked($use_global, 'yes'); ?> />
                            <?php esc_html_e('استفاده از تنظیمات سراسری', 'wc-installment'); ?>
                        </label>
                    </p>
                </div>

                <div class="options_group wcip-custom-fields" <?php echo ($use_global === 'yes') ? 'style="display:none;"' : ''; ?>>
                    <p class="form-field">
                        <label for="_wcip_down_payment_type"><?php esc_html_e('نوع پیش‌پرداخت', 'wc-installment'); ?></label>
                        <select name="_wcip_down_payment_type" id="_wcip_down_payment_type" class="select short">
                            <option value="percentage" <?php selected($down_payment_type, 'percentage'); ?>><?php esc_html_e('درصدی', 'wc-installment'); ?></option>
                            <option value="fixed" <?php selected($down_payment_type, 'fixed'); ?>><?php esc_html_e('مبلغ ثابت', 'wc-installment'); ?></option>
                        </select>
                    </p>

                    <p class="form-field">
                        <label for="_wcip_down_payment_value"><?php esc_html_e('مقدار پیش‌پرداخت', 'wc-installment'); ?></label>
                        <input type="text" name="_wcip_down_payment_value" id="_wcip_down_payment_value" value="<?php echo esc_attr($down_payment_value); ?>" class="short" />
                    </p>

                    <p class="form-field">
                        <label for="_wcip_fee_type"><?php esc_html_e('نوع کارمزد', 'wc-installment'); ?></label>
                        <select name="_wcip_fee_type" id="_wcip_fee_type" class="select short">
                            <option value="percentage" <?php selected($fee_type, 'percentage'); ?>><?php esc_html_e('درصدی', 'wc-installment'); ?></option>
                            <option value="fixed" <?php selected($fee_type, 'fixed'); ?>><?php esc_html_e('مبلغ ثابت', 'wc-installment'); ?></option>
                        </select>
                    </p>

                    <p class="form-field">
                        <label for="_wcip_fee_value"><?php esc_html_e('مقدار کارمزد', 'wc-installment'); ?></label>
                        <input type="text" name="_wcip_fee_value" id="_wcip_fee_value" value="<?php echo esc_attr($fee_value); ?>" class="short" />
                    </p>

                    <!-- Plans builder -->
                    <div class="wcip-plans-builder">
                        <h4><?php esc_html_e('طرح‌های اقساطی', 'wc-installment'); ?></h4>
                        <p class="wcip-plans-desc"><?php esc_html_e('طرح‌های اقساطی قابل ارائه به مشتری را تعریف کنید. هر طرح شامل تعداد ماه و درصد سود/markup می‌باشد.', 'wc-installment'); ?></p>

                        <table class="wcip-plans-table widefat">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e('تعداد ماه', 'wc-installment'); ?></th>
                                    <th><?php esc_html_e('درصد سود/کارمزد', 'wc-installment'); ?></th>
                                    <th><?php esc_html_e('عملیات', 'wc-installment'); ?></th>
                                </tr>
                            </thead>
                            <tbody id="wcip-plans-body">
                                <?php if (count($plans) > 0) : ?>
                                    <?php foreach ($plans as $i => $plan) : ?>
                                        <tr class="wcip-plan-row">
                                            <td><input type="number" name="_wcip_plans[<?php echo esc_attr($i); ?>][months]" value="<?php echo esc_attr($plan['months']); ?>" min="1" step="1" class="wcip-plan-months" /></td>
                                            <td><input type="number" name="_wcip_plans[<?php echo esc_attr($i); ?>][interest_rate]" value="<?php echo esc_attr($plan['interest_rate']); ?>" min="0" step="any" class="wcip-plan-interest" /></td>
                                            <td><button type="button" class="button wcip-remove-plan"><?php esc_html_e('حذف', 'wc-installment'); ?></button></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else : ?>
                                    <tr class="wcip-plan-row">
                                        <td><input type="number" name="_wcip_plans[0][months]" value="3" min="1" step="1" class="wcip-plan-months" /></td>
                                        <td><input type="number" name="_wcip_plans[0][interest_rate]" value="0" min="0" step="any" class="wcip-plan-interest" /></td>
                                        <td><button type="button" class="button wcip-remove-plan"><?php esc_html_e('حذف', 'wc-installment'); ?></button></td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>

                        <p>
                            <button type="button" class="button button-secondary" id="wcip-add-plan"><?php esc_html_e('افزودن طرح جدید', 'wc-installment'); ?></button>
                        </p>
                    </div>
                </div>
            </div>
            <?php
        }

        /**
         * Saves per-product installment meta data including plans.
         *
         * @param int $post_id Product (post) ID.
         */
        public function save_product_meta($post_id)
        {
            $enabled = isset($_POST['_wcip_enabled']) ? 'yes' : 'no';
            update_post_meta($post_id, '_wcip_enabled', $enabled);

            $use_global = isset($_POST['_wcip_use_global']) ? 'yes' : 'no';
            update_post_meta($post_id, '_wcip_use_global', $use_global);

            $down_payment_type = isset($_POST['_wcip_down_payment_type']) ? sanitize_text_field(wp_unslash($_POST['_wcip_down_payment_type'])) : 'percentage';
            if (!in_array($down_payment_type, array('percentage', 'fixed'), true)) {
                $down_payment_type = 'percentage';
            }
            update_post_meta($post_id, '_wcip_down_payment_type', $down_payment_type);

            $down_payment_value = isset($_POST['_wcip_down_payment_value']) ? sanitize_text_field(wp_unslash($_POST['_wcip_down_payment_value'])) : '0';
            $down_payment_value = preg_replace('/[^0-9.]/', '', $down_payment_value);
            update_post_meta($post_id, '_wcip_down_payment_value', $down_payment_value);

            $fee_type = isset($_POST['_wcip_fee_type']) ? sanitize_text_field(wp_unslash($_POST['_wcip_fee_type'])) : 'percentage';
            if (!in_array($fee_type, array('percentage', 'fixed'), true)) {
                $fee_type = 'percentage';
            }
            update_post_meta($post_id, '_wcip_fee_type', $fee_type);

            $fee_value = isset($_POST['_wcip_fee_value']) ? sanitize_text_field(wp_unslash($_POST['_wcip_fee_value'])) : '0';
            $fee_value = preg_replace('/[^0-9.]/', '', $fee_value);
            update_post_meta($post_id, '_wcip_fee_value', $fee_value);

            // Save plans
            $plans = array();
            if (isset($_POST['_wcip_plans']) && is_array($_POST['_wcip_plans'])) {
                foreach ($_POST['_wcip_plans'] as $plan) {
                    $months = isset($plan['months']) ? absint($plan['months']) : 0;
                    $interest = isset($plan['interest_rate']) ? floatval(preg_replace('/[^0-9.]/', '', $plan['interest_rate'])) : 0;
                    if ($months > 0) {
                        $plans[] = array(
                            'months' => $months,
                            'interest_rate' => $interest,
                        );
                    }
                }
            }
            update_post_meta($post_id, '_wcip_plans', $plans);

            // Keep backward-compatible single count from first plan.
            $fallback_count = count($plans) > 0 ? $plans[0]['months'] : 3;
            update_post_meta($post_id, '_wcip_installment_count', $fallback_count);
        }
    }
}
