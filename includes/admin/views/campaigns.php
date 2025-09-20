<?php
if (!defined('ABSPATH')) {
    exit;
}

// Debug information
$debug_info = [];
$campaigns = [];
$products = [];

try {
    global $wpdb;
    
    // Check if campaigns table exists
    $campaigns_table = $wpdb->prefix . 'bemacrm_campaignsmeta';
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$campaigns_table}'") === $campaigns_table;
    $debug_info['table_exists'] = $table_exists;
    $debug_info['table_name'] = $campaigns_table;
    
    if ($table_exists) {
        // Get campaigns from database
        $campaigns = $wpdb->get_results("SELECT * FROM {$campaigns_table} ORDER BY id DESC", ARRAY_A);
        $debug_info['campaigns_count'] = count($campaigns);
        $debug_info['last_error'] = $wpdb->last_error;
    }
    
    // Get EDD products
    $products = get_posts([
        'post_type' => 'download',
        'post_status' => 'publish',
        'numberposts' => 50,
        'orderby' => 'title',
        'order' => 'ASC'
    ]);
    $debug_info['products_count'] = count($products);
    
} catch (Exception $e) {
    $debug_info['error'] = $e->getMessage();
}

function get_status_class($status) {
    $classes = [
        'publish' => 'status-active',
        'draft' => 'status-draft',
        'completed' => 'status-completed',
        'paused' => 'status-paused'
    ];
    return $classes[$status] ?? 'status-unknown';
}
?>

<div class="wrap">
    <h1>Campaigns</h1>

    <!-- Create Campaign Form -->
    <div class="campaign-form-section">
        <h2>Create New Campaign</h2>
        <form method="post" action="">
            <?php wp_nonce_field('bema_create_campaign', 'bema_nonce'); ?>
            
            <table class="form-table">
                <tr>
                    <th><label for="campaign_name">Campaign Name</label></th>
                    <td>
                        <input type="text" id="campaign_name" name="campaign_name" class="regular-text" placeholder="2025_ARTIST_ALBUM" required>
                    </td>
                </tr>
                <tr>
                    <th><label for="product_id">Album</label></th>
                    <td>
                        <select id="product_id" name="product_id">
                            <option value="">Album</option>
                            <?php if (!empty($products)): ?>
                                <?php foreach ($products as $product): ?>
                                    <option value="<?php echo esc_attr($product->ID); ?>">
                                        <?php echo esc_html($product->post_title); ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="start_date">Start Date</label></th>
                    <td>
                        <input type="date" id="start_date" name="start_date" class="date-text">
                        <p class="description">Campaign start date (Optional)</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="end_date">End Date</label></th>
                    <td>
                        <input type="date" id="end_date" name="end_date" class="date-text">
                        <p class="description">Campaign end date (Optional)</p>
                    </td>
                </tr>
            </table>
            
            <p class="submit">
                <input type="submit" name="create_campaign" class="button button-primary" value="Create Campaign">
            </p>
        </form>
    </div>

    <!-- Campaigns List -->
    <div class="campaigns-list-section">
        <h2>All Campaigns (<?php echo count($campaigns); ?>)</h2>
        
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>Campaign</th>
                    <th>Product ID</th>
                    <th>Start Date</th>
                    <th>End Date</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($campaigns)): ?>

                    <?php foreach ($campaigns as $campaign): ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html($campaign['campaign'] ?? '—'); ?></strong>
                                <br><small>ID: <?php echo esc_html($campaign['id'] ?? '—'); ?></small>
                            </td>
                            <td><?php echo esc_html($campaign['product_id'] ?? '—'); ?></td>
                            <td>
                                <?php echo esc_html($campaign['start_date'] ?? '—'); ?>
                            </td>
                            <td>
                                <?php echo esc_html($campaign['end_date'] ?? '—'); ?>
                            </td>
                            <td>
                                <span class="status-badge <?php echo esc_attr(get_status_class($campaign['status'] ?? 'draft')); ?>">
                                    <?php echo esc_html(ucfirst($campaign['status'] ?? 'Draft')); ?>
                                </span>
                            </td>
                            <td>
                                <a href="#" class="button button-small">Edit</a>
                                <a href="#" class="button button-small">Subscribers</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php else: ?>
                        <tr>
                            <td colspan="6" class="no-campaigns">
                                <?php if (!$debug_info['table_exists']): ?>
                                    <strong>No campaigns table found.</strong>
                                <?php else: ?>
                                    <strong>No campaigns found.</strong>
                                <?php endif; ?>
                            </td>
                        </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<style>
.campaign-form-section {
    background: #fff;
    padding: 20px;
    margin: 20px 0;
    border: 1px solid #ccd0d4;
    border-radius: 4px;
}

.campaigns-list-section {
    margin-top: 30px;
}

.status-badge {
    padding: 4px 8px;
    border-radius: 3px;
    font-size: 12px;
    font-weight: 600;
}

.status-active {
    background: #d1e7dd;
    color: #0f5132;
}

.status-draft {
    background: #fff3cd;
    color: #664d03;
}

.no-campaigns {
    text-align: center;
    padding: 40px 20px;
    color: #666;
    font-style: italic;
}

pre {
    background: #f1f1f1;
    padding: 10px;
    border-radius: 4px;
    overflow-x: auto;
    font-size: 12px;
}
</style>
