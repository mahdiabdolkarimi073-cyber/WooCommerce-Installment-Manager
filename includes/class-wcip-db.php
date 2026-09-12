<?php
/**
 * Database management — creates and upgrades the installments table.
 *
 * @package WC_Installment_Payment
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('WCIP_DB')) {

    class WCIP_DB
    {
        /**
         * @var WCIP_DB|null
         */
        private static $instance = null;

        /**
         * @var string Custom table name (without prefix).
         */
        const TABLE_NAME = 'wcip_installments';

        /**
         * @var string Settlement requests table name (without prefix).
         */
        const SETTLEMENT_TABLE = 'wcip_settlement_requests';

        /**
         * Singleton instance.
         *
         * @return WCIP_DB
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
        }

        /**
         * Returns the full table name with WordPress prefix.
         *
         * @return string
         */
        public static function table_name()
        {
            global $wpdb;
            return $wpdb->prefix . self::TABLE_NAME;
        }

        /**
         * Returns the full settlement requests table name with prefix.
         *
         * @return string
         */
        public static function settlement_table_name()
        {
            global $wpdb;
            return $wpdb->prefix . self::SETTLEMENT_TABLE;
        }

        /**
         * Creates or upgrades the installments table.
         * Called on plugin activation and via the upgrade routine.
         */
        public function create_table()
        {
            global $wpdb;

            $table = self::table_name();
            $charset_collate = $wpdb->get_charset_collate();

            $sql = "CREATE TABLE {$table} (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                order_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
                user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
                product_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
                installment_number INT(11) UNSIGNED NOT NULL DEFAULT 1,
                total_installments INT(11) UNSIGNED NOT NULL DEFAULT 1,
                amount DECIMAL(20,2) NOT NULL DEFAULT 0.00,
                due_date DATE NOT NULL DEFAULT '1970-01-01',
                paid_date DATETIME NULL DEFAULT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'unpaid',
                transaction_id VARCHAR(255) NULL DEFAULT NULL,
                admin_notes TEXT NULL DEFAULT NULL,
                invoice_url VARCHAR(255) NULL DEFAULT NULL,
                reminder_sent TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
                retry_count INT(11) UNSIGNED NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY order_id (order_id),
                KEY user_id (user_id),
                KEY product_id (product_id),
                KEY status (status),
                KEY due_date (due_date)
            ) {$charset_collate};";

            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            dbDelta($sql);

            $this->create_settlement_table();

            // Create SMS log table.
            if (class_exists('SMS_Logger')) {
                SMS_Logger::instance()->create_table();
            }

            update_option('wcip_db_version', WCIP_VERSION);
        }

        /**
         * Creates the settlement requests table for early settlement feature.
         */
        public function create_settlement_table()
        {
            global $wpdb;

            $table = self::settlement_table_name();
            $charset_collate = $wpdb->get_charset_collate();

            $sql = "CREATE TABLE {$table} (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                order_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
                user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
                product_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
                remaining_amount DECIMAL(20,2) NOT NULL DEFAULT 0.00,
                discount_amount DECIMAL(20,2) NOT NULL DEFAULT 0.00,
                final_amount DECIMAL(20,2) NOT NULL DEFAULT 0.00,
                status VARCHAR(20) NOT NULL DEFAULT 'pending',
                admin_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
                admin_note TEXT NULL DEFAULT NULL,
                requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                reviewed_at DATETIME NULL DEFAULT NULL,
                PRIMARY KEY (id),
                KEY order_id (order_id),
                KEY user_id (user_id),
                KEY status (status)
            ) {$charset_collate};";

            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            dbDelta($sql);
        }

        /**
         * Runs the upgrade routine — adds missing columns to an existing table
         * without breaking existing data.
         */
        public function maybe_upgrade()
        {
            global $wpdb;

            $current_version = get_option('wcip_db_version', '0');
            if (version_compare($current_version, WCIP_VERSION, '>=')) {
                return;
            }

            $table = self::table_name();

            // Check if the table exists at all.
            $table_exists = $wpdb->get_var(
                $wpdb->prepare("SHOW TABLES LIKE %s", $table)
            );

            if (!$table_exists) {
                $this->create_table();
                return;
            }

            // Ensure the settlement table exists.
            $settlement_table = self::settlement_table_name();
            $settlement_exists = $wpdb->get_var(
                $wpdb->prepare("SHOW TABLES LIKE %s", $settlement_table)
            );
            if (!$settlement_exists) {
                $this->create_settlement_table();
            }

            // Get existing columns.
            $existing_columns = $wpdb->get_col("DESC {$table}", 0);
            $existing_columns = array_flip($existing_columns);

            // Required columns with their definitions.
            $required_columns = array(
                'id'                  => "BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT",
                'order_id'            => "BIGINT(20) UNSIGNED NOT NULL DEFAULT 0",
                'user_id'             => "BIGINT(20) UNSIGNED NOT NULL DEFAULT 0",
                'product_id'          => "BIGINT(20) UNSIGNED NOT NULL DEFAULT 0",
                'installment_number'  => "INT(11) UNSIGNED NOT NULL DEFAULT 1",
                'total_installments'  => "INT(11) UNSIGNED NOT NULL DEFAULT 1",
                'amount'              => "DECIMAL(20,2) NOT NULL DEFAULT 0.00",
                'due_date'            => "DATE NOT NULL DEFAULT '1970-01-01'",
                'paid_date'           => "DATETIME NULL DEFAULT NULL",
                'status'              => "VARCHAR(20) NOT NULL DEFAULT 'unpaid'",
                'transaction_id'      => "VARCHAR(255) NULL DEFAULT NULL",
                'admin_notes'          => "TEXT NULL DEFAULT NULL",
                'invoice_url'          => "VARCHAR(255) NULL DEFAULT NULL",
                'reminder_sent'       => "TINYINT(1) UNSIGNED NOT NULL DEFAULT 0",
                'retry_count'         => "INT(11) UNSIGNED NOT NULL DEFAULT 0",
                'created_at'          => "DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",
            );

            foreach ($required_columns as $column => $definition) {
                if (!isset($existing_columns[$column])) {
                    $wpdb->query("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
                }
            }

            // Ensure the primary key exists.
            $has_pk = $wpdb->get_var(
                "SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
                 WHERE TABLE_SCHEMA = DATABASE()
                 AND TABLE_NAME = '{$table}'
                 AND CONSTRAINT_NAME = 'PRIMARY'"
            );
            if (!$has_pk) {
                $wpdb->query("ALTER TABLE {$table} ADD PRIMARY KEY (id)");
            }

            // Add indexes if missing.
            $indexes = array('order_id', 'user_id', 'product_id', 'status', 'due_date');
            foreach ($indexes as $idx) {
                $has_index = $wpdb->get_var(
                    "SELECT COUNT(*) FROM information_schema.STATISTICS
                     WHERE TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = '{$table}'
                     AND INDEX_NAME = '{$idx}'"
                );
                if (!$has_index) {
                    $wpdb->query("ALTER TABLE {$table} ADD INDEX {$idx} ({$idx})");
                }
            }

            update_option('wcip_db_version', WCIP_VERSION);
        }

        /**
         * Inserts a single installment record.
         *
         * @param array $data Associative array of column => value.
         * @return int|false Inserted ID or false on failure.
         */
        public function insert_installment($data)
        {
            global $wpdb;
            $table = self::table_name();

            $defaults = array(
                'order_id'           => 0,
                'user_id'            => 0,
                'product_id'         => 0,
                'installment_number' => 1,
                'total_installments' => 1,
                'amount'             => 0.00,
                'due_date'           => current_time('Y-m-d'),
                'paid_date'          => null,
                'status'             => 'unpaid',
                'transaction_id'     => null,
                'admin_notes'        => null,
                'invoice_url'        => null,
                'reminder_sent'      => 0,
                'retry_count'        => 0,
            );

            $data = wp_parse_args($data, $defaults);

            $result = $wpdb->insert(
                $table,
                $data,
                array(
                    '%d', '%d', '%d', '%d', '%d', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d',
                )
            );

            if ($result === false) {
                return false;
            }

            return (int) $wpdb->insert_id;
        }

        /**
         * Updates an installment record.
         *
         * @param int   $id   Installment ID.
         * @param array $data Associative array of column => value.
         * @return bool True on success, false on failure.
         */
        public function update_installment($id, $data)
        {
            global $wpdb;
            $table = self::table_name();

            $id = absint($id);
            if ($id <= 0) {
                return false;
            }

            $format = array();
            foreach ($data as $key => $value) {
                switch ($key) {
                    case 'order_id':
                    case 'user_id':
                    case 'product_id':
                    case 'installment_number':
                    case 'total_installments':
                        $format[] = '%d';
                        break;
                    case 'amount':
                        $format[] = '%f';
                        break;
                    case 'due_date':
                    case 'paid_date':
                    case 'status':
                    case 'transaction_id':
                    case 'admin_notes':
                    case 'invoice_url':
                        $format[] = '%s';
                        break;
                    case 'reminder_sent':
                    case 'retry_count':
                        $format[] = '%d';
                        break;
                    default:
                        unset($data[$key]);
                        break;
                }
            }

            if (empty($data)) {
                return false;
            }

            $result = $wpdb->update($table, $data, array('id' => $id), $format, array('%d'));

            return $result !== false;
        }

        /**
         * Deletes an installment record.
         *
         * @param int $id Installment ID.
         * @return bool True on success, false on failure.
         */
        public function delete_installment($id)
        {
            global $wpdb;
            $table = self::table_name();

            $id = absint($id);
            if ($id <= 0) {
                return false;
            }

            $result = $wpdb->delete($table, array('id' => $id), array('%d'));

            return $result !== false;
        }

        /**
         * Retrieves a single installment by ID.
         *
         * @param int $id Installment ID.
         * @return object|null
         */
        public function get_installment($id)
        {
            global $wpdb;
            $table = self::table_name();

            $id = absint($id);
            if ($id <= 0) {
                return null;
            }

            return $wpdb->get_row(
                $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id)
            );
        }

        /**
         * Retrieves installments for a specific order.
         *
         * @param int $order_id Order ID.
         * @return array
         */
        public function get_order_installments($order_id)
        {
            global $wpdb;
            $table = self::table_name();

            $order_id = absint($order_id);
            if ($order_id <= 0) {
                return array();
            }

            return $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$table} WHERE order_id = %d ORDER BY installment_number ASC",
                    $order_id
                )
            );
        }

        /**
         * Generates installment records for an order based on the order meta.
         *
         * @param int $order_id   Order ID.
         * @param int $user_id    Customer user ID.
         * @param int $product_id Product ID.
         * @param float $per_installment Amount per installment.
         * @param int   $total_installments Total number of installments.
         * @param int   $start_days Days from now until the first due date.
         * @param int   $interval_days Days between each installment due date.
         * @return int Number of installments created.
         */
        public function generate_installments($order_id, $user_id, $product_id, $per_installment, $total_installments, $start_days = 30, $interval_days = 30)
        {
            $created = 0;

            for ($i = 1; $i <= $total_installments; $i++) {
                $due_offset = $start_days + (($i - 1) * $interval_days);
                $due_date = date('Y-m-d', strtotime("+{$due_offset} days"));

                $id = $this->insert_installment(array(
                    'order_id'           => $order_id,
                    'user_id'            => $user_id,
                    'product_id'         => $product_id,
                    'installment_number' => $i,
                    'total_installments' => $total_installments,
                    'amount'             => $per_installment,
                    'due_date'           => $due_date,
                    'status'             => 'unpaid',
                ));

                if ($id) {
                    $created++;
                }
            }

            return $created;
        }

        /**
         * Updates the status of installments that are past due and still unpaid.
         *
         * @return int Number of installments updated.
         */
        public function mark_overdue_installments()
        {
            global $wpdb;
            $table = self::table_name();

            $today = current_time('Y-m-d');

            $result = $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$table} SET status = 'overdue'
                     WHERE status = 'unpaid'
                     AND due_date < %s",
                    $today
                )
            );

            return (int) $result;
        }

        /**
         * Retrieves installments that are due within the given number of days.
         *
         * @param int $days Number of days from today.
         * @return array
         */
        public function get_installments_due_soon($days = 3)
        {
            global $wpdb;
            $table = self::table_name();

            $today = current_time('Y-m-d');
            $future = date('Y-m-d', strtotime("+{$days} days"));

            return $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$table}
                     WHERE status = 'unpaid'
                     AND due_date >= %s
                     AND due_date <= %s",
                    $today,
                    $future
                )
            );
        }

        /**
         * Retrieves installments that are overdue (past due date and unpaid).
         *
         * @return array
         */
        public function get_overdue_installments()
        {
            global $wpdb;
            $table = self::table_name();

            $today = current_time('Y-m-d');

            return $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$table}
                     WHERE status IN ('unpaid', 'overdue')
                     AND due_date < %s",
                    $today
                )
            );
        }

        /**
         * Computes the dynamic status of an installment based on due date and paid status.
         * Priority: Paid → Due Today → Overdue → Due Soon → Normal (unpaid).
         *
         * @param object $installment Installment record with at least status and due_date.
         * @return string One of: 'paid', 'due_today', 'overdue', 'due_soon', 'unpaid'.
         */
        public function compute_dynamic_status($installment)
        {
            if (!is_object($installment)) {
                return 'unpaid';
            }

            if ($installment->status === 'paid') {
                return 'paid';
            }

            $today = current_time('Y-m-d');
            $due = $installment->due_date;

            if ($due < $today) {
                return 'overdue';
            }

            if ($due === $today) {
                return 'due_today';
            }

            $diff = (strtotime($due) - strtotime($today)) / 86400;
            if ($diff >= 1 && $diff <= 3) {
                return 'due_soon';
            }

            return 'unpaid';
        }

        /**
         * Returns the human-readable label for a dynamic status.
         *
         * @param string $status Dynamic status string.
         * @return string
         */
        public static function get_status_label($status)
        {
            $labels = array(
                'paid'      => __('پرداخت شده', 'wc-installment'),
                'due_today' => __('سررسید امروز', 'wc-installment'),
                'overdue'   => __('معوق', 'wc-installment'),
                'due_soon'  => __('نزدیک سررسید', 'wc-installment'),
                'unpaid'    => __('در انتظار پرداخت', 'wc-installment'),
            );

            return isset($labels[$status]) ? $labels[$status] : $labels['unpaid'];
        }

        /**
         * Returns the CSS class for a dynamic status.
         *
         * @param string $status Dynamic status string.
         * @return string
         */
        public static function get_status_class($status)
        {
            $classes = array(
                'paid'      => 'wcip-status-paid',
                'due_today' => 'wcip-status-due-today',
                'overdue'   => 'wcip-status-overdue',
                'due_soon'  => 'wcip-status-due-soon',
                'unpaid'    => 'wcip-status-unpaid',
            );

            return isset($classes[$status]) ? $classes[$status] : $classes['unpaid'];
        }

        /**
         * Retrieves all active reminders for a user — installments within the
         * reminder window (due within 3 days or overdue) that are not paid.
         *
         * @param int $user_id User ID.
         * @param int $days   Reminder window in days (default 3).
         * @return array Array of installment objects with computed dynamic_status.
         */
        public function get_user_reminders($user_id, $days = 3)
        {
            global $wpdb;
            $table = self::table_name();

            $user_id = absint($user_id);
            if ($user_id <= 0) {
                return array();
            }

            $today = current_time('Y-m-d');
            $future = date('Y-m-d', strtotime("+{$days} days"));

            $results = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$table}
                     WHERE user_id = %d
                     AND status IN ('unpaid', 'overdue')
                     AND due_date <= %s
                     ORDER BY due_date ASC",
                    $user_id,
                    $future
                )
            );

            foreach ($results as $row) {
                $row->dynamic_status = $this->compute_dynamic_status($row);
                $row->days_diff = (int) round((strtotime($row->due_date) - strtotime($today)) / 86400);
            }

            return $results;
        }

        /**
         * Counts active reminders for a user.
         *
         * @param int $user_id User ID.
         * @param int $days   Reminder window in days.
         * @return int
         */
        public function count_user_reminders($user_id, $days = 3)
        {
            return count($this->get_user_reminders($user_id, $days));
        }

        /**
         * Retrieves all installments for a specific user.
         *
         * @param int $user_id User ID.
         * @return array
         */
        public function get_user_installments($user_id)
        {
            global $wpdb;
            $table = self::table_name();

            $user_id = absint($user_id);
            if ($user_id <= 0) {
                return array();
            }

            return $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$table} WHERE user_id = %d ORDER BY installment_number ASC",
                    $user_id
                )
            );
        }

        /**
         * Retrieves the next upcoming (unpaid) installment for a user.
         *
         * @param int $user_id User ID.
         * @return object|null
         */
        public function get_next_upcoming_installment($user_id)
        {
            global $wpdb;
            $table = self::table_name();

            $user_id = absint($user_id);
            if ($user_id <= 0) {
                return null;
            }

            $today = current_time('Y-m-d');

            return $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$table}
                     WHERE user_id = %d
                     AND status IN ('unpaid', 'overdue')
                     ORDER BY due_date ASC
                     LIMIT 1",
                    $user_id
                )
            );
        }

        /**
         * Retrieves installments that need a reminder (due within N days, reminder not sent).
         *
         * @param int $days Number of days before due date.
         * @return array
         */
        public function get_installments_needing_reminder($days = 3)
        {
            global $wpdb;
            $table = self::table_name();

            $today = current_time('Y-m-d');
            $future = date('Y-m-d', strtotime("+{$days} days"));

            return $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$table}
                     WHERE status IN ('unpaid', 'overdue')
                     AND reminder_sent = 0
                     AND due_date >= %s
                     AND due_date <= %s",
                    $today,
                    $future
                )
            );
        }

        /**
         * Marks that a reminder has been sent for an installment.
         *
         * @param int $id Installment ID.
         * @return bool
         */
        public function mark_reminder_sent($id)
        {
            return $this->update_installment($id, array('reminder_sent' => 1));
        }

        /**
         * Increments the retry count for an installment.
         *
         * @param int $id Installment ID.
         * @return int New retry count.
         */
        public function increment_retry_count($id)
        {
            global $wpdb;
            $table = self::table_name();

            $id = absint($id);
            if ($id <= 0) {
                return 0;
            }

            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$table} SET retry_count = retry_count + 1 WHERE id = %d",
                    $id
                )
            );

            return (int) $wpdb->get_var(
                $wpdb->prepare("SELECT retry_count FROM {$table} WHERE id = %d", $id)
            );
        }

        /**
         * Resets the retry count for an installment.
         *
         * @param int $id Installment ID.
         * @return bool
         */
        public function reset_retry_count($id)
        {
            return $this->update_installment($id, array('retry_count' => 0));
        }

        /**
         * Retrieves all installments with optional filtering.
         *
         * @param array $args Query arguments.
         * @return array
         */
        public function get_installments($args = array())
        {
            global $wpdb;
            $table = self::table_name();

            $defaults = array(
                'status'      => '',
                'search'      => '',
                'orderby'     => 'id',
                'order'       => 'DESC',
                'per_page'    => 20,
                'page'        => 1,
            );

            $args = wp_parse_args($args, $defaults);

            $where = '1=1';
            $params = array();

            if (!empty($args['status'])) {
                if ($args['status'] === 'overdue') {
                    $where .= " AND i.status IN ('unpaid','overdue') AND i.due_date < %s";
                    $params[] = current_time('Y-m-d');
                } else {
                    $where .= " AND i.status = %s";
                    $params[] = $args['status'];
                }
            }

            if (!empty($args['search'])) {
                $search = '%' . $wpdb->esc_like($args['search']) . '%';
                $where .= " AND (u.display_name LIKE %s OR i.order_id LIKE %s)";
                $params[] = $search;
                $params[] = $search;
            }

            $orderby = in_array($args['orderby'], array('id', 'order_id', 'user_id', 'due_date', 'status', 'amount'), true)
                ? $args['orderby'] : 'id';
            $order = strtoupper($args['order']) === 'ASC' ? 'ASC' : 'DESC';

            $per_page = absint($args['per_page']);
            $page = max(1, absint($args['page']));
            $offset = ($page - 1) * $per_page;

            $sql = "SELECT i.*, u.display_name AS customer_name
                    FROM {$table} i
                    LEFT JOIN {$wpdb->users} u ON i.user_id = u.ID
                    WHERE {$where}
                    ORDER BY i.{$orderby} {$order}
                    LIMIT %d OFFSET %d";

            $params[] = $per_page;
            $params[] = $offset;

            if (!empty($params)) {
                $sql = $wpdb->prepare($sql, $params);
            }

            return $wpdb->get_results($sql);
        }

        /**
         * Counts installments matching the given filter.
         *
         * @param array $args Query arguments.
         * @return int
         */
        public function count_installments($args = array())
        {
            global $wpdb;
            $table = self::table_name();

            $defaults = array(
                'status' => '',
                'search' => '',
            );

            $args = wp_parse_args($args, $defaults);

            $where = '1=1';
            $params = array();

            if (!empty($args['status'])) {
                if ($args['status'] === 'overdue') {
                    $where .= " AND i.status IN ('unpaid','overdue') AND i.due_date < %s";
                    $params[] = current_time('Y-m-d');
                } else {
                    $where .= " AND i.status = %s";
                    $params[] = $args['status'];
                }
            }

            if (!empty($args['search'])) {
                $search = '%' . $wpdb->esc_like($args['search']) . '%';
                $where .= " AND (u.display_name LIKE %s OR i.order_id LIKE %s)";
                $params[] = $search;
                $params[] = $search;
            }

            $sql = "SELECT COUNT(*) FROM {$table} i
                    LEFT JOIN {$wpdb->users} u ON i.user_id = u.ID
                    WHERE {$where}";

            if (!empty($params)) {
                $sql = $wpdb->prepare($sql, $params);
            }

            return (int) $wpdb->get_var($sql);
        }

        // ===== Feature 7: Reports & Settlement =====

        /**
         * Retrieves report data with filtering, sorting, and pagination.
         *
         * @param array $args Query arguments (report_type, date_from, date_to,
         *                    customer_id, status_filter, product_id, orderby, order,
         *                    per_page, page).
         * @return array Results array.
         */
        public function get_report_data($args = array())
        {
            global $wpdb;
            $table = self::table_name();

            $defaults = array(
                'report_type'  => 'total_sales',
                'date_from'    => '',
                'date_to'      => '',
                'customer_id'  => 0,
                'status_filter'=> '',
                'product_id'   => 0,
                'orderby'      => 'id',
                'order'        => 'DESC',
                'per_page'     => 20,
                'page'         => 1,
            );

            $args = wp_parse_args($args, $defaults);

            $where = '1=1';
            $params = array();

            // Date range filter (applies to due_date or paid_date depending on report).
            if (!empty($args['date_from'])) {
                $where .= ' AND i.due_date >= %s';
                $params[] = $args['date_from'];
            }
            if (!empty($args['date_to'])) {
                $where .= ' AND i.due_date <= %s';
                $params[] = $args['date_to'];
            }

            // Customer filter.
            if (!empty($args['customer_id'])) {
                $where .= ' AND i.user_id = %d';
                $params[] = absint($args['customer_id']);
            }

            // Status filter.
            if (!empty($args['status_filter'])) {
                if ($args['status_filter'] === 'overdue') {
                    $where .= " AND i.status IN ('unpaid','overdue') AND i.due_date < %s";
                    $params[] = current_time('Y-m-d');
                } else {
                    $where .= ' AND i.status = %s';
                    $params[] = $args['status_filter'];
                }
            }

            // Product filter.
            if (!empty($args['product_id'])) {
                $where .= ' AND i.product_id = %d';
                $params[] = absint($args['product_id']);
            }

            // Apply report-specific conditions.
            switch ($args['report_type']) {
                case 'down_payments':
                    // Down payments are stored in order meta, not installments.
                    return $this->get_down_payments_report($args);

                case 'received_amounts':
                    $where .= " AND i.status = 'paid'";
                    break;

                case 'remaining_balances':
                    $where .= " AND i.status IN ('unpaid','overdue')";
                    break;

                case 'overdue_installments':
                    $where .= " AND i.status IN ('unpaid','overdue') AND i.due_date < %s";
                    $params[] = current_time('Y-m-d');
                    break;

                case 'active_contracts':
                    $where .= " AND i.status IN ('unpaid','overdue','paid')
                     AND i.order_id IN (
                        SELECT order_id FROM {$table}
                        WHERE status IN ('unpaid','overdue')
                     )";
                    break;

                case 'settled_contracts':
                    $where .= " AND i.order_id IN (
                        SELECT order_id FROM {$table}
                        GROUP BY order_id
                        HAVING COUNT(*) = SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END)
                    )";
                    break;
            }

            $orderby = in_array($args['orderby'], array('id', 'order_id', 'user_id', 'due_date', 'status', 'amount', 'customer_name'), true)
                ? $args['orderby'] : 'id';
            if ($orderby === 'customer_name') {
                $orderby = 'u.display_name';
            } elseif ($orderby === 'order_id') {
                $orderby = 'i.order_id';
            } else {
                $orderby = 'i.' . $orderby;
            }
            $order = strtoupper($args['order']) === 'ASC' ? 'ASC' : 'DESC';

            $per_page = absint($args['per_page']);
            $page = max(1, absint($args['page']));
            $offset = ($page - 1) * $per_page;

            $sql = "SELECT i.*, u.display_name AS customer_name
                    FROM {$table} i
                    LEFT JOIN {$wpdb->users} u ON i.user_id = u.ID
                    WHERE {$where}
                    ORDER BY {$orderby} {$order}
                    LIMIT %d OFFSET %d";

            $params[] = $per_page;
            $params[] = $offset;

            $sql = $wpdb->prepare($sql, $params);

            return $wpdb->get_results($sql);
        }

        /**
         * Counts report rows for pagination.
         *
         * @param array $args Same arguments as get_report_data.
         * @return int
         */
        public function count_report_data($args = array())
        {
            global $wpdb;
            $table = self::table_name();

            $defaults = array(
                'report_type'  => 'total_sales',
                'date_from'    => '',
                'date_to'      => '',
                'customer_id'  => 0,
                'status_filter'=> '',
                'product_id'   => 0,
            );

            $args = wp_parse_args($args, $defaults);

            // Down payments use a different query.
            if ($args['report_type'] === 'down_payments') {
                return $this->count_down_payments_report($args);
            }

            $where = '1=1';
            $params = array();

            if (!empty($args['date_from'])) {
                $where .= ' AND i.due_date >= %s';
                $params[] = $args['date_from'];
            }
            if (!empty($args['date_to'])) {
                $where .= ' AND i.due_date <= %s';
                $params[] = $args['date_to'];
            }
            if (!empty($args['customer_id'])) {
                $where .= ' AND i.user_id = %d';
                $params[] = absint($args['customer_id']);
            }
            if (!empty($args['status_filter'])) {
                if ($args['status_filter'] === 'overdue') {
                    $where .= " AND i.status IN ('unpaid','overdue') AND i.due_date < %s";
                    $params[] = current_time('Y-m-d');
                } else {
                    $where .= ' AND i.status = %s';
                    $params[] = $args['status_filter'];
                }
            }
            if (!empty($args['product_id'])) {
                $where .= ' AND i.product_id = %d';
                $params[] = absint($args['product_id']);
            }

            switch ($args['report_type']) {
                case 'received_amounts':
                    $where .= " AND i.status = 'paid'";
                    break;
                case 'remaining_balances':
                    $where .= " AND i.status IN ('unpaid','overdue')";
                    break;
                case 'overdue_installments':
                    $where .= " AND i.status IN ('unpaid','overdue') AND i.due_date < %s";
                    $params[] = current_time('Y-m-d');
                    break;
                case 'active_contracts':
                    $where .= " AND i.order_id IN (
                        SELECT order_id FROM {$table}
                        WHERE status IN ('unpaid','overdue')
                    )";
                    break;
                case 'settled_contracts':
                    $where .= " AND i.order_id IN (
                        SELECT order_id FROM {$table}
                        GROUP BY order_id
                        HAVING COUNT(*) = SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END)
                    )";
                    break;
            }

            $sql = "SELECT COUNT(*) FROM {$table} i
                    LEFT JOIN {$wpdb->users} u ON i.user_id = u.ID
                    WHERE {$where}";

            if (!empty($params)) {
                $sql = $wpdb->prepare($sql, $params);
            }

            return (int) $wpdb->get_var($sql);
        }

        /**
         * Retrieves the down payments report from order meta.
         *
         * @param array $args Query arguments.
         * @return array
         */
        protected function get_down_payments_report($args)
        {
            global $wpdb;

            $where = '1=1';
            $params = array();

            if (!empty($args['date_from'])) {
                $where .= ' AND p.post_date >= %s';
                $params[] = $args['date_from'];
            }
            if (!empty($args['date_to'])) {
                $where .= ' AND p.post_date <= %s';
                $params[] = $args['date_to'];
            }
            if (!empty($args['customer_id'])) {
                $where .= ' AND o.meta_value = %d';
                $params[] = absint($args['customer_id']);
            }

            $orderby = in_array($args['orderby'], array('order_id', 'amount', 'date', 'customer_name'), true)
                ? $args['orderby'] : 'order_id';
            $order = strtoupper($args['order']) === 'ASC' ? 'ASC' : 'DESC';

            $order_col = ($orderby === 'amount') ? 'down_val' : ($orderby === 'date' ? 'p.post_date' : ($orderby === 'customer_name' ? 'u.display_name' : 'p.ID'));

            $per_page = absint($args['per_page']);
            $page = max(1, absint($args['page']));
            $offset = ($page - 1) * $per_page;

            $sql = "SELECT p.ID AS order_id, p.post_date AS order_date,
                    om_down.meta_value AS down_payment,
                    u.display_name AS customer_name,
                    u.ID AS customer_id
                    FROM {$wpdb->posts} p
                    INNER JOIN {$wpdb->postmeta} om_enable ON p.ID = om_enable.post_id
                        AND om_enable.meta_key = '_installment_enabled'
                        AND om_enable.meta_value = 'true'
                    LEFT JOIN {$wpdb->postmeta} om_down ON p.ID = om_down.post_id
                        AND om_down.meta_key = '_installment_down_payment'
                    LEFT JOIN {$wpdb->postmeta} o ON p.ID = o.post_id
                        AND o.meta_key = '_customer_user'
                    LEFT JOIN {$wpdb->users} u ON o.meta_value = u.ID
                    WHERE {$where}
                    ORDER BY {$order_col} {$order}
                    LIMIT %d OFFSET %d";

            $params[] = $per_page;
            $params[] = $offset;

            $sql = $wpdb->prepare($sql, $params);

            return $wpdb->get_results($sql);
        }

        /**
         * Counts down payment report rows.
         *
         * @param array $args Query arguments.
         * @return int
         */
        protected function count_down_payments_report($args)
        {
            global $wpdb;

            $where = '1=1';
            $params = array();

            if (!empty($args['date_from'])) {
                $where .= ' AND p.post_date >= %s';
                $params[] = $args['date_from'];
            }
            if (!empty($args['date_to'])) {
                $where .= ' AND p.post_date <= %s';
                $params[] = $args['date_to'];
            }
            if (!empty($args['customer_id'])) {
                $where .= ' AND o.meta_value = %d';
                $params[] = absint($args['customer_id']);
            }

            $sql = "SELECT COUNT(*)
                    FROM {$wpdb->posts} p
                    INNER JOIN {$wpdb->postmeta} om_enable ON p.ID = om_enable.post_id
                        AND om_enable.meta_key = '_installment_enabled'
                        AND om_enable.meta_value = 'true'
                    LEFT JOIN {$wpdb->postmeta} o ON p.ID = o.post_id
                        AND o.meta_key = '_customer_user'
                    WHERE {$where}";

            if (!empty($params)) {
                $sql = $wpdb->prepare($sql, $params);
            }

            return (int) $wpdb->get_var($sql);
        }

        /**
         * Returns summary totals for a report type (for summary cards).
         *
         * @param string $report_type Report type.
         * @param array  $args       Filter arguments.
         * @return array Array with 'total', 'count', 'extra' keys.
         */
        public function get_report_summary($report_type, $args = array())
        {
            global $wpdb;
            $table = self::table_name();

            $where = '1=1';
            $params = array();

            if (!empty($args['date_from'])) {
                $where .= ' AND i.due_date >= %s';
                $params[] = $args['date_from'];
            }
            if (!empty($args['date_to'])) {
                $where .= ' AND i.due_date <= %s';
                $params[] = $args['date_to'];
            }
            if (!empty($args['customer_id'])) {
                $where .= ' AND i.user_id = %d';
                $params[] = absint($args['customer_id']);
            }

            switch ($report_type) {
                case 'total_sales':
                    $sql = "SELECT COALESCE(SUM(i.amount),0) AS total, COUNT(*) AS count
                            FROM {$table} i WHERE {$where}";
                    break;

                case 'received_amounts':
                    $where .= " AND i.status = 'paid'";
                    $sql = "SELECT COALESCE(SUM(i.amount),0) AS total, COUNT(*) AS count
                            FROM {$table} i WHERE {$where}";
                    break;

                case 'remaining_balances':
                    $where .= " AND i.status IN ('unpaid','overdue')";
                    $sql = "SELECT COALESCE(SUM(i.amount),0) AS total, COUNT(*) AS count
                            FROM {$table} i WHERE {$where}";
                    break;

                case 'overdue_installments':
                    $where .= " AND i.status IN ('unpaid','overdue') AND i.due_date < %s";
                    $params[] = current_time('Y-m-d');
                    $sql = "SELECT COALESCE(SUM(i.amount),0) AS total, COUNT(*) AS count
                            FROM {$table} i WHERE {$where}";
                    break;

                case 'active_contracts':
                    $sql = "SELECT 0 AS total, COUNT(DISTINCT i.order_id) AS count
                            FROM {$table} i
                            WHERE i.order_id IN (
                                SELECT order_id FROM {$table}
                                WHERE status IN ('unpaid','overdue')
                            )";
                    break;

                case 'settled_contracts':
                    $sql = "SELECT 0 AS total, COUNT(DISTINCT i.order_id) AS count
                            FROM {$table} i
                            WHERE i.order_id IN (
                                SELECT order_id FROM {$table}
                                GROUP BY order_id
                                HAVING COUNT(*) = SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END)
                            )";
                    break;

                case 'down_payments':
                    $sql = "SELECT COALESCE(SUM(om_down.meta_value),0) AS total, COUNT(*) AS count
                            FROM {$wpdb->posts} p
                            INNER JOIN {$wpdb->postmeta} om_enable ON p.ID = om_enable.post_id
                                AND om_enable.meta_key = '_installment_enabled'
                                AND om_enable.meta_value = 'true'
                            LEFT JOIN {$wpdb->postmeta} om_down ON p.ID = om_down.post_id
                                AND om_down.meta_key = '_installment_down_payment'
                            WHERE 1=1";
                    if (!empty($args['date_from'])) {
                        $sql = $wpdb->prepare($sql . " AND p.post_date >= %s", $args['date_from']);
                    }
                    if (!empty($args['date_to'])) {
                        $sql = $wpdb->prepare($sql . " AND p.post_date <= %s", $args['date_to']);
                    }
                    return $wpdb->get_row($sql) ?: (object) array('total' => 0, 'count' => 0);

                default:
                    return (object) array('total' => 0, 'count' => 0);
            }

            if (!empty($params)) {
                $sql = $wpdb->prepare($sql, $params);
            }

            return $wpdb->get_row($sql) ?: (object) array('total' => 0, 'count' => 0);
        }

        /**
         * Returns period comparison data between two date ranges.
         *
         * @param string $report_type  Report type.
         * @param string $from1        Start date of period 1.
         * @param string $to1          End date of period 1.
         * @param string $from2        Start date of period 2.
         * @param string $to2          End date of period 2.
         * @return array Comparison data.
         */
        public function get_period_comparison($report_type, $from1, $to1, $from2, $to2)
        {
            $period1 = $this->get_report_summary($report_type, array(
                'date_from' => $from1,
                'date_to'   => $to1,
            ));
            $period2 = $this->get_report_summary($report_type, array(
                'date_from' => $from2,
                'date_to'   => $to2,
            ));

            $total1 = floatval($period1->total);
            $total2 = floatval($period2->total);
            $count1 = (int) $period1->count;
            $count2 = (int) $period2->count;

            $diff = $total2 - $total1;
            $pct_change = 0;
            if ($total1 > 0) {
                $pct_change = (($total2 - $total1) / $total1) * 100;
            }

            return array(
                'period1' => array('total' => $total1, 'count' => $count1, 'from' => $from1, 'to' => $to1),
                'period2' => array('total' => $total2, 'count' => $count2, 'from' => $from2, 'to' => $to2),
                'diff'     => $diff,
                'pct_change' => $pct_change,
            );
        }

        // ===== Settlement Requests =====

        /**
         * Inserts a new settlement request.
         *
         * @param array $data Settlement request data.
         * @return int|false Inserted ID or false.
         */
        public function insert_settlement_request($data)
        {
            global $wpdb;
            $table = self::settlement_table_name();

            $defaults = array(
                'order_id'        => 0,
                'user_id'         => 0,
                'product_id'      => 0,
                'remaining_amount'=> 0.00,
                'discount_amount' => 0.00,
                'final_amount'    => 0.00,
                'status'          => 'pending',
                'admin_id'        => 0,
                'admin_note'      => null,
            );

            $data = wp_parse_args($data, $defaults);

            $result = $wpdb->insert(
                $table,
                $data,
                array('%d', '%d', '%d', '%f', '%f', '%f', '%s', '%d', '%s')
            );

            if ($result === false) {
                return false;
            }

            return (int) $wpdb->insert_id;
        }

        /**
         * Updates a settlement request.
         *
         * @param int   $id   Settlement request ID.
         * @param array $data Update data.
         * @return bool
         */
        public function update_settlement_request($id, $data)
        {
            global $wpdb;
            $table = self::settlement_table_name();

            $id = absint($id);
            if ($id <= 0) {
                return false;
            }

            $format = array();
            foreach ($data as $key => $value) {
                switch ($key) {
                    case 'order_id':
                    case 'user_id':
                    case 'product_id':
                    case 'admin_id':
                        $format[] = '%d';
                        break;
                    case 'remaining_amount':
                    case 'discount_amount':
                    case 'final_amount':
                        $format[] = '%f';
                        break;
                    case 'status':
                    case 'admin_note':
                    case 'reviewed_at':
                        $format[] = '%s';
                        break;
                    default:
                        unset($data[$key]);
                        break;
                }
            }

            if (empty($data)) {
                return false;
            }

            $result = $wpdb->update($table, $data, array('id' => $id), $format, array('%d'));

            return $result !== false;
        }

        /**
         * Retrieves a single settlement request by ID.
         *
         * @param int $id Settlement request ID.
         * @return object|null
         */
        public function get_settlement_request($id)
        {
            global $wpdb;
            $table = self::settlement_table_name();

            $id = absint($id);
            if ($id <= 0) {
                return null;
            }

            return $wpdb->get_row(
                $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id)
            );
        }

        /**
         * Retrieves all settlement requests with optional filtering.
         *
         * @param array $args Query arguments.
         * @return array
         */
        public function get_settlement_requests($args = array())
        {
            global $wpdb;
            $table = self::settlement_table_name();

            $defaults = array(
                'status'   => '',
                'user_id'  => 0,
                'orderby'  => 'id',
                'order'    => 'DESC',
                'per_page' => 20,
                'page'     => 1,
            );

            $args = wp_parse_args($args, $defaults);

            $where = '1=1';
            $params = array();

            if (!empty($args['status'])) {
                $where .= ' AND s.status = %s';
                $params[] = $args['status'];
            }
            if (!empty($args['user_id'])) {
                $where .= ' AND s.user_id = %d';
                $params[] = absint($args['user_id']);
            }

            $orderby = in_array($args['orderby'], array('id', 'order_id', 'remaining_amount', 'final_amount', 'status', 'requested_at'), true)
                ? $args['orderby'] : 'id';
            $order = strtoupper($args['order']) === 'ASC' ? 'ASC' : 'DESC';

            $per_page = absint($args['per_page']);
            $page = max(1, absint($args['page']));
            $offset = ($page - 1) * $per_page;

            $sql = "SELECT s.*, u.display_name AS customer_name
                    FROM {$table} s
                    LEFT JOIN {$wpdb->users} u ON s.user_id = u.ID
                    WHERE {$where}
                    ORDER BY s.{$orderby} {$order}
                    LIMIT %d OFFSET %d";

            $params[] = $per_page;
            $params[] = $offset;

            $sql = $wpdb->prepare($sql, $params);

            return $wpdb->get_results($sql);
        }

        /**
         * Counts settlement requests matching the filter.
         *
         * @param array $args Query arguments.
         * @return int
         */
        public function count_settlement_requests($args = array())
        {
            global $wpdb;
            $table = self::settlement_table_name();

            $defaults = array(
                'status'  => '',
                'user_id' => 0,
            );

            $args = wp_parse_args($args, $defaults);

            $where = '1=1';
            $params = array();

            if (!empty($args['status'])) {
                $where .= ' AND s.status = %s';
                $params[] = $args['status'];
            }
            if (!empty($args['user_id'])) {
                $where .= ' AND s.user_id = %d';
                $params[] = absint($args['user_id']);
            }

            $sql = "SELECT COUNT(*) FROM {$table} s WHERE {$where}";

            if (!empty($params)) {
                $sql = $wpdb->prepare($sql, $params);
            }

            return (int) $wpdb->get_var($sql);
        }

        /**
         * Calculates the remaining balance for an order (sum of unpaid installments).
         *
         * @param int $order_id Order ID.
         * @return float
         */
        public function get_order_remaining_balance($order_id)
        {
            global $wpdb;
            $table = self::table_name();

            $order_id = absint($order_id);
            if ($order_id <= 0) {
                return 0.0;
            }

            return (float) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COALESCE(SUM(amount),0) FROM {$table}
                     WHERE order_id = %d AND status IN ('unpaid','overdue')",
                    $order_id
                )
            );
        }

        /**
         * Checks if an order has any pending settlement request.
         *
         * @param int $order_id Order ID.
         * @return bool
         */
        public function has_pending_settlement_request($order_id)
        {
            global $wpdb;
            $table = self::settlement_table_name();

            $order_id = absint($order_id);
            if ($order_id <= 0) {
                return false;
            }

            $count = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table} WHERE order_id = %d AND status = 'pending'",
                    $order_id
                )
            );

            return $count > 0;
        }

        /**
         * Marks an order's remaining installments as cancelled (settled).
         *
         * @param int $order_id Order ID.
         * @return int Number of updated rows.
         */
        public function settle_order_installments($order_id)
        {
            global $wpdb;
            $table = self::table_name();

            $order_id = absint($order_id);
            if ($order_id <= 0) {
                return 0;
            }

            $result = $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$table} SET status = 'paid', paid_date = %s
                     WHERE order_id = %d AND status IN ('unpaid','overdue')",
                    current_time('mysql'),
                    $order_id
                )
            );

            return (int) $result;
        }

        /**
         * Retrieves all unique orders that have installment records (for customer contracts).
         *
         * @param int $user_id User ID. 0 for all users.
         * @return array
         */
        public function get_user_contracts($user_id)
        {
            global $wpdb;
            $table = self::table_name();

            $user_id = absint($user_id);
            if ($user_id <= 0) {
                return array();
            }

            return $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT order_id, user_id, product_id, MIN(installment_number) as first_installment,
                            MAX(total_installments) as total_installments,
                            SUM(amount) as total_amount,
                            SUM(CASE WHEN status = 'paid' THEN amount ELSE 0 END) as paid_amount,
                            SUM(CASE WHEN status IN ('unpaid','overdue') THEN amount ELSE 0 END) as remaining_amount,
                            MIN(due_date) as first_due_date,
                            MAX(due_date) as last_due_date
                     FROM {$table} WHERE user_id = %d
                     GROUP BY order_id
                     ORDER BY order_id DESC",
                    $user_id
                )
            );
        }

        /**
         * Retrieves installments that need SMS notification for a given event type.
         *
         * @param string $event_type  'pre_due', 'due_date', or 'overdue'.
         * @param string $date_from   Start date (Y-m-d). Empty for no lower bound.
         * @param string $date_to     End date (Y-m-d). Empty for no upper bound.
         * @return array
         */
        public function get_installments_for_sms($event_type, $date_from, $date_to)
        {
            global $wpdb;
            $table = self::table_name();

            $where = "i.status IN ('unpaid', 'overdue')";
            $params = array();

            switch ($event_type) {
                case 'pre_due':
                    if (!empty($date_from) && !empty($date_to)) {
                        $where .= ' AND i.due_date >= %s AND i.due_date <= %s';
                        $params[] = $date_from;
                        $params[] = $date_to;
                    }
                    break;

                case 'due_date':
                    $where .= ' AND i.due_date = %s';
                    $params[] = $date_to;
                    break;

                case 'overdue':
                    $where .= ' AND i.due_date < %s';
                    $params[] = $date_to;
                    break;
            }

            $sql = "SELECT i.* FROM {$table} i WHERE {$where} ORDER BY i.due_date ASC";

            if (!empty($params)) {
                $sql = $wpdb->prepare($sql, $params);
            }

            return $wpdb->get_results($sql);
        }
    }
}
