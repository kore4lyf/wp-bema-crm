<?php

$transition_database = new \Bema\Database\Transition_Database_Manager();


$transition_history = $transition_database->get_all_records();
?>

<?php if (!empty($transition_history)): ?>
    <div class="transition-history" style="margin-top: 30px;">
        <h3>Recent Transitions</h3>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>Source Campaign</th>
                    <th>Destination Campaign</th>
                    <th>Status</th>
                    <th>Subscribers Moved</th>
                    <th>Date</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($transition_history)): ?>
                    <?php foreach ($transition_history as $row): ?>
                        <tr>
                            <td><?php echo esc_html($row['source']); ?></td>
                            <td><?php echo esc_html($row['destination']); ?></td>
                            <td><?php echo esc_html($row['status']); ?></td>
                            <td><?php echo esc_html($row['subscribers']); ?></td>
                            <td>
                                <?php
                                    if (isset($row['transition_date'])) {
                                        try {
                                            $date_time_obj = new DateTime($row['transition_date']);
                                            $readable_date_time = $date_time_obj->format('F j, Y, g:i a');
                                            echo $readable_date_time;

                                        } catch (Exception $e) {
                                            echo '—';
                                            $admin->$logger('Error: ' . esc_html($e->getMessage()));
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
                        <td class="text-center" colspan="5">No transition history found.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>