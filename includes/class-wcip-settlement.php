<?php
/**
 * Early Settlement — customer request flow + admin approval with optional discount.
 *
 * @package WC_Installment_Payment
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('WCIP_Settlement')) {

    class WCIP_Settlement
    {
        /**
         * @var WCIP_Settlement|null
         */
        private static $instance = null;

        /**
         * Singleton instance.
         *
         * @return WCIP_Settlement
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
            // Admin: settlement requests management submenu.
            add_action('admin_menu', array($this, 'add_admin_menu'));
            add_action('admin_init', array($this, 'handle_admin_actions'));

            // Customer: My Account endpoint for contracts & early settlement.
            add_action('init', array($this, 'add_settlement_endpoint'));
            add_filter('query_vars', array($this, 'add_query_vars'));
            add_filter('woocommerce_account_menu_items', array($this, 'add_account_menu_item'));
            add_action('woocommerce_account_wcip-settlement_endpoint', array($this, 'render_customer_settlement_page'));

            // Customer: handle settlement request submission.
            add_action('template_redirect', array($this, 'handle_settlement_request'));

            // AJAX: customer submits early settlement request.
            add_action('wp_ajax_wcip_submit_settlement', array($this, 'ajax_submit_settlement'));
        }

        // ===== Admin Menu =====

        /**
         * Adds the settlement requests submenu under WooCommerce.
         */
        public function add_admin_menu()
        {
            add_submenu_page(
                'woocommerce',
                __('درخواست‌های تسویه زودهنگام', 'wc-installment'),
                __('درخواست‌های تسویه', 'wc-installment'),
                'manage_woocommerce',
                'wcip-settlement-requests',
                array($this, 'render_admin_page')
            );
        }

        /**
         * Adds the settlement endpoint to WooCommerce My Account.
         */
        public function add_settlement_endpoint()
        {
            add_rewrite_endpoint('wcip-settlement', EP_ROOT | EP_PAGES);
        }

        /**
         * Registers custom query variables.
         *
         * @param array $vars Existing query vars.
         * @return array
         */
        public function add_query_vars($vars)
        {
            $vars[] = 'wcip-settlement';
            return $vars;
        }

        /**
         * Adds the "تسویه زودهنگام" menu item to the WooCommerce My Account menu.
         *
         * @param array $items Existing menu items.
         * @return array
         */
        public function add_account_menu_item($items)
        {
            $new_items = array();
            $inserted = false;

            foreach ($items as $key => $label) {
                $new_items[$key] = $label;
                if ($key === 'wcip-installments' && !$inserted) {
                    $new_items['wcip-settlement'] = __('تسویه زودهنگام', 'wc-installment');
                    $inserted = true;
                }
            }

            if (!$inserted) {
                $new_items['wcip-settlement'] = __('تسویه زودهنگام', 'wc-installment');
            }

            return $new_items;
        }

        // ===== Admin Actions =====

        /**
         * Handles admin approve/reject actions for settlement requests.
         */
        public function handle_admin_actions()
        {
            if (!current_user_can('manage_woocommerce') && !current_user_can('manage_options')) {
                return;
            }

            if (isset($_POST['wcip_settlement_action']) && isset($_POST['settlement_id']) && isset($_POST['wcip_settlement_nonce'])) {
                $this->handle_admin_decision();
            }
        }

        /**
         * Processes the admin's approve/reject decision with optional discount.
         */
        protected function handle_admin_decision()
        {
            if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['wcip_settlement_nonce'])), 'wcip_settlement_decision')) {
                add_action('admin_notices', function () {
                    echo '<div class="notice notice-error"><p>' . esc_html__('خطای امنیتی. عملیات انجام نشد.', 'wc-installment') . '</p></div>';
                });
                return;
            }

            $settlement_id = absint($_POST['settlement_id']);
            if ($settlement_id <= 0) {
                return;
            }

            $action = sanitize_text_field(wp_unslash($_POST['wcip_settlement_action']));
            $db = WCIP_DB::instance();
            $request = $db->get_settlement_request($settlement_id);
            if (!$request || $request->status !== 'pending') {
                add_action('admin_notices', function () {
                    echo '<div class="notice notice-error"><p>' . esc_html__('این درخواست قبلاً بررسی شده است.', 'wc-installment') . '</p></div>';
                });
                return;
            }

            $admin_note = isset($_POST['admin_note']) ? sanitize_textarea_field(wp_unslash($_POST['admin_note'])) : '';

            if ($action === 'approve') {
                // Parse discount: either fixed amount or percentage.
                $discount_type = isset($_POST['discount_type']) ? sanitize_text_field(wp_unslash($_POST['discount_type'])) : 'amount';
                $discount_value = isset($_POST['discount_value']) ? floatval(preg_replace('/[^0-9.]/', '', sanitize_text_field(wp_unslash($_POST['discount_value'])))) : 0;

                $remaining = floatval($request->remaining_amount);
                $discount_amount = 0;

                if ($discount_type === 'percentage') {
                    $discount_amount = ($remaining * $discount_value) / 100;
                } else {
                    $discount_amount = $discount_value;
                }

                if ($discount_amount < 0) {
                    $discount_amount = 0;
                }
                if ($discount_amount > $remaining) {
                    $discount_amount = $remaining;
                }

                $final_amount = $remaining - $discount_amount;

                // Update the settlement request.
                $db->update_settlement_request($settlement_id, array(
                    'discount_amount' => $discount_amount,
                    'final_amount'    => $final_amount,
                    'status'         => 'approved',
                    'admin_id'       => get_current_user_id(),
                    'admin_note'     => $admin_note,
                    'reviewed_at'    => current_time('mysql'),
                ));

                // Mark remaining installments as paid (settled).
                $db->settle_order_installments($request->order_id);

                add_action('admin_notices', function () {
                    echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('درخواست تسویه تأیید شد و قرارداد تسویه گردید.', 'wc-installment') . '</p></div>';
                });
            } elseif ($action === 'reject') {
                $db->update_settlement_request($settlement_id, array(
                    'status'      => 'rejected',
                    'admin_id'    => get_current_user_id(),
                    'admin_note'  => $admin_note,
                    'reviewed_at' => current_time('mysql'),
                ));

                add_action('admin_notices', function () {
                    echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__('درخواست تسویه رد شد.', 'wc-installment') . '</p></div>';
                });
            }
        }

        // ===== Admin Page Rendering =====

        /**
         * Renders the admin settlement requests page.
         */
        public function render_admin_page()
        {
            if (!current_user_can('manage_woocommerce') && !current_user_can('manage_options')) {
                echo '<p>' . esc_html__('شما اجازه دسترسی به این صفحه را ندارید.', 'wc-installment') . '</p>';
                return;
            }

            $action = isset($_GET['action']) ? sanitize_text_field(wp_unslash($_GET['action'])) : '';
            $settlement_id = isset($_GET['settlement_id']) ? absint($_GET['settlement_id']) : 0;

            if ($action === 'review' && $settlement_id > 0) {
                $this->render_review_form($settlement_id);
            } else {
                $this->render_requests_list();
            }
        }

        /**
         * Renders the list of settlement requests.
         */
        protected function render_requests_list()
        {
            $status_filter = isset($_GET['status_filter']) ? sanitize_text_field(wp_unslash($_GET['status_filter'])) : '';
            $db = WCIP_DB::instance();

            $args = array(
                'status'   => $status_filter,
                'per_page' => 20,
                'page'     => isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1,
            );

            $requests = $db->get_settlement_requests($args);
            $total = $db->count_settlement_requests(array('status' => $status_filter));
            $per_page = 20;
            $total_pages = (int) ceil($total / $per_page);
            $current_page = $args['page'];
            ?>
            <div class="wrap wcip-settlement-admin-wrap">
                <h1><?php esc_html_e('درخواست‌های تسویه زودهنگام', 'wc-installment'); ?></h1>

                <div class="tablenav top">
                    <div class="alignleft actions">
                        <form method="get" action="">
                            <input type="hidden" name="page" value="wcip-settlement-requests" />
                            <select name="status_filter">
                                <option value=""><?php esc_html_e('همه وضعیت‌ها', 'wc-installment'); ?></option>
                                <option value="pending" <?php selected($status_filter, 'pending'); ?>><?php esc_html_e('در انتظار بررسی', 'wc-installment'); ?></option>
                                <option value="approved" <?php selected($status_filter, 'approved'); ?>><?php esc_html_e('تأیید شده', 'wc-installment'); ?></option>
                                <option value="rejected" <?php selected($status_filter, 'rejected'); ?>><?php esc_html_e('رد شده', 'wc-installment'); ?></option>
                            </select>
                            <?php submit_button(__('فیلتر', 'wc-installment'), '', 'filter_action', false); ?>
                        </form>
                    </div>
                </div>

                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('شناسه', 'wc-installment'); ?></th>
                            <th><?php esc_html_e('مشتری', 'wc-installment'); ?></th>
                            <th><?php esc_html_e('شماره سفارش', 'wc-installment'); ?></th>
                            <th><?php esc_html_e('مبلغ باقی‌مانده', 'wc-installment'); ?></th>
                            <th><?php esc_html_e('وضعیت', 'wc-installment'); ?></th>
                            <th><?php esc_html_e('تاریخ درخواست', 'wc-installment'); ?></th>
                            <th><?php esc_html_e('عملیات', 'wc-installment'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($requests)) : ?>
                            <tr><td colspan="7"><?php esc_html_e('درخواستی وجود ندارد.', 'wc-installment'); ?></td></tr>
                        <?php else : foreach ($requests as $req) : ?>
                            <tr>
                                <td><?php echo esc_html(number_to_persian($req->id)); ?></td>
                                <td><?php echo esc_html($req->customer_name ?: __('کاربر', 'wc-installment')); ?></td>
                                <td>
                                    <a href="<?php echo esc_url(admin_url('post.php?post=' . $req->order_id . '&action=edit')); ?>">
                                        #<?php echo esc_html(number_to_persian($req->order_id)); ?>
                                    </a>
                                </td>
                                <td><?php echo esc_html(wcip_format_toman($req->remaining_amount)); ?></td>
                                <td>
                                    <?php echo $this->render_settlement_status_badge($req->status); // phpcs:ignore ?>
                                </td>
                                <td><?php echo esc_html(wcip_gregorian_to_jalali(substr($req->requested_at, 0, 10))); ?></td>
                                <td>
                                    <?php if ($req->status === 'pending') : ?>
                                        <a href="<?php echo esc_url(admin_url('admin.php?page=wcip-settlement-requests&action=review&settlement_id=' . $req->id)); ?>"
                                           class="button button-primary">
                                            <?php esc_html_e('بررسی', 'wc-installment'); ?>
                                        </a>
                                    <?php else : ?>
                                        <a href="<?php echo esc_url(admin_url('admin.php?page=wcip-settlement-requests&action=review&settlement_id=' . $req->id)); ?>"
                                           class="button button-secondary">
                                            <?php esc_html_e('مشاهده', 'wc-installment'); ?>
                                        </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>

                <?php if ($total_pages > 1) : ?>
                    <div class="tablenav bottom">
                        <div class="tablenav-pages">
                            <?php
                            echo paginate_links(array(
                                'base'      => add_query_arg('paged', '%#%'),
                                'format'    => '',
                                'prev_text' => __('«', 'wc-installment'),
                                'next_text' => __('»', 'wc-installment'),
                                'total'     => $total_pages,
                                'current'   => $current_page,
                            ));
                            ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
            <?php
        }

        /**
         * Renders the review form for a single settlement request.
         *
         * @param int $settlement_id Settlement request ID.
         */
        protected function render_review_form($settlement_id)
        {
            $db = WCIP_DB::instance();
            $req = $db->get_settlement_request($settlement_id);
            if (!$req) {
                echo '<p>' . esc_html__('درخواست یافت نشد.', 'wc-installment') . '</p>';
                return;
            }

            $user = get_userdata($req->user_id);
            $customer_name = $user ? $user->display_name : __('کاربر', 'wc-installment');

            $product = wc_get_product($req->product_id);
            $product_name = $product ? $product->get_name() : '—';

            $remaining = floatval($req->remaining_amount);
            $is_pending = $req->status === 'pending';
            ?>
            <div class="wrap wcip-settlement-review-wrap">
                <h1><?php esc_html_e('بررسی درخواست تسویه زودهنگام', 'wc-installment'); ?></h1>

                <div class="wcip-settlement-info-bar">
                    <p><strong><?php esc_html_e('مشتری:', 'wc-installment'); ?></strong> <?php echo esc_html($customer_name); ?></p>
                    <p><strong><?php esc_html_e('شماره سفارش:', 'wc-installment'); ?></strong>
                        <a href="<?php echo esc_url(admin_url('post.php?post=' . $req->order_id . '&action=edit')); ?>">
                            #<?php echo esc_html(number_to_persian($req->order_id)); ?>
                        </a>
                    </p>
                    <p><strong><?php esc_html_e('محصول:', 'wc-installment'); ?></strong> <?php echo esc_html($product_name); ?></p>
                    <p><strong><?php esc_html_e('مبلغ باقی‌مانده:', 'wc-installment'); ?></strong> <?php echo esc_html(wcip_format_toman($remaining)); ?></p>
                    <p><strong><?php esc_html_e('تاریخ درخواست:', 'wc-installment'); ?></strong> <?php echo esc_html(wcip_gregorian_to_jalali(substr($req->requested_at, 0, 10))); ?></p>
                    <p><strong><?php esc_html_e('وضعیت:', 'wc-installment'); ?></strong> <?php echo $this->render_settlement_status_badge($req->status); // phpcs:ignore ?></p>
                    <?php if ($req->status !== 'pending' && !empty($req->reviewed_at)) : ?>
                        <p><strong><?php esc_html_e('تاریخ بررسی:', 'wc-installment'); ?></strong> <?php echo esc_html(wcip_gregorian_to_jalali(substr($req->reviewed_at, 0, 10))); ?></p>
                    <?php endif; ?>
                    <?php if ($req->status === 'approved') : ?>
                        <p><strong><?php esc_html_e('تخفیف اعمال‌شده:', 'wc-installment'); ?></strong> <?php echo esc_html(wcip_format_toman($req->discount_amount)); ?></p>
                        <p><strong><?php esc_html_e('مبلغ نهایی پرداخت:', 'wc-installment'); ?></strong> <?php echo esc_html(wcip_format_toman($req->final_amount)); ?></p>
                    <?php endif; ?>
                    <?php if (!empty($req->admin_note)) : ?>
                        <p><strong><?php esc_html_e('یادداشت مدیر:', 'wc-installment'); ?></strong> <?php echo esc_html($req->admin_note); ?></p>
                    <?php endif; ?>
                </div>

                <?php if ($is_pending) : ?>
                <form method="post" action="" class="wcip-settlement-decision-form">
                    <?php wp_nonce_field('wcip_settlement_decision', 'wcip_settlement_nonce'); ?>
                    <input type="hidden" name="settlement_id" value="<?php echo esc_attr($settlement_id); ?>" />

                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="discount_type"><?php esc_html_e('نوع تخفیف', 'wc-installment'); ?></label>
                            </th>
                            <td>
                                <select name="discount_type" id="discount_type">
                                    <option value="amount"><?php esc_html_e('مبلغ ثابت (تومان)', 'wc-installment'); ?></option>
                                    <option value="percentage"><?php esc_html_e('درصد', 'wc-installment'); ?></option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="discount_value"><?php esc_html_e('مقدار تخفیف', 'wc-installment'); ?></label>
                            </th>
                            <td>
                                <input type="text" name="discount_value" id="discount_value" value="0" class="regular-text" />
                                <p class="description"><?php esc_html_e('مقدار تخفیف را وارد کنید (۰ برای عدم تخفیف).', 'wc-installment'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="admin_note"><?php esc_html_e('یادداشت (اختیاری)', 'wc-installment'); ?></label>
                            </th>
                            <td>
                                <textarea name="admin_note" id="admin_note" rows="4" cols="50" class="large-text"></textarea>
                            </td>
                        </tr>
                    </table>

                    <p class="submit">
                        <button type="submit" name="wcip_settlement_action" value="approve" class="button button-primary">
                            <?php esc_html_e('تأیید و تسویه', 'wc-installment'); ?>
                        </button>
                        <button type="submit" name="wcip_settlement_action" value="reject" class="button button-secondary">
                            <?php esc_html_e('رد درخواست', 'wc-installment'); ?>
                        </button>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=wcip-settlement-requests')); ?>" class="button">
                            <?php esc_html_e('بازگشت', 'wc-installment'); ?>
                        </a>
                    </p>
                </form>
                <?php else : ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=wcip-settlement-requests')); ?>" class="button button-secondary">
                        <?php esc_html_e('بازگشت', 'wc-installment'); ?>
                    </a>
                <?php endif; ?>
            </div>
            <?php
        }

        /**
         * Renders a status badge for a settlement request.
         *
         * @param string $status Settlement status.
         * @return string HTML badge.
         */
        protected function render_settlement_status_badge($status)
        {
            $labels = array(
                'pending'  => __('در انتظار بررسی', 'wc-installment'),
                'approved' => __('تأیید شده', 'wc-installment'),
                'rejected' => __('رد شده', 'wc-installment'),
            );

            $label = isset($labels[$status]) ? $labels[$status] : $status;
            $class = 'wcip-badge wcip-settlement-badge-' . esc_attr($status);

            return sprintf('<span class="%s">%s</span>', esc_attr($class), esc_html($label));
        }

        // ===== Customer-Facing Settlement Page =====

        /**
         * Renders the customer's settlement page on My Account.
         * Shows contracts with remaining balances and early settlement request button.
         */
        public function render_customer_settlement_page()
        {
            if (!is_user_logged_in()) {
                echo '<p>' . esc_html__('لطفاً وارد شوید.', 'wc-installment') . '</p>';
                return;
            }

            $user_id = get_current_user_id();
            $db = WCIP_DB::instance();
            $contracts = $db->get_user_contracts($user_id);

            wp_enqueue_style('wcip-frontend-style', WCIP_PLUGIN_URL . 'assets/css/frontend.css', array(), WCIP_VERSION);
            wp_enqueue_script('wcip-frontend-script', WCIP_PLUGIN_URL . 'assets/js/frontend.js', array('jquery'), WCIP_VERSION, true);

            wp_localize_script('wcip-frontend-script', 'wcipSettlement', array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce'   => wp_create_nonce('wcip-frontend'),
                'i18n'    => array(
                    'confirm'   => __('آیا از درخواست تسویه زودهنگام مطمئن هستید؟', 'wc-installment'),
                    'success'   => __('درخواست شما ثبت شد و در انتظار بررسی مدیر است.', 'wc-installment'),
                    'error'     => __('خطا در ثبت درخواست.', 'wc-installment'),
                    'pending'   => __('در انتظار بررسی', 'wc-installment'),
                    'requesting'=> __('در حال ارسال...', 'wc-installment'),
                ),
            ));
            ?>
            <div class="wcip-account-settlement" id="wcip-account-settlement">
                <h2><?php esc_html_e('تسویه زودهنگام', 'wc-installment'); ?></h2>

                <?php if (empty($contracts)) : ?>
                    <div class="wcip-empty-state">
                        <p><?php esc_html_e('ش هیچ قرارداد اقساطی فعالی ندارید.', 'wc-installment'); ?></p>
                    </div>
                <?php else : ?>
                    <div class="wcip-settlement-contracts">
                        <?php foreach ($contracts as $contract) :
                            $remaining = floatval($contract->remaining_amount);
                            $has_pending = $db->has_pending_settlement_request($contract->order_id);
                            $product = wc_get_product($contract->product_id);
                            $product_name = $product ? $product->get_name() : '—';

                            // Check if contract is fully settled.
                            $is_settled = ($remaining <= 0);
                        ?>
                            <div class="wcip-settlement-card <?php echo $is_settled ? 'wcip-settlement-card-settled' : ''; ?>"
                                 data-order-id="<?php echo esc_attr($contract->order_id); ?>">

                                <div class="wcip-card-header">
                                    <span class="wcip-card-title">
                                        <?php esc_html_e('قرارداد سفارش', 'wc-installment'); ?>
                                        #<?php echo esc_html(number_to_persian($contract->order_id)); ?>
                                    </span>
                                    <?php if ($is_settled) : ?>
                                        <span class="wcip-status-badge wcip-status-paid"><?php esc_html_e('تسویه شده', 'wc-installment'); ?></span>
                                    <?php elseif ($has_pending) : ?>
                                        <span class="wcip-status-badge wcip-settlement-badge-pending"><?php esc_html_e('درخواست ثبت شده', 'wc-installment'); ?></span>
                                    <?php endif; ?>
                                </div>

                                <div class="wcip-card-body">
                                    <div class="wcip-card-row">
                                        <span class="wcip-card-label"><?php esc_html_e('محصول', 'wc-installment'); ?></span>
                                        <span class="wcip-card-value"><?php echo esc_html($product_name); ?></span>
                                    </div>
                                    <div class="wcip-card-row">
                                        <span class="wcip-card-label"><?php esc_html_e('مجموع مبلغ قرارداد', 'wc-installment'); ?></span>
                                        <span class="wcip-card-value"><?php echo esc_html(wcip_format_toman($contract->total_amount)); ?></span>
                                    </div>
                                    <div class="wcip-card-row">
                                        <span class="wcip-card-label"><?php esc_html_e('پرداخت‌شده', 'wc-installment'); ?></span>
                                        <span class="wcip-card-value"><?php echo esc_html(wcip_format_toman($contract->paid_amount)); ?></span>
                                    </div>
                                    <div class="wcip-card-row">
                                        <span class="wcip-card-label"><?php esc_html_e('باقی‌مانده', 'wc-installment'); ?></span>
                                        <span class="wcip-card-value wcip-remaining-amount"><?php echo esc_html(wcip_format_toman($remaining)); ?></span>
                                    </div>
                                    <div class="wcip-card-row">
                                        <span class="wcip-card-label"><?php esc_html_e('تعداد اقساط', 'wc-installment'); ?></span>
                                        <span class="wcip-card-value"><?php echo esc_html(number_to_persian($contract->total_installments)); ?></span>
                                    </div>
                                </div>

                                <?php if (!$is_settled && !$has_pending) : ?>
                                    <div class="wcip-card-actions">
                                        <button type="button" class="button wcip-settlement-btn"
                                                data-order-id="<?php echo esc_attr($contract->order_id); ?>"
                                                data-remaining="<?php echo esc_attr($remaining); ?>">
                                            <?php esc_html_e('درخواست تسویه زودهنگام', 'wc-installment'); ?>
                                        </button>
                                    </div>
                                <?php endif; ?>

                                <div class="wcip-card-status-msg" style="display:none;"></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            <?php
        }

        /**
         * Handles the settlement request form submission from the My Account page.
         */
        public function handle_settlement_request()
        {
            if (!isset($_POST['wcip_request_settlement']) || !isset($_POST['order_id'])) {
                return;
            }

            if (!is_user_logged_in()) {
                return;
            }

            if (!isset($_POST['wcip_settlement_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['wcip_settlement_nonce'])), 'wcip_request_settlement')) {
                wc_add_notice(__('خطای امنیتی. لطفاً دوباره تلاش کنید.', 'wc-installment'), 'error');
                return;
            }

            $order_id = absint($_POST['order_id']);
            $user_id = get_current_user_id();

            $this->create_settlement_request($order_id, $user_id);
        }

        /**
         * AJAX handler — customer submits an early settlement request.
         */
        public function ajax_submit_settlement()
        {
            check_ajax_referer('wcip-frontend', 'nonce');

            if (!is_user_logged_in()) {
                wp_send_json_error(array('message' => __('لطفاً وارد شوید.', 'wc-installment')));
            }

            $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
            if ($order_id <= 0) {
                wp_send_json_error(array('message' => __('شناسه سفارش نامعتبر.', 'wc-installment')));
            }

            $result = $this->create_settlement_request($order_id, get_current_user_id());

            if (is_wp_error($result)) {
                wp_send_json_error(array('message' => $result->get_error_message()));
            }

            wp_send_json_success(array(
                'message' => __('درخواست شما ثبت شد و در انتظار بررسی مدیر است.', 'wc-installment'),
            ));
        }

        /**
         * Creates a settlement request after validating ownership and remaining balance.
         *
         * @param int $order_id Order ID.
         * @param int $user_id User ID.
         * @return int|WP_Error Settlement request ID or WP_Error.
         */
        protected function create_settlement_request($order_id, $user_id)
        {
            $order_id = absint($order_id);
            $user_id = absint($user_id);
            $db = WCIP_DB::instance();

            // Verify the order belongs to this user.
            $order = wc_get_order($order_id);
            if (!$order || (int) $order->get_customer_id() !== $user_id) {
                return new WP_Error('wcip_invalid_order', __('این سفارش متعلق به شما نیست.', 'wc-installment'));
            }

            // Check for existing pending request.
            if ($db->has_pending_settlement_request($order_id)) {
                return new WP_Error('wcip_pending_exists', __('شما قبلاً برای این سفارش درخواست تسویه ثبت کرده‌اید.', 'wc-installment'));
            }

            // Calculate remaining balance.
            $remaining = $db->get_order_remaining_balance($order_id);
            if ($remaining <= 0) {
                return new WP_Error('wcip_no_balance', __('مبلغ باقی‌مانده برای این سفارش صفر است.', 'wc-installment'));
            }

            // Get product_id from the first installment of this order.
            $installments = $db->get_order_installments($order_id);
            $product_id = !empty($installments) ? (int) $installments[0]->product_id : 0;

            $id = $db->insert_settlement_request(array(
                'order_id'         => $order_id,
                'user_id'          => $user_id,
                'product_id'       => $product_id,
                'remaining_amount' => $remaining,
                'discount_amount'  => 0,
                'final_amount'     => $remaining,
                'status'           => 'pending',
            ));

            if (!$id) {
                return new WP_Error('wcip_insert_failed', __('خطا در ثبت درخواست.', 'wc-installment'));
            }

            return $id;
        }
    }
}
