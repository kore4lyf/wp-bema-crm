<?php

namespace Bema;

use Bema\EM_Sync;
use Bema\Providers\MailerLite;
use Bema\Utils;
use Bema\BemaCRMLogger;
use EDD\Orders\Order;
use EDD_Customer;

if ( ! defined( 'ABSPATH' ) ) {
 exit;
}
class Triggers
{
    private MailerLite $mailerlite;
    private EM_Sync $em_sync;
    private Utils $utils;
    private Group_Database_Manager $group_db_manager;
    private BemaCRMLogger $logger;

    public function __construct(MailerLite $mailerlite, EM_Sync $em_sync, Utils $utils, Group_Database_Manager $group_db_manager, ?BemaCRMLogger $logger = null)
    {
        $this->mailerlite = $mailerlite;
        $this->em_sync = $em_sync;
        $this->utils = $utils;
        $this->group_db_manager = $group_db_manager;
        $this->logger = $logger ?? new BemaCRMLogger();
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
        
        $this->logger->log('Scheduling purchase field update for order ID: ' . $order_id, 'info');
        error_log('Scheduling purchase field update for order ID: ' . $order_id . "\n", 3, dirname(__FILE__) . '/debug.log');
        
        // Schedule a single event to run in 30 seconds
        wp_schedule_single_event(
            time() + 60,
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
        $this->logger->log('WP-Cron Trigger Start: order purchase field update', 'info', ['order_id' => $order_id]);
        error_log('WP-Cron Trigger Start: order purchase field update' . $order_id . "\n", 3, dirname(__FILE__) . '/debug.log');
        
        $ordered_albums = [];

        if (!empty($order_data)) {
            // Collect all ordered products.
            foreach ($order_data as $item) {
                $product_name = $item['name'] ?? '';
                $product_price = (float) ($item['price'] ?? 0);

                if ($product_name) {
                    $ordered_albums[$product_name] = $product_price;
                }
            }
            
            $this->logger->log("Ordered products found: " . print_r($ordered_albums, true), 'info');
            error_log("Ordered products found: " . print_r($ordered_albums, true) . "\n", 3, dirname(__FILE__) . '/debug.log');

            foreach ($ordered_albums as $album_name => $album_price) {
                // Fetch album details
                $album_details = $this->em_sync->get_album_details($album_name);
                $album_release_year = $album_details['year'] ?? '';
                $album_artist = $album_details['artist'] ?? '';

                $field_name = $this->utils->get_campaign_group_name(
                    $album_release_year,
                    $album_artist,
                    $album_name,
                    'PURCHASE'
                );
                $field_value = 1; // 1 represents true

                if ($field_name) {
                    // Update purchase status
                    $this->em_sync->updateSubscriberField($customer_email, $field_name, $field_value);
                    $this->logger->log('PURCHASE TRIGGER RUNNING: Customer Mailerlite field updated', 'info', [
                        'customer_email' => $customer_email,
                        'field_name' => $field_name,
                        'album_name' => $album_name
                    ] );
                    error_log('PURCHASE TRIGGER RUNNING: Customer Mailerlite field updated' . 
                        'customer_email: ' . $customer_email . '\n' .
                        'field_name: ' . $field_name . '\n' .
                        'album_name: ' . $album_name . '\n' .
                     "\n", 3, dirname(__FILE__) . '/debug.log');
                }
            }
            $this->logger->log('WP-Cron Trigger End: order purchase field update', 'info');
            error_log('WP-Cron Trigger End: order purchase field update' . "\n", 3, dirname(__FILE__) . '/debug.log');;
        } else {
            $this->logger->log('WP-Cron Warning: No order data found for order ID ' . $order_id, 'warning');
            error_log('WP-Cron Warning: No order data found for order ID ' . $order_id . "\n", 3, dirname(__FILE__) . '/debug.log');
        }
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
            $this->logger->log('Scheduling group creation for new album: ' . $post->post_title, 'info');
            error_log('Scheduling group creation for new album: ' . $post->post_title . "\n", 3, dirname(__FILE__) . '/debug.log');

            // Schedule a single event to run in 30 seconds, passing the post ID
            wp_schedule_single_event(
                time() + 30,
                'bema_create_groups_on_album_publish',
                [$post->ID]
            );
        }
    }

