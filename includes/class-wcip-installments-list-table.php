<?php
/**
 * Custom WP_List_Table for displaying installments in the admin panel.
 *
 * @package WC_Installment_Payment
 */

if (!defined('ABSPATH')) {
    exit;
}

// Load the WP_List_Table base class if not already loaded.
if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

if (!class_exists('WCIP_Installments_List_Table')) {

    class WCIP_Installments_List_Table extends WP_List_Table
    {
        /**
         * Constructor.
         */
        public function __construct()
        {
            parent::__construct(array(
                'singular' => __('قسط', 'wc-installment'),
                'plural'   => __('اقساط', 'wc-installment'),
                'ajax'     => false,
                'screen'   => 'wcip-installments',
            ));
        }

        /**
         * Returns the list of columns.
         *
         * @return array
         */
        public function get_columns()
        {
            return array(
                'cb'                  => '<input type="checkbox" />',
                'customer'            => __('مشتری', 'wc-installment'),
                'order'               => __('سفارش', 'wc-installment'),
                'product'             => __('محصول', 'wc-installment'),
                'total_installments'  => __('تعداد اقساط', 'wc-installment'),
                'amount'              => __('مبلغ هر قسط', 'wc-installment'),
                'due_date'            => __('تاریخ سررسید', 'wc-installment'),
                'paid_count'          => __('پرداخت‌شده', 'wc-installment'),
                'unpaid_count'        => __('پرداخت‌نشده', 'wc-installment'),
                'overdue_count'       => __('معوق', 'wc-installment'),
                'status'              => __('وضعیت', 'wc-installment'),
            );
        }

        /**
         * Returns sortable columns.
         *
         * @return array
         */
        public function get_sortable_columns()
        {
            return array(
                'order'      => array('order_id', false),
                'amount'     => array('amount', false),
                'due_date'   => array('due_date', false),
                'status'     => array('status', false),
            );
        }

        /**
         * Returns the list of bulk actions.
         *
         * @return array
         */
        public function get_bulk_actions()
        {
            return array(
                'delete' => __('حذف', 'wc-installment'),
            );
        }

        /**
         * Renders the checkbox column.
         *
         * @param object $item Row item.
         * @return string
         */
        public function column_cb($item)
        {
            return sprintf(
                '<input type="checkbox" name="installment[]" value="%d" />',
                (int) $item->id
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
                case 'customer':
                    $user_id = (int) $item->user_id;
                    $name = !empty($item->customer_name) ? $item->customer_name : __('کاربر', 'wc-installment');
                    $edit_link = admin_url('user-edit.php?user_id=' . $user_id);
                    return sprintf(
                        '<a href="%s">%s</a>',
                        esc_url($edit_link),
                        esc_html($name)
                    );

                case 'order':
                    $order_id = (int) $item->order_id;
                    $order_link = admin_url('post.php?post=' . $order_id . '&action=edit');
                    return sprintf(
                        '<a href="%s">#%s</a>',
                        esc_url($order_link),
                        esc_html(number_to_persian($order_id))
                    );

                case 'product':
                    $product_id = (int) $item->product_id;
                    $product = wc_get_product($product_id);
                    if ($product) {
                        $edit_link = get_edit_post_link($product_id);
                        return sprintf(
                            '<a href="%s">%s</a>',
                            esc_url($edit_link),
                            esc_html($product->get_name())
                        );
                    }
                    return esc_html(sprintf('#%s', number_to_persian($product_id)));

                case 'total_installments':
                    return esc_html(number_to_persian($item->installment_number) . ' ' . __('از', 'wc-installment') . ' ' . number_to_persian($item->total_installments));

                case 'amount':
                    return esc_html(wcip_format_toman($item->amount));

                case 'due_date':
                    return esc_html(wcip_gregorian_to_jalali($item->due_date));

                case 'paid_count':
                case 'unpaid_count':
                case 'overdue_count':
                    return $this->get_grouped_count($item, $column_name);

                case 'status':
                    return $this->render_status_badge($item->status);

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
         * Returns counts for the grouped columns (paid/unpaid/overdue) for an order.
         *
         * @param object $item        Row item.
         * @param string $column_name Column name.
         * @return string
         */
        protected function get_grouped_count($item, $column_name)
        {
            $db = WCIP_DB::instance();
            $installments = $db->get_order_installments((int) $item->order_id);

            $paid = 0;
            $unpaid = 0;
            $overdue = 0;
            $today = current_time('Y-m-d');

            foreach ($installments as $inst) {
                if ($inst->status === 'paid') {
                    $paid++;
                } elseif ($inst->status === 'overdue' || ($inst->status === 'unpaid' && $inst->due_date < $today)) {
                    $overdue++;
                } else {
                    $unpaid++;
                }
            }

            $value = 0;
            switch ($column_name) {
                case 'paid_count':
                    $value = $paid;
                    break;
                case 'unpaid_count':
                    $value = $unpaid;
                    break;
                case 'overdue_count':
                    $value = $overdue;
                    break;
            }

            return esc_html(number_to_persian($value));
        }

        /**
         * Renders the row actions (edit/delete links).
         *
         * @param object $item Row item.
         * @return string
         */
        public function column_customer($item)
        {
            $user_id = (int) $item->user_id;
            $name = !empty($item->customer_name) ? $item->customer_name : __('کاربر', 'wc-installment');
            $edit_link = admin_url('user-edit.php?user_id=' . $user_id);

            $page_url = admin_url('admin.php?page=wcip-installments');

            $edit_url = wp_nonce_url(
                add_query_arg(array(
                    'action' => 'edit',
                    'installment_id' => (int) $item->id,
                ), $page_url),
                'wcip_edit_installment_' . $item->id,
                'wcip_nonce'
            );

            $delete_url = wp_nonce_url(
                add_query_arg(array(
                    'action' => 'delete',
                    'installment_id' => (int) $item->id,
                ), $page_url),
                'wcip_delete_installment_' . $item->id,
                'wcip_nonce'
            );

            $actions = array(
                'edit'   => sprintf('<a href="%s">%s</a>', esc_url($edit_url), esc_html__('ویرایش', 'wc-installment')),
                'delete' => sprintf(
                    '<a href="%s" class="wcip-delete-installment" data-id="%d">%s</a>',
                    esc_url($delete_url),
                    (int) $item->id,
                    esc_html__('حذف', 'wc-installment')
                ),
            );

            return sprintf(
                '<a href="%s"><strong>%s</strong></a>%s',
                esc_url($edit_link),
                esc_html($name),
                $this->row_actions($actions)
            );
        }

        /**
         * Prepares the items for display.
         */
        public function prepare_items()
        {
            $per_page = $this->get_items_per_page('installments_per_page', 20);
            $current_page = $this->get_pagenum();
            $total_items = 0;

            // Get filter values.
            $status_filter = isset($_GET['status_filter']) ? sanitize_text_field(wp_unslash($_GET['status_filter'])) : '';
            $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';

            // Get sort parameters.
            $orderby = isset($_GET['orderby']) ? sanitize_text_field(wp_unslash($_GET['orderby'])) : 'id';
            $order = isset($_GET['order']) ? sanitize_text_field(wp_unslash($_GET['order'])) : 'DESC';

            $db = WCIP_DB::instance();

            $args = array(
                'status'   => $status_filter,
                'search'   => $search,
                'orderby'  => $orderby,
                'order'    => $order,
                'per_page' => $per_page,
                'page'     => $current_page,
            );

            $this->items = $db->get_installments($args);
            $total_items = $db->count_installments($args);

            $this->set_pagination_args(array(
                'total_items' => $total_items,
                'per_page'    => $per_page,
                'total_pages' => (int) ceil($total_items / $per_page),
            ));

            $this->_column_headers = array($this->get_columns(), array(), $this->get_sortable_columns());
        }

        /**
         * Displays the filter dropdown above the table.
         *
         * @param string $which Top or bottom.
         */
        public function extra_tablenav($which)
        {
            if ($which !== 'top') {
                return;
            }

            $status_filter = isset($_GET['status_filter']) ? sanitize_text_field(wp_unslash($_GET['status_filter'])) : '';
            $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
            ?>
            <div class="alignleft actions">
                <select name="status_filter">
                    <option value=""><?php esc_html_e('همه وضعیت‌ها', 'wc-installment'); ?></option>
                    <option value="paid" <?php selected($status_filter, 'paid'); ?>><?php esc_html_e('پرداخت‌شده', 'wc-installment'); ?></option>
                    <option value="unpaid" <?php selected($status_filter, 'unpaid'); ?>><?php esc_html_e('پرداخت‌نشده', 'wc-installment'); ?></option>
                    <option value="overdue" <?php selected($status_filter, 'overdue'); ?>><?php esc_html_e('معوق', 'wc-installment'); ?></option>
                </select>
                <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php esc_attr_e('جستجوی مشتری یا شماره سفارش', 'wc-installment'); ?>" />
                <?php submit_button(__('فیلتر', 'wc-installment'), '', 'filter_action', false); ?>
            </div>
            <?php
        }
    }
}
