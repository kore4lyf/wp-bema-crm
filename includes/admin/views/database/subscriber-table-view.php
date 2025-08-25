<?php
use Bema\Subscribers_Database_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Handle filter inputs.
$selected_tier     = isset( $_GET['tier'] ) ? sanitize_text_field( wp_unslash( $_GET['tier'] ) ) : '';
$selected_campaign = isset( $_GET['campaign'] ) ? sanitize_text_field( wp_unslash( $_GET['campaign'] ) ) : '';
$search_query      = isset( $_GET['search'] ) ? sanitize_text_field( wp_unslash( $_GET['search'] ) ) : '';

// Fetch subscriber data with filters/pagination.
$subscriber_db = new Subscribers_Database_Manager();

// Retrieve tiers from WordPress option.
$tiers = get_option( 'bema_crm_tiers', []);

// Retrieve EDD product list (campaigns).
$campaigns = $admin->utils->get_campaigns_names();

// Pagination setup.
$paged    = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
$per_page = 25;
$offset   = ( $paged - 1 ) * $per_page;

// Fetch subscribers with filters
$subscribers = $subscriber_db->get_subscribers(
    $per_page,
    $offset,
    $selected_campaign,
    $selected_tier,
    $search_query
);

// Get total count for pagination
$total_items = $subscriber_db->count_subscribers(
    $selected_campaign,
    $selected_tier,
    $search_query
);

$total_pages = max( 1, (int) ceil( $total_items / $per_page ) );
?>

<div class="wrap">

	<!-- Filter Form -->
	<form method="get" action="">
		<input type="hidden" name="page" value="<?php echo esc_attr( $_GET['page'] ); ?>" />

		<div class="tablenav top">
			<div class="alignleft actions">
				<!-- Tier Filter -->
				<select name="tier" id="filter-tier">
					<option value="">Tiers</option>
					<?php foreach ( $tiers as $tier ) : ?>
						<option value="<?php echo esc_attr( $tier ); ?>" <?php selected( $selected_tier, $tier ); ?>>
							<?php echo esc_html( $tier ); ?>
						</option>
					<?php endforeach; ?>
				</select>

				<!-- Campaign Filter -->
				<select name="campaign" id="filter-campaign">
					<option value="">Campaigns</option>
					<?php foreach ( $campaigns as $name ) : ?>
						<option value="<?php echo esc_attr( $name ); ?>" <?php selected( $selected_campaign, $name); ?>>
							<?php echo esc_html( $name ); ?>
						</option>
					<?php endforeach; ?>
				</select>

				<!-- Search -->
				<input type="search" id="subscriber-search-input" name="search" placeholder="name@email.com" value="<?php echo esc_attr( $search_query ); ?>" />

				<?php submit_button( 'Filter', '', 'filter_action', false ); ?>
			</div>
		</div>
	</form>

	<!-- Bulk Actions Form -->
	<form method="post" action="">
		<?php wp_nonce_field( 'bema_crm_bulk_action', 'bema_crm_nonce' ); ?>

		<div class="tablenav top">
			<div class="alignleft actions bulkactions">
				<select name="action" id="bulk-action-selector-top">
					<option value="-1">Bulk actions</option>
					<option value="resync">Resync</option>
				</select>
				<?php submit_button( 'Apply', '', 'doaction', false ); ?>
			</div>
			<div class="tablenav-pages">
				<span class="displaying-num"><?php echo esc_html( $total_items ); ?> items</span>
				<?php
				echo paginate_links(
					array(
						'base'      => add_query_arg( 'paged', '%#%' ),
						'format'    => '',
						'prev_text' => '«',
						'next_text' => '»',
						'total'     => $total_pages,
						'current'   => $paged,
					)
				);
				?>
			</div>
		</div>

		<table class="wp-list-table widefat fixed striped table-view-list users">
			<thead>
				<tr>
					<td id="cb" class="manage-column column-cb check-column">
						<input type="checkbox" />
					</td>
					<th scope="col" class="manage-column">Email</th>
					<th scope="col" class="manage-column">Name</th>
					<th scope="col" class="manage-column">Status</th>
					<?php if ( $selected_campaign ) : ?>
						<th scope="col" class="manage-column">Tier</th>
						<th scope="col" class="manage-column">Purchase ID</th>
					<?php endif; ?>
				</tr>
			</thead>
			<tbody>
				<?php if ( ! empty( $subscribers ) ) : ?>
					<?php foreach ( $subscribers as $subscriber ) : ?>
						<tr>
							<th scope="row" class="check-column">
								<input type="checkbox" name="subscriber_ids[]" value="<?php echo esc_attr( $subscriber['id'] ); ?>" />
							</th>
							<td><?php echo esc_html( $subscriber['email'] ); ?></td>
							<td><?php echo esc_html( $subscriber['name'] ); ?></td>
							<td><?php echo esc_html( ucfirst( $subscriber['status'] ) ); ?></td>
							<?php if ( $selected_campaign ) : ?>
								<td><?php echo esc_html( $subscriber['tier'] ); ?></td>
								<td><?php echo esc_html( $subscriber['purchase_id'] ); ?></td>
							<?php endif; ?>
						</tr>
					<?php endforeach; ?>
				<?php else : ?>
					<tr>
						<td class="text-center" colspan="<?php echo $selected_campaign ? 6 : 4; ?>">
							No subscribers found.
						</td>
					</tr>
				<?php endif; ?>
			</tbody>
		</table>

		<div class="tablenav bottom">
			<div class="tablenav-pages">
				<span class="displaying-num"><?php echo esc_html( $total_items ); ?> items</span>
				<?php
				echo paginate_links(
					array(
						'base'      => add_query_arg( 'paged', '%#%' ),
						'format'    => '',
						'prev_text' => '«',
						'next_text' => '»',
						'total'     => $total_pages,
						'current'   => $paged,
					)
				);
				?>
			</div>
		</div>
	</form>
</div>
