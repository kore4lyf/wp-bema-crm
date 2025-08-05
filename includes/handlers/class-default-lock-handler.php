<?php
namespace Bema\Handlers;

use Bema\Interfaces\Lock_Handler;
if (!defined('ABSPATH')) {
    exit;
}

class Default_Lock_Handler implements Lock_Handler
{
    public function acquireLock(string $key): bool
    {
        return set_transient($key, time(), 900);
    }

    public function releaseLock(string $key): bool
    {
        return delete_transient($key);
    }

    public function getActiveLocks(): array
    {
        global $wpdb;
        $locks = $wpdb->get_results(
            "SELECT * FROM {$wpdb->options} WHERE option_name LIKE '_transient_bema_sync_lock_%'",
            ARRAY_A
        );
        return array_map(function ($lock) {
            return [
                'key' => str_replace('_transient_', '', $lock['option_name']),
                'timestamp' => maybe_unserialize($lock['option_value'])
            ];
        }, $locks);
    }
}
