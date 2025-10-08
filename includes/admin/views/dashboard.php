<?php
namespace Bema\Admin\Views;

use function esc_url;
use function admin_url;
use function esc_html;
use function esc_attr__;
use function wp_json_encode;
use const ARRAY_A;

if (!defined('ABSPATH')) {
    exit;
}

// Dashboard Data Collection - All logic in one place
class Dashboard_Data {
    
    public static function get_campaign_statistics(): array {
        try {
            global $wpdb;
            $table = $wpdb->prefix . 'bemacrm_campaignsmeta';
            
            $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
            $active = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'active'");
            
            $active_campaigns = $wpdb->get_results(
                "SELECT * FROM {$table} WHERE status = 'active' ORDER BY id DESC LIMIT 5", 
                \ARRAY_A
            );
            
            return [
                'total_count' => $total,
                'active_count' => $active,
                'active_campaigns' => $active_campaigns ?: []
            ];
        } catch (\Exception $e) {
            return ['total_count' => 0, 'active_count' => 0, 'active_campaigns' => []];
        }
    }
    
    public static function get_sync_statistics(): array {
        try {
            global $wpdb;
            $table = $wpdb->prefix . 'bemacrm_sync_log';
            
            // Check if table exists first
            $table_exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) ) === $table;
            if (!$table_exists) {
                return ['success_count' => 0, 'failed_count' => 0, 'last_sync_time' => 'Never'];
            }
            
            $success = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'Complete'");
            $failed = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'Failed'");
            $last_sync = $wpdb->get_var("SELECT sync_date FROM {$table} ORDER BY sync_date DESC LIMIT 1");
            
            return [
                'success_count' => $success,
                'failed_count' => $failed,
                'last_sync_time' => $last_sync ?: 'Never'
            ];
        } catch (\Exception $e) {
            return ['success_count' => 0, 'failed_count' => 0, 'last_sync_time' => 'Never'];
        }
    }
    
    public static function get_subscriber_statistics(): array {
        try {
            global $wpdb;
            $table = $wpdb->prefix . 'bemacrm_subscribersmeta';
            
            $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
            
            $status_results = $wpdb->get_results(
                "SELECT status, COUNT(*) as count FROM {$table} GROUP BY status", 
                \ARRAY_A
            );
            
            $status_counts = [
                'active' => 0,
                'unsubscribed' => 0,
                'unconfirmed' => 0,
                'bounced' => 0,
                'junk' => 0
            ];
            
            foreach ($status_results as $row) {
                $status_counts[$row['status']] = (int) $row['count'];
            }
            
            return [
                'total_count' => $total,
                'status_counts' => $status_counts
            ];
        } catch (\Exception $e) {
            return [
                'total_count' => 0,
                'status_counts' => ['active' => 0, 'unsubscribed' => 0, 'unconfirmed' => 0, 'bounced' => 0, 'junk' => 0]
            ];
        }
    }
    
    /**
     * Calculate campaign revenue using EDD functions.
     * 
     * @param int $campaign_id The campaign ID
     * @return float The total revenue for the campaign
     */
    private static function get_campaign_revenue_from_edd(int $campaign_id): float
    {
        try {
            global $wpdb;
            
            // Get all purchase IDs for this campaign
            $campaign_subs_table = $wpdb->prefix . 'bemacrm_campaign_subscribersmeta';
            $purchase_ids = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT purchase_id FROM {$campaign_subs_table} WHERE campaign_id = %d AND purchase_id IS NOT NULL",
                    $campaign_id
                )
            );
            
            $total_revenue = 0.0;
            
            // For each purchase ID, get the payment amount using EDD functions
            foreach ($purchase_ids as $purchase_id) {
                if (function_exists('edd_get_payment_amount')) {
                    // edd_get_payment_amount returns the payment total
                    $amount = \edd_get_payment_amount($purchase_id);
                    $total_revenue += (float) $amount;
                } elseif (function_exists('edd_get_payment_meta')) {
                    // Fallback to edd_get_payment_meta
                    $amount = \edd_get_payment_meta($purchase_id, '_edd_payment_total', true);
                    $total_revenue += (float) $amount;
                }
            }
            
            return $total_revenue;
        } catch (\Exception $e) {
            // Log the error and return 0 revenue
            error_log('Error calculating EDD revenue for campaign ' . $campaign_id . ': ' . $e->getMessage());
            return 0.0;
        }
    }
    
    public static function get_revenue_statistics(): array {
        try {
            global $wpdb;
            $campaigns_table = $wpdb->prefix . 'bemacrm_campaignsmeta';
            
            // Get all campaigns
            $campaigns_list = $wpdb->get_results(
                "SELECT id, campaign, status FROM {$campaigns_table} ORDER BY id DESC",
                \ARRAY_A
            );

            $total_revenue = 0.0;
            $campaign_revenues = [];

            // Check if EDD functions are available
            $edd_available = function_exists('edd_get_payment_meta');
            
            if ($edd_available) {
                // Use EDD functions to calculate revenue
                foreach ($campaigns_list as $c) {
                    $rev = self::get_campaign_revenue_from_edd((int) $c['id']);
                    $total_revenue += $rev;
                    $campaign_revenues[] = [
                        'id' => $c['id'],
                        'name' => $c['campaign'],
                        'status' => $c['status'],
                        'revenue' => $rev
                    ];
                }
            } else {
                // Fallback to database helper method
                $cgsm = new \Bema\Database\Campaign_Group_Subscribers_Database_Manager();
                foreach ($campaigns_list as $c) {
                    $rev = (float) $cgsm->get_revenue_by_campaign((int) $c['id']);
                    $total_revenue += $rev;
                    $campaign_revenues[] = [
                        'id' => $c['id'],
                        'name' => $c['campaign'],
                        'status' => $c['status'],
                        'revenue' => $rev
                    ];
                }
            }
            
            return [
                'total_revenue' => $total_revenue,
                'campaigns' => $campaign_revenues
            ];
        } catch (\Exception $e) {
            return [
                'total_revenue' => 0,
                'campaigns' => []
            ];
        }
    }
}

