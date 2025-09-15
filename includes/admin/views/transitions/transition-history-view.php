<?php

$transition_database = new \Bema\Database\Transition_Database_Manager();


$transition_history = $transition_database->get_all_records();
?>

<?php if (!empty($transition_history)): ?>
    <div class="transition-history" style="margin-top: 30px;">
        <h3><?php _e('Recent Transitions', 'bema-crm'); ?></h3>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php _e('Source Campaign', 'bema-crm'); ?></th>
                    <th><?php _e('Destination Campaign', 'bema-crm'); ?></th>
                    <th><?php _e('Status', 'bema-crm'); ?></th>
                    <th><?php _e('Subscribers Moved', 'bema-crm'); ?></th>
                    <th><?php _e('Date', 'bema-crm'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($transition_history)): ?>
                    <?php foreach ($transition_history as $row): ?>
                        <tr>
                            <td><?php _e(esc_html($row['source'], 'bema-crm')); ?></td>
                            <td><?php _e(esc_html($row['destination']), 'bema-crm'); ?></td>
                            <td><?php _e(esc_html($row['status']), 'bema-crm'); ?></td>
                            <td><?php _e(esc_html($row['subscribers']), 'bema-crm'); ?></td>
                            <td>
                                <?php
                                try {
                                    $dateString = '2025-09-10 09:50:31';

                                    $date_time_obj = new DateTime($dateString);
                                    $readable_date_time = $date_time_obj->format('F j, Y, g:i a');
                                    _e($readable_date_time, 'bema-crm');

                                } catch (Exception $e) {
                                    _e('—', 'bema-crm');
                                    $admin->$logger('Error: ' . esc_html($e->getMessage()));
                                }
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td class="text-center" colspan="5">No transition history found.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>