<?php

namespace Bema;

use Bema\EM_Sync;
use Bema\Utils;
use Bema\BemaCRMLogger;
use EDD\Orders\Order;
use EDD_Customer;

class Triggers
{
  public function __construct(EM_Sync $em_sync, Utils $utils, ?BemaCRMLogger $logger = null)
  {
    $this->em_sync = $em_sync;
    $this->utils = $utils;
    $this->logger = $logger ?? new BemaCRMLogger();
  }

  /**
   * update_purchase_field_on_order_complete: Updates subscriber/user purchase status on mailerlite'
   * 
   * Process non-critical tasks after an order has been completed.
   * 
   * This runs ~30 seconds after a purchase is completed via WP_Cron.
   * 
   * @param $order_id The Order ID that was marked as completed.
   * @param $order The Order object that was completed.
   * @param $customer The EDD customer object.
   * 
   * @return void
   */
  public function update_purchase_field_on_order_complete(int $order_id, Order $order, EDD_Customer $customer): void
  {
    error_log("update_purchase_field_on_order_complete: start trigger" . "\n", 3, dirname(__FILE__) . '/debug.log');
    $this->logger->log('TRIGGER START: Order completed', 'info');
    // Subscriber/Customer Email
    $customer_email = $customer->email;
    error_log("update_purchase_field_on_order_complete: store customer email" . "\n", 3, dirname(__FILE__) . '/debug.log');
    
    $order_data = $order->get_items();
    error_log("update_purchase_field_on_order_complete: Get ordered products" . "\n", 3, dirname(__FILE__) . '/debug.log');
    
    // ordered Products
    $ordered_albums = [];
    
    if (!empty($order_data)) {
        // Collect all ordered products.
        foreach ($order_data as $item) {
            $product_name = $item->product_name;
            $product_price = (float) $item->price;
            
            $ordered_albums[$product_name] = (float) $product_price;
        }
        
    error_log("update_purchase_field_on_order_complete: Products: " . var_dump($ordered_albums)  . "\n", 3, dirname(__FILE__) . '/debug.log');

    error_log("update_purchase_field_on_order_complete: looping through products." . "\n", 3, dirname(__FILE__) . '/debug.log');
    foreach ($ordered_albums as $album_name => $album_price) {
        // Fetch album details
        $album_details = $this->em_sync->get_album_details($album_name);
        $album_release_year = $album_details['year'];
        $album_artist = $album_details['artist'];
        
        $field_name = $this->utils->get_campaign_group_name($album_release_year, $album_artist, $album_name, 'PURCHASE');
        $field_value = 1; // 1 represents true

        if ($field_name) {
          // Update purchase status
          $this->em_sync->updateSubscriberField($customer_email, $field_name, $field_value);
          $this->logger->log('TRIGGER RUNNING: Order complete: Customer Mailerlite field updated', 'info', [
            'customer_email' => $customer_email,
            'field_name' => $field_name,
            'album_name' => $album_name
          ]);
          error_log("update_purchase_field_on_order_complete: Updating user purchase status on mailerlite.". var_dump([
            'customer_email' => $customer_email,
            'field_name' => $field_name,
            'album_name' => $album_name
          ]) . "\n", 3, dirname(__FILE__) . '/debug.log');
        }
      }
      
      $this->logger->log('TRIGGER END: Order complete', 'info');
    }
  }


}