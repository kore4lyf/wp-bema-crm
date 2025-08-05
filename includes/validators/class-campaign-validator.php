<?php

namespace Bema\Validators;

use Bema\BemaCRMLogger;
use Bema\Providers\MailerLite;
use Exception;

if (!defined('ABSPATH')) {
    exit;
}

class Campaign_Validator extends Base_Validator
{
    private $requiredGroups = [
        'opt-in',
        'gold',
        'gold_purchased',
        'silver',
        'silver_purchased',
        'bronze',
        'bronze_purchased',
        'wood'
    ];

    private $mailerLite;
    private $campaignPrefixPattern = '/^\d{4}_[A-Z]+_[A-Z0-9]+$/';
    private $maxCampaignsPerYear = 12;
    private $minCampaignInterval = 30; // days
    private $campaignHistory = [];

    public function __construct(\Bema\Providers\MailerLite $mailerLite, \Bema\BemaCRMLogger $logger)
    {
        parent::__construct($logger);
        $this->mailerLite = $mailerLite;
    }

    public function validate($campaignData): bool
    {
        $this->clearErrors();

        if (!$this->validateDataType($campaignData, 'array')) {
            return false;
        }

        $isValid = true;

        foreach ($campaignData as $prefix => $album) {
            if (!$this->validateCampaignPrefix($prefix)) {
                $isValid = false;
            }

            if (!$this->validateAlbumData($album)) {
                $isValid = false;
            }

            if (!$this->validateCampaignTiming($prefix)) {
                $isValid = false;
            }

            if (!$this->validateMailerLiteGroups($prefix)) {
                $isValid = false;
            }

            if ($isValid) {
                $this->logCampaignValidation($prefix, $album);
            }
        }

        return $isValid;
    }

    private function validateCampaignPrefix(string $prefix): bool
    {
        if (!preg_match($this->campaignPrefixPattern, $prefix)) {
            $this->addError(
                "Invalid campaign prefix format: $prefix. Expected format: YYYY_ARTIST_CAMPAIGN",
                ['prefix' => $prefix]
            );
            return false;
        }

        list($year, $artist, $campaign) = explode('_', $prefix);

        if ($year < date('Y') || $year > date('Y') + 1) {
            $this->addError(
                "Invalid campaign year: $year",
                ['year' => $year, 'prefix' => $prefix]
            );
            return false;
        }

        return true;
    }

    private function validateAlbumData($album): bool
    {
        if (empty($album) || !is_string($album)) {
            $this->addError(
                "Invalid album name",
                ['album' => print_r($album, true)]
            );
            return false;
        }

        if (strlen($album) > 255) {
            $this->addError(
                "Album name exceeds maximum length",
                ['album' => $album, 'length' => strlen($album)]
            );
            return false;
        }

        return true;
    }

    private function validateCampaignTiming(string $prefix): bool
    {
        list($year, $artist, $campaign) = explode('_', $prefix);

        $yearCampaigns = array_filter(
            $this->campaignHistory,
            fn($c) => strpos($c['prefix'], $year) === 0
        );

        if (count($yearCampaigns) >= $this->maxCampaignsPerYear) {
            $this->addError(
                "Maximum campaigns per year exceeded",
                ['year' => $year, 'count' => count($yearCampaigns)]
            );
            return false;
        }

        $lastCampaign = end($this->campaignHistory);
        if ($lastCampaign) {
            $daysSinceLastCampaign = (time() - $lastCampaign['timestamp']) / 86400;
            if ($daysSinceLastCampaign < $this->minCampaignInterval) {
                $this->addError(
                    "Minimum interval between campaigns not met",
                    [
                        'days_since_last' => round($daysSinceLastCampaign),
                        'min_interval' => $this->minCampaignInterval
                    ]
                );
                return false;
            }
        }

        return true;
    }

    private function validateMailerLiteGroups(string $prefix): bool
    {
        try {
            $existingGroups = $this->mailerLite->getGroups();
            $existingGroupNames = array_map(function ($group) {
                // Normalize group names by removing spaces and converting to uppercase
                return str_replace(' ', '_', strtoupper($group['name']));
            }, $existingGroups);

            $isValid = true;

            foreach ($this->requiredGroups as $group) {
                $groupName = "{$prefix}_{$group}";
                // Normalize the group name we're looking for
                $normalizedGroupName = str_replace(' ', '_', strtoupper($groupName));

                if (!in_array($normalizedGroupName, $existingGroupNames)) {
                    // Also check with space variant
                    $spaceVariant = str_replace('_', ' ', $normalizedGroupName);
                    if (!in_array($spaceVariant, $existingGroupNames)) {
                        $this->addError(
                            "Required MailerLite group missing",
                            ['group' => $groupName, 'normalized' => $normalizedGroupName]
                        );
                        $isValid = false;
                    }
                }
            }

            return $isValid;
        } catch (Exception $e) {
            $this->addError(
                "Failed to validate MailerLite groups",
                ['error' => $e->getMessage()]
            );
            return false;
        }
    }

