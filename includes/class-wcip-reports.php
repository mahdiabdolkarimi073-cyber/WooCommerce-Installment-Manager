<?php
/**
 * Admin Reports Dashboard — 8 report types with filtering, sorting, and period comparison.
 *
 * @package WC_Installment_Payment
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('WCIP_Reports')) {

    class WCIP_Reports
    {
        /**
         * @var WCIP_Reports|null
         */
        private static $instance = null;

        /**
         * Available report types.
         *
         * @var array
         */
        private $report_types = array();

        /**
         * Singleton instance.
         *
         * @return WCIP_Reports
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
            add_action('admin_init', array($this, 'handle_comparison'));

            $this->report_types = array(
                'total_sales'        => __('مجموع فروش اقساطی', 'wc-installment'),
                'down_payments'      => __('مجموع پیش‌پرداخت‌ها', 'wc-installment'),
                'received_amounts'   => __('مبالغ دریافتی', 'wc-installment'),
                'remaining_balances' => __('مبالغ باقی‌مانده', 'wc-installment'),
                'overdue_installments' => __('اقساط معوق', 'wc-installment'),
                'active_contracts'   => __('قراردادهای فعال', 'wc-installment'),
                'settled_contracts'  => __('قراردادهای تسویه‌شده', 'wc-installment'),
                'comparison'         => __('مقایسه دوره‌ای', 'wc-installment'),
            );
        }

        /**
         * Adds the Reports submenu under WooCommerce.
         */
        public function add_admin_menu()
        {
            add_submenu_page(
                'woocommerce',
                __('گزارشات و تسویه', 'wc-installment'),
                __('گزارشات و تسویه', 'wc-installment'),
                'manage_woocommerce',
                'wcip-reports',
                array($this, 'render_page')
            );
        }

        /**
         * Renders the reports dashboard page.
         */
        public function render_page()
        {
            if (!current_user_can('manage_woocommerce') && !current_user_can('manage_options')) {
                echo '<p>' . esc_html__('شما اجازه دسترسی به این صفحه را ندارید.', 'wc-installment') . '</p>';
                return;
            }

            $current_tab = isset($_GET['tab']) ? sanitize_text_field(wp_unslash($_GET['tab'])) : 'total_sales';
            if (!array_key_exists($current_tab, $this->report_types)) {
                $current_tab = 'total_sales';
            }
            ?>
            <div class="wrap wcip-reports-wrap">
                <h1><?php esc_html_e('گزارشات و تسویه', 'wc-installment'); ?></h1>

                <!-- Report type tabs -->
                <h2 class="nav-tab-wrapper wcip-report-tabs">
                    <?php foreach ($this->report_types as $key => $label) : ?>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=wcip-reports&tab=' . $key)); ?>"
                           class="nav-tab <?php echo $current_tab === $key ? 'nav-tab-active' : ''; ?>">
                            <?php echo esc_html($label); ?>
                        </a>
                    <?php endforeach; ?>
                </h2>

                <?php
                if ($current_tab === 'comparison') {
                    $this->render_comparison();
                } else {
                    $this->render_report_tab($current_tab);
                }
                ?>
            </div>
            <?php
        }

        /**
         * Renders a single report tab with summary card + table.
         *
         * @param string $report_type Report type key.
         */
        protected function render_report_tab($report_type)
        {
            $db = WCIP_DB::instance();

            $filter_args = array(
                'date_from'     => isset($_GET['date_from']) ? sanitize_text_field(wp_unslash($_GET['date_from'])) : '',
                'date_to'       => isset($_GET['date_to']) ? sanitize_text_field(wp_unslash($_GET['date_to'])) : '',
                'customer_id'   => isset($_GET['customer_id']) ? absint($_GET['customer_id']) : 0,
                'status_filter' => isset($_GET['status_filter']) ? sanitize_text_field(wp_unslash($_GET['status_filter'])) : '',
            );

            $summary = $db->get_report_summary($report_type, $filter_args);
            $total = floatval($summary->total);
            $count = (int) $summary->count;
            ?>
            <!-- Summary card -->
            <div class="wcip-report-summary-card">
                <?php if ($report_type === 'active_contracts' || $report_type === 'settled_contracts') : ?>
                    <div class="wcip-summary-item">
                        <span class="wcip-summary-label"><?php esc_html_e('تعداد قراردادها:', 'wc-installment'); ?></span>
                        <span class="wcip-summary-value"><?php echo esc_html(number_to_persian($count)); ?></span>
                    </div>
                <?php else : ?>
                    <div class="wcip-summary-item">
                        <span class="wcip-summary-label"><?php esc_html_e('مجموع مبالغ:', 'wc-installment'); ?></span>
                        <span class="wcip-summary-value"><?php echo esc_html(wcip_format_toman($total)); ?></span>
                    </div>
                    <div class="wcip-summary-item">
                        <span class="wcip-summary-label"><?php esc_html_e('تعداد رکوردها:', 'wc-installment'); ?></span>
                        <span class="wcip-summary-value"><?php echo esc_html(number_to_persian($count)); ?></span>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Report table -->
            <form method="get" action="">
                <input type="hidden" name="page" value="wcip-reports" />
                <input type="hidden" name="tab" value="<?php echo esc_attr($report_type); ?>" />
                <?php
                $list_table = new WCIP_Reports_List_Table($report_type);
                $list_table->prepare_items();
                $list_table->display();
                ?>
            </form>
            <?php
        }

        /**
         * Handles the period comparison form submission.
         */
        public function handle_comparison()
        {
            // No server-side action needed; comparison is rendered from GET params.
        }

        /**
         * Renders the period comparison view.
         */
        protected function render_comparison()
        {
            $from1 = isset($_GET['from1']) ? sanitize_text_field(wp_unslash($_GET['from1'])) : '';
            $to1   = isset($_GET['to1']) ? sanitize_text_field(wp_unslash($_GET['to1'])) : '';
            $from2 = isset($_GET['from2']) ? sanitize_text_field(wp_unslash($_GET['from2'])) : '';
            $to2   = isset($_GET['to2']) ? sanitize_text_field(wp_unslash($_GET['to2'])) : '';
            $metric = isset($_GET['metric']) ? sanitize_text_field(wp_unslash($_GET['metric'])) : 'total_sales';

            if (!array_key_exists($metric, $this->report_types) || $metric === 'comparison') {
                $metric = 'total_sales';
            }

            $has_data = !empty($from1) && !empty($to1) && !empty($from2) && !empty($to2);
            $comparison = null;
            if ($has_data) {
                $db = WCIP_DB::instance();
                $comparison = $db->get_period_comparison($metric, $from1, $to1, $from2, $to2);
            }
            ?>
            <div class="wcip-comparison-form">
                <form method="get" action="">
                    <input type="hidden" name="page" value="wcip-reports" />
                    <input type="hidden" name="tab" value="comparison" />

                    <div class="wcip-comparison-field">
                        <label for="metric"><?php esc_html_e('معیار مقایسه:', 'wc-installment'); ?></label>
                        <select name="metric" id="metric">
                            <?php foreach ($this->report_types as $key => $label) : ?>
                                <?php if ($key === 'comparison') continue; ?>
                                <option value="<?php echo esc_attr($key); ?>" <?php selected($metric, $key); ?>>
                                    <?php echo esc_html($label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="wcip-comparison-periods">
                        <div class="wcip-comparison-period">
                            <h4><?php esc_html_e('دوره اول', 'wc-installment'); ?></h4>
                            <input type="date" name="from1" value="<?php echo esc_attr($from1); ?>" placeholder="<?php esc_attr_e('از تاریخ', 'wc-installment'); ?>" />
                            <input type="date" name="to1" value="<?php echo esc_attr($to1); ?>" placeholder="<?php esc_attr_e('تا تاریخ', 'wc-installment'); ?>" />
                        </div>
                        <div class="wcip-comparison-period">
                            <h4><?php esc_html_e('دوره دوم', 'wc-installment'); ?></h4>
                            <input type="date" name="from2" value="<?php echo esc_attr($from2); ?>" placeholder="<?php esc_attr_e('از تاریخ', 'wc-installment'); ?>" />
                            <input type="date" name="to2" value="<?php echo esc_attr($to2); ?>" placeholder="<?php esc_attr_e('تا تاریخ', 'wc-installment'); ?>" />
                        </div>
                    </div>

                    <?php submit_button(__('مقایسه', 'wc-installment')); ?>
                </form>
            </div>

            <?php if ($comparison) : ?>
                <div class="wcip-comparison-results">
                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('دوره', 'wc-installment'); ?></th>
                                <th><?php esc_html_e('بازه تاریخ', 'wc-installment'); ?></th>
                                <th><?php esc_html_e('مجموع', 'wc-installment'); ?></th>
                                <th><?php esc_html_e('تعداد', 'wc-installment'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><?php esc_html_e('دوره اول', 'wc-installment'); ?></td>
                                <td><?php echo esc_html(wcip_gregorian_to_jalali($comparison['period1']['from']) . ' — ' . wcip_gregorian_to_jalali($comparison['period1']['to'])); ?></td>
                                <td><?php echo esc_html(wcip_format_toman($comparison['period1']['total'])); ?></td>
                                <td><?php echo esc_html(number_to_persian($comparison['period1']['count'])); ?></td>
                            </tr>
                            <tr>
                                <td><?php esc_html_e('دوره دوم', 'wc-installment'); ?></td>
                                <td><?php echo esc_html(wcip_gregorian_to_jalali($comparison['period2']['from']) . ' — ' . wcip_gregorian_to_jalali($comparison['period2']['to'])); ?></td>
                                <td><?php echo esc_html(wcip_format_toman($comparison['period2']['total'])); ?></td>
                                <td><?php echo esc_html(number_to_persian($comparison['period2']['count'])); ?></td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr class="wcip-comparison-diff-row">
                                <td colspan="2"><strong><?php esc_html_e('اختلاف:', 'wc-installment'); ?></strong></td>
                                <td>
                                    <?php
                                    $diff = $comparison['diff'];
                                    $sign = $diff >= 0 ? '+' : '';
                                    echo esc_html($sign . wcip_format_toman(abs($diff)));
                                    ?>
                                </td>
                                <td>
                                    <?php
                                    $pct = $comparison['pct_change'];
                                    $pct_sign = $pct >= 0 ? '+' : '';
                                    echo esc_html($pct_sign . number_to_persian(number_format(abs($pct), 1)) . '٪');
                                    ?>
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            <?php elseif ($has_data) : ?>
                <p class="wcip-no-data"><?php esc_html_e('داده‌ای برای مقایسه یافت نشد.', 'wc-installment'); ?></p>
            <?php endif; ?>
            <?php
        }
    }
}
