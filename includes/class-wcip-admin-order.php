<?php
/**
 * Admin order view — meta box showing installment details on the order detail page.
 *
 * @package WC_Installment_Payment
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('WCIP_Admin_Order')) {

    class WCIP_Admin_Order
    {
        /**
         * @var WCIP_Admin_Order|null
         */
        private static $instance = null;

        /**
         * Singleton instance.
         *
         * @return WCIP_Admin_Order
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
            add_action('add_meta_boxes', array($this, 'add_order_meta_box'));
        }

        /**
         * Registers the "جزئیات اقساط" meta box on the order admin page.
         */
        public function add_order_meta_box()
        {
            $screen = class_exists('\Automattic\WooCommerce\Admin\OrderUtil') && method_exists('\Automattic\WooCommerce\Admin\OrderUtil', 'get_order_admin_screen')
                ? \Automattic\WooCommerce\Admin\OrderUtil::get_order_admin_screen()
                : 'shop_order';

            add_meta_box(
                'wcip_installment_details',
                __('جزئیات اقساط', 'wc-installment'),
                array($this, 'render_meta_box'),
                $screen,
                'side',
                'default'
            );
        }

        /**
         * Renders the meta box content for installment details.
         *
         * @param WP_Post|WC_Order $post_or_order Post object (classic) or order object (HPOS).
         */
        public function render_meta_box($post_or_order)
        {
            // Support both classic (WP_Post) and HPOS (WC_Order) contexts.
            if (is_a($post_or_order, 'WP_Post')) {
                $order = wc_get_order($post_or_order->ID);
            } else {
                $order = $post_or_order;
            }

            if (!$order) {
                echo '<p>' . esc_html__('خطا در بارگذاری سفارش.', 'wc-installment') . '</p>';
                return;
            }

            $enabled = $order->get_meta('_installment_enabled');

            if ($enabled !== 'true') {
                echo '<p>' . esc_html__('این سفارش پرداخت اقساطی ندارد.', 'wc-installment') . '</p>';
                return;
            }

            $down_payment    = $order->get_meta('_installment_down_payment');
            $per_installment = $order->get_meta('_installment_per_month');
            $total_installs  = $order->get_meta('_installment_total_installments');
            $fee             = $order->get_meta('_installment_fee');
            $method          = $order->get_meta('_installment_method');

            $method_label = ($method === 'months')
                ? __('تعداد ماه', 'wc-installment')
                : __('تعداد اقساط', 'wc-installment');

            $fee_label = ($fee > 0) ? wcip_format_toman($fee) : __('بدون کارمزد', 'wc-installment');
            ?>
            <table class="widefat wcip-admin-order-table" style="border:none;">
                <tbody>
                    <tr>
                        <td style="font-weight:bold; width:50%;"><?php esc_html_e('مبلغ پیش‌پرداخت', 'wc-installment'); ?></td>
                        <td><?php echo esc_html(wcip_format_toman($down_payment)); ?></td>
                    </tr>
                    <tr>
                        <td style="font-weight:bold;"><?php esc_html_e('مبلغ هر قسط', 'wc-installment'); ?></td>
                        <td><?php echo esc_html(wcip_format_toman($per_installment)); ?></td>
                    </tr>
                    <tr>
                        <td style="font-weight:bold;"><?php echo esc_html($method_label); ?></td>
                        <td><?php echo esc_html(number_to_persian($total_installs)); ?></td>
                    </tr>
                    <tr>
                        <td style="font-weight:bold;"><?php esc_html_e('کارمزد', 'wc-installment'); ?></td>
                        <td><?php echo esc_html($fee_label); ?></td>
                    </tr>
                </tbody>
            </table>
            <?php
        }
    }
}
