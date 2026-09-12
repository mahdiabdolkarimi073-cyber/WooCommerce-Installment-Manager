<?php
/**
 * SMS Logger — stores and retrieves SMS send logs in a custom DB table.
 *
 * @package WC_Installment_Payment
 */

if (!defined('ABSPATH')) {
    exit;
}

class SMS_Logger
{
    private static $instance = null;

    const TABLE_NAME = 'installment_sms_log';

    public static function instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
    }

    public static function table_name()
    {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_NAME;
    }

    public function create_table()
    {
        global $wpdb;

        $table = self::table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            installment_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            event_type VARCHAR(20) NOT NULL DEFAULT '',
            recipient VARCHAR(20) NOT NULL DEFAULT '',
            message TEXT NOT NULL,
            status VARCHAR(10) NOT NULL DEFAULT 'sent',
            provider VARCHAR(50) NULL DEFAULT NULL,
            provider_response TEXT NULL DEFAULT NULL,
            sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY order_id (order_id),
            KEY installment_id (installment_id),
            KEY event_type (event_type),
            KEY status (status)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    public function log($data)
    {
        global $wpdb;
        $table = self::table_name();

        $defaults = array(
            'order_id'         => 0,
            'installment_id'   => 0,
            'event_type'       => '',
            'recipient'        => '',
            'message'          => '',
            'status'           => 'sent',
            'provider'         => '',
            'provider_response'=> '',
        );

        $data = wp_parse_args($data, $defaults);

        $result = $wpdb->insert(
            $table,
            $data,
            array('%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s')
        );

        return $result !== false ? (int) $wpdb->insert_id : false;
    }

    public function get_logs($args = array())
    {
        global $wpdb;
        $table = self::table_name();

        $defaults = array(
            'per_page'  => 20,
            'page'      => 1,
            'orderby'   => 'id',
            'order'     => 'DESC',
            'status'    => '',
            'event_type'=> '',
        );

        $args = wp_parse_args($args, $defaults);

        $where = '1=1';
        $params = array();

        if (!empty($args['status'])) {
            $where .= ' AND status = %s';
            $params[] = $args['status'];
        }
        if (!empty($args['event_type'])) {
            $where .= ' AND event_type = %s';
            $params[] = $args['event_type'];
        }

        $orderby = in_array($args['orderby'], array('id', 'sent_at', 'status', 'event_type'), true)
            ? $args['orderby'] : 'id';
        $order = strtoupper($args['order']) === 'ASC' ? 'ASC' : 'DESC';

        $per_page = absint($args['per_page']);
        $page = max(1, absint($args['page']));
        $offset = ($page - 1) * $per_page;

        $sql = "SELECT * FROM {$table} WHERE {$where} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
        $params[] = $per_page;
        $params[] = $offset;

        $sql = $wpdb->prepare($sql, $params);

        return $wpdb->get_results($sql);
    }

    public function count_logs($args = array())
    {
        global $wpdb;
        $table = self::table_name();

        $where = '1=1';
        $params = array();

        if (!empty($args['status'])) {
            $where .= ' AND status = %s';
            $params[] = $args['status'];
        }
        if (!empty($args['event_type'])) {
            $where .= ' AND event_type = %s';
            $params[] = $args['event_type'];
        }

        $sql = "SELECT COUNT(*) FROM {$table} WHERE {$where}";

        if (!empty($params)) {
            $sql = $wpdb->prepare($sql, $params);
        }

        return (int) $wpdb->get_var($sql);
    }

    public function has_been_sent($installment_id, $event_type)
    {
        global $wpdb;
        $table = self::table_name();

        $installment_id = absint($installment_id);
        if ($installment_id <= 0) {
            return false;
        }

        $count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table}
                 WHERE installment_id = %d AND event_type = %s AND status = 'sent'",
                $installment_id,
                $event_type
            )
        );

        return $count > 0;
    }

    public function delete_old_logs($days = 90)
    {
        global $wpdb;
        $table = self::table_name();

        $days = absint($days);
        if ($days <= 0) {
            return 0;
        }

        $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        return (int) $wpdb->query(
            $wpdb->prepare("DELETE FROM {$table} WHERE sent_at < %s", $cutoff)
        );
    }
}
