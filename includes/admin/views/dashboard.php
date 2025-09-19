<?php
namespace Bema\Admin\Views;

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
            $active = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'publish'");
            
            $active_campaigns = $wpdb->get_results(
                "SELECT * FROM {$table} WHERE status = 'publish' ORDER BY id DESC LIMIT 5", 
                ARRAY_A
            );
            
            return [
                'total_count' => $total,
                'active_count' => $active,
                'active_campaigns' => $active_campaigns ?: []
            ];
        } catch (Exception $e) {
            return ['total_count' => 0, 'active_count' => 0, 'active_campaigns' => []];
        }
    }
    
    public static function get_sync_statistics(): array {
        try {
            global $wpdb;
            $table = $wpdb->prefix . 'bemacrm_sync_log';
            
            $success = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'success'");
            $failed = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'failed'");
            $last_sync = $wpdb->get_var("SELECT sync_date FROM {$table} ORDER BY sync_date DESC LIMIT 1");
            
            return [
                'success_count' => $success,
                'failed_count' => $failed,
                'last_sync_time' => $last_sync
            ];
        } catch (Exception $e) {
            return ['success_count' => 0, 'failed_count' => 0, 'last_sync_time' => null];
        }
    }
    
    public static function get_subscriber_statistics(): array {
        try {
            global $wpdb;
            $table = $wpdb->prefix . 'bemacrm_subscribersmeta';
            
            $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
            
            $status_results = $wpdb->get_results(
                "SELECT status, COUNT(*) as count FROM {$table} GROUP BY status", 
                ARRAY_A
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
        } catch (Exception $e) {
            return [
                'total_count' => 0,
                'status_counts' => ['active' => 0, 'unsubscribed' => 0, 'unconfirmed' => 0, 'bounced' => 0, 'junk' => 0]
            ];
        }
    }
    
    public static function get_tier_and_revenue_statistics(): array {
        try {
            global $wpdb;
            $campaigns_table = $wpdb->prefix . 'bemacrm_campaignsmeta';
            $subscribers_table = $wpdb->prefix . 'bemacrm_subscribersmeta';
            $campaign_subs_table = $wpdb->prefix . 'bemacrm_campaign_subscribersmeta';
            
            // Get active campaigns
            $active_campaigns = $wpdb->get_results(
                "SELECT * FROM {$campaigns_table} WHERE status = 'publish'", 
                ARRAY_A
            );
            
            $tier_data = [];
            $total_revenue = 0;
            
            foreach ($active_campaigns as $campaign) {
                $campaign_id = $campaign['id'];
                
                // Get tier counts for this campaign
                $tier_results = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT tier, COUNT(*) as count FROM {$campaign_subs_table} WHERE campaign_id = %d GROUP BY tier",
                        $campaign_id
                    ),
                    ARRAY_A
                );
                
                $tier_counts = [];
                foreach ($tier_results as $row) {
                    $tier_counts[$row['tier']] = (int) $row['count'];
                }
                
                // Get revenue for this campaign
                $revenue = (float) $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT SUM(pm.meta_value) 
                         FROM {$campaign_subs_table} cgs
                         JOIN {$wpdb->posts} p ON cgs.purchase_id = p.ID
                         JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
                         WHERE cgs.campaign_id = %d 
                         AND p.post_type = 'edd_payment'
                         AND p.post_status = 'publish'
                         AND pm.meta_key = '_edd_payment_total'",
                        $campaign_id
                    )
                );
                
                $tier_data[$campaign['campaign']] = [
                    'tiers' => $tier_counts,
                    'revenue' => $revenue ?: 0
                ];
                
                $total_revenue += $revenue ?: 0;
            }
            
            // Subscribers without tier
            $without_tier = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$subscribers_table} s 
                 WHERE s.id NOT IN (SELECT DISTINCT subscriber_id FROM {$campaign_subs_table})"
            );
            
            return [
                'tier_data' => $tier_data,
                'total_revenue' => $total_revenue,
                'subscribers_without_tier' => $without_tier
            ];
        } catch (Exception $e) {
            return [
                'tier_data' => [],
                'total_revenue' => 0,
                'subscribers_without_tier' => 0
            ];
        }
    }
}

// Collect all dashboard data
$campaign_stats = Dashboard_Data::get_campaign_statistics();
$sync_stats = Dashboard_Data::get_sync_statistics();
$subscriber_stats = Dashboard_Data::get_subscriber_statistics();
$tier_revenue_stats = Dashboard_Data::get_tier_and_revenue_statistics();
?>

