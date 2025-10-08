<?php
use Bema\Database\Subscribers_Database_Manager;
use Bema\Database\Campaign_Database_Manager;
use Bema\Database\Transition_Database_Manager;
use Bema\Manager_Factory;

if (!defined('ABSPATH')) {
	exit;
}

$sync_manager = Manager_Factory::get_sync_manager();
$campaign_database = new Campaign_Database_Manager();

// Get database manager from main plugin instance
$transition_database = new Transition_Database_Manager();

$transition_date_from_id_map = $transition_database->get_transition_date_from_id_map();

// Handle filter inputs.
$selected_tier = isset($_GET['tier']) ? sanitize_text_field(wp_unslash($_GET['tier'])) : '';
$selected_campaign = isset($_GET['campaign']) ? sanitize_text_field(wp_unslash($_GET['campaign'])) : '';
$search_query = isset($_GET['search']) ? sanitize_text_field(wp_unslash($_GET['search'])) : '';
$orderby = isset($_GET['orderby']) ? sanitize_text_field(wp_unslash($_GET['orderby'])) : 'id';
$order = isset($_GET['order']) ? sanitize_text_field(wp_unslash($_GET['order'])) : 'desc';

// Fetch subscriber data with filters/pagination.
$subscriber_db = new Subscribers_Database_Manager();

// Retrieve tiers from WordPress option.
$tiers = get_option('bema_crm_tiers', []);

// Retrieve EDD product list (campaigns).
$campaigns = $campaign_database->get_all_campaigns();


// Pagination setup.
$paged = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;
$per_page = 25;
$offset = ($paged - 1) * $per_page;

// Fetch subscribers with filters
$subscribers = $subscriber_db->get_subscribers(
	$per_page,
	$offset,
	$selected_campaign,
	$selected_tier,
	$search_query,
	$orderby,
	$order
);

// Get total count for pagination
$total_items = $subscriber_db->count_subscribers(
	$selected_campaign,
	$selected_tier,
	$search_query
);

$total_pages = max(1, (int) ceil($total_items / $per_page));