    private function logCampaignValidation(string $prefix, string $album): void
    {
        $this->campaignHistory[] = [
            'prefix' => $prefix,
            'album' => $album,
            'timestamp' => time()
        ];

        $this->logger->log(
            "Campaign validation successful",
            'info',
            [
                'prefix' => $prefix,
                'album' => $album
            ]
        );
    }

    public function setMaxCampaignsPerYear(int $max): void
    {
        $this->maxCampaignsPerYear = max(1, $max);
    }

    public function setMinCampaignInterval(int $days): void
    {
        $this->minCampaignInterval = max(1, $days);
    }

    public function getCampaignHistory(): array
    {
        return $this->campaignHistory;
    }

    /**
     * Validate campaign groups
     */
    private function validateCampaignGroups(string $campaign, array $groups): bool
    {
        try {
            $campaignCode = strtoupper($campaign);
            $requiredGroups = [];

            // Generate full group names with campaign prefix
            foreach ($groups as $groupName) {
                $fullGroupName = "{$campaignCode}_{$groupName}";
                $requiredGroups[] = $fullGroupName;
            }

            // Get all MailerLite groups
            $mailerliteGroups = $this->mailerLite->getGroups();
            $mailerliteGroupNames = array_map(function ($group) {
                return $group['name'];
            }, $mailerliteGroups);

            $missingGroups = [];
            foreach ($requiredGroups as $groupName) {
                if (!in_array($groupName, $mailerliteGroupNames)) {
                    $missingGroups[] = $groupName;
                }
            }

            if (!empty($missingGroups)) {
                $this->addError("Missing required groups: " . implode(', ', $missingGroups));
                return false;
            }

            return true;
        } catch (Exception $e) {
            $this->addError("Group validation failed: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * Get required groups for campaign
     */
    private function getRequiredGroups(string $campaignName): array
    {
        $baseGroups = [
            'optin' => "{$campaignName}_OPT-IN",
            'gold' => "{$campaignName}_GOLD",
            'gold_purchased' => "{$campaignName}_GOLD_PURCHASED",
            'silver' => "{$campaignName}_SILVER",
            'silver_purchased' => "{$campaignName}_SILVER_PURCHASED",
            'bronze' => "{$campaignName}_BRONZE",
            'bronze_purchased' => "{$campaignName}_BRONZE_PURCHASED",
            'wood' => "{$campaignName}_WOOD"
        ];

        return array_filter($baseGroups);
    }

    /**
     * Validate group settings
     */
    private function validateGroupSettings(array $group): bool
    {
        // Add any specific validation for group settings
        // For example, check if the group has the correct permissions,
        // is active, etc.
        return true;
    }

    /**
     * Extended campaign validation
     * @param array $campaign Campaign data
     * @param array|null $groups Optional groups to validate
     * @return bool
     */
    public function validateCampaign(array $campaign, ?array $groups = null): bool
    {
        $this->clearErrors();

        if (!$this->validateCampaignStructure($campaign)) {
            return false;
        }

        $isValid = true;

        // Validate prefix format
        if (!$this->validateCampaignPrefix($campaign['name'])) {
            $isValid = false;
        }

        // Validate campaign timing
        if (!$this->validateCampaignTiming($campaign['name'])) {
            $isValid = false;
        }

        // Use provided groups or get from campaign
        $groupsToValidate = $groups ?? $this->getRequiredGroups($campaign['name']);

        // Validate campaign groups
        if (!$this->validateCampaignGroups($campaign['name'], $groupsToValidate)) {
            $isValid = false;
        }

        if ($isValid) {
            $this->logCampaignValidation($campaign['name'], $campaign['album'] ?? '');
        }

        return $isValid;
    }

    /**
     * Validate campaign data structure
     */
    private function validateCampaignStructure(array $campaign): bool
    {
        $requiredFields = ['name', 'field', 'tag'];
        foreach ($requiredFields as $field) {
            if (!isset($campaign[$field])) {
                $this->addError("Missing required campaign field: {$field}");
                return false;
            }
        }
        return true;
    }
}
