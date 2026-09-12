<?php
/**
 * REST API endpoints for installment settings.
 *
 *   GET  /wp-json/wc-installment/v1/installment-settings/:productId
 *   POST /wp-json/wc-installment/v1/admin/installment-settings
 *
 * @package WC_Installment_Payment
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('WCIP_REST_API')) {

    class WCIP_REST_API
    {
        /**
         * @var WCIP_REST_API|null
         */
        private static $instance = null;

        /**
         * @var string REST namespace.
         */
        const NAMESPACE = 'wc-installment/v1';

        /**
         * Singleton instance.
         *
         * @return WCIP_REST_API
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
            add_action('rest_api_init', array($this, 'register_routes'));
        }

        /**
         * Registers REST API routes.
         */
        public function register_routes()
        {
            register_rest_route(self::NAMESPACE, '/installment-settings/(?P<productId>\d+)', array(
                array(
                    'methods'             => 'GET',
                    'callback'            => array($this, 'get_installment_settings'),
                    'permission_callback' => array($this, 'public_permission'),
                    'args'                => array(
                        'productId' => array(
                            'validate_callback' => function ($param) {
                                return is_numeric($param);
                            },
                            'sanitize_callback' => 'absint',
                        ),
                    ),
                ),
            ));

            register_rest_route(self::NAMESPACE, '/admin/installment-settings', array(
                array(
                    'methods'             => 'POST',
                    'callback'            => array($this, 'save_installment_settings'),
                    'permission_callback' => array($this, 'admin_permission'),
                ),
            ));
        }

        /**
         * Public permission — anyone can read settings for display purposes.
         *
         * @return bool
         */
        public function public_permission()
        {
            return true;
        }

        /**
         * Admin permission — only logged-in users who can manage WooCommerce.
         *
         * @param WP_REST_Request $request Request object.
         * @return bool|WP_Error
         */
        public function admin_permission($request)
        {
            if (!current_user_can('manage_woocommerce') && !current_user_can('administrator')) {
                return new WP_Error(
                    'wcip_rest_forbidden',
                    __('شما اجازه انجام این عملیات را ندارید.', 'wc-installment'),
                    array('status' => 403)
                );
            }
            return true;
        }

        /**
         * GET handler — returns installment settings and plans for a product.
         *
         * @param WP_REST_Request $request Request object.
         * @return WP_REST_Response
         */
        public function get_installment_settings($request)
        {
            $product_id = absint($request['productId']);

            if ($product_id <= 0) {
                return new WP_REST_Response(array(
                    'success' => false,
                    'message' => __('شناسه محصول نامعتبر است.', 'wc-installment'),
                ), 400);
            }

            $settings = wcip_get_product_installment_settings($product_id);
            $plans    = wcip_get_product_plans($product_id);

            $product = wc_get_product($product_id);
            $price = $product ? floatval($product->get_price()) : 0;

            $response = array(
                'success'        => true,
                'productId'      => $product_id,
                'productPrice'   => $price,
                'isInstallmentEnabled' => !empty($settings['enabled']),
                'downPaymentType'=> isset($settings['down_payment_type']) ? $settings['down_payment_type'] : 'percentage',
                'downPaymentValue' => isset($settings['down_payment_value']) ? floatval($settings['down_payment_value']) : 0,
                'feeType'        => isset($settings['fee_type']) ? $settings['fee_type'] : 'percentage',
                'feeValue'       => isset($settings['fee_value']) ? floatval($settings['fee_value']) : 0,
                'plans'          => array(),
            );

            foreach ($plans as $plan) {
                $response['plans'][] = array(
                    'months'       => intval($plan['months']),
                    'interestRate' => floatval($plan['interest_rate']),
                );
            }

            return new WP_REST_Response($response, 200);
        }

        /**
         * POST handler — saves installment settings for a product or global.
         *
         * @param WP_REST_Request $request Request object.
         * @return WP_REST_Response
         */
        public function save_installment_settings($request)
        {
            $product_id = $request->get_param('productId');
            $is_global = ($product_id === 'global' || $product_id === 0);

            $enabled           = $request->get_param('isInstallmentEnabled');
            $down_payment_type = $request->get_param('downPaymentType');
            $down_payment_value= $request->get_param('downPaymentValue');
            $fee_type          = $request->get_param('feeType');
            $fee_value         = $request->get_param('feeValue');
            $plans_param       = $request->get_param('plans');

            // Validate enum fields.
            if (!in_array($down_payment_type, array('percentage', 'fixed'), true)) {
                $down_payment_type = 'percentage';
            }
            if (!in_array($fee_type, array('percentage', 'fixed'), true)) {
                $fee_type = 'percentage';
            }

            // Sanitize numeric fields.
            $down_payment_value = floatval(preg_replace('/[^0-9.]/', '', (string) $down_payment_value));
            $fee_value = floatval(preg_replace('/[^0-9.]/', '', (string) $fee_value));

            // Sanitize plans.
            $plans = array();
            if (is_array($plans_param)) {
                foreach ($plans_param as $plan) {
                    $months = isset($plan['months']) ? absint($plan['months']) : 0;
                    $interest = isset($plan['interestRate']) ? floatval(preg_replace('/[^0-9.]/', '', (string) $plan['interestRate'])) : 0;
                    if ($months > 0) {
                        $plans[] = array(
                            'months' => $months,
                            'interest_rate' => $interest,
                        );
                    }
                }
            }

            if ($is_global) {
                $settings = get_option('wcip_global_settings', array());
                if (!is_array($settings)) {
                    $settings = array();
                }
                $settings['enabled'] = $enabled ? 'yes' : 'no';
                $settings['down_payment_type'] = $down_payment_type;
                $settings['down_payment_value'] = $down_payment_value;
                $settings['fee_type'] = $fee_type;
                $settings['fee_value'] = $fee_value;
                update_option('wcip_global_settings', $settings);
                update_option('wcip_global_plans', $plans);
            } else {
                $product_id = absint($product_id);
                if ($product_id <= 0) {
                    return new WP_REST_Response(array(
                        'success' => false,
                        'message' => __('شناسه محصول نامعتبر است.', 'wc-installment'),
                    ), 400);
                }

                update_post_meta($product_id, '_wcip_enabled', $enabled ? 'yes' : 'no');
                update_post_meta($product_id, '_wcip_use_global', 'no');
                update_post_meta($product_id, '_wcip_down_payment_type', $down_payment_type);
                update_post_meta($product_id, '_wcip_down_payment_value', $down_payment_value);
                update_post_meta($product_id, '_wcip_fee_type', $fee_type);
                update_post_meta($product_id, '_wcip_fee_value', $fee_value);
                update_post_meta($product_id, '_wcip_plans', $plans);

                if (count($plans) > 0) {
                    update_post_meta($product_id, '_wcip_installment_count', $plans[0]['months']);
                }
            }

            return new WP_REST_Response(array(
                'success' => true,
                'message' => __('تنظیمات اقساط با موفقیت ذخیره شد.', 'wc-installment'),
                'plans'   => $plans,
            ), 200);
        }
    }
}
