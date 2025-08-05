<?php
global $wpdb;
$query = $wpdb->prepare( 
    "SELECT * FROM $wpdb->bemameta
    WHERE bema_id = %d",
    $post->ID
);
$results = $wpdb->get_results( $query, ARRAY_A );
$idx = count($results) - 1;
?>
<table class="form-table bema-metabox"> 
    <!-- Nonce -->
    <input type="hidden" name="bema_nonce" value="<?php echo wp_create_nonce( 'bema_nonce' ); ?>">

    <input 
    type="hidden" 
    name="bema_action" 
    value="<?php echo ( empty ( $results[$idx]['tier'] ) || empty ( $results[$idx]['subscriber'] ) ? 'save' : 'save' ); ?>">

    <tr>
        <th>
            <label for="bema_tier"><?php esc_html_e( 'Tier', 'bema_crm' ); ?></label>
        </th>
        <td>
            <select name="bema_tier" id="bema_tier">
                <option value="unassigned" <?php if( isset( $results[$idx]['tier'] ) ) selected( $results[$idx]['tier'], 'unassigned' ); ?>><?php esc_html_e( 'unassigned', 'bema_crm' )?></option>';
                <option value="opt-in" <?php if( isset( $results[$idx]['tier'] ) ) selected( $results[$idx]['tier'], 'opt-in' ); ?>><?php esc_html_e( 'opt-in', 'bema_crm' )?></option>';
                <option value="gold" <?php if( isset( $results[$idx]['tier'] ) ) selected( $results[$idx]['tier'], 'gold' ); ?>><?php esc_html_e( 'gold', 'bema_crm' )?></option>';
                <option value="gold-purchased" <?php if( isset( $results[$idx]['tier'] ) ) selected( $results[$idx]['tier'], 'gold-purchased' ); ?>><?php esc_html_e( 'gold-purchased', 'bema_crm' )?></option>';
                <option value="silver" <?php if( isset( $results[$idx]['tier'] ) ) selected( $results[$idx]['tier'], 'silver' ); ?>><?php esc_html_e( 'silver', 'bema_crm' )?></option>';
                <option value="silver-purchased" <?php if( isset( $results[$idx]['tier'] ) ) selected( $results[$idx]['tier'], 'silver-purchased' ); ?>><?php esc_html_e( 'silver-purchased', 'bema_crm' )?></option>';
                <option value="bronze" <?php if( isset( $results[$idx]['tier'] ) ) selected( $results[$idx]['tier'], 'bronze' ); ?>><?php esc_html_e( 'bronze', 'bema_crm' )?></option>';
                <option value="bronze-purchased" <?php if( isset( $results[$idx]['tier'] ) ) selected( $results[$idx]['tier'], 'bronze-purchased' ); ?>><?php esc_html_e( 'bronze-purchased', 'bema_crm' )?></option>';
                
            </select>            
        </td>
    </tr>
    <tr>
        <th>
            <label for="bema_purchase_indicator"><?php esc_html_e( 'Purchase Indicator', 'bema_crm' ); ?></label>
        </th>
        <td>
        <input 
            type="checkbox" 
            name="bema_purchase_indicator" 
            id="bema_purchase_indicator" 
            class="regular-text video-url"
            <?php echo ( isset( $results[$idx]['purchase_indicator'] ) && $results[$idx]['purchase_indicator'] == '1' ) ? 'checked' : ''; ?>
            value="1"
        >
        </td>
    </tr>
    <tr>
        <th>
            <label for="bema_campaign"><?php esc_html_e( 'Campaign', 'bema_crm' ); ?></label>
        </th>
        <td>
        <input 
            type="text" 
            name="bema_campaign" 
            id="bema_campaign" 
            class="regular-text video-url"
            value="<?php echo ( isset( $results[$idx]['campaign'] ) ) ? esc_html( $results[$idx]['campaign'] ) : ""; ?>"
        >
        </td>
    </tr>
    <tr>
        <th>
            <label for="bema_mailerlite_group_id"><?php esc_html_e( 'Mailerlite GroupId', 'bema_crm' ); ?></label>
        </th>
        <td>
        <input 
            type="text" 
            name="bema_mailerlite_group_id" 
            id="bema_mailerlite_group_id" 
            class="regular-text video-url"
            value="<?php echo ( isset( $results[$idx]['mailerlite_group_id'] ) ) ? esc_html( $results[$idx]['mailerlite_group_id'] ) : "$idx"; ?>"
        >
        </td>
    </tr>
    <tr>
        <th>
            <label for="bema_date_added"><?php esc_html_e( 'As Of Date', 'bema_crm' ); ?></label>
        </th>
        <td>
        <input 
            type="datetime-local" 
            name="bema_date_added" 
            id="bema_date_added" 
            class="regular-text video-url"
            value="<?php echo ( isset( $results[$idx]['date_added'] ) ) ? esc_html( $results[$idx]['date_added'] ) : ""; ?>"
        >
        </td>
    </tr>  
    <tr>
        <th>
            <label for="bema_candidate"><?php esc_html_e( 'Candidate', 'bema_crm' ); ?></label>
        </th>
        <td>
        <input 
            type="text" 
            name="bema_candidate" 
            id="bema_candidate" 
            class="regular-text"
            value="<?php echo ( isset( $results[$idx]['candidate'] ) ) ? esc_html( $results[$idx]['candidate'] ) : ""; ?>"
        >
        </td>
    </tr>
    <tr>
        <th>
            <label for="bema_subscriber"><?php esc_html_e( 'Subscriber', 'bema_crm' ); ?></label>
        </th>
        <td>
        <input 
            type="text" 
            name="bema_subscriber" 
            id="bema_subscriber" 
            class="regular-text"
            value="<?php echo ( isset( $results[$idx]['subscriber'] ) ) ? esc_html( $results[$idx]['subscriber'] ) : ""; ?>"
        >
        </td>
    </tr>
    <tr>
        <th>
            <label for="bema_source"><?php esc_html_e( 'Source', 'bema_crm' ); ?></label>
        </th>
        <td>
        <input 
            type="text" 
            name="bema_source" 
            id="bema_source" 
            class="regular-text"
            value="<?php echo ( isset( $results[$idx]['source'] ) ) ? esc_html( $results[$idx]['source'] ) : ""; ?>"
        >
        </td>
    </tr>
</table>