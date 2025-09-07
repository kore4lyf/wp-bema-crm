<?php
namespace Bema\Validators;

use Bema\Bema_CRM_Logger;

if (!defined('ABSPATH')) {
    exit;
}

class Tier_Validator extends Base_Validator
{
    private $validTransitions = [
        'unassigned' => ['opt-in', 'gold_purchased'],
        'opt-in' => ['silver', 'gold_purchased'],
        'silver' => ['bronze', 'silver_purchased'],
        'bronze' => ['wood', 'bronze_purchased'],
        'gold' => ['silver', 'gold_purchased'],
        'gold_purchased' => ['gold_purchased', 'gold'],
        'silver_purchased' => ['silver_purchased', 'gold'],
        'bronze_purchased' => ['bronze_purchased', 'silver'],
        'wood' => ['wood', 'bronze']
    ];

    private $purchaseRequiredTiers = [
        'gold_purchased',
        'silver_purchased',
        'bronze_purchased'
    ];

    private $transitionReasons = [];
    private $transitionHistory = [];
    private $maxTransitionsPerDay = 3;

    public function validate($data): bool
    {
        $this->clearErrors();

        if (!$this->validateDataStructure($data)) {
            return false;
        }

        return $this->validateTierTransition(
            $data['current_tier'],
            $data['new_tier'],
            $data['has_purchased'] ?? false,
            $data['timestamp'] ?? time(),
            $data['reason'] ?? null
        );
    }

    private function validateDataStructure(array $data): bool
    {
        $requiredFields = ['current_tier', 'new_tier'];
        foreach ($requiredFields as $field) {
            if (!isset($data[$field])) {
                $this->addError("Missing required field: $field", ['field' => $field]);
                return false;
            }
        }
        return true;
    }

    private function validateTierTransition(
        string $currentTier,
        string $newTier,
        bool $hasPurchased,
        int $timestamp,
        ?string $reason
    ): bool {
        $isValid = true;

        if (!$this->validateTierExists($currentTier)) {
            $isValid = false;
        }

        if (!$this->validateTierExists($newTier)) {
            $isValid = false;
        }

        if (!$this->validateTransitionAllowed($currentTier, $newTier)) {
            $isValid = false;
        }

        if (!$this->validatePurchaseRequirements($newTier, $hasPurchased)) {
            $isValid = false;
        }

        if (!$this->validateTransitionFrequency($currentTier, $timestamp)) {
            $isValid = false;
        }

        if ($reason && !$this->validateTransitionReason($reason)) {
            $isValid = false;
        }

        if ($isValid) {
            $this->logTransition($currentTier, $newTier, $timestamp, $reason);
        }

        return $isValid;
    }

    private function validateTierExists(string $tier): bool
    {
        if (!isset($this->validTransitions[$tier])) {
            $this->addError("Invalid tier: $tier", ['tier' => $tier]);
            return false;
        }
        return true;
    }

    private function validateTransitionAllowed(string $currentTier, string $newTier): bool
    {
        if (!in_array($newTier, $this->validTransitions[$currentTier])) {
            $this->addError(
                "Invalid tier transition from $currentTier to $newTier",
                ['from_tier' => $currentTier, 'to_tier' => $newTier]
            );
            return false;
        }
        return true;
    }

    private function validatePurchaseRequirements(string $newTier, bool $hasPurchased): bool
    {
        if (in_array($newTier, $this->purchaseRequiredTiers) && !$hasPurchased) {
            $this->addError(
                "Purchase required for tier: $newTier",
                ['tier' => $newTier, 'has_purchased' => $hasPurchased]
            );
            return false;
        }
        return true;
    }

    private function validateTransitionFrequency(string $currentTier, int $timestamp): bool
    {
        $dailyTransitions = $this->getTransitionsInLastDay($timestamp);
        if ($dailyTransitions >= $this->maxTransitionsPerDay) {
            $this->addError(
                "Maximum tier transitions per day exceeded",
                ['daily_transitions' => $dailyTransitions, 'max_allowed' => $this->maxTransitionsPerDay]
            );
            return false;
        }
        return true;
    }

    private function validateTransitionReason(string $reason): bool
    {
        if (!in_array($reason, $this->transitionReasons)) {
            $this->addWarning(
                "Unrecognized transition reason: $reason",
                ['reason' => $reason]
            );
            if ($this->validateMode === 'strict') {
                return false;
            }
        }
        return true;
    }

    private function getTransitionsInLastDay(int $timestamp): int
    {
        $dayStart = strtotime('today', $timestamp);
        return count(array_filter(
            $this->transitionHistory,
            fn($transition) => $transition['timestamp'] >= $dayStart
        ));
    }

    private function logTransition(string $fromTier, string $toTier, int $timestamp, ?string $reason): void
    {
        $this->transitionHistory[] = [
            'from_tier' => $fromTier,
            'to_tier' => $toTier,
            'timestamp' => $timestamp,
            'reason' => $reason
        ];

        $this->logger->info(
            "Tier transition logged",
            'info',
            [
                'from_tier' => $fromTier,
                'to_tier' => $toTier,
                'reason' => $reason
            ]
        );
    }

    public function setTransitionReasons(array $reasons): void
    {
        $this->transitionReasons = $reasons;
    }

    public function setMaxTransitionsPerDay(int $max): void
    {
        $this->maxTransitionsPerDay = max(1, $max);
    }

    public function getTransitionHistory(): array
    {
        return $this->transitionHistory;
    }
}
