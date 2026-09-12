<?php
/**
 * SMS.ir SMS Provider — api.sms.ir
 *
 * @package WC_Installment_Payment
 */

if (!defined('ABSPATH')) {
    exit;
}

class SMS_Provider_SMSIr implements SMS_Provider_Interface
{
    private $api_key;
    private $sender;
    private $endpoint = 'https://api.sms.ir/v1/send/bulk';

    public function __construct($config)
    {
        $this->api_key = isset($config['api_key']) ? $config['api_key'] : '';
        $this->sender  = isset($config['sender_number']) ? $config['sender_number'] : '';
    }

    public function get_name()
    {
        return 'smsir';
    }

    public function validate_config()
    {
        return !empty($this->api_key);
    }

    public function send($receptor, $message)
    {
        if (!$this->validate_config()) {
            return false;
        }

        $body = array(
            'lineId'  => $this->sender,
            'message' => $message,
            'mobiles' => array($receptor),
        );

        $response = wp_remote_post($this->endpoint, array(
            'body'    => wp_json_encode($body),
            'timeout' => 15,
            'headers' => array(
                'Content-Type'  => 'application/json',
                'X-API-KEY'     => $this->api_key,
            ),
        ));

        if (is_wp_error($response)) {
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            return false;
        }

        $body_json = wp_remote_retrieve_body($response);
        $data = json_decode($body_json, true);

        if (isset($data['status']) && (int) $data['status'] === 1) {
            return true;
        }

        return false;
    }
}
