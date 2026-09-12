<?php
/**
 * SMS Settings — registers the SMS settings section within the WooCommerce
 * installment settings tab. Shows auto-detected SMS panels grouped by
 * whether they have a real send integration or not.
 *
 * @package WC_Installment_Payment
 */

if (!defined('ABSPATH')) {
    exit;
}

class SMS_Settings
{
    private static $instance = null;

    public static function instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        add_action('wp_ajax_wcip_send_test_sms', array($this, 'ajax_send_test_sms'));
        add_action('wp_ajax_wcip_connect_sms_panel', array($this, 'ajax_connect_sms_panel'));
        add_action('woocommerce_settings_tabs_installment', array($this, 'render_panel_status'), 5);
    }

    /**
     * Get settings fields — no API key fields.
     */
    public function get_settings_fields()
    {
        $connectable = WCIP_SMS_Detector::instance()->get_connectable();
        $panel_options = array('' => __('— انتخاب خودکار —', 'wc-installment'));
        foreach ($connectable as $id => $panel) {
            $panel_options[$id] = $panel['name'];
        }

        return array(

            // ===== SMS Notification Settings =====
            array(
                'title' => __('تنظیمات پیامک خودکار', 'wc-installment'),
                'type'  => 'title',
                'desc'  => __('سیستم پیامک به‌صورت خودکار پنل‌های پیامک نصب‌شده روی سایت را شناسایی می‌کند. فقط پنل‌هایی که قابلیت ارسال از داخل وردپرس دارند قابل اتصال هستند. نیازی به کلید API نیست.', 'wc-installment'),
                'id'    => 'wcip_section_sms_auto',
            ),

            array(
                'title'   => __('فعال‌سازی اطلاع‌رسانی پیامک', 'wc-installment'),
                'desc'    => __('فعال/غیرفعال کردن ارسال خودکار پیامک', 'wc-installment'),
                'id'      => 'wcip_sms_enabled',
                'default' => 'no',
                'type'    => 'checkbox',
                'checkboxgroup' => 'start',
            ),

            array(
                'title'   => __('پنل پیامک فعال', 'wc-installment'),
                'id'      => 'wcip_sms_selected_panel',
                'default' => '',
                'type'    => 'select',
                'options' => $panel_options,
                'desc'    => __('پنل پیامک قابل اتصال را انتخاب کنید. «انتخاب خودکار» یعنی اولین پنل فعال استفاده شود.', 'wc-installment'),
            ),

            array(
                'title'       => __('تعداد روز قبل از سررسید', 'wc-installment'),
                'id'          => 'wcip_sms_pre_due_days',
                'default'     => '3',
                'type'        => 'number',
                'custom_attributes' => array('min' => '1', 'max' => '30', 'step' => '1'),
                'desc'        => __('چند روز قبل از سررسید قسط، پیامک یادآوری ارسال شود.', 'wc-installment'),
            ),

            array(
                'title'   => __('پیامک قبل از سررسید', 'wc-installment'),
                'desc'    => __('فعال‌سازی ارسال پیامک یادآوری قبل از سررسید', 'wc-installment'),
                'id'      => 'wcip_sms_pre_due_enabled',
                'default' => 'yes',
                'type'    => 'checkbox',
            ),

            array(
                'title'   => __('پیامک روز سررسید', 'wc-installment'),
                'desc'    => __('فعال‌سازی ارسال پیامک در روز سررسید', 'wc-installment'),
                'id'      => 'wcip_sms_due_date_enabled',
                'default' => 'yes',
                'type'    => 'checkbox',
            ),

            array(
                'title'   => __('پیامک معوق', 'wc-installment'),
                'desc'    => __('فعال‌سازی ارسال پیامک هشدار معوق شدن', 'wc-installment'),
                'id'      => 'wcip_sms_overdue_enabled',
                'default' => 'yes',
                'type'    => 'checkbox',
            ),

            array(
                'title'   => __('قالب پیامک قبل از سررسید', 'wc-installment'),
                'id'      => 'wcip_sms_template_pre_due',
                'default' => 'مشتری گرامی، قسط شماره {installment_number} سفارش #{order_id} به مبلغ {amount} تومان در تاریخ {due_date} سررسید می‌شود.',
                'type'    => 'textarea',
                'desc'    => __('متغیرها: {installment_number}, {order_id}, {amount}, {due_date}, {customer_name}, {shop_name}', 'wc-installment'),
                'css'     => 'min-width:500px; min-height:80px;',
            ),

            array(
                'title'   => __('قالب پیامک روز سررسید', 'wc-installment'),
                'id'      => 'wcip_sms_template_due_date',
                'default' => 'مشتری گرامی، امروز موعد پرداخت قسط شما به مبلغ {amount} تومان است.',
                'type'    => 'textarea',
                'desc'    => __('متغیرها: {installment_number}, {order_id}, {amount}, {due_date}, {customer_name}, {shop_name}', 'wc-installment'),
                'css'     => 'min-width:500px; min-height:80px;',
            ),

            array(
                'title'   => __('قالب پیامک معوق', 'wc-installment'),
                'id'      => 'wcip_sms_template_overdue',
                'default' => 'مشتری گرامی، قسط شماره {installment_number} شما به مبلغ {amount} تومان پرداخت نشده و معوق شده است. لطفاً نسبت به پرداخت آن اقدام فرمایید.',
                'type'    => 'textarea',
                'desc'    => __('متغیرها: {installment_number}, {order_id}, {amount}, {due_date}, {customer_name}, {shop_name}', 'wc-installment'),
                'css'     => 'min-width:500px; min-height:80px;',
            ),

            array(
                'type' => 'sectionend',
                'id'   => 'wcip_section_sms_auto',
            ),
        );
    }

    /**
     * Render the SMS panel status dashboard above the settings form.
     * Shows connectable panels and detected-but-not-connectable panels
     * in separate sections with appropriate UI.
     */
    public function render_panel_status()
    {
        // Only render on the installment settings tab.
        if (!isset($_GET['tab']) || $_GET['tab'] !== 'installment') {
            return;
        }

        $all = WCIP_SMS_Detector::instance()->get_detected();
        if (empty($all)) {
            echo '<div class="wcip-sms-status-wrap">';
            echo '<h3>' . esc_html__('وضعیت پنل‌های پیامک', 'wc-installment') . '</h3>';
            echo '<p class="wcip-sms-no-panels">' . esc_html__('هیچ پنل پیامکی روی سایت نصب و فعال نشده است. ابتدا یک افزونه پنل پیامک نصب و فعال کنید.', 'wc-installment') . '</p>';
            echo '</div>';
            return;
        }

        $connectable    = array();
        $not_connectable = array();
        foreach ($all as $id => $panel) {
            if (!empty($panel['can_send'])) {
                $connectable[$id] = $panel;
            } else {
                $not_connectable[$id] = $panel;
            }
        }

        $selected_id = get_option('wcip_sms_selected_panel', '');
        $enabled     = get_option('wcip_sms_enabled', 'no') === 'yes';

        echo '<div class="wcip-sms-status-wrap">';
        echo '<h3>' . esc_html__('وضعیت پنل‌های پیامک شناسایی‌شده', 'wc-installment') . '</h3>';

        // Connectable panels.
        if (!empty($connectable)) {
            echo '<table class="wcip-sms-panels-table wcip-sms-connectable">';
            echo '<thead><tr>';
            echo '<th>' . esc_html__('پنل پیامک', 'wc-installment') . '</th>';
            echo '<th>' . esc_html__('روش اتصال', 'wc-installment') . '</th>';
            echo '<th>' . esc_html__('وضعیت', 'wc-installment') . '</th>';
            echo '<th>' . esc_html__('عملیات', 'wc-installment') . '</th>';
            echo '</tr></thead><tbody>';

            foreach ($connectable as $id => $panel) {
                $is_selected = ($selected_id === $id) || ($selected_id === '' && $id === array_key_first($connectable));
                $method_label = $this->method_label($panel['send_method']);

                echo '<tr>';
                echo '<td class="wcip-panel-name">' . esc_html($panel['name']) . '</td>';
                echo '<td>' . esc_html($method_label) . '</td>';
                echo '<td>';
                if ($is_selected && $enabled) {
                    echo '<span class="wcip-badge wcip-badge-connected">' . esc_html__('متصل و فعال', 'wc-installment') . '</span>';
                } else {
                    echo '<span class="wcip-badge wcip-badge-available">' . esc_html__('قابل اتصال', 'wc-installment') . '</span>';
                }
                echo '</td>';
                echo '<td>';
                echo '<button type="button" class="button wcip-connect-sms" data-panel-id="' . esc_attr($id) . '" data-nonce="' . esc_attr(wp_create_nonce('wcip-admin')) . '">';
                echo esc_html__('اتصال', 'wc-installment');
                echo '</button>';
                echo '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }

        // Detected but not connectable.
        if (!empty($not_connectable)) {
            echo '<h4 style="margin-top:20px;">' . esc_html__('شناسایی شده اما ارسال خودکار ممکن نیست', 'wc-installment') . '</h4>';
            echo '<table class="wcip-sms-panels-table wcip-sms-not-connectable">';
            echo '<thead><tr>';
            echo '<th>' . esc_html__('پنل پیامک', 'wc-installment') . '</th>';
            echo '<th>' . esc_html__('وضعیت', 'wc-installment') . '</th>';
            echo '</tr></thead><tbody>';

            foreach ($not_connectable as $id => $panel) {
                echo '<tr>';
                echo '<td>' . esc_html($panel['name']) . '</td>';
                echo '<td><span class="wcip-badge wcip-badge-warning">' . esc_html__('پشتیبانی از ارسال خودکار موجود نیست', 'wc-installment') . '</span></td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }

        // Test SMS section (only if there's a connectable panel).
        if (!empty($connectable)) {
            $selected_panel = WCIP_SMS_Detector::instance()->get_selected();
            echo '<div class="wcip-test-sms-wrap">';
            echo '<h4>' . esc_html__('ارسال پیامک آزمایشی', 'wc-installment') . '</h4>';
            if ($selected_panel) {
                echo '<p class="wcip-test-sms-panel">' . sprintf(esc_html__('پنل فعال: %s', 'wc-installment'), '<strong>' . esc_html($selected_panel['name']) . '</strong>') . '</p>';
            }
            echo '<input type="text" id="wcip-test-sms-phone" placeholder="' . esc_attr__('شماره موبایل', 'wc-installment') . '" value="" />';
            echo '<button type="button" class="button button-primary wcip-send-test-sms" data-nonce="' . esc_attr(wp_create_nonce('wcip-admin')) . '">' . esc_html__('ارسال پیامک آزمایشی', 'wc-installment') . '</button>';
            echo '<span class="wcip-test-sms-result"></span>';
            echo '</div>';
        }

        echo '</div>';
    }

    /**
     * Human-readable label for the send method.
     */
    private function method_label($method)
    {
        switch ($method) {
            case 'action':
                return __('هوک اکشن', 'wc-installment');
            case 'filter':
                return __('هوک فیلتر', 'wc-installment');
            case 'function':
                return __('تابع قابل فراخوانی', 'wc-installment');
            default:
                return __('—', 'wc-installment');
        }
    }

    /**
     * Save settings.
     */
    public function save_settings()
    {
        $fields = $this->get_settings_fields();

        foreach ($fields as $field) {
            if (!isset($field['id']) || $field['type'] === 'title' || $field['type'] === 'sectionend') {
                continue;
            }

            $option_key = $field['id'];
            $default = isset($field['default']) ? $field['default'] : '';

            if ($field['type'] === 'checkbox') {
                $value = isset($_POST[$option_key]) ? 'yes' : 'no';
            } elseif ($field['type'] === 'number') {
                $value = isset($_POST[$option_key]) ? absint($_POST[$option_key]) : $default;
            } elseif ($field['type'] === 'textarea') {
                $value = isset($_POST[$option_key]) ? sanitize_textarea_field(wp_unslash($_POST[$option_key])) : $default;
            } elseif ($field['type'] === 'select') {
                $value = isset($_POST[$option_key]) ? sanitize_text_field($_POST[$option_key]) : $default;
            } else {
                $value = isset($_POST[$option_key]) ? sanitize_text_field($_POST[$option_key]) : $default;
            }

            update_option($option_key, $value);
        }
    }

    /**
     * AJAX: One-click connect to a detected SMS panel.
     * Only allows connecting to panels with verified send capability.
     */
    public function ajax_connect_sms_panel()
    {
        check_ajax_referer('wcip-admin', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('شما دسترسی لازم را ندارید.', 'wc-installment')));
        }

        $panel_id = isset($_POST['panel_id']) ? sanitize_text_field($_POST['panel_id']) : '';
        if (empty($panel_id)) {
            wp_send_json_error(array('message' => __('پنل پیامک انتخاب نشده است.', 'wc-installment')));
        }

        $connectable = WCIP_SMS_Detector::instance()->get_connectable();
        if (!isset($connectable[$panel_id])) {
            wp_send_json_error(array('message' => __('این پنل قابلیت ارسال خودکار ندارد و قابل اتصال نیست.', 'wc-installment')));
        }

        update_option('wcip_sms_selected_panel', $panel_id);
        update_option('wcip_sms_enabled', 'yes');

        wp_send_json_success(array(
            'message' => sprintf(__('پنل «%s» با موفقیت متصل شد. اکنون می‌توانید پیامک آزمایشی ارسال کنید.', 'wc-installment'), $connectable[$panel_id]['name']),
        ));
    }

    /**
     * AJAX: Send a test SMS via the selected connectable panel.
     */
    public function ajax_send_test_sms()
    {
        check_ajax_referer('wcip-admin', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('شما دسترسی لازم را ندارید.', 'wc-installment')));
        }

        $phone = isset($_POST['phone']) ? sanitize_text_field($_POST['phone']) : '';
        if (empty($phone)) {
            wp_send_json_error(array('message' => __('شماره تلفن را وارد کنید.', 'wc-installment')));
        }

        $panel = WCIP_SMS_Detector::instance()->get_selected();
        if (!$panel) {
            $all = WCIP_SMS_Detector::instance()->get_detected();
            if (!empty($all)) {
                wp_send_json_error(array('message' => __('پنل پیامک شناسایی شده اما قابلیت ارسال از داخل وردپرس را ندارد. یک پنل قابل اتصال انتخاب کنید.', 'wc-installment')));
            }
            wp_send_json_error(array('message' => __('هیچ پنل پیامک فعالی شناسایی نشد. ابتدا یک افزونه پنل پیامک نصب و فعال کنید.', 'wc-installment')));
        }

        $result = SMS_Manager::instance()->send_test_sms($phone);

        if ($result['success']) {
            wp_send_json_success(array(
                'message' => sprintf(__('پیامک آزمایشی از طریق «%s» با موفقیت ارسال شد.', 'wc-installment'), $result['provider'] ?? $panel['name']),
            ));
        } else {
            $error = $result['message'] ?? __('ارسال پیامک ناموفق بود.', 'wc-installment');
            wp_send_json_error(array('message' => $error));
        }
    }
}
