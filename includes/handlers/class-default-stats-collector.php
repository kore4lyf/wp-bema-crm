<?php

namespace Bema\Handlers;

use Bema\Interfaces\Stats_Collector;

if (!defined('ABSPATH')) {
    exit;
}

class Default_Stats_Collector implements Stats_Collector
{
    private $stats = [];
    const STATS_OPTION = 'bema_sync_stats';
    public function initialize(): void
    {
        try {
            $this->stats = [
                'total_campaigns' => 0,
                'successful_campaigns' => 0,
                'failed_campaigns' => 0,
                'total_memory_cleanups' => 0,
                'last_update' => current_time('mysql'),
                'average_campaign_duration' => 0,
                'peak_memory_usage' => memory_get_peak_usage(true)
            ];

            update_option(self::STATS_OPTION, $this->stats);
        } catch (\Exception $e) {
            error_log('Failed to initialize stats collector: ' . $e->getMessage());
        }
    }
    public function startCampaign(string $campaign): void {}
    public function endCampaign(string $campaign, array $result): void {}
    public function updateStats(): void {}
    public function recordMemoryCleanup(): void {}
}