<div class="wrap bema-dashboard">
    <div class="bema-header">
        <h1 class="bema-title">
            <span class="bema-logo">🎵</span>
            Bema CRM Dashboard
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
        
        <?php if (!empty($campaign_stats['active_campaigns'])): ?>
        <div class="bema-table-container">
            <h3 class="bema-table-title">Active Campaigns</h3>
            <table class="bema-table">
                <thead>
                    <tr>
                        <th>Campaign</th>
                        <th>Start Date</th>
                        <th>End Date</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($campaign_stats['active_campaigns'] as $campaign): ?>
                        <?php 
                        $days_left = 'N/A';
                        if (!empty($campaign['end_date'])) {
                            $end_date = new DateTime($campaign['end_date']);
                            $today = new DateTime();
                            $diff = $today->diff($end_date);
                            $days_left = $end_date > $today ? $diff->days . ' days left' : 'Expired';
                        }
                        ?>
                        <tr>
                            <td class="bema-campaign-name"><?php echo esc_html($campaign['campaign']); ?></td>
                            <td><?php echo esc_html($campaign['start_date'] ?: 'Not set'); ?></td>
                            <td><?php echo esc_html($campaign['end_date'] ?: 'Not set'); ?></td>
                            <td><span class="bema-status-badge bema-status-active"><?php echo esc_html($days_left); ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
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
    
    <!-- Revenue & Tier Analytics -->
    <div class="bema-row">
        <!-- Revenue Metrics -->
        <div class="bema-section bema-col-half">
            <div class="bema-section-header">
                <h2><span class="dashicons dashicons-chart-line"></span> Revenue Analytics</h2>
            </div>
            
            <div class="bema-revenue-overview">
                <div class="bema-total-revenue">
                    <div class="bema-big-number bema-revenue-number">$<?php echo esc_html(number_format($tier_revenue_stats['total_revenue'], 2)); ?></div>
                    <div class="bema-big-label">Total Revenue</div>
                </div>
                
                <?php if (!empty($tier_revenue_stats['tier_data'])): ?>
                <div class="bema-revenue-breakdown">
                    <?php foreach (array_slice($tier_revenue_stats['tier_data'], 0, 3) as $campaign_name => $data): ?>
                        <div class="bema-revenue-item">
                            <div class="bema-campaign-revenue">
                                <span class="bema-campaign-name"><?php echo esc_html($campaign_name); ?></span>
                                <span class="bema-campaign-amount">$<?php echo esc_html(number_format($data['revenue'], 2)); ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Tier Breakdown -->
        <div class="bema-section bema-col-half">
            <div class="bema-section-header">
                <h2><span class="dashicons dashicons-awards"></span> Tier Distribution</h2>
            </div>
            
            <div class="bema-tier-overview">
                <div class="bema-no-tier">
                    <span class="bema-tier-icon">👥</span>
                    <div class="bema-tier-info">
                        <div class="bema-tier-count"><?php echo esc_html($tier_revenue_stats['subscribers_without_tier']); ?></div>
                        <div class="bema-tier-label">Without Tier</div>
                    </div>
                </div>
                
                <?php if (!empty($tier_revenue_stats['tier_data'])): ?>
                <div class="bema-tier-list">
                    <?php foreach ($tier_revenue_stats['tier_data'] as $campaign_name => $data): ?>
                        <?php foreach ($data['tiers'] as $tier => $count): ?>
                            <div class="bema-tier-item">
                                <span class="bema-tier-badge"><?php echo esc_html(strtoupper($tier)); ?></span>
                                <span class="bema-tier-campaign"><?php echo esc_html($campaign_name); ?></span>
                                <span class="bema-tier-count"><?php echo esc_html($count); ?></span>
                            </div>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<style>
.bema-dashboard {
    max-width: 1400px;
    margin: 0;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
}

.bema-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 2rem;
    border-radius: 12px;
    margin-bottom: 2rem;
    box-shadow: 0 4px 20px rgba(0,0,0,0.1);
}

.bema-title {
    margin: 0;
    font-size: 2.5rem;
    font-weight: 700;
    display: flex;
    align-items: center;
    gap: 1rem;
}

.bema-logo {
    font-size: 3rem;
}

.bema-subtitle {
    margin: 0.5rem 0 0 0;
    opacity: 0.9;
    font-size: 1.1rem;
}

.bema-section {
    background: white;
    border-radius: 12px;
    padding: 1.5rem;
    margin-bottom: 1.5rem;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    border: 1px solid #e1e5e9;
}

.bema-section-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1.5rem;
    padding-bottom: 1rem;
    border-bottom: 2px solid #f8f9fa;
}

.bema-section-header h2 {
    margin: 0;
    color: #2c3e50;
    font-size: 1.4rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.bema-btn {
    padding: 0.5rem 1rem;
    border-radius: 6px;
    text-decoration: none;
    font-weight: 500;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    transition: all 0.2s;
}

.bema-btn-primary {
    background: #3498db;
    color: white;
}

.bema-btn-primary:hover {
    background: #2980b9;
    color: white;
}

.bema-stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
    margin-bottom: 1.5rem;
}

.bema-stats-compact {
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
}

