<?php
/**
 * Admin installments management page — list, edit, delete, and bulk actions.
 *
 * @package WC_Installment_Payment
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('WCIP_Admin_Installments')) {

    class WCIP_Admin_Installments
    {
        /**
         * @var WCIP_Admin_Installments|null
         */
        private static $instance = null;

        /**
         * Singleton instance.
         *
         * @return WCIP_Admin_Installments
         */
        public static function instance()
        {
            if (null === self::$instance) {
                self::$instance = new self();
            }
            return self::$instance;
        }

        /**
         * Constructor.
         */
        private function __construct()
        {
            add_action('admin_menu', array($this, 'add_admin_menu'));
            add_action('admin_init', array($this, 'handle_actions'));
        }

        /**
         * Adds the installments submenu under WooCommerce.
         */
        public function add_admin_menu()
        {
            add_submenu_page(
                'woocommerce',
                __('مدیریت اقساط', 'wc-installment'),
                __('مدیریت اقساط', 'wc-installment'),
                'manage_woocommerce',
                'wcip-installments',
                array($this, 'render_page')
            );
        }

        /**
         * Handles edit, delete, and bulk actions.
         */
        public function handle_actions()
        {
            if (!current_user_can('manage_woocommerce') && !current_user_can('manage_options')) {
                return;
            }

            $action = isset($_GET['action']) ? sanitize_text_field(wp_unslash($_GET['action'])) : '';
            $page = isset($_GET['page']) ? sanitize_text_field(wp_unslash($_GET['page'])) : '';

            // Handle edit form submission (POST).
            if (isset($_POST['wcip_edit_installment']) && isset($_POST['wcip_edit_nonce'])) {
                $this->handle_edit_submission();
                return;
            }

            // Handle bulk delete (POST).
            if (isset($_POST['action']) && $_POST['action'] === 'delete' && isset($_POST['installment']) && isset($_POST['_wpnonce'])) {
                $this->handle_bulk_delete();
                return;
            }

            // Handle single delete (GET).
            if ($page === 'wcip-installments' && $action === 'delete' && isset($_GET['installment_id']) && isset($_GET['wcip_nonce'])) {
                $this->handle_single_delete();
                return;
            }
        }

        /**
         * Handles the edit form submission.
         */
        protected function handle_edit_submission()
        {
            if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['wcip_edit_nonce'])), 'wcip_edit_installment')) {
                add_action('admin_notices', function () {
                    echo '<div class="notice notice-error"><p>' . esc_html__('خطای امنیتی. عملیات انجام نشد.', 'wc-installment') . '</p></div>';
                });
                return;
            }

            $installment_id = absint($_POST['installment_id']);
            if ($installment_id <= 0) {
                return;
            }

            $db = WCIP_DB::instance();
            $installment = $db->get_installment($installment_id);
            if (!$installment) {
                return;
            }

            // Sanitize and prepare update data.
            $due_date_raw = isset($_POST['due_date']) ? sanitize_text_field(wp_unslash($_POST['due_date'])) : '';
            $due_date = $this->parse_jalali_date_input($due_date_raw, $installment->due_date);

            $amount = isset($_POST['amount']) ? floatval(preg_replace('/[^0-9.]/', '', sanitize_text_field(wp_unslash($_POST['amount'])))) : 0;
            $status = isset($_POST['status']) ? sanitize_text_field(wp_unslash($_POST['status'])) : 'unpaid';
            if (!in_array($status, array('paid', 'unpaid', 'overdue'), true)) {
                $status = 'unpaid';
            }

            $admin_notes = isset($_POST['admin_notes']) ? sanitize_textarea_field(wp_unslash($_POST['admin_notes'])) : '';

            $update_data = array(
                'due_date'    => $due_date,
                'amount'      => $amount,
                'status'      => $status,
                'admin_notes' => $admin_notes,
            );

            // If marked as paid, set paid_date; if not paid, clear it.
            if ($status === 'paid' && empty($installment->paid_date)) {
                $update_data['paid_date'] = current_time('mysql');
            } elseif ($status !== 'paid') {
                $update_data['paid_date'] = null;
            }

            $result = $db->update_installment($installment_id, $update_data);

            if ($result) {
                // If the status changed to paid, trigger SMS.
                if ($status === 'paid' && $installment->status !== 'paid') {
                    $updated = $db->get_installment($installment_id);
                    if ($updated) {
                        do_action('wcip_installment_paid', $installment_id, $updated);
                    }
                }

                add_action('admin_notices', function () {
                    echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('قسط با موفقیت به‌روزرسانی شد.', 'wc-installment') . '</p></div>';
                });
            } else {
                add_action('admin_notices', function () {
                    echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__('خطا در به‌روزرسانی قسط.', 'wc-installment') . '</p></div>';
                });
            }
        }

        /**
         * Handles single installment deletion.
         */
        protected function handle_single_delete()
        {
            $installment_id = absint($_GET['installment_id']);
            if ($installment_id <= 0) {
                return;
            }

            if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['wcip_nonce'])), 'wcip_delete_installment_' . $installment_id)) {
                add_action('admin_notices', function () {
                    echo '<div class="notice notice-error"><p>' . esc_html__('خطای امنیتی. عملیات حذف انجام نشد.', 'wc-installment') . '</p></div>';
                });
                return;
            }

            $db = WCIP_DB::instance();
            $result = $db->delete_installment($installment_id);

            if ($result) {
                add_action('admin_notices', function () {
                    echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('قسط با موفقیت حذف شد.', 'wc-installment') . '</p></div>';
                });
            } else {
                add_action('admin_notices', function () {
                    echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__('خطا در حذف قسط.', 'wc-installment') . '</p></div>';
                });
            }

            wp_safe_redirect(admin_url('admin.php?page=wcip-installments'));
            exit;
        }

        /**
         * Handles bulk delete.
         */
        protected function handle_bulk_delete()
        {
            if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'bulk-installments')) {
                add_action('admin_notices', function () {
                    echo '<div class="notice notice-error"><p>' . esc_html__('خطای امنیتی. حذف گروهی انجام نشد.', 'wc-installment') . '</p></div>';
                });
                return;
            }

            $ids = isset($_POST['installment']) ? array_map('absint', (array) $_POST['installment']) : array();
            if (empty($ids)) {
                return;
            }

            $db = WCIP_DB::instance();
            $deleted = 0;

            foreach ($ids as $id) {
                if ($db->delete_installment($id)) {
                    $deleted++;
                }
            }

            add_action('admin_notices', function () use ($deleted) {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(sprintf(
                    __('%s قسط با موفقیت حذف شد.', 'wc-installment'),
                    number_to_persian($deleted)
                )) . '</p></div>';
            });

            wp_safe_redirect(admin_url('admin.php?page=wcip-installments'));
            exit;
        }

        /**
         * Parses a Jalali date input (Y/m/d) and returns a Gregorian Y-m-d date.
         *
         * @param string $input     Jalali date string from the form.
         * @param string $fallback Existing Gregorian date as fallback.
         * @return string Gregorian date in Y-m-d format.
         */
        protected function parse_jalali_date_input($input, $fallback)
        {
            $input = trim($input);
            if (empty($input)) {
                return $fallback;
            }

            // Convert Persian digits to English.
            $persian = array('۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹');
            $input = str_replace($persian, range(0, 9), $input);

            // Try parsing as Jalali Y/m/d or Y-m-d.
            $parts = preg_split('/[\/\-\.]/', $input);
            if (count($parts) === 3) {
                $jy = (int) $parts[0];
                $jm = (int) $parts[1];
                $jd = (int) $parts[2];

                if ($jy > 1300 && $jy < 1500) {
                    return wcip_jalali_to_gregorian($jy, $jm, $jd);
                }
            }

            // Try as Gregorian.
            $timestamp = strtotime($input);
            if ($timestamp !== false) {
                return date('Y-m-d', $timestamp);
            }

            return $fallback;
        }

        /**
         * Renders the admin page — either the list table or the edit form.
         */
        public function render_page()
        {
            if (!current_user_can('manage_woocommerce') && !current_user_can('manage_options')) {
                echo '<p>' . esc_html__('شما اجازه دسترسی به این صفحه را ندارید.', 'wc-installment') . '</p>';
                return;
            }

            $action = isset($_GET['action']) ? sanitize_text_field(wp_unslash($_GET['action'])) : '';

            if ($action === 'edit' && isset($_GET['installment_id'])) {
                $this->render_edit_form();
            } else {
                $this->render_list_table();
            }
        }

        /**
         * Renders the installments list table.
         */
        protected function render_list_table()
        {
            $list_table = new WCIP_Installments_List_Table();
            $list_table->prepare_items();
            ?>
            <div class="wrap wcip-admin-installments-wrap">
                <h1><?php esc_html_e('مدیریت اقساط', 'wc-installment'); ?></h1>

                <form method="get" action="">
                    <input type="hidden" name="page" value="wcip-installments" />
                    <?php
                    $list_table->search_box(__('جستجو', 'wc-installment'), 'installment');
                    $list_table->display();
                    ?>
                </form>
            </div>
            <?php
        }

        /**
         * Renders the edit form for a single installment.
         */
        protected function render_edit_form()
        {
            $installment_id = absint($_GET['installment_id']);
            if ($installment_id <= 0) {
                echo '<p>' . esc_html__('شناسه قسط نامعتبر است.', 'wc-installment') . '</p>';
                return;
            }

            if (!isset($_GET['wcip_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['wcip_nonce'])), 'wcip_edit_installment_' . $installment_id)) {
                echo '<p>' . esc_html__('خطای امنیتی.', 'wc-installment') . '</p>';
                return;
            }

            $db = WCIP_DB::instance();
            $installment = $db->get_installment($installment_id);
            if (!$installment) {
                echo '<p>' . esc_html__('قسط یافت نشد.', 'wc-installment') . '</p>';
                return;
            }

            // Get related data for display.
            $customer_name = __('کاربر', 'wc-installment');
            if ($installment->user_id > 0) {
                $user = get_userdata($installment->user_id);
                if ($user) {
                    $customer_name = $user->display_name;
                }
            }

            $product_name = '—';
            $product = wc_get_product($installment->product_id);
            if ($product) {
                $product_name = $product->get_name();
            }

            $due_date_jalali = wcip_gregorian_to_jalali($installment->due_date, 'Y/m/d');
            $paid_date_jalali = !empty($installment->paid_date) ? wcip_gregorian_to_jalali(substr($installment->paid_date, 0, 10), 'Y/m/d') : '—';
            ?>
            <div class="wrap wcip-admin-edit-wrap">
                <h1><?php esc_html_e('ویرایش قسط', 'wc-installment'); ?></h1>

                <div class="wcip-edit-info-bar">
                    <p>
                        <strong><?php esc_html_e('مشتری:', 'wc-installment'); ?></strong>
                        <?php echo esc_html($customer_name); ?>
                    </p>
                    <p>
                        <strong><?php esc_html_e('سفارش:', 'wc-installment'); ?></strong>
                        <a href="<?php echo esc_url(admin_url('post.php?post=' . $installment->order_id . '&action=edit')); ?>">
                            #<?php echo esc_html(number_to_persian($installment->order_id)); ?>
                        </a>
                    </p>
                    <p>
                        <strong><?php esc_html_e('محصول:', 'wc-installment'); ?></strong>
                        <?php echo esc_html($product_name); ?>
                    </p>
                    <p>
                        <strong><?php esc_html_e('شماره قسط:', 'wc-installment'); ?></strong>
                        <?php echo esc_html(number_to_persian($installment->installment_number) . ' ' . __('از', 'wc-installment') . ' ' . number_to_persian($installment->total_installments)); ?>
                    </p>
                    <p>
                        <strong><?php esc_html_e('تاریخ پرداخت:', 'wc-installment'); ?></strong>
                        <?php echo esc_html($paid_date_jalali); ?>
                    </p>
                    <?php if (!empty($installment->transaction_id)) : ?>
                    <p>
                        <strong><?php esc_html_e('کد تراکنش:', 'wc-installment'); ?></strong>
                        <?php echo esc_html($installment->transaction_id); ?>
                    </p>
                    <?php endif; ?>
                </div>

                <form method="post" action="" class="wcip-edit-form">
                    <?php wp_nonce_field('wcip_edit_installment', 'wcip_edit_nonce'); ?>
                    <input type="hidden" name="wcip_edit_installment" value="1" />
                    <input type="hidden" name="installment_id" value="<?php echo esc_attr($installment_id); ?>" />

                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="due_date"><?php esc_html_e('تاریخ سررسید (شمسی)', 'wc-installment'); ?></label>
                            </th>
                            <td>
                                <input type="text" name="due_date" id="due_date" value="<?php echo esc_attr($due_date_jalali); ?>" class="regular-text wcip-jalali-input" placeholder="<?php esc_attr_e('مثال: ۱۴۰۳/۰۶/۱۵', 'wc-installment'); ?>" />
                                <p class="description"><?php esc_html_e('تاریخ را به‌صورت شمسی (سال/ماه/روز) وارد کنید.', 'wc-installment'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="amount"><?php esc_html_e('مبلغ قسط (تومان)', 'wc-installment'); ?></label>
                            </th>
                            <td>
                                <input type="text" name="amount" id="amount" value="<?php echo esc_attr(number_format(floatval($installment->amount), 0, '.', ',')); ?>" class="regular-text" />
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="status"><?php esc_html_e('وضعیت', 'wc-installment'); ?></label>
                            </th>
                            <td>
                                <select name="status" id="status">
                                    <option value="unpaid" <?php selected($installment->status, 'unpaid'); ?>><?php esc_html_e('پرداخت‌نشده', 'wc-installment'); ?></option>
                                    <option value="paid" <?php selected($installment->status, 'paid'); ?>><?php esc_html_e('پرداخت‌شده', 'wc-installment'); ?></option>
                                    <option value="overdue" <?php selected($installment->status, 'overdue'); ?>><?php esc_html_e('معوق', 'wc-installment'); ?></option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="admin_notes"><?php esc_html_e('یادداشت مدیر', 'wc-installment'); ?></label>
                            </th>
                            <td>
                                <textarea name="admin_notes" id="admin_notes" rows="4" cols="50" class="large-text"><?php echo esc_textarea($installment->admin_notes); ?></textarea>
                            </td>
                        </tr>
                    </table>

                    <p class="submit">
                        <button type="submit" class="button button-primary"><?php esc_html_e('ذخیره تغییرات', 'wc-installment'); ?></button>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=wcip-installments')); ?>" class="button button-secondary"><?php esc_html_e('بازگشت', 'wc-installment'); ?></a>
                    </p>
                </form>
            </div>
            <?php
        }
    }
}
