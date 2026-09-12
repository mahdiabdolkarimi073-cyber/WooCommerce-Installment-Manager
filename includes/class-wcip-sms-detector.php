<?php
/**
 * SMS Detector — auto-detects all active SMS plugins/panels on the site
 * and verifies whether each one actually has a usable send integration
 * (hook, filter, or callable function) that the installment plugin can
 * use to send SMS messages.
 *
 * No API keys, usernames, passwords, or credentials of any kind are
 * stored or requested. Sending is delegated entirely to the existing
 * SMS plugin's own integration.
 *
 * @package WC_Installment_Payment
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCIP_SMS_Detector
{
    private static $instance = null;

    const TRANSIENT_KEY = 'wcip_detected_sms_panels';

    /**
     * Known SMS plugin signatures.
     *
     * Each entry lists:
     *  - name:       Human-readable panel name
     *  - file:       Expected plugin main file path
     *  - classes:    Classes/functions whose presence indicates the plugin is loaded
     *  - send_hooks: Action/filter hooks the plugin exposes for sending SMS
     *  - send_funcs: Global functions the plugin exposes for sending SMS
     *
     * Only hooks/functions that are actually registered at runtime count
     * as a usable integration.
     */
    private $known_panels = array(
        'melipayamak' => array(
            'name'       => 'ملی پیامک',
            'file'       => 'melipayamak/melipayamak.php',
            'classes'    => array('melipayamak', 'Melipayamak', 'MelliPayamak'),
            'send_hooks' => array('melipayamak_send_sms', 'mellipayamak_send'),
            'send_funcs' => array('melipayamak_send_sms', 'Melipayamak_SendSms', 'mellipayamak_send'),
        ),
        'kavenegar' => array(
            'name'       => 'کاوه نگار',
            'file'       => 'kavenegar/kavenegar.php',
            'classes'    => array('Kavenegar', 'KavenegarApi'),
            'send_hooks' => array('kavenegar_send_sms', 'kavenegar_send'),
            'send_funcs' => array('kavenegar_send', 'kavenegar_sms_send'),
        ),
        'smsir' => array(
            'name'       => 'SMS.ir',
            'file'       => 'smsir/smsir.php',
            'classes'    => array('SMSIR', 'Smsir', 'SmsIr'),
            'send_hooks' => array('smsir_send_sms', 'smsir_send'),
            'send_funcs' => array('smsir_send_sms', 'smsir_send'),
        ),
        'farazsms' => array(
            'name'       => 'فراز اس‌ام‌اس',
            'file'       => 'farazsms/farazsms.php',
            'classes'    => array('FarazSMS', 'Farazsms', 'farazsms'),
            'send_hooks' => array('farazsms_send_sms', 'farazsms_send'),
            'send_funcs' => array('farazsms_send_sms', 'farazsms_send'),
        ),
        'woo_sms' => array(
            'name'       => 'WooCommerce SMS',
            'file'       => 'woo-sms/woo-sms.php',
            'classes'    => array('WooCommerceSMS', 'WooCommerce_SMS'),
            'send_hooks' => array('woocommerce_sms_send', 'woo_sms_send'),
            'send_funcs' => array('woocommerce_sms_send', 'woo_sms_send'),
        ),
        'persian_woo_sms' => array(
            'name'       => 'پارسیان اس‌ام‌اس ووکامرس',
            'file'       => 'persian-woo-sms/persian-woo-sms.php',
            'classes'    => array('PWooSMS', 'PersianWooSMS'),
            'send_hooks' => array('pwoosms_send', 'persian_woo_sms_send'),
            'send_funcs' => array('pwoosms_send', 'persian_woo_sms_send'),
        ),
        'asresms' => array(
            'name'       => 'آسه پیامک',
            'file'       => 'asresms/asresms.php',
            'classes'    => array('AsreSms', 'asresms'),
            'send_hooks' => array('asresms_send_sms', 'asresms_send'),
            'send_funcs' => array('asresms_send_sms', 'asresms_send'),
        ),
        'netspeed' => array(
            'name'       => 'نت اس‌پی ار',
            'file'       => 'netspeed/netspeed.php',
            'classes'    => array('NetSpeed', 'netspeed'),
            'send_hooks' => array('netspeed_send_sms', 'netspeed_send'),
            'send_funcs' => array('netspeed_send_sms', 'netspeed_send'),
        ),
    );

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
        add_action('activated_plugin', array($this, 'refresh_detected'));
        add_action('deactivated_plugin', array($this, 'refresh_detected'));
    }

    private function is_plugin_active($file)
    {
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        return is_plugin_active($file);
    }

    private function signature_exists($classes, $functions)
    {
        foreach ((array) $classes as $c) {
            if (class_exists($c) || function_exists($c)) {
                return true;
            }
        }
        foreach ((array) $functions as $f) {
            if (function_exists($f)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check whether a given panel has a real, usable send integration.
     * A panel is "connectable" only if at least one of these is true:
     *  - An action hook is registered (has_action returns true)
     *  - A filter hook is registered (has_filter returns true)
     *  - A send function exists and is callable
     *
     * @param array $send_hooks Hook names to check.
     * @param array $send_funcs Function names to check.
     * @return array ['can_send' => bool, 'method' => string, 'target' => string]
     */
    private function check_send_capability($send_hooks, $send_funcs)
    {
        foreach ((array) $send_hooks as $hook) {
            if (has_action($hook)) {
                return array('can_send' => true, 'method' => 'action', 'target' => $hook);
            }
            if (has_filter($hook)) {
                return array('can_send' => true, 'method' => 'filter', 'target' => $hook);
            }
        }

        foreach ((array) $send_funcs as $func) {
            if (function_exists($func) && is_callable($func)) {
                return array('can_send' => true, 'method' => 'function', 'target' => $func);
            }
        }

        return array('can_send' => false, 'method' => '', 'target' => '');
    }

    /**
     * Detect all active SMS plugins on the site.
     *
     * Each detected panel includes:
     *  - id:         Slug identifier
     *  - name:       Display name
     *  - file:       Plugin file path
     *  - active:     Whether the plugin is active
     *  - can_send:   Whether a real send integration exists (true = connectable)
     *  - send_method: How SMS is sent ('action', 'filter', 'function', '')
     *  - send_target: The hook/function name that will be used
     *  - source:     How it was detected ('known', 'scan', 'hook')
     *
     * @return array Detected panels indexed by slug.
     */
    public function detect()
    {
        $detected = array();

        // 1. Check known panels.
        foreach ($this->known_panels as $key => $info) {
            $active   = $this->is_plugin_active($info['file']);
            $exists   = $this->signature_exists($info['classes'], $info['send_funcs']);
            $has_hook = false;
            foreach ($info['send_hooks'] as $h) {
                if (has_action($h) || has_filter($h)) {
                    $has_hook = true;
                    break;
                }
            }

            if (!$active && !$exists && !$has_hook) {
                continue;
            }

            $capability = $this->check_send_capability($info['send_hooks'], $info['send_funcs']);

            $detected[$key] = array(
                'id'          => $key,
                'name'        => $info['name'],
                'file'        => $info['file'],
                'active'      => true,
                'can_send'    => $capability['can_send'],
                'send_method' => $capability['method'],
                'send_target' => $capability['target'],
                'source'      => 'known',
            );
        }

        // 2. Scan all active plugins for SMS-related keywords in name/description.
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $all_plugins = get_plugins();
        foreach ($all_plugins as $plugin_file => $plugin_data) {
            if (!$this->is_plugin_active($plugin_file)) {
                continue;
            }
            // Skip already detected by known list.
            $already = false;
            foreach ($detected as $d) {
                if ($d['file'] === $plugin_file) {
                    $already = true;
                    break;
                }
            }
            if ($already) {
                continue;
            }

            $text = strtolower(($plugin_data['Name'] ?? '') . ' ' . ($plugin_data['Description'] ?? ''));
            if (!preg_match('/(sms|پیامک|پيامک|text message|kavenegar|melipayamak|farazsms|sms\.ir)/i', $text)) {
                continue;
            }

            $slug = sanitize_key(basename(dirname($plugin_file)));

            // For scanned plugins, check if they expose any generic SMS hooks/functions.
            $generic_hooks = array('woocommerce_sms_send', 'wp_sms_send', 'sms_send');
            $generic_funcs = array($slug . '_send_sms', 'send_sms_' . $slug, str_replace('-', '_', $slug) . '_send_sms');
            $capability = $this->check_send_capability($generic_hooks, $generic_funcs);

            $detected[$slug] = array(
                'id'          => $slug,
                'name'        => $plugin_data['Name'] ?: basename(dirname($plugin_file)),
                'file'        => $plugin_file,
                'active'      => true,
                'can_send'    => $capability['can_send'],
                'send_method' => $capability['method'],
                'send_target' => $capability['target'],
                'source'      => 'scan',
            );
        }

        // 3. Check global $wp_filter for SMS-related callbacks on order hooks.
        global $wp_filter;
        $order_hooks = array('woocommerce_order_status_changed', 'woocommerce_payment_complete');
        foreach ($order_hooks as $hook_name) {
            if (!isset($wp_filter[$hook_name])) {
                continue;
            }
            foreach ($wp_filter[$hook_name]->callbacks as $priority => $callbacks) {
                foreach ($callbacks as $id => $callback) {
                    $name = '';
                    if (is_array($callback['function'])) {
                        $name = is_object($callback['function'][0])
                            ? get_class($callback['function'][0])
                            : (string) $callback['function'][0];
                        $name .= '::' . $callback['function'][1];
                    } elseif (is_string($callback['function'])) {
                        $name = $callback['function'];
                    }
                    // Skip our own hooks.
                    if (false !== stripos($name, 'WCIP_') || false !== stripos($name, 'SMS_')) {
                        continue;
                    }
                    if (!preg_match('/(sms|پیامک|smsir|kavenegar|melipayamak|farazsms|asresms|netspeed)/i', $name)) {
                        continue;
                    }

                    $slug = sanitize_key('hooked_' . $id);
                    if (isset($detected[$slug])) {
                        continue;
                    }

                    // Hooked callbacks prove the plugin sends SMS on order events,
                    // but that doesn't mean it exposes a send function we can call.
                    // Check for a generic hook the plugin might listen to.
                    $capability = $this->check_send_capability(
                        array('woocommerce_sms_send', 'wp_sms_send', 'sms_send'),
                        array()
                    );

                    $detected[$slug] = array(
                        'id'          => $slug,
                        'name'        => sprintf(__('پنل پیامک متصل: %s', 'wc-installment'), $name),
                        'file'        => '',
                        'active'      => true,
                        'can_send'    => $capability['can_send'],
                        'send_method' => $capability['method'],
                        'send_target' => $capability['target'],
                        'source'      => 'hook',
                    );
                }
            }
        }

        return $detected;
    }

    public function refresh_detected()
    {
        $panels = $this->detect();
        set_transient(self::TRANSIENT_KEY, $panels, HOUR_IN_SECONDS);
        return $panels;
    }

    public function get_detected()
    {
        $cached = get_transient(self::TRANSIENT_KEY);
        if (false !== $cached && is_array($cached)) {
            return $cached;
        }
        return $this->refresh_detected();
    }

    /**
     * Get only panels that have a verified send integration (connectable).
     *
     * @return array
     */
    public function get_connectable()
    {
        $all = $this->get_detected();
        return array_filter($all, function ($p) {
            return !empty($p['can_send']);
        });
    }

    /**
     * Get the selected panel from settings.
     * Only returns panels that are actually connectable.
     *
     * @return array|null
     */
    public function get_selected()
    {
        $selected_id = get_option('wcip_sms_selected_panel', '');
        $connectable = $this->get_connectable();

        if ($selected_id && isset($connectable[$selected_id])) {
            return $connectable[$selected_id];
        }

        // Auto-select first connectable panel.
        if (empty($selected_id) && !empty($connectable)) {
            return reset($connectable);
        }

        return null;
    }

    /**
     * Send SMS via the selected panel's real integration.
     *
     * Returns success=true ONLY when a real hook was fired or a real
     * function was called. If the panel was detected but has no usable
     * send integration, returns failure with a clear error.
     *
     * @param string $phone   Recipient phone.
     * @param string $message Message body.
     * @return array ['success' => bool, 'provider' => string, 'error' => string, 'message' => string]
     */
    public function send($phone, $message)
    {
        $phone   = $this->sanitize_phone($phone);
        $message = wp_strip_all_tags($message);

        if (empty($phone)) {
            return array(
                'success'  => false,
                'error'    => 'invalid_phone',
                'message'  => __('شماره تلفن نامعتبر است.', 'wc-installment'),
            );
        }

        $panel = $this->get_selected();

        if (!$panel) {
            $all = $this->get_detected();
            if (empty($all)) {
                return array(
                    'success'  => false,
                    'error'    => 'no_sms_panel',
                    'message'  => __('هیچ پنل پیامکی روی سایت نصب نشده است.', 'wc-installment'),
                );
            }
            return array(
                'success'  => false,
                'error'    => 'no_send_integration',
                'message'  => __('پنل پیامک شناسایی شده است اما قابلیت ارسال از داخل وردپرس را ندارد. لطفاً یک پنل قابل اتصال انتخاب کنید.', 'wc-installment'),
            );
        }

        $method = $panel['send_method'];
        $target = $panel['send_target'];

        // 1. Fire the action hook.
        if ($method === 'action' && has_action($target)) {
            ob_start();
            do_action($target, $phone, $message);
            ob_end_clean();
            return array('success' => true, 'provider' => $panel['name']);
        }

        // 2. Apply the filter hook.
        if ($method === 'filter' && has_filter($target)) {
            $result = apply_filters($target, false, $phone, $message);
            if ($result) {
                return array('success' => true, 'provider' => $panel['name']);
            }
            return array(
                'success'  => false,
                'error'    => 'filter_returned_false',
                'provider' => $panel['name'],
                'message'  => sprintf(__('پنل «%s» پیامک را ارسال نکرد.', 'wc-installment'), $panel['name']),
            );
        }

        // 3. Call the function directly.
        if ($method === 'function' && function_exists($target) && is_callable($target)) {
            $result = call_user_func($target, $phone, $message);
            if ($result) {
                return array('success' => true, 'provider' => $panel['name']);
            }
            return array(
                'success'  => false,
                'error'    => 'function_returned_false',
                'provider' => $panel['name'],
                'message'  => sprintf(__('پنل «%s» پیامک را ارسال نکرد.', 'wc-installment'), $panel['name']),
            );
        }

        // The panel's integration disappeared since detection (plugin deactivated?).
        return array(
            'success'  => false,
            'error'    => 'integration_lost',
            'provider' => $panel['name'],
            'message'  => sprintf(__('قابلیت ارسال پنل «%s» دیگر در دسترس نیست. ممکن است افزونه غیرفعال شده باشد.', 'wc-installment'), $panel['name']),
        );
    }

    public function sanitize_phone($phone)
    {
        $phone = preg_replace('/[^0-9+]/', '', $phone);
        if (strpos($phone, '+98') === 0) {
            $phone = '0' . substr($phone, 3);
        } elseif (strpos($phone, '98') === 0 && strlen($phone) === 12) {
            $phone = '0' . substr($phone, 2);
        }
        return $phone;
    }
}