    /**
     * This new method is the WP-Cron handler for creating subscriber groups.
     * It contains the original API logic and runs in the background.
     *
     * @param int $post_id The ID of the post to process.
     * @return void
     */
    public function handle_create_groups_via_cron(int $post_id): void
    {
        $post = get_post($post_id);
        if (!$post) {
            $this->logger->log('WP-Cron event failed: Post not found for ID ' . $post_id, 'error');
            error_log('WP-Cron event failed: Post not found for ID ' . $post_id, 'error');
            return;
        }
        
        $this->logger->log('WP-Cron Trigger Start: create subscriber groups', 'info', ['post_id' => $post->ID]);
        error_log('WP-Cron Trigger Start: create subscriber groups. ' . 'post_id: ' . $post->ID . "\n", 3, dirname(__FILE__) . '/debug.log');

        $post_title = trim($post->post_title);
        $tiers = get_option('bema_crm_tiers', []);
        $groups = [];

        // Fetch album details
        $album_details = $this->em_sync->get_album_details($post_title);
        $album_release_year = $album_details['year'] ?? '';
        $album_artist = $album_details['artist'] ?? '';

        // Generate group name, create the group and store group details in the database
        foreach ($tiers as $tier) {
            $group_name = $this->utils->get_campaign_group_name(
                $album_release_year,
                $album_artist,
                $post_title,
                $tier
            );


            if (!empty($group_name)) {
                // Create mailerlite subscriber group with post title
                $this->logger->log("Creating group for: " . $group_name, 'info');
                error_log("Creating group for: " . $group_name . "\n", 3, dirname(__FILE__) . '/debug.log');
                error_log("Creating group for: " . $group_name . "\n", 3, dirname(__FILE__) . '/debug.log');
                $group_data = $this->mailerlite->createGroup($group_name);
                $this->logger->log("MailerLite response for group: " . print_r($group_data, true), 'info');
                error_log("MailerLite response for group: " . print_r($group_data, true) . "\n", 3, dirname(__FILE__) . '/debug.log');
                
                $group_id = $group_data['id'] ?? null;
                error_log("group id: " . $group_id . "\n", 3, dirname(__FILE__) . '/debug.log');
                
                if ($group_id) {
                    $groups[] = [
                        'group_name' => $group_name,
                        'group_id' => $group_id
                    ];
                }
            } else {
                $this->logger->log('Group name could not be generated for tier: ' . $tier, 'warning');
                error_log('Group name could not be generated for tier: ' . $tier . "\n", 3, dirname(__FILE__) . '/debug.log');
            }
        }
        
        if (!empty($groups)) {
            $this->group_db_manager->insert_groups_bulk($groups);
        }

        $this->logger->log('WP-Cron Trigger End: create subscriber groups', 'info');
        error_log('WP-Cron Trigger End: create subscriber groups' . "\n", 3, dirname(__FILE__) . '/debug.log');
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
            $this->logger->log('Scheduling purchase field creation for new album: ' . $post->post_title, 'info');
            error_log('Scheduling purchase field creation for new album: ' . $post->post_title . "\n", 3, dirname(__FILE__) . '/debug.log');

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
            $this->logger->log('WP-Cron event failed: Post not found for ID ' . $post_id, 'error');
            error_log('WP-Cron event failed: Post not found for ID ' . $post_id . "\n", 3, dirname(__FILE__) . '/debug.log');
            return;
        }

        $this->logger->log('WP-Cron Trigger Start: create subscriber purchase field', 'info', ['post_id' => $post->ID]);
        error_log('WP-Cron Trigger Start: create subscriber purchase field' . ['post_id' => $post->ID] . "\n", 3, dirname(__FILE__) . '/debug.log');

        // Get album details and generate the field name
        $album_details = $this->em_sync->get_album_details($post->post_title);
        $field_name = $this->utils->get_campaign_group_name(
            $album_details['year'] ?? '',
            $album_details['artist'] ?? '',
            $post->post_title,
            'PURCHASE'
        );

        // Create the field if the name is valid
        if (!empty($field_name)) {
            $this->mailerlite->createField($field_name, 'number');
            $this->logger->log("MailerLite field created: {$field_name}", 'info');
            error_log("MailerLite field created: {$field_name}" . "\n", 3, dirname(__FILE__) . '/debug.log');
        } else {
            $this->logger->log('Failed to create field: name could not be generated.', 'warning', ['post_id' => $post->ID, 'post_title' => $post->post_title]);
            error_log('Failed to create field: name could not be generated.' . ['post_id' => $post->ID, 'post_title' => $post->post_title] . "\n", 3, dirname(__FILE__) . '/debug.log');
        }
        
        $this->logger->log('WP-Cron Trigger End: create subscriber purchase field', 'info');
        error_log('WP-Cron Trigger End: create subscriber purchase field' . "\n", 3, dirname(__FILE__) . '/debug.log');
    }
}
