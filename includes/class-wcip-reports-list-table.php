<?php
/**
 * Custom WP_List_Table for displaying report data in the admin panel.
 *
 * @package WC_Installment_Payment
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

if (!class_exists('WCIP_Reports_List_Table')) {

    class WCIP_Reports_List_Table extends WP_List_Table
    {
        /**
         * @var string Current report type.
         */
        private $report_type = 'total_sales';

        /**
         * Constructor.
         *
         * @param string $report_type Report type.
         */
        public function __construct($report_type = 'total_sales')
        {
            $this->report_type = $report_type;

            parent::__construct(array(
                'singular' => __('رکورد', 'wc-installment'),
                'plural'   => __('رکوردها', 'wc-installment'),
                'ajax'     => false,
                'screen'   => 'wcip-reports',
            ));
        }

        /**
         * Returns columns based on the current report type.
         *
         * @return array
         */
        public function get_columns()
        {
            switch ($this->report_type) {
                case 'down_payments':
                    return array(
                        'order_id'      => __('شماره سفارش', 'wc-installment'),
                        'customer'      => __('مشتری', 'wc-installment'),
                        'down_payment'  => __('پیش‌پرداخت', 'wc-installment'),
                        'order_date'    => __('تاریخ سفارش', 'wc-installment'),
                    );

                case 'received_amounts':
                    return array(
                        'order_id'      => __('شماره سفارش', 'wc-installment'),
                        'customer'      => __('مشتری', 'wc-installment'),
                        'product'       => __('محصول', 'wc-installment'),
                        'installment'   => __('قسط', 'wc-installment'),
                        'amount'        => __('مبلغ دریافتی', 'wc-installment'),
                        'paid_date'     => __('تاریخ پرداخت', 'wc-installment'),
                    );

                case 'remaining_balances':
                    return array(
                        'order_id'      => __('شماره سفارش', 'wc-installment'),
                        'customer'      => __('مشتری', 'wc-installment'),
                        'product'       => __('محصول', 'wc-installment'),
                        'installment'   => __('قسط', 'wc-installment'),
                        'amount'        => __('مبلغ باقی‌مانده', 'wc-installment'),
                        'due_date'      => __('تاریخ سررسید', 'wc-installment'),
                        'status'        => __('وضعیت', 'wc-installment'),
                    );

                case 'overdue_installments':
                    return array(
                        'order_id'      => __('شماره سفارش', 'wc-installment'),
                        'customer'      => __('مشتری', 'wc-installment'),
                        'product'       => __('محصول', 'wc-installment'),
                        'installment'   => __('قسط', 'wc-installment'),
                        'amount'        => __('مبلغ', 'wc-installment'),
                        'due_date'      => __('تاریخ سررسید', 'wc-installment'),
                        'status'        => __('وضعیت', 'wc-installment'),
                    );

                case 'active_contracts':
                case 'settled_contracts':
                case 'total_sales':
                default:
                    return array(
                        'order_id'      => __('شماره سفارش', 'wc-installment'),
                        'customer'      => __('مشتری', 'wc-installment'),
                        'product'       => __('محصول', 'wc-installment'),
                        'installment'   => __('قسط', 'wc-installment'),
                        'amount'        => __('مبلغ', 'wc-installment'),
                        'due_date'      => __('تاریخ سررسید', 'wc-installment'),
                        'status'        => __('وضعیت', 'wc-installment'),
                    );
            }
        }

        /**
         * Returns sortable columns.
         *
         * @return array
         */
        public function get_sortable_columns()
        {
            return array(
                'order_id'   => array('order_id', false),
                'customer'   => array('customer_name', false),
                'amount'     => array('amount', false),
                'due_date'   => array('due_date', false),
                'status'     => array('status', false),
                'order_date' => array('date', false),
                'paid_date'  => array('paid_date', false),
            );
        }

        /**
         * Default column renderer.
         *
         * @param object $item        Row item.
         * @param string $column_name Column name.
         * @return string
         */
        public function column_default($item, $column_name)
        {
            switch ($column_name) {
                case 'order_id':
                    $order_id = (int) ($item->order_id ?? 0);
                    $link = admin_url('post.php?post=' . $order_id . '&action=edit');
                    return sprintf('<a href="%s">#%s</a>', esc_url($link), esc_html(number_to_persian($order_id)));

                case 'customer':
                    $name = !empty($item->customer_name) ? $item->customer_name : __('کاربر', 'wc-installment');
                    $user_id = (int) ($item->user_id ?? $item->customer_id ?? 0);
                    if ($user_id > 0) {
                        return sprintf('<a href="%s">%s</a>', esc_url(admin_url('user-edit.php?user_id=' . $user_id)), esc_html($name));
                    }
                    return esc_html($name);

                case 'product':
                    $product_id = (int) ($item->product_id ?? 0);
                    $product = wc_get_product($product_id);
                    if ($product) {
                        $link = get_edit_post_link($product_id);
                        return sprintf('<a href="%s">%s</a>', esc_url($link), esc_html($product->get_name()));
                    }
                    return '—';

                case 'installment':
                    $num = (int) ($item->installment_number ?? 0);
                    $total = (int) ($item->total_installments ?? 0);
                    return esc_html(number_to_persian($num) . ' ' . __('از', 'wc-installment') . ' ' . number_to_persian($total));

                case 'amount':
                    return esc_html(wcip_format_toman($item->amount ?? 0));

                case 'down_payment':
                    return esc_html(wcip_format_toman($item->down_payment ?? 0));

                case 'due_date':
                    return esc_html(wcip_gregorian_to_jalali($item->due_date ?? ''));

                case 'order_date':
                    return esc_html(wcip_gregorian_to_jalali(substr($item->order_date ?? '', 0, 10)));

                case 'paid_date':
                    $pd = $item->paid_date ?? '';
                    if (empty($pd)) {
                        return '—';
                    }
                    return esc_html(wcip_gregorian_to_jalali(substr($pd, 0, 10)));

                case 'status':
                    return $this->render_status_badge($item->status ?? '');

                default:
                    return esc_html($item->$column_name ?? '');
            }
        }

        /**
         * Renders the status column with a colored badge.
         *
         * @param string $status Status string.
         * @return string
         */
        protected function render_status_badge($status)
        {
            $labels = array(
                'paid'    => __('پرداخت‌شده', 'wc-installment'),
                'unpaid'  => __('پرداخت‌نشده', 'wc-installment'),
                'overdue' => __('معوق', 'wc-installment'),
            );

            $label = isset($labels[$status]) ? $labels[$status] : $status;
            $class = 'wcip-badge wcip-badge-' . esc_attr($status);

            return sprintf('<span class="%s">%s</span>', esc_attr($class), esc_html($label));
        }

        /**
         * Prepares items for display.
         */
        public function prepare_items()
        {
            $per_page = $this->get_items_per_page('reports_per_page', 20);
            $current_page = $this->get_pagenum();

            $args = array(
                'report_type'   => $this->report_type,
                'date_from'     => isset($_GET['date_from']) ? sanitize_text_field(wp_unslash($_GET['date_from'])) : '',
                'date_to'       => isset($_GET['date_to']) ? sanitize_text_field(wp_unslash($_GET['date_to'])) : '',
                'customer_id'   => isset($_GET['customer_id']) ? absint($_GET['customer_id']) : 0,
                'status_filter' => isset($_GET['status_filter']) ? sanitize_text_field(wp_unslash($_GET['status_filter'])) : '',
                'product_id'    => isset($_GET['product_id']) ? absint($_GET['product_id']) : 0,
                'orderby'       => isset($_GET['orderby']) ? sanitize_text_field(wp_unslash($_GET['orderby'])) : 'id',
                'order'         => isset($_GET['order']) ? sanitize_text_field(wp_unslash($_GET['order'])) : 'DESC',
                'per_page'      => $per_page,
                'page'          => $current_page,
            );

            $db = WCIP_DB::instance();

            $this->items = $db->get_report_data($args);
            $total_items = $db->count_report_data($args);

            $this->set_pagination_args(array(
                'total_items' => $total_items,
                'per_page'    => $per_page,
                'total_pages' => (int) ceil($total_items / $per_page),
            ));

            $this->_column_headers = array($this->get_columns(), array(), $this->get_sortable_columns());
        }

        /**
         * Displays the filter bar above the table.
         *
         * @param string $which Top or bottom.
         */
        public function extra_tablenav($which)
        {
            if ($which !== 'top') {
                return;
            }

            $date_from     = isset($_GET['date_from']) ? sanitize_text_field(wp_unslash($_GET['date_from'])) : '';
            $date_to       = isset($_GET['date_to']) ? sanitize_text_field(wp_unslash($_GET['date_to'])) : '';
            $customer_id   = isset($_GET['customer_id']) ? absint($_GET['customer_id']) : 0;
            $status_filter = isset($_GET['status_filter']) ? sanitize_text_field(wp_unslash($_GET['status_filter'])) : '';
            $product_id    = isset($_GET['product_id']) ? absint($_GET['product_id']) : 0;
            ?>
            <div class="alignleft actions wcip-report-filters">
                <input type="date" name="date_from" value="<?php echo esc_attr($date_from); ?>" placeholder="<?php esc_attr_e('از تاریخ', 'wc-installment'); ?>" />
                <input type="date" name="date_to" value="<?php echo esc_attr($date_to); ?>" placeholder="<?php esc_attr_e('تا تاریخ', 'wc-installment'); ?>" />
                <input type="number" name="customer_id" value="<?php echo esc_attr($customer_id > 0 ? $customer_id : ''); ?>" placeholder="<?php esc_attr_e('شناسه مشتری', 'wc-installment'); ?>" min="1" style="width:120px;" />
                <input type="number" name="product_id" value="<?php echo esc_attr($product_id > 0 ? $product_id : ''); ?>" placeholder="<?php esc_attr_e('شناسه محصول', 'wc-installment'); ?>" min="1" style="width:120px;" />
                <select name="status_filter">
                    <option value=""><?php esc_html_e('همه وضعیت‌ها', 'wc-installment'); ?></option>
                    <option value="paid" <?php selected($status_filter, 'paid'); ?>><?php esc_html_e('پرداخت‌شده', 'wc-installment'); ?></option>
                    <option value="unpaid" <?php selected($status_filter, 'unpaid'); ?>><?php esc_html_e('پرداخت‌نشده', 'wc-installment'); ?></option>
                    <option value="overdue" <?php selected($status_filter, 'overdue'); ?>><?php esc_html_e('معوق', 'wc-installment'); ?></option>
                </select>
                <?php submit_button(__('فیلتر', 'wc-installment'), '', 'filter_action', false); ?>
                <a href="<?php echo esc_url(admin_url('admin.php?page=wcip-reports&tab=' . $this->report_type)); ?>" class="button button-secondary"><?php esc_html_e('پاک کردن فیلتر', 'wc-installment'); ?></a>
            </div>
            <?php
        }
    }
}
