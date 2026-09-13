<?php
/**
 * Debug & Logs admin page for the installment plugin.
 * Reads WooCommerce log files (source: wc-installment) and shows system info.
 *
 * @package WC_Installment_Payment
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('WCIP_Debug')) {

    class WCIP_Debug
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
            add_action('admin_menu', array($this, 'register_menu'), 20);
            add_action('wp_ajax_wcip_clear_logs', array($this, 'ajax_clear_logs'));
            add_action('wp_ajax_wcip_write_test_log', array($this, 'ajax_write_test_log'));
        }

        public function register_menu()
        {
            add_submenu_page(
                'wcip-dashboard',
                __('دیباگ و لاگ‌ها', 'wc-installment'),
                __('دیباگ و لاگ‌ها', 'wc-installment'),
                'manage_woocommerce',
                'wcip-debug',
                array($this, 'render_page')
            );
        }

        /**
         * Returns all WC log files belonging to this plugin (source: wc-installment).
         *
         * @return array Associative array of [filename => full_path], newest first.
         */
        private function get_log_files()
        {
            $log_dir = defined('WC_LOG_DIR') ? WC_LOG_DIR : (WP_CONTENT_DIR . '/uploads/wc-logs/');
            if (!is_dir($log_dir)) {
                return array();
            }

            $files = glob($log_dir . 'wc-installment-*.log');
            if (!$files) {
                return array();
            }

            rsort($files);
            $result = array();
            foreach ($files as $path) {
                $result[basename($path)] = $path;
            }
            return $result;
        }

        /**
         * Reads the last N lines of a file efficiently.
         *
         * @param string $path   Full path to file.
         * @param int    $lines  Number of lines to return.
         * @return string
         */
        private function tail_file($path, $lines = 200)
        {
            if (!file_exists($path) || !is_readable($path)) {
                return '';
            }

            $fp       = fopen($path, 'rb');
            $buffer   = '';
            $count    = 0;
            $pos      = -1;
            $chunk    = 4096;
            $size     = filesize($path);
            $read     = 0;

            if ($size === 0) {
                fclose($fp);
                return '';
            }

            while ($count < $lines && $read < $size) {
                $seek = max(0, $size - $read - $chunk);
                fseek($fp, $seek);
                $part   = fread($fp, min($chunk, $size - $read));
                $buffer = $part . $buffer;
                $read  += $chunk;
                $count  = substr_count($buffer, "\n");
            }

            fclose($fp);

            $all = explode("\n", $buffer);
            $tail = array_slice($all, -($lines + 1));
            return implode("\n", $tail);
        }

        public function ajax_clear_logs()
        {
            check_ajax_referer('wcip-debug-action', 'nonce');
            if (!current_user_can('manage_woocommerce')) {
                wp_send_json_error();
            }

            $files = $this->get_log_files();
            $cleared = 0;
            foreach ($files as $path) {
                if (@file_put_contents($path, '') !== false) {
                    $cleared++;
                }
            }

            wp_send_json_success(array('cleared' => $cleared));
        }

        public function ajax_write_test_log()
        {
            check_ajax_referer('wcip-debug-action', 'nonce');
            if (!current_user_can('manage_woocommerce')) {
                wp_send_json_error();
            }

            if (function_exists('wcip_debug_log')) {
                wcip_debug_log('Test log entry written from debug page.', array(
                    'time'    => current_time('mysql'),
                    'user_id' => get_current_user_id(),
                ));
                wp_send_json_success();
            } else {
                wp_send_json_error(array('message' => 'wcip_debug_log function not found.'));
            }
        }

        public function render_page()
        {
            if (!current_user_can('manage_woocommerce') && !current_user_can('manage_options')) {
                echo '<p>' . esc_html__('دسترسی مجاز نیست.', 'wc-installment') . '</p>';
                return;
            }

            $nonce    = wp_create_nonce('wcip-debug-action');
            $log_dir  = defined('WC_LOG_DIR') ? WC_LOG_DIR : (WP_CONTENT_DIR . '/uploads/wc-logs/');
            $files    = $this->get_log_files();

            // Which file to view.
            $selected_file = isset($_GET['log_file']) ? sanitize_file_name(wp_unslash($_GET['log_file'])) : '';
            if ($selected_file && !isset($files[$selected_file])) {
                $selected_file = '';
            }
            if (!$selected_file && !empty($files)) {
                $selected_file = array_key_first($files);
            }

            $log_content = '';
            if ($selected_file && isset($files[$selected_file])) {
                $log_content = $this->tail_file($files[$selected_file], 300);
            }

            // System info.
            $global_enabled = get_option('wcip_enabled', 'no');
            $wc_version     = defined('WC_VERSION') ? WC_VERSION : 'نامشخص';
            $wp_version     = get_bloginfo('version');
            $php_version    = PHP_VERSION;
            $plugin_version = defined('WCIP_VERSION') ? WCIP_VERSION : 'نامشخص';
            $log_dir_exists = is_dir($log_dir) ? 'بله' : 'خیر';
            $log_dir_writable = is_writable($log_dir) ? 'بله' : 'خیر';
            $global_plans   = get_option('wcip_global_plans', array());

            ?>
            <div class="wrap wcip-debug-wrap" dir="rtl" style="direction:rtl;text-align:right;">
                <h1 style="margin-bottom:20px;"><?php esc_html_e('دیباگ و لاگ‌های پرداخت اقساطی', 'wc-installment'); ?></h1>

                <!-- System Info -->
                <div class="wcip-debug-card" style="background:#fff;border:1px solid #e0e0e0;border-radius:8px;padding:20px;margin-bottom:20px;">
                    <h2 style="margin-top:0;font-size:16px;border-bottom:2px solid #eee;padding-bottom:10px;"><?php esc_html_e('اطلاعات سیستم', 'wc-installment'); ?></h2>
                    <table class="widefat striped" style="border:none;">
                        <tbody>
                            <tr>
                                <td style="font-weight:600;width:220px;"><?php esc_html_e('نسخه افزونه', 'wc-installment'); ?></td>
                                <td><?php echo esc_html($plugin_version); ?></td>
                            </tr>
                            <tr>
                                <td style="font-weight:600;"><?php esc_html_e('افزونه فعال سراسری', 'wc-installment'); ?></td>
                                <td>
                                    <?php if ($global_enabled === 'yes') : ?>
                                        <span style="color:#2a8a2a;font-weight:700;"><?php esc_html_e('فعال', 'wc-installment'); ?></span>
                                    <?php else : ?>
                                        <span style="color:#c03030;font-weight:700;"><?php esc_html_e('غیرفعال', 'wc-installment'); ?></span>
                                        <span style="color:#888;font-size:12px;"> — <?php esc_html_e('از WooCommerce › تنظیمات › پرداخت اقساطی فعال کنید', 'wc-installment'); ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <td style="font-weight:600;"><?php esc_html_e('طرح‌های سراسری تعریف‌شده', 'wc-installment'); ?></td>
                                <td>
                                    <?php if (is_array($global_plans) && count($global_plans) > 0) :
                                        foreach ($global_plans as $p) :
                                            echo esc_html($p['months'] . ' ماه' . ($p['interest_rate'] > 0 ? ' (' . $p['interest_rate'] . '% سود)' : ' (بدون سود)'));
                                            echo '<br>';
                                        endforeach;
                                    else : ?>
                                        <span style="color:#888;"><?php esc_html_e('هیچ طرحی تعریف نشده (از صفحه محصول اضافه کنید)', 'wc-installment'); ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <td style="font-weight:600;"><?php esc_html_e('نسخه WooCommerce', 'wc-installment'); ?></td>
                                <td><?php echo esc_html($wc_version); ?></td>
                            </tr>
                            <tr>
                                <td style="font-weight:600;"><?php esc_html_e('نسخه WordPress', 'wc-installment'); ?></td>
                                <td><?php echo esc_html($wp_version); ?></td>
                            </tr>
                            <tr>
                                <td style="font-weight:600;"><?php esc_html_e('نسخه PHP', 'wc-installment'); ?></td>
                                <td><?php echo esc_html($php_version); ?></td>
                            </tr>
                            <tr>
                                <td style="font-weight:600;"><?php esc_html_e('پوشه لاگ WooCommerce', 'wc-installment'); ?></td>
                                <td><code style="font-size:12px;"><?php echo esc_html($log_dir); ?></code></td>
                            </tr>
                            <tr>
                                <td style="font-weight:600;"><?php esc_html_e('پوشه لاگ موجود است', 'wc-installment'); ?></td>
                                <td><?php echo esc_html($log_dir_exists); ?></td>
                            </tr>
                            <tr>
                                <td style="font-weight:600;"><?php esc_html_e('پوشه لاگ قابل نوشتن', 'wc-installment'); ?></td>
                                <td><?php echo esc_html($log_dir_writable); ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Actions -->
                <div class="wcip-debug-card" style="background:#fff;border:1px solid #e0e0e0;border-radius:8px;padding:20px;margin-bottom:20px;">
                    <h2 style="margin-top:0;font-size:16px;border-bottom:2px solid #eee;padding-bottom:10px;"><?php esc_html_e('عملیات', 'wc-installment'); ?></h2>
                    <div style="display:flex;gap:10px;flex-wrap:wrap;">
                        <button type="button" id="wcip-test-log-btn" class="button button-secondary" data-nonce="<?php echo esc_attr($nonce); ?>">
                            <?php esc_html_e('نوشتن لاگ آزمایشی', 'wc-installment'); ?>
                        </button>
                        <button type="button" id="wcip-clear-logs-btn" class="button" style="color:#c03030;border-color:#c03030;" data-nonce="<?php echo esc_attr($nonce); ?>">
                            <?php esc_html_e('پاک‌کردن تمام لاگ‌ها', 'wc-installment'); ?>
                        </button>
                        <a href="<?php echo esc_url(add_query_arg(array('page' => 'wcip-debug'), admin_url('admin.php'))); ?>" class="button">
                            <?php esc_html_e('بارگذاری مجدد', 'wc-installment'); ?>
                        </a>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=wc-settings&tab=installment')); ?>" class="button button-primary">
                            <?php esc_html_e('تنظیمات افزونه', 'wc-installment'); ?>
                        </a>
                    </div>
                    <p id="wcip-debug-action-msg" style="margin:10px 0 0;color:#2a8a2a;display:none;font-weight:600;"></p>
                </div>

                <!-- Log viewer -->
                <div class="wcip-debug-card" style="background:#fff;border:1px solid #e0e0e0;border-radius:8px;padding:20px;">
                    <h2 style="margin-top:0;font-size:16px;border-bottom:2px solid #eee;padding-bottom:10px;"><?php esc_html_e('فایل‌های لاگ', 'wc-installment'); ?></h2>

                    <?php if (empty($files)) : ?>
                        <div style="padding:20px;background:#f9f9f9;border-radius:6px;color:#888;text-align:center;">
                            <p style="margin:0;font-size:14px;"><?php esc_html_e('هیچ فایل لاگی یافت نشد.', 'wc-installment'); ?></p>
                            <p style="margin:8px 0 0;font-size:12px;"><?php esc_html_e('لاگ‌ها پس از اولین فعالیت افزونه (مثل بازدید از صفحه محصول) ایجاد می‌شوند.', 'wc-installment'); ?></p>
                            <p style="margin:8px 0 0;font-size:12px;"><?php echo esc_html(sprintf('مسیر لاگ: %s', $log_dir)); ?></p>
                        </div>
                    <?php else : ?>
                        <!-- File selector -->
                        <form method="get" style="margin-bottom:16px;">
                            <input type="hidden" name="page" value="wcip-debug">
                            <label style="font-weight:600;margin-left:8px;"><?php esc_html_e('انتخاب فایل لاگ:', 'wc-installment'); ?></label>
                            <select name="log_file" onchange="this.form.submit()" style="min-width:320px;">
                                <?php foreach ($files as $fname => $fpath) : ?>
                                    <option value="<?php echo esc_attr($fname); ?>" <?php selected($fname, $selected_file); ?>>
                                        <?php echo esc_html($fname); ?> (<?php echo esc_html(size_format(filesize($fpath))); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </form>

                        <!-- Log content -->
                        <?php if ($log_content !== '') : ?>
                            <div style="position:relative;">
                                <pre id="wcip-log-content" style="background:#1a1a2e;color:#e0e0e0;padding:16px;border-radius:6px;font-size:12px;line-height:1.6;max-height:500px;overflow-y:auto;direction:ltr;text-align:left;white-space:pre-wrap;word-break:break-all;margin:0;"><?php echo esc_html($log_content); ?></pre>
                                <button type="button" id="wcip-copy-log" class="button" style="position:absolute;top:10px;left:10px;font-size:11px;">
                                    <?php esc_html_e('کپی متن', 'wc-installment'); ?>
                                </button>
                            </div>
                            <p style="margin:8px 0 0;font-size:12px;color:#888;">
                                <?php echo esc_html(sprintf(__('آخر %d خط نمایش داده می‌شود.', 'wc-installment'), 300)); ?>
                            </p>
                        <?php else : ?>
                            <div style="padding:20px;background:#f9f9f9;border-radius:6px;color:#888;text-align:center;">
                                <p style="margin:0;"><?php esc_html_e('فایل لاگ خالی است.', 'wc-installment'); ?></p>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>

            <script type="text/javascript">
            (function($){
                var nonce = '<?php echo esc_js($nonce); ?>';

                $('#wcip-test-log-btn').on('click', function(){
                    var $btn = $(this);
                    $btn.prop('disabled', true).text('در حال نوشتن...');
                    $.post(ajaxurl, { action: 'wcip_write_test_log', nonce: nonce }, function(res){
                        $btn.prop('disabled', false).text('نوشتن لاگ آزمایشی');
                        var $msg = $('#wcip-debug-action-msg');
                        if (res.success) {
                            $msg.css('color','#2a8a2a').text('لاگ آزمایشی نوشته شد. صفحه را رفرش کنید.').show();
                        } else {
                            $msg.css('color','#c03030').text('خطا در نوشتن لاگ.').show();
                        }
                    });
                });

                $('#wcip-clear-logs-btn').on('click', function(){
                    if (!confirm('آیا از پاک‌کردن تمام لاگ‌ها مطمئن هستید؟')) return;
                    var $btn = $(this);
                    $btn.prop('disabled', true).text('در حال پاک‌کردن...');
                    $.post(ajaxurl, { action: 'wcip_clear_logs', nonce: nonce }, function(res){
                        $btn.prop('disabled', false).text('پاک‌کردن تمام لاگ‌ها');
                        var $msg = $('#wcip-debug-action-msg');
                        if (res.success) {
                            $msg.css('color','#2a8a2a').text('لاگ‌ها پاک شدند. صفحه را رفرش کنید.').show();
                            $('#wcip-log-content').text('');
                        } else {
                            $msg.css('color','#c03030').text('خطا در پاک‌کردن لاگ‌ها.').show();
                        }
                    });
                });

                $('#wcip-copy-log').on('click', function(){
                    var text = $('#wcip-log-content').text();
                    navigator.clipboard.writeText(text).then(function(){
                        var $btn = $('#wcip-copy-log');
                        var orig = $btn.text();
                        $btn.text('کپی شد!');
                        setTimeout(function(){ $btn.text(orig); }, 1500);
                    });
                });

                // Auto-scroll to bottom of log.
                var $log = $('#wcip-log-content');
                if ($log.length) {
                    $log.scrollTop($log[0].scrollHeight);
                }
            })(jQuery);
            </script>
            <?php
        }
    }
}
