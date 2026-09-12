<?php
/**
 * Kavenegar SMS Provider — api.kavenegar.com
 *
 * @package WC_Installment_Payment
 */

if (!defined('ABSPATH')) {
    exit;
}

class SMS_Provider_Kavenegar implements SMS_Provider_Interface
{
    private $api_key;
    private $sender;
    private $endpoint = 'https://api.kavenegar.com/v1/%s/sms/send.json';

    public function __construct($config)
    {
        $this->api_key = isset($config['api_key']) ? $config['api_key'] : '';
        $this->sender  = isset($config['sender_number']) ? $config['sender_number'] : '';
    }

    public function get_name()
    {
        return 'kavenegar';
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

        $url = sprintf($this->endpoint, $this->api_key);

        $body = array(
            'receptor' => $receptor,
            'message'  => $message,
        );

        if (!empty($this->sender)) {
            $body['sender'] = $this->sender;
        }

        $response = wp_remote_post($url, array(
            'body'        => $body,
            'timeout'     => 15,
            'redirection' => 5,
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

        if (isset($data['return']['status']) && $data['return']['status'] == 200) {
            return true;
        }

        return false;
    }
}
