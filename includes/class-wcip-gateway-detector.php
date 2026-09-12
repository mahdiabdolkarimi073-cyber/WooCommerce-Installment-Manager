<?php
/**
 * Payment Gateway Detector — auto-detects all active WooCommerce
 * payment gateways and provides statistics and per-order gateway info.
 *
 * @package WC_Installment_Payment
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCIP_Gateway_Detector
{
    private static $instance = null;

    const TRANSIENT_KEY = 'wcip_detected_gateways';

    public static function instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        add_action('admin_init', array($this, 'refresh_detected'));
        add_action('woocommerce_update_options_payment_gateways', array($this, 'refresh_detected'));
    }

    /**
     * Get all available (active) payment gateways from WooCommerce.
     */
    public function get_available_gateways()
    {
        if (!function_exists('WC') || !WC()->payment_gateways) {
            return array();
        }
        return WC()->payment_gateways()->get_available_payment_gateways();
    }

    /**
     * Get ALL registered gateways (enabled + disabled).
     */
    public function get_all_gateways()
    {
        if (!function_exists('WC') || !WC()->payment_gateways) {
            return array();
        }
        return WC()->payment_gateways()->payment_gateways();
    }

    /**
     * Build a structured list of all detected gateways.
     */
    public function detect()
    {
        $available = $this->get_available_gateways();
        $all       = $this->get_all_gateways();
        $result    = array();

        foreach ($all as $gateway) {
            $is_available = isset($available[$gateway->id]);
            $result[$gateway->id] = array(
                'id'          => $gateway->id,
                'title'       => $gateway->get_title(),
                'description' => $gateway->get_description(),
                'enabled'     => 'yes' === $gateway->enabled,
                'available'   => $is_available,
            );
        }

        return $result;
    }

    /**
     * Store detected gateways in a transient.
     */
    public function refresh_detected()
    {
        $gateways = $this->detect();
        set_transient(self::TRANSIENT_KEY, $gateways, HOUR_IN_SECONDS);
        return $gateways;
    }

    /**
     * Retrieve detected gateways from transient or re-detect.
     */
    public function get_detected()
    {
        $cached = get_transient(self::TRANSIENT_KEY);
        if (false !== $cached && is_array($cached) && !empty($cached)) {
            return $cached;
        }
        return $this->refresh_detected();
    }

    /**
     * Get a single gateway by ID.
     */
    public function get_gateway($gateway_id)
    {
        $gateways = $this->get_detected();
        return isset($gateways[$gateway_id]) ? $gateways[$gateway_id] : null;
    }

    /**
     * Get the gateway used for a specific order.
     */
    public function get_order_gateway($order_id)
    {
        $order = wc_get_order($order_id);
        if (!$order) {
            return null;
        }
        return array(
            'method' => $order->get_payment_method(),
            'title'  => $order->get_payment_method_title(),
        );
    }

    /**
     * Get transaction statistics per gateway.
     *
     * @param string $gateway_id Optional specific gateway. Empty = all.
     * @return array
     */
    public function get_gateway_stats($gateway_id = '')
    {
        $all_gateways = $this->get_detected();
        $gateway_ids  = empty($gateway_id) ? array_keys($all_gateways) : array($gateway_id);
        $stats = array();

        foreach ($gateway_ids as $gid) {
            $all_orders = wc_get_orders(array(
                'payment_method' => $gid,
                'limit'          => -1,
                'return'         => 'ids',
            ));

            $total     = count($all_orders);
            $success   = 0;
            $failed    = 0;
            $refunded  = 0;
            $cancelled = 0;

            foreach ($all_orders as $order_id) {
                $order = wc_get_order($order_id);
                if (!$order) continue;
                $status = $order->get_status();
                if (in_array($status, array('completed', 'processing'), true)) {
                    $success++;
                } elseif ('failed' === $status) {
                    $failed++;
                } elseif ('refunded' === $status) {
                    $refunded++;
                } elseif ('cancelled' === $status) {
                    $cancelled++;
                }
            }

            $stats[$gid] = array(
                'total'     => $total,
                'success'   => $success,
                'failed'    => $failed,
                'refunded'  => $refunded,
                'cancelled' => $cancelled,
            );
        }

        return $gateway_id ? ($stats[$gateway_id] ?? array()) : $stats;
    }

    /**
     * Get a simple list of active gateway ID => title pairs.
     */
    public function get_active_gateways_list()
    {
        $detected = $this->get_detected();
        $list = array();
        foreach ($detected as $id => $gw) {
            if ($gw['enabled'] && $gw['available']) {
                $list[$id] = $gw['title'];
            }
        }
        return $list;
    }

    /**
     * Get the default (first) active gateway ID.
     */
    public function get_default_gateway_id()
    {
        $list = $this->get_active_gateways_list();
        return empty($list) ? null : array_key_first($list);
    }
}
