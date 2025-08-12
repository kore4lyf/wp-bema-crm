# NOTES

## Old transitions table


```html
<div class="postbox">
            <h2 class="transitions-subtitle"><span><?php _e('Tier Transition Matrix', 'bema-crm'); ?></span></h2>
            <div class="inside">
                <table class="wp-list-table widefat fixed striped transition-matrix">
                    <thead>
                        <tr>
                            <th><?php _e('Current Tier', 'bema-crm'); ?></th>
                            <th><?php _e('Next Campaign Tier', 'bema-crm'); ?></th>
                            <th><?php _e('Purchase Required', 'bema-crm'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($transition_matrix as $transition): ?>
                            <tr>
                                <td><?php echo esc_html($transition['current_tier']); ?></td>
                                <td><?php echo esc_html($transition['next_tier']); ?></td>
                                <td>
                                    <?php if ($transition['requires_purchase']): ?>
                                        <span class="dashicons dashicons-yes"></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
```


## TRIGGER old function

```php
/**
   * get_customer_current_group_name: return the customer customer current group name
   * 
   * @param array $ordered_albums
   * @return string
   */
  public function get_customer_current_group_name(array $ordered_albums):string
  {
  $customer_current_group_name = get_user_current_group_name($ordered_albums);


    // For every product update user product purchase field on mailerlite
    foreach ($ordered_albums as $album_name => $album_price) {

      // IF customer ordered a paid product (not free)
      /* 
      if( $album_price > 0 ) {

      }
      */

      // Fetch album details
      $album_details = $this->em_sync->get_album_details($album_name);
      $album_release_year = $album_details['year'];
      $album_artist = $album_details['artist'];

      // Get all album tier from album name
      $tiers =  get_option('bema_crm_tiers', []);;

      $album_group_list = [];

      foreach ($tiers as $tier) {
        $album_group_list[] = $this->utils->get_campaign_group_name($album_release_year, $album_artist, $album_name, $tier);
      }

  // To know the right field to update, one must know (the current user group)
      // Get User groups
      $customer_mailerlite_groups = $this->mailerlite->getSubscriberDetails($customer_email)[data][groups];

    // Solution Type 1: Get user current tier (If group keys is not known)

      if( ! empty( $customer_mailerlite_groups ) ) {

        // Get Mailerlite groups
        $mailerlite_groups = $this->mailerlite->getGroups()['data'];

        $customer_current_group_name = '';

        if ( !empty($mailerlite_groups)) {
          foreach ($mailerlite_groups as $mailerlite_group) {

            if ( in_array($mailerlite_group['id'], $customer_mailerlite_groups)) {
              if( in_array($mailerlite_group['name'], $album_group_list) ) {
                $customer_current_group_name = $mailerlite_group['name'];
                break;
              }
            }
          }
        }
      }

  Solution Type 2: Get user tier (If group keys already being stored in the database)

  Use SQL to query the group names/ids in the table (were group name is equal to the groups in group id)
  query $db_mailerlite_group id & name locally in the album_group table
  $db_mailerlite_groups = ?

  foreach ($db_mailerlite_groups as $group) {
    if( in_array($group['id'], $customer_mailerlite_groups) ){
      $customer_current_group_name = $group['name'];
    }

    $customer_current_group_name = $mailerlite_group['name'];
    break;

  }



      return $customer_current_group_name;
    }

  }
```