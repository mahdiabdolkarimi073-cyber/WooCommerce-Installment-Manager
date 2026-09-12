<?php
/**
 * Helper functions used throughout the plugin.
 *
 * @package WC_Installment_Payment
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Retrieves a global setting value with a fallback default.
 *
 * @param string $key     Setting key.
 * @param mixed  $default Default value.
 * @return mixed
 */
function wcip_get_global_setting($key, $default = '')
{
    $settings = get_option('wcip_global_settings', array());
    if (!is_array($settings)) {
        $settings = array();
    }
    return isset($settings[$key]) ? $settings[$key] : $default;
}

/**
 * Retrieves per-product installment settings merged with global defaults.
 *
 * @param int $product_id Product ID.
 * @return array
 */
function wcip_get_product_installment_settings($product_id)
{
    $enabled      = get_post_meta($product_id, '_wcip_enabled', true);
    $use_global   = get_post_meta($product_id, '_wcip_use_global', true);

    if ($enabled !== 'yes') {
        return array('enabled' => false);
    }

    if ($use_global === 'yes' || $use_global === '') {
        return array(
            'enabled'           => true,
            'use_global'        => true,
            'down_payment_type' => wcip_get_global_setting('down_payment_type', 'percentage'),
            'down_payment_value'=> wcip_get_global_setting('down_payment_value', '30'),
            'installment_method'=> wcip_get_global_setting('installment_method', 'installments'),
            'installment_count' => wcip_get_global_setting('installment_count', '3'),
            'fee_type'          => wcip_get_global_setting('fee_type', 'percentage'),
            'fee_value'         => wcip_get_global_setting('fee_value', '0'),
        );
    }

    return array(
        'enabled'           => true,
        'use_global'        => false,
        'down_payment_type' => get_post_meta($product_id, '_wcip_down_payment_type', true) ?: 'percentage',
        'down_payment_value'=> get_post_meta($product_id, '_wcip_down_payment_value', true) ?: '0',
        'installment_method'=> get_post_meta($product_id, '_wcip_installment_method', true) ?: 'installments',
        'installment_count' => get_post_meta($product_id, '_wcip_installment_count', true) ?: '3',
        'fee_type'          => get_post_meta($product_id, '_wcip_fee_type', true) ?: 'percentage',
        'fee_value'         => get_post_meta($product_id, '_wcip_fee_value', true) ?: '0',
    );
}

/**
 * Retrieves installment plans for a product (per-product or global fallback).
 * Each plan: ['months' => int, 'interest_rate' => float (percentage)].
 *
 * @param int $product_id Product ID.
 * @return array Array of plan arrays.
 */
function wcip_get_product_plans($product_id)
{
    $settings = wcip_get_product_installment_settings($product_id);
    if (empty($settings['enabled'])) {
        return array();
    }

    // If using global defaults, build plans from global settings.
    if (!empty($settings['use_global'])) {
        $global_plans = get_option('wcip_global_plans', array());
        if (is_array($global_plans) && count($global_plans) > 0) {
            return $global_plans;
        }
        // Fallback: single plan from global count.
        $count = intval(wcip_get_global_setting('installment_count', '3'));
        return array(array('months' => $count, 'interest_rate' => 0));
    }

    // Per-product plans.
    $plans = get_post_meta($product_id, '_wcip_plans', true);
    if (is_array($plans) && count($plans) > 0) {
        return $plans;
    }

    // Fallback: single plan from per-product count.
    $count = intval(get_post_meta($product_id, '_wcip_installment_count', true) ?: 3);
    return array(array('months' => $count, 'interest_rate' => 0));
}

/**
 * Checks whether installment is enabled for a given product (considering global toggle).
 *
 * @param int $product_id Product ID.
 * @return bool
 */
function wcip_is_installment_enabled_for_product($product_id)
{
    if (wcip_get_global_setting('enabled', 'no') !== 'yes') {
        return false;
    }
    $settings = wcip_get_product_installment_settings($product_id);
    return !empty($settings['enabled']);
}

/**
 * Formats a number as a price string in Toman.
 *
 * @param float $amount Numeric amount.
 * @return string
 */
function wcip_format_toman($amount)
{
    $amount = floatval($amount);
    $formatted = number_format($amount, 0, '.', ',');

    $persian_digits = array('۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹');
    $formatted = str_replace(range(0, 9), $persian_digits, $formatted);

    return $formatted . ' ' . __('تومان', 'wc-installment');
}