// Collect all dashboard data
$campaign_stats = Dashboard_Data::get_campaign_statistics();
$sync_stats = Dashboard_Data::get_sync_statistics();
$subscriber_stats = Dashboard_Data::get_subscriber_statistics();
$revenue_stats = Dashboard_Data::get_revenue_statistics();
?>

<div class="wrap bema-dashboard">
    <div class="bema-header">
        <h1 class="bema-title">
            <img src="<?php echo esc_url('https://bemamusic.com/wp-content/uploads/2025/10/bema.webp'); ?>" width="50" height="50" alt="<?php echo esc_attr__('Bema Logo', 'bema_crm'); ?>" class="bema-logo"/>
             Dashboard
        </h1>
        <p class="bema-subtitle">Music campaign management and subscriber analytics</p>
    </div>
    
    <!-- Campaign Statistics -->
    <div class="bema-section">
        <div class="bema-section-header">
            <h2><span class="dashicons dashicons-megaphone"></span> Campaign Overview</h2>
            <a href="<?php echo esc_url(admin_url('admin.php?page=bema-campaigns')); ?>" class="bema-btn bema-btn-primary">
                <span class="dashicons dashicons-visibility"></span> View All Campaigns
            </a>
        </div>
        
        <div class="bema-stats-grid">
            <div class="bema-stat-card bema-card-primary">
                <div class="bema-stat-icon">
                    <span class="dashicons dashicons-yes-alt"></span>
                </div>
                <div class="bema-stat-content">
                      <div class="bema-stat-number"><?php echo esc_html($campaign_stats['active_count']); ?></div>
                    <div class="bema-stat-label">Active Campaigns</div>
                </div>
            </div>
            
            <div class="bema-stat-card bema-card-secondary">
                <div class="bema-stat-icon">
                    <span class="dashicons dashicons-portfolio"></span>
                </div>
                <div class="bema-stat-content">
                    <div class="bema-stat-number"><?php echo esc_html($campaign_stats['total_count']); ?></div>
                    <div class="bema-stat-label">Total Campaigns</div>
                </div>
            </div>
        </div>
        
        <!-- Active Campaigns List -->
        <?php if (!empty($campaign_stats['active_campaigns'])): ?>
        <div class="bema-active-campaigns">
            <h3>Active Campaigns</h3>
            <div class="bema-campaigns-list">
                <?php foreach ($campaign_stats['active_campaigns'] as $campaign): ?>
                    <div class="bema-campaign-item">
                        <div class="bema-campaign-name"><?php echo esc_html($campaign['campaign']); ?></div>
                        <div class="bema-campaign-dates">
                            <?php 
                            $start = $campaign['start_date'] ? date('M j', strtotime($campaign['start_date'])) : 'No start';
                            $end = $campaign['end_date'] ? date('M j', strtotime($campaign['end_date'])) : 'No end';
                            echo esc_html("$start - $end");
                            ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- Sync & Subscriber Stats Row -->
    <div class="bema-row">
        <!-- Sync Status -->
        <div class="bema-section bema-col-half">
            <div class="bema-section-header">
                <h2><span class="dashicons dashicons-update"></span> Sync Status</h2>
            </div>
            
            <div class="bema-stats-grid bema-stats-compact">
                <div class="bema-stat-card bema-card-success">
                    <div class="bema-stat-icon">
                        <span class="dashicons dashicons-yes"></span>
                    </div>
                    <div class="bema-stat-content">
                        <div class="bema-stat-number"><?php echo esc_html($sync_stats['success_count']); ?></div>
                        <div class="bema-stat-label">Successful</div>
                    </div>
                </div>
                
                <div class="bema-stat-card bema-card-danger">
                    <div class="bema-stat-icon">
                        <span class="dashicons dashicons-no"></span>
                    </div>
                    <div class="bema-stat-content">
                        <div class="bema-stat-number"><?php echo esc_html($sync_stats['failed_count']); ?></div>
                        <div class="bema-stat-label">Failed</div>
                    </div>
                </div>
            </div>
            
            <div class="bema-last-sync">
                <span class="dashicons dashicons-clock"></span>
                Last sync: <strong><?php echo esc_html($sync_stats['last_sync_time'] ?: 'Never'); ?></strong>
            </div>
        </div>
        
        <!-- Subscriber Metrics -->
        <div class="bema-section bema-col-half">
            <div class="bema-section-header">
                <h2><span class="dashicons dashicons-groups"></span> Subscribers</h2>
            </div>
            
            <div class="bema-subscriber-overview">
                <div class="bema-total-subscribers">
                    <div class="bema-big-number"><?php echo esc_html(number_format($subscriber_stats['total_count'])); ?></div>
                    <div class="bema-big-label">Total Subscribers</div>
                </div>
                
                <div class="bema-subscriber-breakdown">
                    <div class="bema-breakdown-item">
                        <span class="bema-breakdown-dot bema-dot-active"></span>
                        <span class="bema-breakdown-label">Active</span>
                        <span class="bema-breakdown-count"><?php echo esc_html($subscriber_stats['status_counts']['active']); ?></span>
                    </div>
                    <div class="bema-breakdown-item">
                        <span class="bema-breakdown-dot bema-dot-unconfirmed"></span>
                        <span class="bema-breakdown-label">Unconfirmed</span>
                        <span class="bema-breakdown-count"><?php echo esc_html($subscriber_stats['status_counts']['unconfirmed']); ?></span>
                    </div>
                    <div class="bema-breakdown-item">
                        <span class="bema-breakdown-dot bema-dot-unsubscribed"></span>
                        <span class="bema-breakdown-label">Unsubscribed</span>
                        <span class="bema-breakdown-count"><?php echo esc_html($subscriber_stats['status_counts']['unsubscribed']); ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Revenue Analytics -->
    <div class="bema-section">
        <div class="bema-section-header">
            <h2><span class="dashicons dashicons-chart-line"></span> Revenue Analytics</h2>
        </div>
        
        <div class="bema-revenue-overview">
            <div class="bema-total-revenue">
                <div class="bema-big-number bema-revenue-number">$<?php echo esc_html(number_format($revenue_stats['total_revenue'], 2)); ?></div>
                <div class="bema-big-label">Total Revenue</div>
            </div>
            
            <div class="bema-revenue-campaigns">
                <div class="bema-campaigns-header">
                    <h3>Campaign Revenue</h3>
                    <div class="bema-pagination-controls">
                        <button id="prev-page" disabled>Previous</button>
                        <span id="page-info">Page 1</span>
                        <button id="next-page">Next</button>
                    </div>
                </div>
                
                <div id="campaigns-list" class="bema-campaigns-revenue-list">
                    <!-- JavaScript will populate this -->
                </div>
            </div>
        </div>
    </div>
