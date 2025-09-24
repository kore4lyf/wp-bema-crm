<?php

use Bema\Manager_Factory;

$logger = \Bema\Bema_CRM_Logger::create('transition-history-view');

// Handle sorting and pagination
$orderby = isset($_GET['orderby']) ? sanitize_text_field(wp_unslash($_GET['orderby'])) : 'transition_date';
$order = isset($_GET['order']) ? sanitize_text_field(wp_unslash($_GET['order'])) : 'desc';

// Pagination setup
$paged = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;
$per_page = 25;
$offset = ($paged - 1) * $per_page;

try {
    $transition_database = Manager_Factory::get_transition_manager()->transition_database;
    
    // Get transitions with pagination and sorting
    $transition_history = $transition_database->get_records($per_page, $offset, $orderby, $order);
    
    // Get total count for pagination
    $total_items = $transition_database->count_records();
    $total_pages = max(1, (int) ceil($total_items / $per_page));
} catch (Exception $e) {
    $transition_history = [];
    $total_items = 0;
    $total_pages = 1;
    $logger->error('Failed to load transition history', ['error' => $e->getMessage()]);
}

function bema_transitions_sortable_column($column, $label, $current_orderby, $current_order) {
	$new_order = ($current_orderby === $column && $current_order === 'asc') ? 'desc' : 'asc';
	$class = 'sortable';
	if ($current_orderby === $column) {
		$class = $current_order === 'asc' ? 'sorted asc' : 'sorted desc';
	}
	$url = add_query_arg(['orderby' => $column, 'order' => $new_order]);
	return sprintf('<a href="%s"><span>%s</span><span class="sorting-indicator"></span></a>', esc_url($url), esc_html($label));
}
?>

<div class="transition-history" style="margin-top: 30px;">
    <h3>Recent Transitions </h3>
    
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
                <th scope="col" class="manage-column column-source">Source Campaign</th>
                <th scope="col" class="manage-column column-destination">Destination Campaign</th>
                <th scope="col" class="manage-column column-status sortable <?php echo ($orderby === 'status') ? ($order === 'asc' ? 'sorted asc' : 'sorted desc') : 'desc'; ?>"><?php echo bema_transitions_sortable_column('status', 'Status', $orderby, $order); ?></th>
                <th scope="col" class="manage-column column-subscribers sortable <?php echo ($orderby === 'subscribers') ? ($order === 'asc' ? 'sorted asc' : 'sorted desc') : 'desc'; ?>"><?php echo bema_transitions_sortable_column('subscribers', 'Subscribers', $orderby, $order); ?></th>
                <th scope="col" class="manage-column column-date sortable <?php echo ($orderby === 'transition_date') ? ($order === 'asc' ? 'sorted asc' : 'sorted desc') : 'desc'; ?>"><?php echo bema_transitions_sortable_column('transition_date', 'Date', $orderby, $order); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if (!empty($transition_history)): ?>
                <?php foreach ($transition_history as $row): ?>
                    <tr>
                        <td><?php echo esc_html($row['source'] ?? '—'); ?></td>
                        <td><?php echo esc_html($row['destination'] ?? '—'); ?></td>
                        <td>
                            <span class="status-badge status-<?php echo esc_attr(strtolower($row['status'] ?? 'unknown')); ?>">
                                <?php echo esc_html(ucfirst($row['status'] ?? '—')); ?>
                            </span>
                        </td>
                        <td><?php echo esc_html($row['subscribers'] ?? '0'); ?></td>
                        <td>
                            <?php
                                if (isset($row['transition_date'])) {
                                    try {
                                        $timestamp = new DateTime($row['transition_date']);
                                        echo esc_html($timestamp->format('F j, Y, g:i a'));
                                    } catch (Exception $e) {
                                        echo '—';
                                        $logger->error('Date formatting error', ['error' => $e->getMessage()]);
                                    }
                                } else {
                                    echo '—';
                                }
                            ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="5" style="text-align: center; padding: 20px; color: #666;">
                        No transition history found.
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
        </div>
    </div>
</div>
