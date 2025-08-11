<?php

namespace Bema\Triggers;

use Bema\EM_Sync;
use Bema\Core\Utils;

class EDD
{

  public function __construct(EM_Sync $em_sync, Utils $utils)
  {
    $this->em_sync = $em_sync;
    $this->utils = $utils;
  }

  /**
   * orderCompleted: Updates subscriber/user purchase status on mailerlite
   *
   * @return void
   */
  public function orderCompleted($order_id, $order, $customer): void
  {

    // Subscriber/Customer Email
    define('CUSTOMER_EMAIL', $customer->email);
    $order_data = $order->get_items();

    // ordered Products
    $ordered_albums = [];

    if (!empty($order_data)) {
      // Collect all ordered products.
      foreach ($order_data as $item) {
        $product_name = $item->product_name;
        $product_price = (float) $item->product_name;

        $ordered_albums[$product_name] = (float) $product_price;
      }

      
      foreach ($ordered_albums as $album_name => $album_price) {
        // Fetch album details
        $album_details = $this->em_sync->get_album_details($album_name);
        $album_release_year = $album_details['year'];
        $album_artist = $album_details['artist'];

        // If a customer ordered a paid product (not free)
        /* 
        if( $album_price > 0 ) {
          
        }
        */
        
        $fieldName = $this->utils->get_campaign_group_name($album_release_year, $album_artist, $album_name, 'PURCHASE');
        
        if ($fieldName) {
          // Update purchase status
          $this->em_sync->updateSubscriberFieldStatus(CUSTOMER_EMAIL, $fieldName);
        }
      }
    }
  }
  

  /**
   * get_customer_current_group_name: return the customer customer current group name
   * 
   * @param array $ordered_albums
   * @return string
   */
  // public function get_customer_current_group_name(array $ordered_albums):string
  // {
  // $customer_current_group_name = get_user_current_group_name($ordered_albums);


  //   // For every product update user product purchase field on mailerlite
  //   foreach ($ordered_albums as $album_name => $album_price) {

  //     // IF customer ordered a paid product (not free)
  //     /* 
  //     if( $album_price > 0 ) {
        
  //     }
  //     */

  //     // Fetch album details
  //     $album_details = $this->em_sync->get_album_details($album_name);
  //     $album_release_year = $album_details['year'];
  //     $album_artist = $album_details['artist'];

  //     // Get all album tier from album name
  //     $tiers =  get_option('bema_crm_tiers', []);;

  //     $album_group_list = [];

  //     foreach ($tiers as $tier) {
  //       $album_group_list[] = $this->utils->get_campaign_group_name($album_release_year, $album_artist, $album_name, $tier);
  //     }

  // // To know the right field to update, one must know (the current user group)
  //     // Get User groups
  //     $customer_mailerlite_groups = $this->mailerlite->getSubscriberDetails(CUSTOMER_EMAIL)[data][groups];

  //   // Solution Type 1: Get user current tier (If group keys is not known)

  //     if( ! empty( $customer_mailerlite_groups ) ) {

  //       // Get Mailerlite groups
  //       $mailerlite_groups = $this->mailerlite->getGroups()['data'];

  //       $customer_current_group_name = '';

  //       if ( !empty($mailerlite_groups)) {
  //         foreach ($mailerlite_groups as $mailerlite_group) {

  //           if ( in_array($mailerlite_group['id'], $customer_mailerlite_groups)) {
  //             if( in_array($mailerlite_group['name'], $album_group_list) ) {
  //               $customer_current_group_name = $mailerlite_group['name'];
  //               break;
  //             }
  //           }
  //         }
  //       }
  //     }

    // Solution Type 2: Get user tier (If group keys already being stored in the database)
      
      // Use SQL to query the group names/ids in the table (were group name is equal to the groups in group id)
      // query $db_mailerlite_group id & name locally in the album_group table
      // $db_mailerlite_groups = ?

      // foreach ($db_mailerlite_groups as $group) {
      //   if( in_array($group['id'], $customer_mailerlite_groups) ){
      //     $customer_current_group_name = $group['name'];
      //   }
        
      //   $customer_current_group_name = $mailerlite_group['name'];
      //   break;
      
      // }

      

  //     return $customer_current_group_name;
  //   }

  // }

  // add_action( 'edd_after_order_actions', 'my_custom_edd_order_complete_function', 10, 3 );
}