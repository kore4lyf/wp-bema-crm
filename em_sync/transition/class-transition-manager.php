<?php
namespace Bema;

use Bema\Providers\MailerLite;
use Bema\Database\Campaign_Database_Manager;
use Bema\Database\Group_Database_Manager;
use Bema\Database\Transition_Database_Manager;
use Bema\Database\Transition_Subscribers_Database_Manager;
use Bema\Bema_CRM_Logger;

class Transition_Manager
{
    public $mailerLiteInstance;
    public $logger;
    public $campaign_database;
    public $group_database;
    public $transition_database;
    public $transition_subscribers_database;

    // ========================================
    // PUBLIC TRANSITION METHODS
    // ========================================

    /**
     * Validates an EDD customer order by ID and email.
     */
    public function validate_edd_order_and_customer(int $order_id, string $email): bool
    {
        $order_id = absint($order_id);
        $email = sanitize_email($email);

        if ($order_id <= 0 || empty($email)) {
            return false;
        }

        $order_email = '';

        if (function_exists('edd_get_order')) {
            $order = edd_get_order($order_id);
            if (!$order) return false;
            $order_email = sanitize_email($order->email ?? '');
        } elseif (function_exists('edd_get_payment')) {
            $payment = edd_get_payment($order_id);
            if (!$payment) return false;
            $order_email = sanitize_email($payment->email ?? '');
        } else {
            return false;
        }

        return strcasecmp($order_email, $email) === 0;
    }

    /**
     * Transitions subscribers between campaigns based on defined rules.
     */
    public function transition_campaigns(string $source_campaign_name, string $destination_campaign_name)
    {
        try {
            if (!current_user_can('manage_options')) {
                wp_die('You do not have permission to perform this action.');
            }

            $transition_rules = $this->getTransitionRules();
            if (empty($transition_rules)) {
                $this->logger->info('Transition rules are not defined.');
                return;
            }

            $source_campaign_id = $this->getCampaignIdByNameOrFail($source_campaign_name, true);
            $destination_campaign_id = $this->getCampaignIdByNameOrFail($destination_campaign_name, false);

            $transition_id = $this->transition_database->insert_record($source_campaign_id, $destination_campaign_id, "Complete", 0);
            $transfer_count = 0;

            foreach ($transition_rules as $rule) {
                $transfer_count += $this->processTransitionRule($rule, $source_campaign_name, $destination_campaign_name, $transition_id);
            }

            $this->transition_database->upsert_record($transition_id, "Complete", $transfer_count);

        } catch (Exception $e) {
            $this->logger->error('Error transitioning campaigns', ['error' => $e->getMessage()]);
        }
    }

    // ========================================
    // PRIVATE TRANSITION METHODS
    // ========================================

    private function getTransitionRules(): array
    {
        return get_option('bema_crm_transition_matrix', []);
    }

    private function getCampaignIdByNameOrFail(string $campaign_name, bool $isSource): int
    {
        $campaign = $this->campaign_database->get_campaign_by_name($campaign_name);
        if (!$campaign) {
            $type = $isSource ? 'Source' : 'Destination';
            throw new Exception("{$type} campaign '{$campaign_name}' not found.");
        }
        return $campaign['id'];
    }

    private function normalizeTierName(string $tier): string
    {
        return strtoupper(str_replace(' ', '_', $tier));
    }

    private function buildSourcePurchaseFieldName(string $source_campaign_name): string
    {
        return strtolower($source_campaign_name . '_PURCHASE');
    }

    private function buildGroupName(string $campaign_name, string $normalized_tier): string
    {
        return $campaign_name . '_' . $normalized_tier;
    }

    private function processTransitionRule(array $rule, string $source_campaign_name, string $destination_campaign_name, int $transition_id): int
    {
        $normalize_current_tier = $this->normalizeTierName($rule['current_tier']);
        $normalize_next_tier = $this->normalizeTierName($rule['next_tier']);

        $source_group_name = $this->buildGroupName($source_campaign_name, $normalize_current_tier);
        $destination_group_name = $this->buildGroupName($destination_campaign_name, $normalize_next_tier);

        $source_group = $this->group_database->get_group_by_name($source_group_name);
        $destination_group = $this->group_database->get_group_by_name($destination_group_name);

        if (!$source_group || !$destination_group) {
            $this->logger->warning('Group not found', [
                'source_group' => $source_group_name,
                'destination_group' => $destination_group_name
            ]);
            return 0;
        }

        $subscribers = $this->mailerLiteInstance->getGroupSubscribers($source_group['id']);
        
        if (empty($subscribers)) {
            $this->logger->info("No subscribers found in group '{$source_group_name}'");
            return 0;
        }

        $eligible_subscribers = $this->determineEligibleSubscribers(
            $subscribers, 
            $rule, 
            $this->buildSourcePurchaseFieldName($source_campaign_name)
        );

        if (!empty($eligible_subscribers)) {
            try {
                $this->mailerLiteInstance->importBulkSubscribersToGroup($eligible_subscribers, $destination_group['id']);
                $this->transition_subscribers_database->bulk_upsert($eligible_subscribers, $transition_id);
                
                $this->logger->info('Successfully transitioned subscribers', [
                    'count' => count($eligible_subscribers),
                    'from' => $source_group_name,
                    'to' => $destination_group_name
                ]);
            } catch (Exception $e) {
                $this->logger->error('Failed to transition subscribers', [
                    'error' => $e->getMessage(),
                    'from' => $source_group_name,
                    'to' => $destination_group_name
                ]);
            }
        }

        return count($eligible_subscribers);
    }

    private function determineEligibleSubscribers(array $source_campaign_subscribers, array $rule, string $source_purchase_field): array
    {
        if (empty($rule['requires_purchase'])) {
            $this->logger->debug('No purchase required - all subscribers eligible');
            return $source_campaign_subscribers;
        }

        $subscribers_to_transfer = [];
        
        foreach ($source_campaign_subscribers as $subscriber) {
            $field_value = $subscriber['fields'][$source_purchase_field] ?? null;
            
            if ($field_value) {
                try {
                    $is_valid_purchase = $this->validate_edd_order_and_customer($field_value, $subscriber['email']);
                    if ($is_valid_purchase) {
                        $subscribers_to_transfer[] = $subscriber;
                    }
                } catch (Exception $e) {
                    $this->logger->warning("Validation error for subscriber '{$subscriber['email']}'", [
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }

        $this->logger->debug('Filtered eligible subscribers', [
            'total_subscribers' => count($source_campaign_subscribers),
            'eligible_subscribers' => count($subscribers_to_transfer)
        ]);

        return $subscribers_to_transfer;
    }

    /**
     * Determine the next tier based on current tier and purchase status
     */
    public function determineNextTier(string $currentTier, bool $hasPurchased): string
    {
        // Tier progression map
        $tierProgression = [
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

        $currentTier = strtolower($currentTier);
        if (!isset($tierProgression[$currentTier])) {
            return $currentTier;
        }

        return $hasPurchased
            ? $tierProgression[$currentTier]['purchased']
            : $tierProgression[$currentTier]['default'];
    }
}
