<?php

namespace Bema;

use Bema\EM_Sync;
use Bema\Providers\MailerLite;
use Bema\Utils;
use Bema\Bema_CRM_Logger;
use EDD\Orders\Order;
use EDD_Customer;
use Bema\Database\Group_Database_Manager;
use Bema\Database\Field_Database_Manager;
use Bema\Database\Campaign_Database_Manager;

if (!defined('ABSPATH')) {
    exit;
}
class Triggers
{
    private MailerLite $mailerlite;
    private EM_Sync $em_sync;
    private Utils $utils;
    private Group_Database_Manager $group_database;
    private Field_Database_Manager $field_database;
    private Campaign_Database_Manager $campaign_database;
    private Bema_CRM_Logger $logger;
    private array $deleted_album_details = [];

    public function __construct(MailerLite $mailerlite, EM_Sync $em_sync, Utils $utils, Group_Database_Manager $group_database, Field_Database_Manager $field_database, ?Bema_CRM_Logger $logger = null)
    {
        $this->mailerlite = $mailerlite;
        $this->em_sync = $em_sync;
        $this->utils = $utils;
        $this->group_database = $group_database;
        $this->field_database = $field_database;
        $this->logger = $logger ?? Bema_CRM_Logger::create('wordpress-triggers');
        $this->campaign_database = new Campaign_Database_Manager();
    }

    /**
     * Initializes WordPress hooks for the CRM.
     *
     * @return void
     */
    function init(): void
    {
        add_action('edd_after_order_actions', [$this, 'update_purchase_field_on_order_complete'], 10, 3);
        add_action('transition_post_status', [$this, 'create_subscriber_groups_on_album_publish'], 10, 3);
        add_action('transition_post_status', [$this, 'create_subscriber_purchase_field_on_album_publish'], 10, 3);
        // Capture album title before deletion
        add_action('before_delete_post', [$this, 'capture_deleted_album_title']);


        // WP-Cron

        // Schedule WP-Cron after deletion
        add_action('deleted_post', [$this, 'schedule_deleted_album_via_cron']);

        // WP-Cron hook to handle the update of purchase field on mailerlite
        add_action('bema_handle_order_purchase_field_update', [$this, 'handle_order_purchase_field_update_via_cron'], 10, 3);

        // WP-Cron hook to handle the creation of purchase field asynchronously
        add_action('bema_create_groups_on_album_publish', [$this, 'handle_create_groups_via_cron'], 10, 1);

        // WP-Cron hook to handle the creation of purchase field on mailerlite
        add_action('bema_create_purchase_field_on_album_publish', [$this, 'handle_create_purchase_field_via_cron'], 10, 1);

        // WP-Cron hook to handle deletion of album groups/campaigns on mailerlite
        add_action('bema_handle_deleted_album', [$this, 'handle_deleted_album_cron'], 10, 2);
    }

    /**
     * This method is triggered by the 'edd_after_order_actions' hook.
     *
     * @param int $order_id The Order ID that was marked as completed.
     * @param Order $order The Order object that was completed.
     * @param EDD_Customer $customer The EDD customer object.
     * @return void
     */
    public function update_purchase_field_on_order_complete(int $order_id, Order $order, EDD_Customer $customer): void
    {
        // To be safe, we'll serialize the complex objects and pass them to the cron handler.
        $customer_email = $customer->email;
        $order_items = $order->get_items();

        $this->logger->info('Scheduling purchase field update for order ID: ' . $order_id);

        // Schedule a single event to run in 30 seconds
        wp_schedule_single_event(
            time() + 30,
            'bema_handle_order_purchase_field_update',
            [$order_id, $customer_email, $order_items]
        );
    }

