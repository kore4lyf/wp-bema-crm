<?php
namespace Bema\Interfaces;
if (!defined('ABSPATH')) {
    exit;
}

interface Stats_Collector
{
    public function initialize(): void;
    public function startCampaign(string $campaign): void;
    public function endCampaign(string $campaign, array $result): void;
    public function updateStats(): void;
    public function recordMemoryCleanup(): void;
}
