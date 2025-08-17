<?php

namespace Bema;

use Bema\EM_Sync;
use Bema\Providers\MailerLite;
use Bema\Utils;
use Bema\BemaCRMLogger;
use EDD\Orders\Order;
use EDD_Customer;
use Bema\Group_Database_Manager;
use Bema\Field_Database_Manager;

if (!defined('ABSPATH')) {
    exit;
}
class Triggers
{
    private MailerLite $mailerlite;
    private EM_Sync $em_sync;
    private Utils $utils;
    private Group_Database_Manager $group_db_manager;
    private Field_Database_Manager $field_db_manager;
    private BemaCRMLogger $logger;
    private array $deleted_album_titles = [];

    public function __construct(MailerLite $mailerlite, EM_Sync $em_sync, Utils $utils, Group_Database_Manager $group_db_manager, Field_Database_Manager $field_db_manager, ?BemaCRMLogger $logger = null)
    {
        $this->mailerlite = $mailerlite;
        $this->em_sync = $em_sync;
        $this->utils = $utils;
        $this->group_db_manager = $group_db_manager;
        $this->field_db_manager = $field_db_manager;
        $this->logger = $logger ?? new BemaCRMLogger();
    }

    /**
     * Initializes WordPress hooks for the CRM.
     *
     * @return void
     */
    function init(): void
    {
        add_action('edd_after_order_actions', [$this, 'update_purchase_field_on_order_complete'], 10, 3);
        // add_action( 'transition_post_status', [ $this, 'create_subscriber_groups_on_album_publish' ], 10, 3 ); 
        add_action('transition_post_status', [$this, 'create_subscriber_purchase_field_on_album_publish'], 10, 3);

        // Capture album title before deletion
        add_action('before_delete_post', [$this, 'capture_deleted_album_title']);

        // Schedule WP-Cron after deletion
        add_action('deleted_post', [$this, 'schedule_deleted_album_cron']);

        // WP-Cron
        // WP-Cron hook to handle the update of purchase field on mailerlite
        add_action('bema_handle_order_purchase_field_update', [$this, 'handle_order_purchase_field_update_via_cron'], 10, 3);

        // WP-Cron hook to handle the creation of purchase field asynchronously
        // add_action( 'bema_create_groups_on_album_publish', [ $this, 'handle_create_groups_via_cron' ], 10, 1 );

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

                if ($product_name && $product_price && $product_price > 0) {
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
                $field_value = $order_id; // 1 represents true

                if ($field_name) {
                    // Update purchase status
                    $this->em_sync->updateSubscriberField($customer_email, $field_name, $field_value);
                    $this->logger->log('PURCHASE TRIGGER RUNNING: Customer Mailerlite field updated', 'info', [
                        'customer_email' => $customer_email,
                        'field_name' => $field_name,
                        'album_name' => $album_name
                    ]);
                    error_log('PURCHASE TRIGGER RUNNING: Customer Mailerlite field updated' .
                        'customer_email: ' . $customer_email . '\n' .
                        'field_name: ' . $field_name . '\n' .
                        'album_name: ' . $album_name . '\n' .
                        "\n", 3, dirname(__FILE__) . '/debug.log');
                }
            }
            $this->logger->log('WP-Cron Trigger End: order purchase field update', 'info');
            error_log('WP-Cron Trigger End: order purchase field update' . "\n", 3, dirname(__FILE__) . '/debug.log');
            ;
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
        if (!empty($post)) {
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

        // Create the field and store in the field table if the field name exists
        if (!empty($field_name)) {
            $field_id = $this->mailerlite->createField($field_name, 'number');
            $this->field_db_manager->insert_field($field_id, $field_name);
            $this->logger->log("MailerLite field created and recorded in the database: field_name: {$field_name} , field_id: {$field_id}", 'info');
            error_log("MailerLite field created and recorded in the database: {$field_name}" . "\n", 3, dirname(__FILE__) . '/debug.log');
        } else {
            $this->logger->log('Failed to create field: name could not be generated.', 'warning', ['post_id' => $post->ID, 'post_title' => $post->post_title]);
            error_log('Failed to create field: name could not be generated.' . ['post_id' => $post->ID, 'post_title' => $post->post_title] . "\n", 3, dirname(__FILE__) . '/debug.log');
        }

        $this->logger->log('WP-Cron Trigger End: create subscriber purchase field', 'info');
        error_log('WP-Cron Trigger End: create subscriber purchase field' . "\n", 3, dirname(__FILE__) . '/debug.log');
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
            $this->deleted_album_titles[$post_id] = $post->post_title;
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
    public function schedule_deleted_album_cron(int $post_id): void
    {
        if (isset($this->deleted_album_titles[$post_id])) {
            $title = $this->deleted_album_titles[$post_id];

            // Schedule the WP-Cron event if not already scheduled
            if (!wp_next_scheduled('bema_handle_deleted_album', [$post_id, $title])) {
                wp_schedule_single_event(time() + 60, 'bema_handle_deleted_album', [$post_id, $title]);
            }

            // Clean up temporary storage
            unset($this->deleted_album_titles[$post_id]);
        }
    }

    /**
     * Handles album deletion asynchronously via WP-Cron.
     *
     * This method is triggered by the 'bema_handle_deleted_album' WP-Cron event.
     *
     * @param int $post_id The ID of the deleted album.
     * @param string $title The title of the deleted album.
     * @return void
     */
    public function handle_deleted_album_cron(int $post_id, string $title): void
    {
        // Log deletion event
        $this->logger->log(
            "WP-Cron: Album '{$title}' (ID: {$post_id}) was permanently deleted.",
            'info'
        );

        error_log(
            "WP-Cron: Album '{$title}' (ID: {$post_id}) was permanently deleted.\n",
            3,
            dirname(__FILE__) . '/debug.log'
        );

        // Add any additional post-deletion logic here
        // Example: cleanup CRM records, notify APIs, or sync with MailerLite
    }
}