    /**
     * This new method is the WP-Cron handler for order completion.
     * It contains the original API logic and runs in the background.
     *
     * @param int $order_id
     * @param string $customer_email
     * @param array $order_data
     * @return void
     */
    public function handle_order_purchase_field_update_via_cron(int $order_id, string $customer_email, array $order_data): void
    {
        $this->logger->startTimer('order_processing');
        
        $this->logger->info('WP-Cron Trigger Start: order purchase field update', [
            'order_id' => $order_id,
            'order_items_count' => count($order_data)
        ]);

        if (empty($order_data)) {
            $this->logger->warning('No order data found for order ID', ['order_id' => $order_id]);
            return;
        }

        $ordered_albums = [];
        $valid_items = 0;

        foreach ($order_data as $item) {
            $product_name = $item['name'] ?? '';
            $product_price = (float) ($item['price'] ?? 0);

            if ($product_name && $product_price && $product_price > 0) {
                $ordered_albums[$product_name] = $product_price;
                $valid_items++;
            } else {
                $missing_fields = array_keys(array_filter(['name' => empty($product_name), 'price' => $product_price <= 0]));
                $this->logger->logDataValidation('order_item', $missing_fields);
            }
        }

        $this->logger->debug('Order data processed', [
            'total_items' => count($order_data),
            'valid_items' => $valid_items,
            'albums_found' => array_keys($ordered_albums)
        ]);

        foreach ($ordered_albums as $album_name => $album_price) {
            $this->logger->startTimer("album_processing");
            
            $album_details = $this->utils->get_album_details($album_name);
            
            // Validate album data
            $required_fields = ['year', 'artist'];
            $missing_fields = array_diff($required_fields, array_keys(array_filter($album_details)));
            $this->logger->logDataValidation('album_details', $missing_fields, ['album_name' => $album_name]);

            $field_name = $this->utils->get_campaign_group_name(
                $album_details['year'] ?? '',
                $album_details['artist'] ?? '',
                $album_name,
                'PURCHASE'
            );

            if ($field_name) {
                $this->logger->logApiCall('MailerLite', 'updateSubscriberField', [
                    'field_name' => $field_name,
                    'customer_email_hash' => md5($customer_email)
                ]);
                
                $this->em_sync->updateSubscriberField($customer_email, $field_name, $order_id);
                
                $this->logger->info('Customer MailerLite field updated', [
                    'customer_email_hash' => md5($customer_email),
                    'field_name' => $field_name,
                    'album_name' => $album_name,
                    'order_id' => $order_id
                ]);
            } else {
                $this->logger->warning('Field name could not be generated for album', [
                    'album_name' => $album_name
                ]);
            }
            
            $this->logger->endTimer("album_processing");
        }
        
        $this->logger->endTimer('order_processing', ['albums_processed' => count($ordered_albums)]);
    }

    /**
     * This method is triggered by the 'transition_post_status' hook.
     * It now schedules a WP-Cron event to handle the API calls in the background.
     *
     * @param string $new_status The new post status.
     * @param string $old_status The old post status.
     * @param \WP_Post $post The post object.
     * @return void
     */
    public function create_subscriber_groups_on_album_publish(string $new_status, string $old_status, \WP_Post $post): void
    {
        if ('download' === $post->post_type && 'publish' === $new_status && 'publish' !== $old_status) {
            $this->logger->info('Scheduling group creation for new album', [
                'post_id' => $post->ID,
                'post_title' => $post->post_title
            ]);

            // Schedule a single event to run in 30 seconds, passing the post ID
            wp_schedule_single_event(
                time() + 30,
                'bema_create_groups_on_album_publish',
                [$post->ID]
            );
        }
    }

