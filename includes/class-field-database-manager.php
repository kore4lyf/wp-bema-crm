<?php

namespace Bema;

use Exception;
use Bema\BemaCRMLogger;

if (!defined('ABSPATH')) {
  exit;
}

class Field_Database_Manager
{
  /**
   * @var string
   */
  private $table_name;

  /**
   * @var wpdb
   */
  private $wpdb;

  /**
   * @var BemaCRMLogger
   */
  private $logger;

  public function __construct(?BemaCRMLogger $logger = null)
  {
    global $wpdb;
    $this->wpdb = $wpdb;
    $this->table_name = $wpdb->prefix . 'bemacrm_fieldmeta';
    $this->logger = $logger ?? new BemaCRMLogger();
  }

  /**
   * Creates the custom database table.
   *
   * @return bool
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
                field_name VARCHAR(255) NOT NULL,
                field_id BIGINT UNSIGNED NOT NULL,

                PRIMARY KEY (id),
                UNIQUE KEY field_id (field_id)
            ) $charset_collate;";

      $result = dbDelta($sql);

      if (!$result) {
        throw new Exception('Failed to create the field table.');
      }

      return true;
    } catch (Exception $e) {
      $this->logger->log('Field_Database_Manager Error: ' . $e->getMessage(), 'error');
      return false;
    }
  }

  /**
   * Insert a new field.
   *
   * @param int    $field_id
   * @param string $field_name
   * @return int|false
   */
  public function insert_field($field_id, $field_name)
  {
    try {
      $inserted = $this->wpdb->insert(
        $this->table_name,
        [
          'field_id' => absint($field_id),
          'field_name' => sanitize_text_field($field_name)
        ],
        ['%d', '%s']
      );

      if (false === $inserted) {
        throw new Exception('Failed to insert field: ' . $this->wpdb->last_error);
      }

      return $this->wpdb->insert_id;
    } catch (Exception $e) {
      $this->logger->log('Field_Database_Manager Error: ' . $e->getMessage(), 'error');
      return false;
    }
  }

  /**
   * Update a field by its field_id.
   *
   * @param int    $field_id
   * @param string|null $new_name
   * @param mixed|null $new_value
   * @return int|false
   */
  public function update_field_by_id($field_id, $new_name = null)
  {
    try {
      $data = [];
      $data_format = [];
      $where = ['field_id' => absint($field_id)];
      $where_format = ['%d'];

      if ($new_name !== null) {
        $data['field_name'] = sanitize_text_field($new_name);
        $data_format[] = '%s';
      }

      if (empty($data)) {
        return 0;
      }

      $updated = $this->wpdb->update(
        $this->table_name,
        $data,
        $where,
        $data_format,
        $where_format
      );

      if (false === $updated) {
        throw new Exception('Failed to update field: ' . $this->wpdb->last_error);
      }

      return $updated;
    } catch (Exception $e) {
      $this->logger->log('Field_Database_Manager Error: ' . $e->getMessage(), 'error');
      return false;
    }
  }

  /**
   * Delete a field by its field_name.
   *
   * @param string $field_name
   * @return int|false
   */
  public function delete_field_by_name($field_name)
  {
    try {
      $deleted = $this->wpdb->delete(
        $this->table_name,
        ['field_name' => sanitize_text_field($field_name)],
        ['%s']
      );

      if (false === $deleted) {
        throw new Exception('Failed to delete field: ' . $this->wpdb->last_error);
      }

      return $deleted;
    } catch (Exception $e) {
      $this->logger->log('Field_Database_Manager Error: ' . $e->getMessage(), 'error');
      return false;
    }
  }


  /**
   * Get a field by its field_id.
   *
   * @param int $field_id
   * @return array|null
   */
  public function get_field_by_id($field_id)
  {
    return $this->wpdb->get_row(
      $this->wpdb->prepare(
        "SELECT * FROM {$this->table_name} WHERE field_id = %d",
        absint($field_id)
      ),
      ARRAY_A
    );
  }

  /**
   * Get a field by its field_name.
   *
   * @param string $field_name
   * @return array|null
   */
  public function get_field_by_name($field_name)
  {
    return $this->wpdb->get_row(
      $this->wpdb->prepare(
        "SELECT * FROM {$this->table_name} WHERE field_name = %s",
        sanitize_text_field($field_name)
      ),
      ARRAY_A
    );
  }


  /**
   * Get all fields.
   *
   * @return array
   */
  public function get_all_fields()
  {
    return $this->wpdb->get_results("SELECT * FROM {$this->table_name}", ARRAY_A);
  }
}
