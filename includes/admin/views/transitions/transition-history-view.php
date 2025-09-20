<?php

use Bema\Manager_Factory;

$logger = \Bema\Bema_CRM_Logger::create('transition-history-view');

try {
    $transition_database = Manager_Factory::get_transition_manager()->transition_database;
    $transition_history = $transition_database->get_all_records();
} catch (Exception $e) {
    $transition_history = [];
    $logger->error('Failed to load transition history', ['error' => $e->getMessage()]);
}
?>

<div class="transition-history" style="margin-top: 30px;">
    <h3>Recent Transitions</h3>
    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th>Source Campaign</th>
                <th>Destination Campaign</th>
                <th>Status</th>
                <th>Subscribers</th>
                <th>Date</th>
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
                                        $date_time_obj = new DateTime($row['transition_date']);
                                        echo esc_html($date_time_obj->format('F j, Y, g:i a'));
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
</div>