    /**
     * Handles the creation of subscriber groups via a WP-Cron event.
     *
     * @param int $post_id The ID of the post for which to create groups.
     * @return void
     */
    public function handle_create_groups_via_cron(int $post_id): void
    {
        $this->logger->startTimer('group_creation');
        
        $post = get_post($post_id);
        if (!$post) {
            $this->logger->error('WP-Cron event failed: Post not found for ID ' . $post_id);
            return;
        }

        $this->logger->info('WP-Cron Trigger Start: create subscriber groups', [
            'post_id' => $post->ID
        ]);

        $post_title = $post->post_title;
        $tiers = get_option('bema_crm_tiers', []);
        $groups = [];

        // Log system configuration
        $this->logger->debug('System configuration loaded', [
            'tiers_configured' => count($tiers),
            'tiers' => $tiers,
            'wp_debug' => WP_DEBUG
        ]);

        // Fetch album details
        $album_details = $this->utils->get_album_details($post_title);
        $album_release_year = $album_details['year'] ?? '';
        $album_artist = $album_details['artist'] ?? '';

        // Validate album data
        $required_fields = ['year', 'artist'];
        $missing_fields = array_diff($required_fields, array_keys(array_filter($album_details)));
        $this->logger->logDataValidation('album_details', $missing_fields, ['album_name' => $post_title]);

        // Generate group name, create the group and store group details in the database
        foreach ($tiers as $tier) {
            $this->logger->startTimer("tier_processing");
            
            $group_name = $this->utils->get_campaign_group_name(
                $album_release_year,
                $album_artist,
                $post_title,
                $tier
            );

            if (!empty($group_name)) {
                $campaign_name = $this->utils->get_campaign_name_from_text($group_name);
                $campaign_data = $this->campaign_database->get_campaign_by_name($campaign_name);
                $campaign_id = $campaign_data['id'] ?? null;

                if (!$campaign_id) {
                    $this->logger->warning('Campaign not found for group', [
                        'campaign_name' => $campaign_name,
                        'group_name' => $group_name
                    ]);
                    continue;
                }

                $this->logger->logApiCall('MailerLite', 'createGroup', [
                    'group_name' => $group_name,
                    'tier' => $tier
                ]);
                
                $group_data = $this->mailerlite->createGroup($group_name);
                
                $this->logger->info("MailerLite group creation response", [
                    'group_name' => $group_name,
                    'success' => !empty($group_data) && isset($group_data['id']),
                    'group_id' => $group_data['id'] ?? null
                ]);

                if (!empty($group_data) && isset($group_data['id'])) {
                    $groups[] = [
                        'id' => $group_data['id'],
                        'group_name' => $group_name,
                        'campaign_id' => $campaign_id
                    ];
                } else {
                    $this->logger->error('MailerLite API failure: createGroup', [
                        'group_name' => $group_name,
                        'category' => 'external_service',
                        'retry_possible' => true
                    ]);
                }
            } else {
                $this->logger->warning('Group name generation failed', [
                    'tier' => $tier,
                    'album_details' => $album_details
                ]);
            }
            
            $this->logger->endTimer("tier_processing");
        }

        if (!empty($groups)) {
            $this->logger->info('Bulk database insert: groups', [
                'group_count' => count($groups)
            ]);
            $this->group_database->upsert_groups_bulk($groups);
        }

        $this->logger->endTimer('group_creation', [
            'groups_created' => count($groups),
            'tiers_processed' => count($tiers)
        ]);
    }

    /**
     * This method is triggered by the 'transition_post_status' hook.
     * It now schedules a WP-Cron event to handle the API calls in the background.
     *
     * @param string $new_status The new post status.
     * @param string $old_status The old post status.
     * @param \WP_Post $post The post object.
     * @return void
     */
    public function create_subscriber_purchase_field_on_album_publish(string $new_status, string $old_status, \WP_Post $post): void
    {
        if ('download' === $post->post_type && 'publish' === $new_status && 'publish' !== $old_status) {
            $this->logger->info('Scheduling purchase field creation for new album: ' . $post->post_title);

            // Schedule a single event to run in 30 seconds, passing the post ID
            wp_schedule_single_event(
                time() + 30,
                'bema_create_purchase_field_on_album_publish',
                [$post->ID]
            );
        }
    }

    /**
     * This new method is the WP-Cron handler for creating a purchase field.
     * It contains the original API logic and runs in the background.
     *
     * @param int $post_id The ID of the post to process.
     * @return void
     */
    public function handle_create_purchase_field_via_cron(int $post_id): void
    {
        $post = get_post($post_id);
        if (!$post) {
            $this->logger->error('WP-Cron event failed: Post not found for ID ' . $post_id);
            return;
        }

        $this->logger->info('WP-Cron Trigger Start: create subscriber purchase field', ['post_id' => $post->ID]);

        // Get album details and generate the field name
        $album_details = $this->utils->get_album_details($post->post_title);
        $field_name = $this->utils->get_campaign_group_name(
            $album_details['year'] ?? '',
            $album_details['artist'] ?? '',
            $post->post_title,
            'PURCHASE'
        );

        // Create the field and store in the field table if the field name exists
        if (!empty($field_name)) {
            $field_id = $this->mailerlite->createField($field_name, 'number');
            $campaign_name = $this->utils->get_campaign_name_from_text($field_name);
            $campaign_data = $this->campaign_database->get_campaign_by_name($campaign_name);
            $campaign_id = $campaign_data['id'] ?? null;
            
            if ($campaign_id && $field_id) {
                $this->field_database->upsert_field($field_id, $field_name, $campaign_id);
                $this->logger->info("MailerLite field created and recorded in the database", [
                    'field_name' => $field_name,
                    'field_id' => $field_id
                ]);
            } else {
                $this->logger->error('Failed to create field or find campaign', [
                    'field_name' => $field_name,
                    'campaign_name' => $campaign_name,
                    'field_id' => $field_id,
                    'campaign_id' => $campaign_id
                ]);
            }
        } else {
            $this->logger->warning('Failed to create field: name could not be generated.', [
                'post_id' => $post->ID,
                'post_title' => $post->post_title
            ]);
        }

        $this->logger->info('WP-Cron Trigger End: create subscriber purchase field');
    }

