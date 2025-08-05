<?php
namespace Bema\Interfaces;

if (!defined('ABSPATH')) {
    exit;
}

interface Lock_Handler
{
    public function acquireLock(string $key): bool;
    public function releaseLock(string $key): bool;
    public function getActiveLocks(): array;
}
