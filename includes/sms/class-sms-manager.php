<?php
/**
 * SMS Manager — core orchestration class for the SMS notification system.
 * Handles provider instantiation, message building, template/placeholder
 * replacement, and the actual send-and-log flow.
 *
 * @package WC_Installment_Payment
 */

if (!defined('ABSPATH')) {
    exit;
}

class SMS_Manager
{
    private static $instance = null;

    private $provider = null;

    public static function instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
    }

    public function get_settings()
    {
        return array(
            'enabled'           => get_option('wcip_sms_enabled', 'no') === 'yes',
            'provider'          => get_option('wcip_sms_provider', 'none'),
            'api_key'           => get_option('wcip_sms_api_key', ''),
            'sender_number'     => get_option('wcip_sms_sender_number', ''),
            'username'          => get_option('wcip_sms_username', ''),
            'pre_due_days'      => (int) get_option('wcip_sms_pre_due_days', '3'),
            'due_date_enabled'  => get_option('wcip_sms_due_date_enabled', 'yes') === 'yes',
            'overdue_enabled'   => get_option('wcip_sms_overdue_enabled', 'yes') === 'yes',
            'pre_due_enabled'   => get_option('wcip_sms_pre_due_enabled', 'yes') === 'yes',
            'template_pre_due'  => get_option('wcip_sms_template_pre_due',
                'مشتری گرامی، قسط شماره {installment_number} سفارش #{order_id} به مبلغ {amount} تومان در تاریخ {due_date} سررسید می‌شود.'),
            'template_due_date' => get_option('wcip_sms_template_due_date',
                'مشتری گرامی، امروز موعد پرداخت قسط شما به مبلغ {amount} تومان است.'),
            'template_overdue'  => get_option('wcip_sms_template_overdue',
                'مشتری گرامی، قسط شماره {installment_number} شما به مبلغ {amount} تومان پرداخت نشده و معوق شده است. لطفاً نسبت به پرداخت آن اقدام فرمایید.'),
        );
    }

    public function get_provider()
    {
        if ($this->provider !== null) {
            return $this->provider;
        }

        $settings = $this->get_settings();
        $config = array(
            'api_key'       => $settings['api_key'],
            'sender_number' => $settings['sender_number'],
            'username'      => $settings['username'],
        );

        $provider = null;

        switch ($settings['provider']) {
            case 'kavenegar':
                $provider = new SMS_Provider_Kavenegar($config);
                break;
            case 'smsir':
                $provider = new SMS_Provider_SMSIr($config);
                break;
            case 'melipayamak':
                $provider = new SMS_Provider_Melipayamak($config);
                break;
            case 'farazsms':
                $provider = new SMS_Provider_Farazsms($config);
                break;
        }

        $this->provider = $provider;
        return $provider;
    }

    public function get_available_providers()
    {
        return array(
            'none'        => __('— انتخاب نکرده —', 'wc-installment'),
            'kavenegar'   => __('کاوه‌نگار', 'wc-installment'),
            'smsir'       => __('SMS.ir', 'wc-installment'),
            'melipayamak' => __('ملی‌پیامک', 'wc-installment'),
            'farazsms'    => __('فراز SMS', 'wc-installment'),
        );
    }

    public function is_enabled()
    {
        $settings = $this->get_settings();
        return $settings['enabled'] && $settings['provider'] !== 'none';
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

    public function get_customer_phone($user_id, $order_id = 0)
    {
        $phone = '';

        if ($user_id > 0) {
            $phone = get_user_meta($user_id, 'billing_phone', true);
        }

        if (empty($phone) && $order_id > 0) {
            $order = wc_get_order($order_id);
            if ($order) {
                $phone = $order->get_billing_phone();
            }
        }

        return $phone;
    }

    public function get_customer_name($user_id)
    {
        $user = get_userdata($user_id);
        if ($user && $user->display_name) {
            return $user->display_name;
        }
        return '';
    }

    public function get_shop_name()
    {
        return get_bloginfo('name');
    }

    public function replace_placeholders($template, $installment)
    {
        $amount_formatted = wcip_format_toman($installment->amount);
        $due_date_jalali = wcip_gregorian_to_jalali($installment->due_date);
        $customer_name = $this->get_customer_name($installment->user_id);
        $shop_name = $this->get_shop_name();

        $replacements = array(
            '{installment_number}' => number_to_persian($installment->installment_number),
            '{order_id}'            => number_to_persian($installment->order_id),
            '{amount}'              => $amount_formatted,
            '{due_date}'            => $due_date_jalali,
            '{customer_name}'       => $customer_name,
            '{shop_name}'           => $shop_name,
        );

        return strtr($template, $replacements);
    }

    public function send_sms($phone, $message)
    {
        $phone = $this->sanitize_phone($phone);
        if (empty($phone)) {
            return array('success' => false, 'error' => 'invalid_phone');
        }

        $provider = $this->get_provider();
        if (!$provider || !$provider->validate_config()) {
            return array('success' => false, 'error' => 'provider_not_configured');
        }

        $result = $provider->send($phone, $message);

        return array(
            'success'    => $result,
            'provider'   => $provider->get_name(),
        );
    }

    public function send_installment_sms($installment, $event_type, $template)
    {
        $settings = $this->get_settings();

        $phone = $this->get_customer_phone($installment->user_id, $installment->order_id);
        if (empty($phone)) {
            return false;
        }

        $message = $this->replace_placeholders($template, $installment);

        $result = $this->send_sms($phone, $message);

        $log_data = array(
            'order_id'         => (int) $installment->order_id,
            'installment_id'   => (int) $installment->id,
            'event_type'       => $event_type,
            'recipient'        => $phone,
            'message'          => $message,
            'status'           => $result['success'] ? 'sent' : 'failed',
            'provider'         => isset($result['provider']) ? $result['provider'] : '',
            'provider_response'=> '',
        );

        SMS_Logger::instance()->log($log_data);

        return $result['success'];
    }

    public function send_pre_due_notification($installment)
    {
        $settings = $this->get_settings();
        if (!$settings['pre_due_enabled']) {
            return false;
        }

        if (SMS_Logger::instance()->has_been_sent($installment->id, 'pre_due')) {
            return false;
        }

        return $this->send_installment_sms($installment, 'pre_due', $settings['template_pre_due']);
    }

    public function send_due_date_notification($installment)
    {
        $settings = $this->get_settings();
        if (!$settings['due_date_enabled']) {
            return false;
        }

        if (SMS_Logger::instance()->has_been_sent($installment->id, 'due_date')) {
            return false;
        }

        return $this->send_installment_sms($installment, 'due_date', $settings['template_due_date']);
    }

    public function send_overdue_notification($installment)
    {
        $settings = $this->get_settings();
        if (!$settings['overdue_enabled']) {
            return false;
        }

        if (SMS_Logger::instance()->has_been_sent($installment->id, 'overdue')) {
            return false;
        }

        return $this->send_installment_sms($installment, 'overdue', $settings['template_overdue']);
    }

    public function send_paid_notification($installment_id, $installment)
    {
        $phone = $this->get_customer_phone($installment->user_id, $installment->order_id);
        if (empty($phone)) {
            return false;
        }

        $amount = wcip_format_toman($installment->amount);
        $message = sprintf(
            __('کاربر گرامی، پرداخت قسط شماره %1$s از %2$s به مبلغ %3$s با موفقیت ثبت شد. با تشکر از پرداخت به‌موقع شما.', 'wc-installment'),
            number_to_persian($installment->installment_number),
            number_to_persian($installment->total_installments),
            $amount
        );

        $result = $this->send_sms($phone, $message);

        SMS_Logger::instance()->log(array(
            'order_id'       => (int) $installment->order_id,
            'installment_id' => (int) $installment->id,
            'event_type'     => 'paid',
            'recipient'      => $this->sanitize_phone($phone),
            'message'        => $message,
            'status'         => $result['success'] ? 'sent' : 'failed',
            'provider'       => isset($result['provider']) ? $result['provider'] : '',
        ));

        return $result['success'];
    }

    public function send_test_sms($phone)
    {
        $settings = $this->get_settings();
        $message = sprintf(
            __('این یک پیامک آزمایشی از %s است. سیستم پیامک افزونه اقساطی با موفقیت پیکربندی شده است.', 'wc-installment'),
            $this->get_shop_name()
        );

        $result = $this->send_sms($phone, $message);

        SMS_Logger::instance()->log(array(
            'order_id'       => 0,
            'installment_id' => 0,
            'event_type'     => 'test',
            'recipient'      => $this->sanitize_phone($phone),
            'message'        => $message,
            'status'         => $result['success'] ? 'sent' : 'failed',
            'provider'       => isset($result['provider']) ? $result['provider'] : '',
        ));

        return $result;
    }
}