    /**
     * Captures the album title before it is permanently deleted.
     *
     * @param int $post_id The ID of the post being deleted.
     * @return void
     */
    public function capture_deleted_album_title(int $post_id): void
    {
        $post = get_post($post_id);

        if ($post && $post->post_type === 'download') {
            $this->deleted_album_details[$post_id] = [
                'album_name' => $post->post_title,
                'album_details' => [
                    'year' => '2025',
                    'artist' => 'Eko the beat'
                ]
            ];
        }
    }

    /**
     * Schedules a WP-Cron event to handle album deletion asynchronously.
     *
     * Runs after the album has been deleted from the database.
     *
     * @param int $post_id The ID of the post that was deleted.
     * @return void
     */
    public function schedule_deleted_album_via_cron(int $post_id): void
    {
        if (isset($this->deleted_album_details[$post_id])) {
            $details = $this->deleted_album_details[$post_id];

            wp_schedule_single_event(
                time() + 30,
                'bema_handle_deleted_album',
                [$details['album_name'], $details['album_details']]
            );

            // Clean up temporary storage
            unset($this->deleted_album_details[$post_id]);
        }
    }

    /**
     * Handles the deletion of the album purchase field on MailerLite and the local database.
     *
     * @param string $album_name The name of the deleted album.
     * @param array $album_details The details of the deleted album.
     * @return void
     */
    private function handle_deleted_album_fields(string $album_name, array $album_details): void
    {
        $album_release_year = $album_details['year'] ?? '';
        $album_artist = $album_details['artist'] ?? '';

        // Build campaign field name
        $field_name = $this->utils->get_campaign_group_name(
            $album_release_year,
            $album_artist,
            $album_name,
            'PURCHASE'
        );

        // Delete album purchase field on MailerLite
        $this->logger->logApiCall('MailerLite', 'deleteField', ['field_name' => $field_name]);
        $is_field_deleted_on_mailerlite = $this->mailerlite->deleteField($field_name);

        if ($is_field_deleted_on_mailerlite) {
            // Delete album field from local database
            $this->field_database->delete_field_by_name($field_name);
            $this->logger->info('Field successfully deleted from MailerLite and database', ['field_name' => $field_name]);
        } else {
            $this->logger->error('Failed to delete field from MailerLite', ['field_name' => $field_name]);
        }
    }

    /**
     * Handles the deletion of album groups on MailerLite and the local database.
     *
     * @param string $album_name The name of the deleted album.
     * @param array $album_details The details of the deleted album.
     * @param array $tiers The array of tiers for the album.
     * @return void
     */
    private function handle_deleted_album_groups(string $album_name, array $album_details, array $tiers): void
    {
        $album_release_year = $album_details['year'] ?? '';
        $album_artist = $album_details['artist'] ?? '';

        // Delete album groups on MailerLite
        foreach ($tiers as $tier) {
            $group_name = $this->utils->get_campaign_group_name('2025', 'Eko the beat', $album_name, $tier);

            if (!empty($group_name)) {
                // Delete mailerlite subscriber group with post title
                $this->logger->logApiCall('MailerLite', 'deleteGroup', ['group_name' => $group_name]);

                $is_group_deleted_on_mailerlite = $this->mailerlite->deleteGroup($group_name);

                if ($is_group_deleted_on_mailerlite) {
                    $this->group_database->delete_group_by_name($group_name);
                    $this->logger->info("Album group: '{$group_name}' was permanently deleted.");
                } else {
                    $this->logger->error("Failed to delete group from MailerLite: '{$group_name}'");
                }
            } else {
                $this->logger->warning('Group name could not be generated for tier: ' . $tier);
            }
        }
    }

    /**
     * Handles album deletion.
     *
     * This method is triggered by the 'bema_handle_deleted_album' WP-Cron event.
     *
     * @param string $album_name The album_name of the deleted album.
     * @return void
     */
    public function handle_deleted_album_cron(string $album_name, array $album_details): void
    {
        $tiers = get_option('bema_crm_tiers', []);

        // Handle field deletion
        $this->handle_deleted_album_fields($album_name, $album_details);

        // Handle group deletion
        $this->handle_deleted_album_groups($album_name, $album_details, $tiers);
    }
}
