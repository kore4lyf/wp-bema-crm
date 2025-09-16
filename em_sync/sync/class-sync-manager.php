<?php
namespace Bema;

class Sync_Manager
{
    public $mailerLiteInstance;
    public $logger;
    public $utils;
    public $campaign_database;
    public $field_database;
    public $group_database;
    public $subscribers_database;
    public $campaign_group_subscribers_database;
    public $sync_database;
    public $dbManager;

    const TRANSACTION_TIMEOUT = 30;

    // ========================================
    // PUBLIC SYNC METHODS
    // ========================================

    /**
     * Synchronizes album and custom campaign data with MailerLite.
     */
    public function sync_album_campaign_data(): bool
    {
        $this->logger->info('Starting album campaign data sync');

        try {
            $albums = $this->utils->get_all_albums();
            $custom_campaigns = $this->campaign_database->get_all_campaigns();
            $campaign_store_map = $this->prepare_campaign_store($albums, $custom_campaigns);
            $mailerlite_campaign_map = $this->mailerLiteInstance->get_campaigns_name_to_id_map();

            if (!is_array($mailerlite_campaign_map)) {
                $this->logger->error('Failed to retrieve MailerLite campaigns');
                return false;
            }

            $campaigns_to_upsert = $this->process_campaigns($campaign_store_map, $mailerlite_campaign_map);

            if (!empty($campaigns_to_upsert)) {
                $this->campaign_database->upsert_campaigns_bulk($campaigns_to_upsert);
            }

            $this->logger->info('Album campaign data sync completed successfully');
            return true;

        } catch (Exception $e) {
            $this->logger->error('Album campaign data sync failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Synchronizes campaign purchase fields with MailerLite and the local database.
     */
    public function sync_mailerlite_field_data(): bool
    {
        $this->logger->info('Starting MailerLite field data sync');

        try {
            $required_fields = $this->get_required_fields();

            if (empty($required_fields)) {
                $this->logger->warning('No required fields found');
                return true;
            }

            $fields_to_upsert = $this->prepare_field_data_for_upsert($required_fields);

            if (!empty($fields_to_upsert)) {
                $this->field_database->upsert_fields_bulk($fields_to_upsert);
            }

            $this->logger->info('MailerLite field data sync completed successfully');
            return true;

        } catch (Exception $e) {
            $this->logger->error('MailerLite field data sync failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Synchronizes MailerLite group data with the local database.
     */
    public function sync_mailerlite_group_data(): bool
    {
        $this->logger->info('Starting MailerLite group data sync');

        try {
            $campaign_name_list = $this->fetch_campaign_names();
            $all_campaign_group_names = $this->generate_all_campaign_group_names($campaign_name_list);
            $mailerlite_group_data = $this->fetch_mailerlite_groups();

            $process_result = $this->process_mailerlite_groups($mailerlite_group_data, $all_campaign_group_names);
            $groups_to_upsert = $process_result['groups_to_upsert'];

            $missing_groups = array_diff(
                array_map('strtoupper', $all_campaign_group_names),
                $process_result['found_names']
            );

            if (!empty($missing_groups)) {
                $new_groups = $this->create_missing_groups($missing_groups);
                $groups_to_upsert = array_merge($groups_to_upsert, $new_groups);
            }

            if (!empty($groups_to_upsert)) {
                $this->group_database->upsert_groups_bulk($groups_to_upsert);
            }

            $this->validate_group_data_in_database();
            return true;

        } catch (Exception $e) {
            $this->logger->error('MailerLite group data sync failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Synchronizes subscribers from MailerLite.
     */
    public function sync_subscribers(): int
    {
        $this->logger->info('Starting subscriber sync');

        try {
            $all_subscribers = $this->mailerLiteInstance->getSubscribers();
            $subscribers_count = count($all_subscribers);

            if (empty($all_subscribers)) {
                $this->logger->warning('No subscribers found in MailerLite');
                return 0;
            }

            $this->subscribers_database->sync_subscribers($all_subscribers);
            $this->logger->info('Subscriber sync completed successfully', ['total' => $subscribers_count]);

            return $subscribers_count;

        } catch (Exception $e) {
            $this->logger->error('Subscriber sync failed', ['error' => $e->getMessage()]);
            return 0;
        }
    }

    /**
     * Synchronizes campaign group subscribers.
     */
    public function sync_mailerlite_campaign_group_subscribers()
    {
        $this->logger->info('Starting campaign group subscriber sync');

        try {
            $campaign_subscribers_data = [];
            $campaign_group_list = $this->group_database->get_all_groups();
            $mailerlite_groups_map = $this->mailerLiteInstance->getAllGroupsNameMap();

            foreach ($campaign_group_list as $group) {
                $group_details = $mailerlite_groups_map[strtoupper($group['group_name'])] ?? null;

                if (!$group_details) {
                    continue;
                }

                $group_subscribers = $this->mailerLiteInstance->getGroupSubscribers($group['id']);

                if (empty($group_subscribers)) {
                    continue;
                }

                $campaign_name = $this->utils->get_campaign_name_from_text($group['group_name']);
                $campaign_data = $this->campaign_database->get_campaign_by_name($campaign_name);

                if (empty($campaign_data['id'])) {
                    continue;
                }

                foreach ($group_subscribers as $subscriber) {
                    $tier = $this->utils->get_tier_from_group_name($group['group_name']);
                    $purchase_id = $this->get_purchase_id_from_subscriber($subscriber, $campaign_name);
                    $field_id = $this->get_field_id_by_campaign($campaign_data['id']);

                    $campaign_subscribers_data[] = [
                        'campaign_id' => $campaign_data['id'],
                        'subscriber_id' => $subscriber['id'],
                        'group_id' => $group['id'],
                        'field_id' => $field_id,
                        'subscriber_tier' => ucwords(strtolower($tier)),
                        'purchase_id' => $purchase_id,
                    ];
                }
            }

            if (!empty($campaign_subscribers_data)) {
                $this->campaign_group_subscribers_database->upsert_campaign_subscribers_bulk($campaign_subscribers_data);
                return true;
            }

            return false;

        } catch (Exception $e) {
            $this->logger->error('Campaign group subscriber sync failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Synchronizes all MailerLite data.
     */
    public function sync_all_mailerlite_data(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('You do not have permission to perform this action.');
        }

        $start_time = microtime(true);
        $sync_option_key = 'bema_crm_sync_status';

        $this->logger->info('=== STARTING FULL MAILERLITE SYNC ===');

        try {
            $this->update_sync_status('Running', 'Updating campaigns', 0, 5, $sync_option_key);
            $this->sync_album_campaign_data();

            $this->update_sync_status('Running', 'Updating field database', 1, 5, $sync_option_key);
            $this->sync_mailerlite_field_data();

            $this->update_sync_status('Running', 'Updating group database', 2, 5, $sync_option_key);
            $this->sync_mailerlite_group_data();

            $this->update_sync_status('Running', 'Fetching subscribers data', 3, 5, $sync_option_key);
            $subscribers_count = $this->sync_subscribers();

            $this->update_sync_status('Running', 'Syncing campaign group subscribers', 4, 5, $sync_option_key);
            $this->sync_mailerlite_campaign_group_subscribers();

            $total_duration = round(microtime(true) - $start_time, 2);

            $this->update_sync_status('Completed', 'Sync completed', 5, 5, $sync_option_key, $subscribers_count);
            $this->sync_database->upsert_sync_record('Completed', $subscribers_count, "Successfully synced {$subscribers_count} subscribers.", null);

            $this->logger->info('=== FULL MAILERLITE SYNC COMPLETED SUCCESSFULLY ===', [
                'duration' => $total_duration,
                'subscribers' => $subscribers_count
            ]);

        } catch (Exception $e) {
            $this->logger->error('=== FULL MAILERLITE SYNC FAILED ===', ['error' => $e->getMessage()]);
            $this->update_sync_status('Idle', 'Sync failed: ' . $e->getMessage(), 0, 5, $sync_option_key);
            $this->sync_database->upsert_sync_record('Failed', 0, $e->getMessage(), null);
        }
    }

    /**
     * Validates group data in the database against MailerLite groups.
     */
    public function validate_group_data_in_database(): array
    {
        $this->logger->info('Starting group data validation against MailerLite');

        $validation_results = [
            'status' => 'success',
            'total_db_groups' => 0,
            'validated_groups' => 0,
            'deleted_groups' => 0,
            'deleted_group_ids' => [],
            'errors' => []
        ];

        try {
            $mailerlite_groups_map = $this->mailerLiteInstance->getAllGroupsIdMap();
            $database_groups = $this->group_database->get_all_groups();

            $validation_results['total_db_groups'] = count($database_groups);

            $this->dbManager->beginTransactionWithRetry(self::TRANSACTION_TIMEOUT);

            foreach ($database_groups as $db_group) {
                $group_id = $db_group['id'];
                $group_id_key = strtoupper((string) $group_id);

                if (isset($mailerlite_groups_map[$group_id_key])) {
                    $validation_results['validated_groups']++;
                } else {
                    $deleted_rows = $this->group_database->delete_group_by_id($group_id);
                    if ($deleted_rows > 0) {
                        $validation_results['deleted_groups']++;
                        $validation_results['deleted_group_ids'][] = $group_id;
                    }
                }
            }

            $this->dbManager->commit();
            return $validation_results;

        } catch (Exception $e) {
            $this->dbManager->rollback();
            $validation_results['status'] = 'failed';
            $validation_results['errors'][] = $e->getMessage();
            throw $e;
        }
    }

    function resync_subscribers(array $ids)
    {
        $processed = 0;
        $subscribers_data = [];
        
        foreach ($ids as $id) {
            try {
                // 1. Fetch each subscriber details from MailerLite class
                $subscriber = $this->mailerLiteInstance->getSubscriber($id);
                
                if (!empty($subscriber)) {
                    $subscribers_data[] = $subscriber;
                    $processed++;
                }
            } catch (Exception $e) {
                $this->logger->error("Failed to fetch subscriber {$id}: " . $e->getMessage());
            }
        }

        if (!empty($subscribers_data)) {
            // 2. Upsert subscriber data
            $this->subscribers_database->sync_subscribers($subscribers_data);
            
            // 3. Sync campaign group subscriber relationships
            $this->sync_individual_campaign_group_subscribers($subscribers_data);
        }

        \Bema\bema_notice("Resync complete: processed $processed subscriber(s).", 'success', 'Resync Completed');
    }

    private function sync_individual_campaign_group_subscribers(array $subscribers_data)
    {
        $campaign_subscribers_data = [];
        $campaign_group_list = $this->group_database->get_all_groups();

        foreach ($subscribers_data as $subscriber) {
            $subscriber_groups = $this->mailerLiteInstance->getSubscriberGroups($subscriber['id']);
            
            foreach ($subscriber_groups as $group_data) {
                $group_name = $group_data['name'];
                $group = array_filter($campaign_group_list, fn($g) => strtoupper($g['group_name']) === strtoupper($group_name));
                
                if (empty($group)) continue;
                
                $group = reset($group);
                $campaign_name = $this->utils->get_campaign_name_from_text($group['group_name']);
                $campaign_data = $this->campaign_database->get_campaign_by_name($campaign_name);
                
                if (empty($campaign_data['id'])) continue;
                
                $tier = $this->utils->get_tier_from_group_name($group['group_name']);
                $purchase_id = $this->get_purchase_id_from_subscriber($subscriber, $campaign_name);
                $field_id = $this->get_field_id_by_campaign($campaign_data['id']);

                $campaign_subscribers_data[] = [
                    'campaign_id' => $campaign_data['id'],
                    'subscriber_id' => $subscriber['id'],
                    'group_id' => $group['id'],
                    'field_id' => $field_id,
                    'subscriber_tier' => ucwords(strtolower($tier)),
                    'purchase_id' => $purchase_id,
                ];
            }
        }

        if (!empty($campaign_subscribers_data)) {
            $this->campaign_group_subscribers_database->upsert_campaign_subscribers_bulk($campaign_subscribers_data);
        }
    }

    // ========================================
    // PRIVATE SYNC METHODS
    // ========================================

    private function prepare_campaign_store(array $albums, array $custom_campaigns): array
    {
        $campaign_store_map = [];

        foreach ($custom_campaigns as $campaign) {
            $campaign_store_map[$campaign['campaign']] = [
                'name' => $campaign['campaign'],
                'product_id' => $campaign['product_id'],
                'album' => $campaign['album'],
                'year' => $campaign['year'],
                'artist' => $campaign['artist'],
            ];
        }

        foreach ($albums as $album) {
            $campaign_name = $this->utils->get_campaign_group_name(
                $album['year'],
                $album['artist'],
                $album['album']
            );

            $campaign_store_map[$campaign_name] = [
                'name' => $campaign_name,
                'product_id' => $album['product_id'],
                'album' => $album['album'],
                'year' => $album['year'],
                'artist' => $album['artist'],
            ];
        }

        return $campaign_store_map;
    }

    private function process_campaigns(array $campaign_store_map, array $mailerlite_campaign_map): array
    {
        $campaigns_to_upsert = [];

        foreach ($campaign_store_map as $campaign) {
            $campaign_id = $mailerlite_campaign_map[$campaign['name']] ?? null;

            if ($campaign_id) {
                $campaigns_to_upsert[] = $this->format_campaign_for_upsert($campaign, $campaign_id);
            } else {
                $campaign_id = $this->create_new_mailerlite_campaign($campaign);
                if ($campaign_id) {
                    $campaigns_to_upsert[] = $this->format_campaign_for_upsert($campaign, $campaign_id);
                }
            }
        }

        return $campaigns_to_upsert;
    }

    private function create_new_mailerlite_campaign(array $campaign): ?string
    {
        $subject = isset($campaign['album'])
            ? 'Music album: ' . $campaign['album'] . ' by ' . $campaign['artist']
            : 'Custom campaign with no album';

        $response = $this->mailerLiteInstance->create_draft_campaign(
            $campaign['name'],
            'regular',
            $subject
        );

        return $response['id'] ?? null;
    }

    private function format_campaign_for_upsert(array $campaign, string $campaign_id): array
    {
        return [
            'campaign' => strtoupper($campaign['name']),
            'id' => $campaign_id,
            'product_id' => $campaign['product_id'] ?? null,
        ];
    }

    private function get_required_fields(): array
    {
        $campaign_names = $this->utils->get_campaigns_names();
        $custom_campaigns = $this->campaign_database->get_all_campaign_names();
        $all_campaign_names = array_unique(array_merge($campaign_names, $custom_campaigns));

        return array_map(function ($name) {
            return strtoupper($name . '_purchase');
        }, $all_campaign_names);
    }

    private function prepare_field_data_for_upsert(array $required_fields): array
    {
        $fields_to_upsert = [];
        $mailerlite_fields_map = $this->get_mailerlite_fields_map();

        foreach ($required_fields as $field_name) {
            $field_id = $this->get_or_create_field($field_name, $mailerlite_fields_map);

            if ($field_id) {
                $campaign_id = $this->get_campaign_id_for_field($field_name);
                if ($campaign_id) {
                    $fields_to_upsert[] = $this->build_field_data_array($field_id, $field_name, $campaign_id);
                }
            }
        }

        return $fields_to_upsert;
    }

    private function get_mailerlite_fields_map(): array
    {
        $mailerlite_fields = $this->mailerLiteInstance->getFields();
        $fields_map = [];

        foreach ($mailerlite_fields as $field) {
            $fields_map[strtoupper($field['name'])] = $field['id'];
        }

        return $fields_map;
    }

    private function get_or_create_field(string $field_name, array $fields_map): ?string
    {
        if (isset($fields_map[$field_name])) {
            return $fields_map[$field_name];
        }

        $new_field = $this->mailerLiteInstance->createField($field_name, 'number');
        return $new_field['id'] ?? null;
    }

    private function get_campaign_id_for_field(string $field_name): ?string
    {
        $campaign_name = $this->utils->get_campaign_name_from_text($field_name);
        $campaign = $this->campaign_database->get_campaign_by_name($campaign_name);
        return $campaign['id'] ?? null;
    }

    private function build_field_data_array(string $field_id, string $field_name, string $campaign_id): array
    {
        return [
            'id' => $field_id,
            'field_name' => $field_name,
            'campaign_id' => $campaign_id
        ];
    }

    private function fetch_campaign_names(): array
    {
        return $this->campaign_database->get_all_campaign_names();
    }

    private function generate_all_campaign_group_names(array $campaign_name_list): array
    {
        $all_campaign_group_names = [];

        foreach ($campaign_name_list as $campaign_name) {
            $campaign_groups = $this->utils->get_campaign_group_names($campaign_name);
            $all_campaign_group_names = array_merge($all_campaign_group_names, $campaign_groups);
        }

        return $all_campaign_group_names;
    }

    private function fetch_mailerlite_groups(): array
    {
        return $this->mailerLiteInstance->getGroups();
    }

    private function process_mailerlite_groups(array $mailerlite_group_data, array $all_campaign_group_names): array
    {
        $all_upper = array_map('strtoupper', $all_campaign_group_names);
        $group_names_found_on_mailerlite = [];
        $groups_to_upsert = [];

        foreach ($mailerlite_group_data as $group) {
            $group_name_upper = strtoupper($group['name']);

            if (in_array($group_name_upper, $all_upper, true)) {
                $campaign_name = $this->utils->get_campaign_name_from_text($group['name']);
                $campaign_data = $this->campaign_database->get_campaign_by_name($campaign_name);

                if (!$campaign_data || !isset($campaign_data['id'])) {
                    continue;
                }

                $group_names_found_on_mailerlite[] = $group_name_upper;
                $groups_to_upsert[] = [
                    'id' => $group['id'],
                    'group_name' => $group['name'],
                    'campaign_id' => $campaign_data['id']
                ];
            }
        }

        return [
            'found_names' => $group_names_found_on_mailerlite,
            'groups_to_upsert' => $groups_to_upsert
        ];
    }

    private function create_missing_groups(array $missing_groups): array
    {
        $created_groups_to_upsert = [];

        foreach ($missing_groups as $group_name) {
            $campaign_name = $this->utils->get_campaign_name_from_text($group_name);
            $campaign_data = $this->campaign_database->get_campaign_by_name($campaign_name);

            if (!isset($campaign_data['id'])) {
                continue;
            }

            $new_group = $this->mailerLiteInstance->createGroup($group_name);

            if ($new_group && isset($new_group['id'])) {
                $created_groups_to_upsert[] = [
                    'id' => $new_group['id'],
                    'group_name' => $new_group['name'],
                    'campaign_id' => $campaign_data['id']
                ];
            }
        }

        return $created_groups_to_upsert;
    }

    private function get_purchase_id_from_subscriber(array $subscriber, string $campaign_name): ?string
    {
        $purchase_field = strtolower($campaign_name . '_PURCHASE');
        return $subscriber['fields'][$purchase_field] ?? null;
    }

    private function get_field_id_by_campaign(string $campaign_id): ?string
    {
        $fields = $this->field_database->get_field_by_campaign_id($campaign_id);
        return !empty($fields) ? $fields[0]['id'] : null;
    }

    private function update_sync_status(string $status, string $message, int $current, int $total, string $optionKey, int $subscribersCount = 0): void
    {
        update_option($optionKey, [
            'status' => $status,
            'message' => $message,
            'progress' => $current,
            'total' => $total,
            'subscribers_count' => $subscribersCount,
            'timestamp' => time()
        ]);
    }
}
