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

            update_option('wcip_db_version', WCIP_VERSION);
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
    }
}
