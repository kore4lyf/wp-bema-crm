# NOTES

## Old transitions table


```html
<div class="postbox">
            <h2 class="transitions-subtitle"><span><?php _e('Tier Transition Matrix', 'bema-crm'); ?></span></h2>
            <div class="inside">
                <table class="wp-list-table widefat fixed striped transition-matrix">
                    <thead>
                        <tr>
                            <th><?php _e('Current Tier', 'bema-crm'); ?></th>
                            <th><?php _e('Next Campaign Tier', 'bema-crm'); ?></th>
                            <th><?php _e('Purchase Required', 'bema-crm'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($transition_matrix as $transition): ?>
                            <tr>
                                <td><?php echo esc_html($transition['current_tier']); ?></td>
                                <td><?php echo esc_html($transition['next_tier']); ?></td>
                                <td>
                                    <?php if ($transition['requires_purchase']): ?>
                                        <span class="dashicons dashicons-yes"></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
```