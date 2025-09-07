<?php

namespace Bema;

use Exception;
use Bema\Bema_CRM_Logger;

if (!defined('ABSPATH')) {
    exit;
}

class Database_Migrations
{
    private $wpdb;
    private $tables;
    private $logger;
    private $current_version = '1.0.0';
    private $backup_prefix = 'bema_backup_';

    // Migration status constants
    const STATUS_PENDING = 'pending';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';
    const STATUS_ROLLED_BACK = 'rolled_back';

    public function __construct(Bema_CRM_Logger $logger)
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->logger = $logger;

        $this->tables = [
            'bemacrmmeta' => [
                'version' => '1.0.1', // Version bumped to indicate schema change
                'sql' => "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}bemacrmmeta (
                meta_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                bema_id bigint(20) NOT NULL DEFAULT '0',
                tier varchar(255) DEFAULT 'unassigned',
                purchase_indicator tinyint(1) DEFAULT 0,
                campaign varchar(255) DEFAULT NULL,
                mailerlite_group_id varchar(50) DEFAULT '0',
                date_added DATETIME DEFAULT CURRENT_TIMESTAMP,
                date_modified DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                candidate varchar(255) DEFAULT NULL,
                subscriber varchar(255) DEFAULT NULL,
                first_name varchar(255) DEFAULT NULL,
                last_name varchar(255) DEFAULT NULL,
                source varchar(255) DEFAULT NULL,
                last_sync DATETIME DEFAULT NULL,
                sync_status varchar(50) DEFAULT NULL,
                error_log TEXT DEFAULT NULL,
                PRIMARY KEY (meta_id),
                KEY bema_id (bema_id),
                KEY tier (tier),
                KEY purchase_idx (purchase_indicator),
                KEY subscriber_campaign (subscriber(50), campaign),
                KEY campaign_idx (campaign),
                KEY date_added_idx (date_added),
                KEY date_modified_idx (date_modified),
                KEY mailerlite_group_id (mailerlite_group_id),
                KEY last_sync (last_sync),
                KEY sync_status (sync_status)
            ) {$wpdb->get_charset_collate()};"
            ],
            'subscribers' => [
                'version' => '1.0.0',
                'sql' => "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}subscribers (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                email varchar(255) NOT NULL,
                tier varchar(50) DEFAULT 'unassigned',
                date_added datetime DEFAULT CURRENT_TIMESTAMP,
                status varchar(50) DEFAULT 'active',
                source varchar(100) DEFAULT NULL,
                last_sync datetime DEFAULT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY email (email),
                KEY tier (tier),
                KEY status (status)
            ) {$wpdb->get_charset_collate()};"
            ],
            'sync_logs' => [
                'version' => '1.0.1', // Version bumped to indicate schema change
                'sql' => "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}sync_logs (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                operation varchar(255) NOT NULL,
                campaign varchar(50) DEFAULT NULL,
                status varchar(50) NOT NULL,
                data longtext DEFAULT NULL,
                error_message text DEFAULT NULL,
                retry_count int DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY operation (operation),
                KEY campaign_status_idx (campaign, status),
                KEY created_at_idx (created_at),
                KEY retry_count (retry_count)
            ) {$wpdb->get_charset_collate()};"
            ],
            'transitions' => [
                'version' => '1.0.0',
                'sql' => "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}transitions (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        email varchar(255) NOT NULL,
        from_campaign varchar(50) NOT NULL,
        to_campaign varchar(50) NOT NULL,
        from_tier varchar(50) NOT NULL,
        to_tier varchar(50) NOT NULL,
        status varchar(20) NOT NULL DEFAULT 'pending',
        attempts int DEFAULT 0,
        last_attempt DATETIME DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        completed_at DATETIME DEFAULT NULL,
        error_message text DEFAULT NULL,
        PRIMARY KEY (id),
        KEY email (email),
        KEY status (status),
        KEY campaign_idx (from_campaign, to_campaign),
        KEY created_at (created_at)
    ) {$wpdb->get_charset_collate()};"
            ]
        ];
    }

    public function install(): bool
    {
        try {
            if (!function_exists('dbDelta')) {
                require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            }

            $this->logger->log('Starting database installation', 'info');

            foreach ($this->tables as $table => $info) {
                // Create backup if table exists
                if ($this->tableExists($table)) {
                    $this->backupTable($table);
                }

                // Create or upgrade table
                $result = dbDelta($info['sql']);

                $this->logger->log("Table {$table} processed", 'info', [
                    'changes' => $result
                ]);
            }

            update_option('bema_crm_db_version', $this->current_version);
            $this->logger->log('Database installation completed', 'info');

            return true;
        } catch (Exception $e) {
            $this->logger->log('Database installation failed', 'error', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    public function upgrade(): bool
    {
        try {
            $installed_version = get_option('bema_crm_db_version');

            if ($installed_version === $this->current_version) {
                return true;
            }

            $this->logger->log('Starting database upgrade', 'info', [
                'from_version' => $installed_version,
                'to_version' => $this->current_version
            ]);

            // Backup all tables before upgrade
            foreach ($this->tables as $table => $info) {
                $this->backupTable($table);
            }

            $result = $this->install();

            if ($result) {
                $this->logger->log('Database upgrade completed', 'info');
                return true;
            }

            return false;
        } catch (Exception $e) {
            $this->logger->error('Database upgrade failed', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    public function uninstall(): bool
    {
        try {
            $this->logger->log('Starting database uninstallation', 'info');

            foreach ($this->tables as $table => $info) {
                // Backup before dropping
                $this->backupTable($table);

                $this->wpdb->query("DROP TABLE IF EXISTS {$this->wpdb->prefix}{$table}");
                $this->logger->log("Table {$table} dropped", 'info');
            }

            delete_option('bema_crm_db_version');
            $this->logger->log('Database uninstallation completed', 'info');

            return true;
        } catch (Exception $e) {
            $this->logger->log('Database uninstallation failed', 'error', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    private function backupTable(string $table): bool
    {
        $source = $this->wpdb->prefix . $table;
        $backup = $this->wpdb->prefix . $this->backup_prefix . $table . '_' . date('Y_m_d_H_i_s');

        try {
            $this->wpdb->query("CREATE TABLE IF NOT EXISTS {$backup} LIKE {$source}");
            $this->wpdb->query("INSERT INTO {$backup} SELECT * FROM {$source}");

            $this->logger->log("Table {$table} backed up", 'info', [
                'backup_name' => $backup
            ]);

            return true;
        } catch (Exception $e) {
            $this->logger->log("Failed to backup table {$table}", 'error', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    private function tableExists(string $table): bool
    {
        return $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SHOW TABLES LIKE %s",
                $this->wpdb->prefix . $table
            )
        ) !== null;
    }

    public function getBackups(): array
    {
        $backups = [];
        foreach ($this->tables as $table => $info) {
            $results = $this->wpdb->get_results(
                "SHOW TABLES LIKE '{$this->wpdb->prefix}{$this->backup_prefix}{$table}_%'"
            );
            foreach ($results as $result) {
                $backup = (array) $result;
                $backups[] = reset($backup);
            }
        }
        return $backups;
    }

    public function maybe_upgrade(): bool
    {
        try {
            foreach ($this->tables as $table => $info) {
                $installed_version = get_option("bema_{$table}_version");
                if ($installed_version !== $info['version']) {
                    // Run the upgrade SQL
                    $this->wpdb->query($info['sql']);

                    // Update stored version
                    update_option("bema_{$table}_version", $info['version']);

                    $this->logger->log("Upgraded {$table} table", 'info', [
                        'from' => $installed_version,
                        'to' => $info['version']
                    ]);
                }
            }
            return true;
        } catch (Exception $e) {
            $this->logger->error('Database upgrade failed', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    public function restoreBackup(string $backup): bool
    {
        try {
            if (!$this->wpdb->get_var("SHOW TABLES LIKE '{$backup}'")) {
                throw new Exception("Backup table {$backup} does not exist");
            }

            // Extract original table name from backup name
            preg_match("/{$this->backup_prefix}(.+)_\d{4}_\d{2}/", $backup, $matches);
            if (empty($matches[1])) {
                throw new Exception("Invalid backup table name");
            }

            $originalTable = $this->wpdb->prefix . $matches[1];

            $this->wpdb->query("DROP TABLE IF EXISTS {$originalTable}");
            $this->wpdb->query("CREATE TABLE {$originalTable} LIKE {$backup}");
            $this->wpdb->query("INSERT INTO {$originalTable} SELECT * FROM {$backup}");

            $this->logger->log('Backup restored successfully', 'info', [
                'backup' => $backup,
                'table' => $originalTable
            ]);

            return true;
        } catch (Exception $e) {
            $this->logger->error('Failed to restore backup', [
                'error' => $e->getMessage(),
                'backup' => $backup
            ]);
            return false;
        }
    }
}