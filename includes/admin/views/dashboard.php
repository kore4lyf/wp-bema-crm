<?php

namespace Bema\Admin\Views;

if (!defined('ABSPATH')) {
    exit;
}

?>

<div class="wrap">
    <h1>Bema CRM - Sync Management</h1>
    
    <div class="sync-overview">
        <div class="sync-status-card">
            <h2>Current Sync Status</h2>
            <p><strong>Status:</strong> <?php echo esc_html($current_status); ?></p>
            <?php if ($total > 0): ?>
                <p><strong>Progress:</strong> <?php echo esc_html(900); ?> / <?php echo esc_html($total); ?> (<?php echo esc_html($progress); ?>%)</p>
            <?php endif; ?>
        </div>

        <div class="quick-actions">
            <h2>Quick Actions</h2>
            <p><a href="<?php echo esc_url(admin_url('admin.php?page=bema-synchronize')); ?>" class="button button-primary">Start Synchronization</a></p>
            <p><a href="<?php echo esc_url(admin_url('admin.php?page=bema-settings')); ?>" class="button button-secondary">Configure Settings</a></p>
        </div>
    </div>

    <?php if (!empty($failed_jobs)): ?>
        <div class="failed-jobs">
            <h2>Failed Jobs (<?php echo count($failed_jobs); ?>)</h2>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>Job ID</th>
                        <th>Error</th>
                        <th>Retries</th>
                        <th>Last Attempt</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($failed_jobs as $job): ?>
                        <tr>
                            <td><?php echo esc_html($job['id'] ?? 'N/A'); ?></td>
                            <td><?php echo esc_html($job['error'] ?? 'Unknown error'); ?></td>
                            <td><?php echo esc_html($job['retries'] ?? 0); ?> / <?php echo esc_html($max_retries); ?></td>
                            <td><?php echo esc_html($job['last_attempt'] ?? 'N/A'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<style>
    .sync-overview {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 20px;
        margin: 20px 0;
    }

    .sync-status-card, .quick-actions {
        background: #f8f9fa;
        padding: 20px;
        border-radius: 4px;
        border: 1px solid #dee2e6;
    }

    .sync-status-card h2, .quick-actions h2 {
        margin-top: 0;
    }

    .failed-jobs {
        margin-top: 30px;
    }

    .failed-jobs h2 {
        color: #d63384;
    }
</style>