</div>

<script type="text/javascript">
jQuery(document).ready(function($) {
    // Revenue campaigns data from PHP
    const campaignsData = <?php echo wp_json_encode( $revenue_stats['campaigns'] ); ?>;
    const itemsPerPage = 5;
    let currentPage = 1;
    
    function displayPage(page) {
        const startIndex = (page - 1) * itemsPerPage;
        const endIndex = startIndex + itemsPerPage;
        const pageData = campaignsData.slice(startIndex, endIndex);
        
        let html = '';
        pageData.forEach(campaign => {
            const statusClass = campaign.status === 'active' ? 'status-active' : 
                               campaign.status === 'draft' ? 'status-draft' : 'status-inactive';
            
            html += `
                <div class="bema-campaign-revenue-item">
                    <div class="bema-campaign-info">
                        <span class="bema-campaign-name">${campaign.name}</span>
                        <span class="bema-status-badge ${statusClass}">${campaign.status}</span>
                    </div>
                    <div class="bema-campaign-amount">$${campaign.revenue.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</div>
                </div>
            `;
        });
        
        $('#campaigns-list').html(html);
        
        // Update pagination controls
        const totalPages = Math.ceil(campaignsData.length / itemsPerPage);
        $('#page-info').text(`Page ${page} of ${totalPages}`);
        $('#prev-page').prop('disabled', page === 1);
        $('#next-page').prop('disabled', page === totalPages);
    }
    
    // Pagination event handlers
    $('#prev-page').on('click', function() {
        if (currentPage > 1) {
            currentPage--;
            displayPage(currentPage);
        }
    });
    
    $('#next-page').on('click', function() {
        const totalPages = Math.ceil(campaignsData.length / itemsPerPage);
        if (currentPage < totalPages) {
            currentPage++;
            displayPage(currentPage);
        }
    });
    
    // Initial display
    displayPage(1);
});
</script>

<?php
// Enqueue dashboard-specific CSS
wp_enqueue_style(
    'bema-dashboard-css',
    plugins_url('assets/css/dashboard.css', BEMA_FILE),
    [],
    BEMA_VERSION
);
?>
