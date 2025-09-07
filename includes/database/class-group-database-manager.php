<?php

namespace Bema\Database;

use Exception;
use Bema\Bema_CRM_Logger;
use wpdb;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Manages all database operations related to the `bemacrm_groupmeta` table.
 */
class Group_Database_Manager
{

    /**
     * @var string The name of the groups table.
     */
    private $table_name;

    /**
     * @var string The name of the campaigns table.
     */
    private $campaign_table_name;

    /**
     * @var wpdb The WordPress database object.
     */
    private $wpdb;

    /**
     * @var Bema_CRM_Logger The logger instance for recording errors.
     */
    private Bema_CRM_Logger $logger;

    /**
     * Group_Database_Manager constructor.
     *
     * Initializes the class by setting up the table names and the WordPress database object.
     *
     * @param Bema_CRM_Logger|null $logger The logger instance. A new one is created if null.
     */
    public function __construct(?Bema_CRM_Logger $logger = null)
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table_name = $wpdb->prefix . 'bemacrm_groupmeta';
        $this->campaign_table_name = $wpdb->prefix . 'bemacrm_campaignsmeta';
        if ($logger) {
            $this->logger = $logger;
            $this->logger->setIdentifier('group-database');
        } else {
            $this->logger = Bema_CRM_Logger::create('group-database');
        }
    }

    /**
     * Creates the group meta table with 'id' as the primary key and no auto-increment.
     *
     * @return bool True on success, false on failure.
     */
    public function create_table(): bool
    {
        try {
            if (!function_exists('dbDelta')) {
                require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            }

            $charset_collate = $this->wpdb->get_charset_collate();

            $sql = "CREATE TABLE {$this->table_name} (
                id BIGINT UNSIGNED NOT NULL,
                group_name VARCHAR(255) NOT NULL,
                campaign_id BIGINT UNSIGNED NOT NULL,
                
                PRIMARY KEY (id),
                CONSTRAINT fk_bemacrm_groupmeta_campaign_id FOREIGN KEY (campaign_id) REFERENCES {$this->campaign_table_name}(id) ON DELETE CASCADE
            ) $charset_collate;";

            $result = dbDelta($sql);

            if (!$result) {
                throw new Exception('Failed to create the database table.');
            }
            return true;
        } catch (Exception $e) {
            $this->logger->error('Group_Database_Manager Error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Inserts or updates a group using the provided ID.
     *
     * @param int    $id          The group ID to update or insert.
     * @param string $group_name  The name of the group.
     * @param int    $campaign_id The ID of the campaign the group belongs to.
     * @return int|false The group ID on success, or false on failure.
     */
    public function upsert_group(int $id, string $group_name, int $campaign_id)
    {
        try {
            $sanitized_id = absint($id);
            $sanitized_group_name = sanitize_text_field($group_name);
            $sanitized_campaign_id = absint($campaign_id);

            $existing_group = $this->get_group_by_id($sanitized_id);

            if ($existing_group) {
                $updated = $this->wpdb->update(
                    $this->table_name,
                    [
                        'group_name' => $sanitized_group_name,
                        'campaign_id' => $sanitized_campaign_id,
                    ],
                    [
                        'id' => $sanitized_id,
                    ],
                    ['%s', '%d'],
                    ['%d']
                );
                
                if (false === $updated) {
                    throw new Exception('Failed to update group: ' . $this->wpdb->last_error);
                }
                
                return $sanitized_id;

            } else {
                $inserted = $this->wpdb->insert(
                    $this->table_name,
                    [
                        'id' => $sanitized_id,
                        'group_name' => $sanitized_group_name,
                        'campaign_id' => $sanitized_campaign_id,
                    ],
                    ['%d', '%s', '%d']
                );

                if (false === $inserted) {
                    throw new Exception('Failed to insert group: ' . $this->wpdb->last_error);
                }
                return $sanitized_id;
            }

        } catch (Exception $e) {
            $this->logger->error('Group_Database_Manager Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Bulk inserts or updates groups. The input array must now contain 'id' instead of 'group_id'.
     *
     * @param array $groups_to_upsert An array of group data arrays. Each array must contain 'id', 'group_name', and 'campaign_id'.
     * @return int|false The number of affected rows on success, or false on failure.
     */
    public function upsert_groups_bulk(array $groups_to_upsert)
    {

        if (empty($groups_to_upsert)) {
            return false;
        }

        try {
            $placeholders = [];
            $values = [];
            
            foreach ($groups_to_upsert as $group) {
                $placeholders[] = "(%d, %s, %d)";
                $values[] = absint($group['id']);
                $values[] = sanitize_text_field($group['group_name']);
                $values[] = absint($group['campaign_id']);
            }

            $query = "INSERT INTO {$this->table_name} (id, group_name, campaign_id) VALUES " .
                     implode(', ', $placeholders) .
                     " ON DUPLICATE KEY UPDATE group_name = VALUES(group_name), campaign_id = VALUES(campaign_id)";

            $result = $this->wpdb->query($this->wpdb->prepare($query, $values));

            if (false === $result) {
                throw new Exception('Failed to bulk upsert groups: ' . $this->wpdb->last_error);
            }

            return $result;
        } catch (Exception $e) {
            echo 'Failed: ' . $e->getMessage();
            $this->logger->error('Group_Database_Manager Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Deletes a group by its name.
     *
     * @param string $group_name The name of the group to delete.
     * @return int|false The number of deleted rows on success, or false on failure.
     */
    public function delete_group_by_name($group_name)
    {
        try {
            $deleted = $this->wpdb->delete(
                $this->table_name,
                ['group_name' => sanitize_text_field($group_name)],
                ['%s']
            );

            if (false === $deleted) {
                throw new Exception('Failed to delete group: ' . $this->wpdb->last_error);
            }
            return $deleted;
        } catch (Exception $e) {
            $this->logger->error('Group_Database_Manager Error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Deletes a group using the provided ID.
     *
     * @param int $id The ID of the group to delete.
     * @return int|false The number of deleted rows on success, or false on failure.
     */
    public function delete_group_by_id(int $id)
    {
        try {
            $deleted = $this->wpdb->delete(
                $this->table_name,
                ['id' => absint($id)],
                ['%d']
            );
            if (false === $deleted) {
                throw new Exception('Failed to delete group: ' . $this->wpdb->last_error);
            }
            return $deleted;
        } catch (Exception $e) {
            $this->logger->error('Group_Database_Manager Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Retrieves a group by its name.
     *
     * @param string $group_name The name of the group.
     * @return array|object|null The group data as an associative array, or null if not found.
     */
    public function get_group_by_name(string $group_name)
    {
        return $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table_name} WHERE group_name = %s",
                sanitize_text_field($group_name)
            ),
            ARRAY_A
        );
    }

    /**
     * Retrieves a group by its ID.
     *
     * @param int $id The ID of the group to retrieve.
     * @return array|object|null The group data as an associative array, or null if not found.
     */
    public function get_group_by_id(int $id)
    {
        return $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table_name} WHERE id = %d",
                absint($id)
            ),
            ARRAY_A
        );
    }

    /**
     * Retrieves all groups associated with a specific campaign ID.
     *
     * @param int $campaign_id The ID of the campaign.
     * @return array An array of group data, or an empty array if none found.
     */
    public function get_groups_by_campaign_id(int $campaign_id)
    {
        return $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table_name} WHERE campaign_id = %d",
                absint($campaign_id)
            ),
            ARRAY_A
        );
    }

    /**
     * Retrieves all groups from the database.
     *
     * @return array An array of all group data, or an empty array if none found.
     */
    public function get_all_groups()
    {
        return $this->wpdb->get_results("SELECT * FROM {$this->table_name}", ARRAY_A);
    }

    /**
     * Deletes the group meta table from the database.
     *
     * @return bool True if the table was successfully deleted, false otherwise.
     */
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
            $this->logger->error('Database Manager Error: ' . $e->getMessage());
            return false;
        }
    }
}
