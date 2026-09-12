<?php
/**
 * SMS Provider Interface — defines the contract all SMS gateways must implement.
 *
 * @package WC_Installment_Payment
 */

if (!defined('ABSPATH')) {
    exit;
}

interface SMS_Provider_Interface
{
    /**
     * Sends an SMS message to a single recipient.
     *
     * @param string $receptor Recipient phone number.
     * @param string $message  Message body.
     * @return bool True on success, false on failure.
     */
    public function send($receptor, $message);

    /**
     * Returns the provider's display name.
     *
     * @return string
     */
    public function get_name();

    /**
     * Validates that the provider has all required configuration.
     *
     * @return bool
     */
    public function validate_config();
}