.bema-stat-card {
    background: white;
    border-radius: 10px;
    padding: 1.5rem;
    display: flex;
    align-items: center;
    gap: 1rem;
    border-left: 4px solid;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    transition: transform 0.2s;
}

.bema-stat-card:hover {
    transform: translateY(-2px);
}

.bema-card-primary { border-left-color: #3498db; }
.bema-card-secondary { border-left-color: #95a5a6; }
.bema-card-success { border-left-color: #27ae60; }
.bema-card-danger { border-left-color: #e74c3c; }

.bema-stat-icon {
    width: 50px;
    height: 50px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
}

.bema-card-primary .bema-stat-icon { background: #ebf3fd; color: #3498db; }
.bema-card-secondary .bema-stat-icon { background: #f8f9fa; color: #95a5a6; }
.bema-card-success .bema-stat-icon { background: #eafaf1; color: #27ae60; }
.bema-card-danger .bema-stat-icon { background: #fdf2f2; color: #e74c3c; }

.bema-stat-number {
    font-size: 2rem;
    font-weight: 700;
    color: #2c3e50;
    line-height: 1;
}

.bema-stat-label {
    color: #7f8c8d;
    font-size: 0.9rem;
    margin-top: 0.25rem;
}

.bema-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1.5rem;
    margin-bottom: 1.5rem;
}

.bema-col-half {
    margin-bottom: 0;
}

.bema-table-container {
    margin-top: 1rem;
}

.bema-table-title {
    color: #2c3e50;
    margin-bottom: 1rem;
    font-size: 1.1rem;
}

.bema-table {
    width: 100%;
    border-collapse: collapse;
    background: white;
    border-radius: 8px;
    overflow: hidden;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
}

.bema-table th {
    background: #f8f9fa;
    padding: 1rem;
    text-align: left;
    font-weight: 600;
    color: #2c3e50;
    border-bottom: 2px solid #e9ecef;
}

.bema-table td {
    padding: 1rem;
    border-bottom: 1px solid #f1f3f4;
}

.bema-campaign-name {
    font-weight: 600;
    color: #2c3e50;
}

.bema-status-badge {
    padding: 0.25rem 0.75rem;
    border-radius: 20px;
    font-size: 0.8rem;
    font-weight: 500;
}

.bema-status-active {
    background: #d4edda;
    color: #155724;
}

.bema-last-sync {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    color: #6c757d;
    margin-top: 1rem;
    padding-top: 1rem;
    border-top: 1px solid #f1f3f4;
}

.bema-subscriber-overview, .bema-revenue-overview, .bema-tier-overview {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.bema-total-subscribers, .bema-total-revenue {
    text-align: center;
    padding: 1rem;
    background: #f8f9fa;
    border-radius: 8px;
}

.bema-big-number {
    font-size: 2.5rem;
    font-weight: 700;
    color: #2c3e50;
}

.bema-revenue-number {
    color: #27ae60;
}

.bema-big-label {
    color: #6c757d;
    margin-top: 0.5rem;
}

.bema-subscriber-breakdown, .bema-revenue-breakdown {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.bema-breakdown-item, .bema-revenue-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0.75rem;
    background: #f8f9fa;
    border-radius: 6px;
}

.bema-breakdown-dot {
    width: 12px;
    height: 12px;
    border-radius: 50%;
    margin-right: 0.5rem;
}

.bema-dot-active { background: #27ae60; }
.bema-dot-unconfirmed { background: #f39c12; }
.bema-dot-unsubscribed { background: #e74c3c; }

.bema-breakdown-label {
    flex: 1;
}

.bema-breakdown-count, .bema-campaign-amount {
    font-weight: 600;
    color: #2c3e50;
}

.bema-campaign-revenue {
    display: flex;
    justify-content: space-between;
    width: 100%;
}

.bema-no-tier {
    display: flex;
    align-items: center;
    gap: 1rem;
    padding: 1rem;
    background: #f8f9fa;
    border-radius: 8px;
}

.bema-tier-icon {
    font-size: 2rem;
}

.bema-tier-count {
    font-size: 1.5rem;
    font-weight: 700;
    color: #2c3e50;
}

.bema-tier-label {
    color: #6c757d;
}

.bema-tier-list {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.bema-tier-item {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.75rem;
    background: #f8f9fa;
    border-radius: 6px;
}

.bema-tier-badge {
    background: #3498db;
    color: white;
    padding: 0.25rem 0.5rem;
    border-radius: 4px;
    font-size: 0.7rem;
    font-weight: 600;
    min-width: 40px;
    text-align: center;
}

.bema-tier-campaign {
    flex: 1;
    font-size: 0.9rem;
    color: #6c757d;
}

@media (max-width: 768px) {
    .bema-row {
        grid-template-columns: 1fr;
    }
    
    .bema-stats-grid {
        grid-template-columns: 1fr;
    }
    
    .bema-title {
        font-size: 2rem;
    }
    
    .bema-section-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 1rem;
    }
}
</style>
