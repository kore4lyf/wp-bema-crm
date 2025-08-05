<?php
namespace Bema\Interfaces;
if (!defined('ABSPATH')) {
    exit;
}

interface Health_Monitor
{
    public function initializeMonitoring(): void;
    public function startMonitoring(string $jobId): void;
    public function stopMonitoring(string $jobId): void;
    public function performCheck(): void;
    public function getStatus(): array;
    public function checkCampaign(string $campaign): void;
}
