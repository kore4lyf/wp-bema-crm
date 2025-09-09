<?php

namespace Bema;

use Exception;
use function Bema\debug_to_file;

class Campaign_Manager
{
    private $mailerLiteInstance;
    private $logger;
    private $valid_campaigns = [
        '2025_ETB_EOE' => [
            'field' => '2025_ETB_EOE_PURCHASED',
            'tag' => '$2025_etb_eoe_purchased',
            'groups' => [
                'optin' => 'OPT-IN',
                'gold' => 'GOLD',
                'gold_purchased' => 'GOLD_PURCHASED', // Changed from 'GOLD PURCHASED'
                'silver' => 'SILVER',
                'silver_purchased' => 'SILVER_PURCHASE', // Already correct
                'bronze' => 'BRONZE',
                'bronze_purchased' => 'BRONZE_PURCHASE', // Already correct
                'wood' => 'WOOD',
                'wood_purchased' => 'WOOD_PURCHASE' // Already correct
            ]
        ],
        '2025_ETB_NBL' => [
            'field' => '2025_ETB_NBL_PURCHASED',
            'tag' => '$2025_etb_nbl_purchased',
            'groups' => [
                'optin' => 'OPT-IN',
                'gold' => 'GOLD',
                'gold_purchased' => 'GOLD PURCHASED',
                'silver' => 'SILVER',
                'silver_purchased' => 'SILVER PURCHASE',
                'bronze' => 'BRONZE',
                'bronze_purchased' => 'BRONZE PURCHASE',
                'wood' => 'WOOD',
                'wood_purchased' => 'WOOD PURCHASE'
            ]
        ]
    ];
    private $tierTransitions = [
        'gold_purchased' => 'gold',
        'silver_purchased' => 'silver',
        'bronze_purchased' => 'optin'
    ];

    public function __construct(Providers\MailerLite $mailerLiteInstance, ?Bema_CRM_Logger $logger = null)
    {
        $this->mailerLiteInstance = $mailerLiteInstance;
        $this->logger = $logger ?? Bema_CRM_Logger::create('campaign-manager');

        debug_to_file([
            'method' => 'Campaign_Manager_Constructor',
            'valid_campaigns' => $this->valid_campaigns,
            'has_groups' => array_map(function ($campaign) {
                return isset($campaign['groups']);
            }, $this->valid_campaigns)
        ], 'CAMPAIGN_DEBUG');
    }

    public function create_missing_groups(array $groups): array
    {
        $results = [
            'success' => [],
            'failed' => []
        ];

        foreach ($groups as $group) {
            try {
                $result = $this->mailerLiteInstance->createGroup($group);
                if ($result) {
                    $results['success'][] = $group;
                } else {
                    $results['failed'][] = $group;
                }
            } catch (Exception $e) {
                $this->logger->log('Failed to create group', 'error', [
                    'group' => $group,
                    'error' => $e->getMessage()
                ]);
                $results['failed'][] = $group;
            }
        }

        return $results;
    }

    public function get_purchase_field_name(string $campaign_code): ?array
    {
        if (isset($this->valid_campaigns[$campaign_code])) {
            return $this->valid_campaigns[$campaign_code];
        }

        $this->logger->log('Invalid campaign code', 'warning', [
            'campaign_code' => $campaign_code,
            'valid_campaigns' => array_keys($this->valid_campaigns)
        ]);

        return null;
    }

    public function get_campaign_groups(string $campaign_code): ?array
    {
        debug_to_file([
            'method' => 'get_campaign_groups',
            'campaign_code' => $campaign_code,
            'campaign_exists' => isset($this->valid_campaigns[$campaign_code]),
            'has_groups' => isset($this->valid_campaigns[$campaign_code]['groups'])
        ], 'CAMPAIGN_DEBUG');

        if (
            !isset($this->valid_campaigns[$campaign_code]) ||
            !isset($this->valid_campaigns[$campaign_code]['groups'])
        ) {
            $this->logger->log('No group mappings found for campaign', 'warning', [
                'campaign_code' => $campaign_code
            ]);
            return null;
        }

        return $this->valid_campaigns[$campaign_code]['groups'];
    }

    public function validate_campaign_group(string $campaign_code, string $group_name): bool
    {
        $groups = $this->get_campaign_groups($campaign_code);
        if (!$groups) {
            return false;
        }

        return in_array($group_name, $groups);
    }

