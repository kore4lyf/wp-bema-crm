<?php
namespace Bema\Interfaces;

if (!defined('ABSPATH')) {
    exit;
}

interface Provider_Interface
{
    public function validateConnection(): bool;
    public function getSubscribers($status = 'active'): array;
    /**
     * Update a subscriber
     * @param string|int $id Subscriber ID
     * @param array|mixed $data Update data
     * @return bool
     */
    public function updateSubscriber($id, $data): bool;
    public function addSubscriberToGroup($subscriberId, $groupId): bool;
    public function removeSubscriberFromGroup($subscriberId, $groupId): bool;
    public function getGroups(): array;
    public function addOrUpdateSubscriber(array $data): string;
}