/**
 * Converts a Gregorian date string to a Jalali (Shamsi) date string.
 *
 * @param string $gregorian_date A date string in Y-m-d format.
 * @param string $format         Optional. Format string (default: 'Y/m/d').
 * @return string Jalali date string, or the original on failure.
 */
function wcip_gregorian_to_jalali($gregorian_date, $format = 'Y/m/d')
{
    if (empty($gregorian_date) || $gregorian_date === '0000-00-00' || $gregorian_date === '1970-01-01') {
        return '—';
    }

    $timestamp = strtotime($gregorian_date);
    if ($timestamp === false) {
        return $gregorian_date;
    }

    $gy = (int) date('Y', $timestamp);
    $gm = (int) date('n', $timestamp);
    $gd = (int) date('j', $timestamp);

    list($jy, $jm, $jd) = wcip_gregorian_to_jalali_array($gy, $gm, $gd);

    $persian_digits = array('۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹');

    $result = $format;
    $result = str_replace('Y', $jy, $result);
    $result = str_replace('m', str_pad($jm, 2, '0', STR_PAD_LEFT), $result);
    $result = str_replace('d', str_pad($jd, 2, '0', STR_PAD_LEFT), $result);
    $result = str_replace('n', (string) $jm, $result);
    $result = str_replace('j', (string) $jd, $result);

    return str_replace(range(0, 9), $persian_digits, $result);
}

/**
 * Converts a Gregorian date to a Jalali date array.
 * Algorithm adapted from the widely-used Jalali calendar conversion.
 *
 * @param int $gy Gregorian year.
 * @param int $gm Gregorian month (1-12).
 * @param int $gd Gregorian day (1-31).
 * @return array Array of [year, month, day] in Jalali.
 */
function wcip_gregorian_to_jalali_array($gy, $gm, $gd)
{
    $g_d_m = array(0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334);

    $gy2 = ($gm > 2) ? ($gy + 1) : $gy;
    $days = 355666 + (365 * $gy) + (int) (($gy2 + 3) / 4) - (int) (($gy2 + 99) / 100) + (int) (($gy2 + 399) / 400) + $gd + $g_d_m[$gm - 1];

    $jy = -159 + (33 * (int) ($days / 12053));
    $days %= 12053;
    $jy += 4 * (int) ($days / 1461);
    $days %= 1461;

    if ($days > 365) {
        $jy += (int) (($days - 1) / 365);
        $days = ($days - 1) % 365;
    }

    $jm = ($days < 186) ? (1 + (int) ($days / 31)) : (7 + (int) (($days - 186) / 30));
    $jd = 1 + (($days < 186) ? ($days % 31) : (($days - 186) % 30));

    return array($jy, $jm, $jd);
}

/**
 * Converts a Jalali date string to a Gregorian date string.
 *
 * @param int $jy Jalali year.
 * @param int $jm Jalali month (1-12).
 * @param int $jd Jalali day (1-31).
 * @return string Gregorian date in Y-m-d format.
 */
function wcip_jalali_to_gregorian($jy, $jm, $jd)
{
    $jy = (int) $jy;
    $jm = (int) $jm;
    $jd = (int) $jd;

    $jy += 1595;
    $days = -355668 + (365 * $jy) + (int) ($jy / 33) * 8 + (int) (($jy % 33 + 3) / 4) + $jd + (($jm < 7) ? ($jm - 1) * 31 : (($jm - 7) * 30) + 186);

    $gy = 1599 + (int) ($days / 146097) * 400;
    $days %= 146097;
    if ($days > 36524) {
        $gy += 100 * (int) (--$days / 36524);
        $days %= 36524;
        if ($days >= 365) {
            $days++;
        }
    }
    $gy += 4 * (int) ($days / 1461);
    $days %= 1461;

    if ($days > 365) {
        $gy += (int) (($days - 1) / 365);
        $days = ($days - 1) % 365;
    }

    $gd = $days + 1;

    $sal_a = array(0, 31, (($gy % 4 === 0 && $gy % 100 !== 0) || ($gy % 400 === 0)) ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31);

    $gm = 0;
    foreach ($sal_a as $month => $days_in_month) {
        if ($gd <= $days_in_month) {
            $gm = $month;
            break;
        }
        $gd -= $days_in_month;
    }

    return sprintf('%04d-%02d-%02d', $gy, $gm, $gd);
}

/**
 * Returns the current Jalali date string.
 *
 * @param string $format Optional. Format string (default: 'Y/m/d').
 * @return string
 */
function wcip_current_jalali_date($format = 'Y/m/d')
{
    return wcip_gregorian_to_jalali(current_time('Y-m-d'), $format);
}