function bema_crm_print_notice($message, $type = 'success')
{
	printf('<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr($type), esc_html($message));
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
// Bulk actions
if (isset($_POST['bulk-action'])) {
	switch (sanitize_text_field(wp_unslash($_POST['bulk-action']))) {
		case 'resync':
			// Verify nonce
			if (!isset($_POST['bema_crm_nonce']) || !wp_verify_nonce(wp_unslash($_POST['bema_crm_nonce']), 'bema_crm_bulk_action')) {
				wp_die('Security check failed. Please try again.');
			}

			// Capability check
			if (!current_user_can('manage_options')) {
				wp_die('You do not have permission to perform this action.');
			}

			// Get selected subscriber IDs (IDs only per requirements)
			$ids = isset($_POST['subscriber_ids']) ? (array) wp_unslash($_POST['subscriber_ids']) : [];
			$ids = array_filter(array_map('absint', $ids));

			if (empty($ids)) {
				\Bema\bema_notice('No subscribers selected. Please select subscribers to resync.', 'warning', 'Selection Required');
				break;
			}

			try {
				$processed = $sync_manager->resync_subscribers( $ids);
			} catch (Exception $e) {
				\Bema\bema_notice('Resync failed: ' . $e->getMessage(), 'error', 'Resync Error');
			}

			break;
	}
}



?>

<div class="wrap">

	<!-- Filter Form -->
	<form method="get">
		<input type="hidden" name="page" value="<?php echo esc_attr($_GET['page']); ?>" />

		<div class="tablenav top">
			<div class="alignleft actions">
				<!-- Tier Filter -->
				<select name="tier" id="filter-tier">
					<option value="">Tiers</option>
					<?php foreach ($tiers as $tier): ?>
						<option value="<?php echo esc_attr($tier); ?>" <?php selected($selected_tier, $tier); ?>>
							<?php echo esc_html($tier); ?>
						</option>
					<?php endforeach; ?>
				</select>

				<!-- Campaign Filter -->
				<select name="campaign" id="filter-campaign">
					<option value="">Campaigns</option>
					<?php foreach ($campaigns as $name): ?>
						<option value="<?php echo esc_attr($name['campaign']); ?>" <?php selected($selected_campaign, $name['campaign']); ?>>
							<?php echo esc_html($name['campaign']); ?>
						</option>
					<?php endforeach; ?>
				</select>

				<!-- Search -->
				<input type="search" id="subscriber-search-input" name="search" placeholder="name@email.com"
					value="<?php echo esc_attr($search_query); ?>" />

				<?php submit_button('Filter', '', 'filter_action', false); ?>
			</div>
		</div>
	</form>

	<!-- Bulk Actions Form -->
	<form method="post">
		<?php wp_nonce_field('bema_crm_bulk_action', 'bema_crm_nonce'); ?>

		<div class="tablenav top bulk-pagination">
			<div class="alignleft actions bulkactions">
				<select name="bulk-action" id="bulk-action-selector-top">
					<option value="-1">Bulk actions</option>
					<option value="resync">Resync</option>
				</select>
				<?php submit_button('Apply', '', 'doaction', false); ?>
			</div>
			<div class="tablenav-pages">
				<span class="displaying-num"><?php echo esc_html($total_items); ?> items</span>
				<?php
				echo paginate_links(
					array(
						'base' => add_query_arg('paged', '%#%'),
						'format' => '',
						'prev_text' => '«',
						'next_text' => '»',
						'total' => $total_pages,
						'current' => $paged,
					)
				);
				?>
			</div>
		</div>

		<table class="wp-list-table widefat fixed striped table-view-list users">
			<thead>
				<tr>
					<td id="cb" class="manage-column column-cb check-column">
						<input type="checkbox" id="select-all-subscribers" />
					</td>
					<th scope="col" class="manage-column column-email sortable <?php echo ($orderby === 'email') ? ($order === 'asc' ? 'sorted asc' : 'sorted desc') : 'desc'; ?>"><?php echo bema_crm_sortable_column('email', 'Email', $orderby, $order); ?></th>
					<th scope="col" class="manage-column column-name sortable <?php echo ($orderby === 'name') ? ($order === 'asc' ? 'sorted asc' : 'sorted desc') : 'desc'; ?>"><?php echo bema_crm_sortable_column('name', 'Name', $orderby, $order); ?></th>
					<th scope="col" class="manage-column column-status sortable <?php echo ($orderby === 'status') ? ($order === 'asc' ? 'sorted asc' : 'sorted desc') : 'desc'; ?>"><?php echo bema_crm_sortable_column('status', 'Status', $orderby, $order); ?></th>
					<?php if ($selected_campaign): ?>
						<th scope="col" class="manage-column column-campaign sortable <?php echo ($orderby === 'campaign') ? ($order === 'asc' ? 'sorted asc' : 'sorted desc') : 'desc'; ?>"><?php echo bema_crm_sortable_column('campaign', 'Campaign', $orderby, $order); ?></th>
						<th scope="col" class="manage-column column-tier sortable <?php echo ($orderby === 'tier') ? ($order === 'asc' ? 'sorted asc' : 'sorted desc') : 'desc'; ?>"><?php echo bema_crm_sortable_column('tier', 'Tier', $orderby, $order); ?></th>
						<th scope="col" class="manage-column column-purchase_id sortable <?php echo ($orderby === 'purchase_id') ? ($order === 'asc' ? 'sorted asc' : 'sorted desc') : 'desc'; ?>"><?php echo bema_crm_sortable_column('purchase_id', 'Purchase ID', $orderby, $order); ?></th>
						<th scope="col" class="manage-column column-transition_date sortable <?php echo ($orderby === 'transition_date') ? ($order === 'asc' ? 'sorted asc' : 'sorted desc') : 'desc'; ?>"><?php echo bema_crm_sortable_column('transition_date', 'Transition Date', $orderby, $order); ?></th>
					<?php endif; ?>
				</tr>
			</thead>
			<tbody>
				<?php if (!empty($subscribers)): ?>
					<?php foreach ($subscribers as $subscriber): ?>
						<tr>
							<th scope="row" class="check-column">
								<input type="checkbox" name="subscriber_ids[]"
									value="<?php echo esc_attr($subscriber['id']); ?>" />
							</th>
							<td>
							<strong><?php echo esc_html($subscriber['email'] ?? '—'); ?></strong>
                                <br><small>ID: <?php echo esc_html($subscriber['id']); ?></small>
							</td>
							<td><?php echo esc_html(strlen(trim($subscriber['name'])) ? $subscriber['name'] : '—'); ?></td>
							<td>
								<span class="status-badge status-<?php echo esc_attr($subscriber['status'] ?? 'unknown'); ?>">
									<?php echo esc_html(ucfirst($subscriber['status'] ?? '—')); ?>
								</span>
							</td>
							<?php if ($selected_campaign): ?>
								<td><?php echo esc_html($selected_campaign); ?> </td>
								<td><?php echo esc_html($subscriber['tier'] ?? '—'); ?></td>
								<td><?php echo esc_html($subscriber['purchase_id'] ?? '—'); ?></td>
								<td>
									<?php
									    if (isset($subscriber['transition_date'])) {

											try {
												$timestamp = new DateTime($subscriber['transition_date']);
												echo $timestamp->format('F j, Y, g:i a');

											} catch (Exception $e) {
												echo '—';
												$admin->logger('Error: ' . $e->getMessage());
											}
										} else {
											echo '—';
										}
									?>
							</td>
							<?php endif; ?>
						</tr>
					<?php endforeach; ?>
				<?php else: ?>
					<tr>
						<td class="text-center" colspan="<?php echo $selected_campaign ? 9 : 5; ?>">
							No subscribers found.
						</td>
					</tr>
				<?php endif; ?>
			</tbody>
		</table>

		<div class="tablenav bottom">
			<div class="tablenav-pages">
				<span class="displaying-num"><?php echo esc_html($total_items); ?> items</span>
				<?php
				echo paginate_links(
					array(
						'base' => add_query_arg('paged', '%#%'),
						'format' => '',
						'prev_text' => '«',
						'next_text' => '»',
						'total' => $total_pages,
						'current' => $paged,
					)
				);
				?>
			</div>
		</div>
	</form>
</div>
