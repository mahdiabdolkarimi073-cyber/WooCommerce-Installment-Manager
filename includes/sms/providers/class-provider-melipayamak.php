<?php
/**
 * Melipayamak SMS Provider — rest.payamak-panel.com
 *
 * @package WC_Installment_Payment
 */

if (!defined('ABSPATH')) {
    exit;
}

class SMS_Provider_Melipayamak implements SMS_Provider_Interface
{
    private $api_key;
    private $sender;
    private $username;
    private $endpoint = 'https://rest.payamak-panel.com/api/SMS/SendByBaseNumbers';

    public function __construct($config)
    {
        $this->api_key = isset($config['api_key']) ? $config['api_key'] : '';
        $this->sender  = isset($config['sender_number']) ? $config['sender_number'] : '';
        $this->username = isset($config['username']) ? $config['username'] : '';
    }

    public function get_name()
    {
        return 'melipayamak';
    }

    public function validate_config()
    {
        return !empty($this->api_key) && !empty($this->username);
    }

    public function send($receptor, $message)
    {
        if (!$this->validate_config()) {
            return false;
        }

        $body = array(
            'username' => $this->username,
            'password' => $this->api_key,
            'text'     => $message,
            'to'       => $receptor,
            'bodyId'   => $this->sender,
        );

        $response = wp_remote_post($this->endpoint, array(
            'body'    => wp_json_encode($body),
            'timeout' => 15,
            'headers' => array(
                'Content-Type' => 'application/json',
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

        if (isset($data['RetStatus']) && (int) $data['RetStatus'] === 1) {
            return true;
        }

        if (isset($data['Value']) && is_numeric($data['Value']) && (int) $data['Value'] > 0) {
            return true;
        }

        return false;
    }
}