    public function validate_mailerlite_groups(array $required_groups): array
    {
        try {
            $existing_groups = $this->mailerLiteInstance->getGroups();
            $existing_group_names = array_map(function ($group) {
                // Normalize existing group names
                return str_replace(' ', '_', strtoupper($group['name']));
            }, $existing_groups);

            $missing_groups = [];
            foreach ($required_groups as $groupType => $groupName) {
                $normalized_name = str_replace(' ', '_', strtoupper($groupName));
                $found = false;

                // Check both normalized and original format
                if (
                    in_array($normalized_name, $existing_group_names) ||
                    in_array(str_replace('_', ' ', $normalized_name), $existing_group_names)
                ) {
                    $found = true;
                }

                if (!$found) {
                    $missing_groups[] = $groupName;
                }
            }

            debug_to_file([
                'method' => 'validate_mailerlite_groups',
                'required_groups' => $required_groups,
                'existing_groups' => $existing_group_names,
                'missing_groups' => $missing_groups
            ], 'CAMPAIGN_DEBUG');

            return [
                'valid' => empty($missing_groups),
                'missing_groups' => array_values($missing_groups)
            ];
        } catch (Exception $e) {
            $this->logger->log('Failed to validate MailerLite groups', 'error', [
                'error' => $e->getMessage()
            ]);
            return [
                'valid' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    public function is_valid_campaign(string $campaign_code): bool
    {
        $is_valid = isset($this->valid_campaigns[$campaign_code]);

        debug_to_file([
            'method' => 'is_valid_campaign',
            'campaign_code' => $campaign_code,
            'is_valid' => $is_valid,
            'valid_campaigns' => $this->valid_campaigns
        ], 'CAMPAIGN_DEBUG');

        return $is_valid;
    }

    public function get_all_valid_campaigns(): array
    {
        debug_to_file([
            'method' => 'get_all_valid_campaigns',
            'valid_campaigns' => array_keys($this->valid_campaigns)
        ], 'CAMPAIGN_DEBUG');

        return array_keys($this->valid_campaigns);
    }

    public function parse_campaign_code(string $name): array
    {
        $parts = explode('_', $name);
        if (count($parts) !== 3) {
            throw new Exception('Invalid campaign name format. Expected format: YEAR_ARTIST_CAMPAIGN');
        }

        return [
            'year' => $parts[0],
            'artist_code' => $parts[1],
            'campaign_code' => $parts[2]
        ];
    }

    public function validate_purchase_data(array $purchase_data, string $campaign_code): bool
    {
        // Validate that purchase data matches our campaign
        $field_name = $this->get_purchase_field_name($campaign_code);

        if (!$field_name) {
            return false;
        }

        // Additional validation logic can be added here
        // For example, checking if the product names in purchase_data match expected values
        // or other business logic specific to your needs

        return true;
    }

    public function format_campaign_name(string $year, string $artistCode, string $campaignCode): string
    {
        return sprintf(
            '%s_%s_%s',
            $year,
            strtoupper($artistCode),
            strtoupper($campaignCode)
        );
    }

    public function get_field_names_for_campaign(string $campaign_code): array
    {
        if (!$this->is_valid_campaign($campaign_code)) {
            return [];
        }

        return [
            'purchased' => $this->valid_campaigns[$campaign_code]
        ];
    }

    /**
     * Get detailed campaign information
     * @param string $campaign_code Campaign identifier
     * @return array|null Campaign details or null if not found
     */
    public function get_campaign_details(string $campaign_code): ?array
    {
        if (!isset($this->valid_campaigns[$campaign_code])) {
            $this->logger->log('Campaign not found', 'warning', [
                'campaign_code' => $campaign_code
            ]);
            return null;
        }

        $campaign = $this->valid_campaigns[$campaign_code];
        $groups = $this->get_campaign_groups($campaign_code);

        return [
            'name' => $campaign_code,
            'field' => $campaign['field'],
            'tag' => $campaign['tag'],
            'groups' => $groups,
            'product_id' => $this->get_campaign_product_id($campaign_code)
        ];
    }

    /**
     * Get product ID for campaign
     */
    private function get_campaign_product_id(string $campaign_code): ?int
    {
        try {
            $parts = $this->parse_campaign_code($campaign_code);
            // Example: Convert NBL to "No Better Love" or similar mapping
            // This should match your EDD product naming convention
            $productName = $parts['campaign_code'];

            // You'll need to implement this lookup based on your EDD structure
            // For now, returning null as placeholder
            return null;
        } catch (Exception $e) {
            $this->logger->log('Failed to get product ID', 'error', [
                'campaign' => $campaign_code,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    public function validate_tier_structure(string $campaign_code): bool
    {
        try {
            $groups = $this->get_campaign_groups($campaign_code);
            if (!$groups) {
                return false;
            }

            // Required tiers
            $required_tiers = ['optin', 'bronze', 'silver', 'gold'];
            $required_purchase_tiers = ['bronze_purchased', 'silver_purchased', 'gold_purchased'];

            // Check if all required tiers exist
            foreach ($required_tiers as $tier) {
                if (!$this->find_group_for_tier($groups, $tier)) {
                    $this->logger->log('Missing required tier', 'error', [
                        'campaign' => $campaign_code,
                        'tier' => $tier
                    ]);
                    return false;
                }
            }

            // Check if all purchase tiers exist
            foreach ($required_purchase_tiers as $tier) {
                if (!$this->find_group_for_tier($groups, $tier)) {
                    $this->logger->log('Missing required purchase tier', 'error', [
                        'campaign' => $campaign_code,
                        'tier' => $tier
                    ]);
                    return false;
                }
            }

            return true;
        } catch (Exception $e) {
            $this->logger->error('Tier structure validation failed', [
                'campaign' => $campaign_code,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    private function find_group_for_tier(array $groups, string $tier): bool
    {
        foreach ($groups as $group_name => $group_id) {
            if (stripos($group_name, $tier) !== false) {
                return true;
            }
        }
        return false;
    }

    public function get_next_group(string $current_tier, bool $has_purchased): string
    {
        // Define tier progression with purchase paths
        $tier_progression = [
            'optin' => [
                'purchased' => 'gold',
                'default' => 'bronze'
            ],
            'bronze' => [
                'purchased' => 'silver',
                'default' => 'bronze'
            ],
            'silver' => [
                'purchased' => 'gold',
                'default' => 'silver'
            ],
            'gold' => [
                'purchased' => 'gold',
                'default' => 'gold'
            ]
        ];

        $current_tier = strtolower($current_tier);

        if (!isset($tier_progression[$current_tier])) {
            return $current_tier;
        }

        return $has_purchased
            ? $tier_progression[$current_tier]['purchased']
            : $tier_progression[$current_tier]['default'];
    }

    public function get_group_transitions(string $campaign_code): array
    {
        // Define the standard tier progression
        $transitions = [
            'opt-in' => [
                'purchased' => 'gold',
                'default' => 'bronze'
            ],
            'bronze' => [
                'purchased' => 'silver',
                'default' => 'bronze'
            ],
            'silver' => [
                'purchased' => 'gold',
                'default' => 'silver'
            ],
            'gold' => [
                'purchased' => 'gold',
                'default' => 'gold'
            ]
        ];

        debug_to_file([
            'method' => 'get_group_transitions',
            'campaign_code' => $campaign_code,
            'transitions' => $transitions
        ], 'CAMPAIGN_DEBUG');

        return $transitions;
    }

    public function get_next_tier(string $current_tier, bool $has_purchased): string
    {
        $transitions = $this->get_group_transitions('');  // Campaign code not needed for standard transitions

        if (!isset($transitions[$current_tier])) {
            return $current_tier;  // Keep current tier if not found in transition map
        }

        return $has_purchased
            ? $transitions[$current_tier]['purchased']
            : $transitions[$current_tier]['default'];
    }

    public function validate_tier_transition(string $from_tier, string $to_tier, bool $has_purchased): bool
    {
        $valid_transitions = $this->get_group_transitions('');

        if (!isset($valid_transitions[$from_tier])) {
            return false;
        }

        $expected_tier = $has_purchased
            ? $valid_transitions[$from_tier]['purchased']
            : $valid_transitions[$from_tier]['default'];

        return $to_tier === $expected_tier;
    }

    public function getNextCampaignTier(string $currentTier): ?string
    {
        return $this->tierTransitions[$currentTier] ?? null;
    }

    public function validateTransition(string $fromTier, string $toTier): bool
    {
        if (!isset($this->tierTransitions[$fromTier])) {
            return false;
        }
        return $this->tierTransitions[$fromTier] === $toTier;
    }
}