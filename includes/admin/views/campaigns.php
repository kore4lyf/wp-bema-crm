<?php
use Bema\Manager_Factory;

if (!defined('ABSPATH')) {
    exit;
}

// Debug information
$campaigns = [];
$products = [];

try {
    // Handle sorting and pagination
    $orderby = isset($_GET['orderby']) ? sanitize_text_field(wp_unslash($_GET['orderby'])) : 'start_date';
    $order = isset($_GET['order']) ? sanitize_text_field(wp_unslash($_GET['order'])) : 'asc';

    // Pagination setup
    $paged = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;
    $per_page = 5;
    $offset = ($paged - 1) * $per_page;

    // Get campaigns from the database with sorting and pagination
    $campaigns_db = Manager_Factory::get_campaign_database_manager();
    $campaigns = $campaigns_db->get_campaigns($per_page, $offset, $orderby, $order);

    // Get total count for pagination
    $total_items = $campaigns_db->count_campaigns();
    $total_pages = max(1, (int) ceil($total_items / $per_page));

    // Get EDD products
    $products = get_posts([
        'post_type' => 'download',
        'post_status' => 'publish',
        'numberposts' => 50,
        'orderby' => 'title',
        'order' => 'ASC'
    ]);

} catch (Exception $e) {
    $admin->logger('Error: ' . $e->getMessage());
}

function get_status_class($status)
{
    $classes = [
        'active' => 'status-active',
        'draft' => 'status-draft',
        'completed' => 'status-completed',
        'pending' => 'status-pending'
    ];
    return $classes[$status] ?? 'status-unknown';
}

function bema_crm_sortable_column($column, $label, $current_orderby, $current_order) {
	$new_order = ($current_orderby === $column && $current_order === 'asc') ? 'desc' : 'asc';
	$class = 'sortable';
	if ($current_orderby === $column) {
		$class = $current_order === 'asc' ? 'sorted asc' : 'sorted desc';
	}
	$url = add_query_arg(['orderby' => $column, 'order' => $new_order]);
	return sprintf('<a href="%s"><span>%s</span><span class="sorting-indicator"></span></a>', esc_url($url), esc_html($label));
}

?>

