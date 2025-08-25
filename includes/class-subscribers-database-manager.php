<?php

namespace Bema;

use Exception;
use Bema\BemaCRMLogger;

if (!defined('ABSPATH')) {
    exit;
}

class Subscribers_Database_Manager
{
    private $table_name;
    private $wpdb;
    private $logger;

    public function __construct(?BemaCRMLogger $logger = null)
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table_name = $wpdb->prefix . 'bemacrm_subscribersmeta';
        $this->logger = $logger ?? new BemaCRMLogger();
    }

    /**
     * Creates the custom database table for subscribers.
     * Only stores timestamps that actually contain values from MailerLite.
     */
    public function create_table()
    {
        try {
            if (!function_exists('dbDelta')) {
                require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            }

            $charset_collate = $this->wpdb->get_charset_collate();

            $sql = "CREATE TABLE {$this->table_name} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            subscriber_id BIGINT UNSIGNED NOT NULL,
            email VARCHAR(255) NOT NULL,
            name VARCHAR(255),
            status ENUM('active', 'unsubscribed', 'unconfirmed', 'bounced', 'junk') NOT NULL DEFAULT 'unconfirmed',

            subscribed_at DATETIME NULL,
            unsubscribed_at DATETIME NULL,
            updated_at DATETIME NULL,

            PRIMARY KEY  (id),
            UNIQUE KEY subscriber_id (subscriber_id),
            UNIQUE KEY email (email)
            ) $charset_collate;";

            $result = dbDelta($sql);

            if (!$result) {
                throw new Exception('Failed to create the database table.');
            }
            return true;
        } catch (Exception $e) {
            $this->logger->log('Subscribers_Database_Manager Error: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Insert a single subscriber.
     */
    public function insert_subscriber(
        $subscriber_id,
        $email,
        $name,
        $status,
        $subscribed_at = null,
        $unsubscribed_at = null,
        $updated_at = null
    ) {
        try {
            $data = [
                'subscriber_id' => absint($subscriber_id),
                'email' => sanitize_email($email),
                'name' => sanitize_text_field($name),
                'status' => sanitize_text_field($status),
            ];
            $format = ['%d', '%s', '%s', '%s'];

            if ($subscribed_at !== null) {
                $data['subscribed_at'] = $subscribed_at;
                $format[] = '%s';
            }
            if ($unsubscribed_at !== null) {
                $data['unsubscribed_at'] = $unsubscribed_at;
                $format[] = '%s';
            }
            if ($updated_at !== null) {
                $data['updated_at'] = $updated_at;
                $format[] = '%s';
            }

            $inserted = $this->wpdb->insert($this->table_name, $data, $format);

            if (false === $inserted) {
                throw new Exception('Failed to insert subscriber: ' . $this->wpdb->last_error);
            }
            return true;
        } catch (Exception $e) {
            $this->logger->log('Subscribers_Database_Manager Error: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Bulk insert subscribers.
     */
    public function insert_subscribers_bulk(array $subscribers_to_insert)
    {
        if (empty($subscribers_to_insert)) {
            return false;
        }

        try {
            $placeholders = [];
            $values = [];
            foreach ($subscribers_to_insert as $s) {
                $placeholders[] = "(%d, %s, %s, %s, NULLIF(%s,''), NULLIF(%s,''), NULLIF(%s,''))";
                $values[] = absint($s['subscriber_id']);
                $values[] = sanitize_email($s['email']);
                $values[] = sanitize_text_field($s['name'] ?? '');
                $values[] = sanitize_text_field($s['status'] ?? 'unconfirmed');
                $values[] = $s['subscribed_at'] ?? '';
                $values[] = $s['unsubscribed_at'] ?? '';
                $values[] = $s['updated_at'] ?? '';
            }

            $query = "INSERT INTO {$this->table_name}
(subscriber_id, email, name, status, subscribed_at, unsubscribed_at, updated_at)
VALUES " . implode(', ', $placeholders);

            $inserted = $this->wpdb->query($this->wpdb->prepare($query, $values));

            if (false === $inserted) {
                throw new Exception('Failed to bulk insert subscribers: ' . $this->wpdb->last_error);
            }
            return $inserted;
        } catch (Exception $e) {
            $this->logger->log('Subscribers_Database_Manager Error: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Update by email.
     */
    public function update_subscriber_by_email($current_email, $new)
    {
        try {
            $data = [];
            $fmt = [];
            $where = ['email' => sanitize_email($current_email)];
            $where_fmt = ['%s'];

            if (isset($new['subscriber_id'])) {
                $data['subscriber_id'] = absint($new['subscriber_id']);
                $fmt[] = '%d';
            }
            if (isset($new['email'])) {
                $data['email'] = sanitize_email($new['email']);
                $fmt[] = '%s';
            }
            if (isset($new['name'])) {
                $data['name'] = sanitize_text_field($new['name']);
                $fmt[] = '%s';
            }
            if (isset($new['status'])) {
                $data['status'] = sanitize_text_field($new['status']);
                $fmt[] = '%s';
            }

            if (array_key_exists('subscribed_at', $new)) {
                $data['subscribed_at'] = $new['subscribed_at'];
                $fmt[] = '%s';
            }
            if (array_key_exists('unsubscribed_at', $new)) {
                $data['unsubscribed_at'] = $new['unsubscribed_at'];
                $fmt[] = '%s';
            }
            if (array_key_exists('updated_at', $new)) {
                $data['updated_at'] = $new['updated_at'];
                $fmt[] = '%s';
            }

            if (empty($data)) {
                return 0;
            }

            $updated = $this->wpdb->update($this->table_name, $data, $where, $fmt, $where_fmt);

            if (false === $updated) {
                throw new Exception('Failed to update subscriber: ' . $this->wpdb->last_error);
            }
            return $updated;
        } catch (Exception $e) {
            $this->logger->log('Subscribers_Database_Manager Error: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Update by primary key id.
     */
    public function update_subscriber_by_id($id, $new)
    {
        try {
            $data = [];
            $fmt = [];
            $where = ['id' => absint($id)];
            $where_fmt = ['%d'];

            if (isset($new['subscriber_id'])) {
                $data['subscriber_id'] = absint($new['subscriber_id']);
                $fmt[] = '%d';
            }
            if (isset($new['email'])) {
                $data['email'] = sanitize_email($new['email']);
                $fmt[] = '%s';
            }
            if (isset($new['name'])) {
                $data['name'] = sanitize_text_field($new['name']);
                $fmt[] = '%s';
            }
            if (isset($new['status'])) {
                $data['status'] = sanitize_text_field($new['status']);
                $fmt[] = '%s';
            }

            if (array_key_exists('subscribed_at', $new)) {
                $data['subscribed_at'] = $new['subscribed_at'];
                $fmt[] = '%s';
            }
            if (array_key_exists('unsubscribed_at', $new)) {
                $data['unsubscribed_at'] = $new['unsubscribed_at'];
                $fmt[] = '%s';
            }
            if (array_key_exists('updated_at', $new)) {
                $data['updated_at'] = $new['updated_at'];
                $fmt[] = '%s';
            }

            if (empty($data)) {
                return 0;
            }

            $updated = $this->wpdb->update($this->table_name, $data, $where, $fmt, $where_fmt);

            if (false === $updated) {
                throw new Exception('Failed to update subscriber: ' . $this->wpdb->last_error);
            }
            return $updated;
        } catch (Exception $e) {
            $this->logger->log('Subscribers_Database_Manager Error: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    public function delete_subscriber_by_email($email)
    {
        try {
            $deleted = $this->wpdb->delete(
                $this->table_name,
                ['email' => sanitize_email($email)],
                ['%s']
            );

            if (false === $deleted) {
                throw new Exception('Failed to delete subscriber: ' . $this->wpdb->last_error);
            }
            return $deleted;
        } catch (Exception $e) {
            $this->logger->log('Subscribers_Database_Manager Error: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    public function get_subscriber_by_email($email)
    {
        return $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table_name} WHERE email = %s",
                sanitize_email($email)
            ),
            ARRAY_A
        );
    }

    public function get_subscriber_by_id($subscriber_id)
    {
        return $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table_name} WHERE subscriber_id = %d",
                absint($subscriber_id)
            ),
            ARRAY_A
        );
    }

    public function get_all_subscribers()
    {
        return $this->wpdb->get_results("SELECT * FROM {$this->table_name}", ARRAY_A);
    }

    /**
     * Get subscribers with optional filters and pagination.
     */
    public function get_subscribers(
        int $per_page = 25,
        int $offset = 0,
        string $campaign_name = '',
        string $tier = '',
        string $search = ''
    ): array {
        $where = [];
        $params = [];

        // Apply filters
        if ($campaign_name !== '') {
            $where[] = "c.campaign_name = %s";
            $params[] = $campaign_name;
        }

        if ($tier !== '') {
            $where[] = "c.tier = %s";
            $params[] = $tier;
        }

        if ($search !== '') {
            $where[] = "s.email LIKE %s";
            $params[] = '%' . $this->wpdb->esc_like($search) . '%';
        }

        $where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';


        if ($campaign_name !== '') {
            $params[] = $per_page;
            $params[] = $offset;

            $sql = $this->wpdb->prepare(
                "SELECT s.*, c.tier, c.purchase_id
             FROM {$this->table_name} AS s
             INNER JOIN {$this->wpdb->prefix}bemacrm_campaign_subscribersmeta AS c
                ON s.subscriber_id = c.subscriber_id
             {$where_sql}
             ORDER BY s.id DESC
             LIMIT %d OFFSET %d",
                ...$params
            );
        } else {
            $params[] = $per_page;
            $params[] = $offset;

            $sql = $this->wpdb->prepare(
                "SELECT * 
             FROM {$this->table_name} AS s
             {$where_sql}
             ORDER BY s.id DESC
             LIMIT %d OFFSET %d",
                ...$params
            );
        }

        return $this->wpdb->get_results($sql, ARRAY_A);
    }

    /**
     * Count subscribers with optional filters.
     */
    public function count_subscribers(
        string $campaign_name = '',
        string $tier = '',
        string $search = ''
    ): int {
        $where = [];
        $params = [];

        // Apply filters
        if ($campaign_name !== '') {
            $where[] = "c.campaign_name = %s";
            $params[] = $campaign_name;
        }

        if ($tier !== '') {
            $where[] = "c.tier = %s";
            $params[] = $tier;
        }

        if ($search !== '') {
            $where[] = "s.email LIKE %s";
            $params[] = '%' . $this->wpdb->esc_like($search) . '%';
        }

        $where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        if ($campaign_name !== '') {
            $sql = $this->wpdb->prepare(
                "SELECT COUNT(*)
             FROM {$this->table_name} AS s
             INNER JOIN {$this->wpdb->prefix}bemacrm_campaign_subscribersmeta AS c
                ON s.subscriber_id = c.subscriber_id
             {$where_sql}",
                ...$params
            );
        } else {
            $sql = $this->wpdb->prepare(
                "SELECT COUNT(*)
             FROM {$this->table_name} AS s
             {$where_sql}",
                ...$params
            );
        }

        return (int) $this->wpdb->get_var($sql);
    }


    /**
     * Sync with source-of-truth dataset.
     */
    public function sync_subscribers(array $mailerlite_subscribers_data)
    {
        if (empty($mailerlite_subscribers_data)) {
            $this->logger->log('No subscriber data received for sync.', 'notice');
            return false;
        }

        try {
            $affected = $this->insert_or_update_subscribers($mailerlite_subscribers_data);
            if (false === $affected) {
                throw new Exception('Failed to insert or update subscribers during sync.');
            }
            return true;
        } catch (Exception $e) {
            $this->logger->log('Subscribers_Database_Manager Sync Error: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Bulk upsert using source-provided data.
     */
    private function insert_or_update_subscribers(array $subscribers_to_process)
    {
        if (empty($subscribers_to_process)) {
            return false;
        }

        try {
            $placeholders = [];
            $values = [];

            foreach ($subscribers_to_process as $s) {
                $subscriber_id = absint($s['id']);
                $email = sanitize_email($s['email']);
                $name = sanitize_text_field(trim(($s['fields']['name'] ?? '') . ' ' . ($s['fields']['last_name'] ?? '')));
                $status = sanitize_text_field($s['status'] ?? 'unconfirmed');

                $subscribed_at = $s['subscribed_at'] ?? '';
                $unsubscribed_at = $s['unsubscribed_at'] ?? '';
                $updated_at = $s['updated_at'] ?? '';

                $placeholders[] = "(%d, %s, %s, %s, NULLIF(%s,''), NULLIF(%s,''), NULLIF(%s,''))";
                $values[] = $subscriber_id;
                $values[] = $email;
                $values[] = $name;
                $values[] = $status;
                $values[] = $subscribed_at;
                $values[] = $unsubscribed_at;
                $values[] = $updated_at;
            }

            $query = "INSERT INTO {$this->table_name}
(subscriber_id, email, name, status, subscribed_at, unsubscribed_at, updated_at)
VALUES " . implode(', ', $placeholders) . "
ON DUPLICATE KEY UPDATE
email   = VALUES(email),
name= VALUES(name),
status  = VALUES(status),
subscribed_at   = VALUES(subscribed_at),
unsubscribed_at = VALUES(unsubscribed_at),
updated_at  = VALUES(updated_at)";

            $result = $this->wpdb->query($this->wpdb->prepare($query, $values));

            if (false === $result) {
                throw new Exception('Failed to bulk upsert subscribers: ' . $this->wpdb->last_error);
            }

            return $result;
        } catch (Exception $e) {
            $this->logger->log('Subscriber_Database_Manager Error: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    public function delete_table(): bool
    {
        try {
            $sql = "DROP TABLE IF EXISTS {$this->table_name}";
            $this->wpdb->query($sql);

            if ($this->wpdb->get_var("SHOW TABLES LIKE '{$this->table_name}'") !== $this->table_name) {
                return true;
            }
            throw new Exception("Failed to delete the database table: {$this->table_name}");
        } catch (Exception $e) {
            $this->logger->log('Database Manager Error: ' . $e->getMessage(), 'error');
            return false;
        }
    }
}