<div class="wrap">
    <h1>Campaigns</h1>
    <?php wp_nonce_field('bema_campaign_nonce', 'bema_campaign_nonce'); ?>

    <!-- Create Campaign Form -->
    <div class="campaign-form-section bema-crm-lite-section-card">
        <h2>Create New Campaign</h2>
        <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>">
            <?php wp_nonce_field('bema_create_campaign', 'bema_nonce'); ?>

            <input type="hidden" name="action" value="bema_create_campaign" />
            <table class="form-table">
                <tr>
                    <th><label for="campaign_name">Campaign Name</label></th>
                    <td>
                        <input type="text" id="campaign_name" name="campaign_name" class="regular-text"
                            placeholder="2025_ARTIST_ALBUM" required>
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
        <h2>All Campaigns</h2>

        <div class="tablenav top">
            <div class="tablenav-pages">
                <span class="displaying-num"><?php echo esc_html($total_items); ?> items</span>
                <?php
                echo paginate_links(array(
                    'base' => add_query_arg('paged', '%#%'),
                    'format' => '',
                    'prev_text' => '«',
                    'next_text' => '»',
                    'total' => $total_pages,
                    'current' => $paged,
                ));
                ?>
            </div>
        </div>

        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th scope="col" class="manage-column column-name">Campaign</th>
                    <th scope="col" class="manage-column column-product product-id-col">Product ID</th>
                    <th scope="col" class="manage-column column-start-date sortable <?php echo ($orderby === 'start_date') ? ($order === 'asc' ? 'sorted asc' : 'sorted desc') : 'desc'; ?>"><?php echo bema_crm_sortable_column('start_date', 'Start Date', $orderby, $order); ?></th>
                    <th scope="col" class="manage-column column-end-date sortable <?php echo ($orderby === 'end_date') ? ($order === 'asc' ? 'sorted asc' : 'sorted desc') : 'desc'; ?>"><?php echo bema_crm_sortable_column('end_date', 'End Date', $orderby, $order); ?></th>
                    <th scope="col" class="manage-column column-status sortable <?php echo ($orderby === 'status') ? ($order === 'asc' ? 'sorted asc' : 'sorted desc') : 'desc'; ?>"><?php echo bema_crm_sortable_column('status', 'Status', $orderby, $order); ?></th>
                    <th scope="col" class="manage-column column-actions">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($campaigns)): ?>

                    <?php foreach ($campaigns as $campaign): ?>
                        <tr data-campaign-id="<?php echo esc_attr($campaign['id']); ?>" data-campaign-name="<?php echo esc_attr($campaign['campaign']); ?>">
                            <td>
                                <strong><?php echo esc_html($campaign['campaign'] ?? '—'); ?></strong>
                                <br><small>ID: <?php echo esc_html($campaign['id'] ?? '—'); ?></small>
                            </td>
                            <td  class="product-id-col"><?php echo esc_html($campaign['product_id'] ?? '—'); ?></td>
                            <td class="editable-cell" data-field="start_date">
                                <span class="display-value">
                                    <?php
                                    if (!empty($campaign['start_date']) && $campaign['start_date'] !== '0000-00-00') {
                                        try {
                                            $timestamp = new DateTime($campaign['start_date']);
                                            echo esc_html($timestamp->format('F j, Y'));
                                        } catch (Exception $e) {
                                            echo '—';
                                        }
                                    } else {
                                        echo '—';
                                    }
                                    ?>
                                </span>
                                <input type="date" class="edit-input" value="<?php echo esc_attr($campaign['start_date'] ?? ''); ?>" style="display:none;">
                            </td>
                            <td class="editable-cell" data-field="end_date">
                                <span class="display-value">
                                    <?php
                                    if (!empty($campaign['end_date']) && $campaign['end_date'] !== '0000-00-00') {
                                        try {
                                            $timestamp = new DateTime($campaign['end_date']);
                                            echo esc_html($timestamp->format('F j, Y'));
                                        } catch (Exception $e) {
                                            echo '—';
                                        }
                                    } else {
                                        echo '—';
                                    }
                                    ?>
                                </span>
                                <input type="date" class="edit-input" value="<?php echo esc_attr($campaign['end_date'] ?? ''); ?>" style="display:none;">
                            </td>
                            <td class="editable-cell" data-field="status">
                                <span class="display-value">
                                    <?php $ui_status = $campaign['status'] ?? 'draft'; ?>
                                                                        <span class="status-badge <?php echo esc_attr(get_status_class($ui_status)); ?>">
                                                                            <?php echo esc_html(ucfirst($ui_status)); ?>
                                    </span>
                                </span>
                                <select class="edit-input" style="display:none;">
                                    <?php $ui_status_for_select = $campaign['status'] ?? 'draft'; ?>
                                    <option value="draft" <?php selected($ui_status_for_select, 'draft'); ?>>Draft</option>
                                    <option value="active" <?php selected($ui_status_for_select, 'active'); ?>>Active</option>
                                    <option value="pending" <?php selected($ui_status_for_select, 'pending'); ?>>Pending</option>
                                    <option value="completed" <?php selected($ui_status_for_select, 'completed'); ?>>Completed</option>
                                </select>
                            </td>
                            <td class="actions-cell">
                                <button class="button button-small edit-btn">Edit</button>
                                <button class="button button-primary button-small submit-btn" style="display:none;">Submit</button>
                                <button class="button button-small cancel-btn" style="display:none;">Cancel</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="6" class="no-campaigns">
                            <?php

                            $tableExists = !empty($campaigns) || $total_items > 0;
                            
                            if (!$tableExists): ?>
                                <strong>No campaigns table found.</strong>
                            <?php else: ?>
                                <strong>No campaigns found.</strong>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
        
        <div class="tablenav bottom">
            <div class="tablenav-pages">
                <span class="displaying-num"><?php echo esc_html($total_items); ?> items</span>
                <?php
                echo paginate_links(array(
                    'base' => add_query_arg('paged', '%#%'),
                    'format' => '',
                    'prev_text' => '«',
                    'next_text' => '»',
                    'total' => $total_pages,
                    'current' => $paged,
                ));
                ?>
            </form>
        </div>
    </div>
</div>
