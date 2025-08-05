<?php
namespace Bema\Validators;

use Bema\BemaCRMLogger;

if (!defined('ABSPATH')) {
    exit;
}

class Subscriber_Validator extends Base_Validator
{
    private $validTiers = [
        'unassigned',
        'opt-in',
        'gold',
        'gold_purchased',
        'silver',
        'silver_purchased',
        'bronze',
        'bronze_purchased',
        'wood'
    ];

    private $requiredFields = ['email', 'tier', 'campaign'];
    private $maxFieldLengths = [
        'first_name' => 100,
        'last_name' => 100,
        'email' => 255,
        'campaign' => 50,
        'source' => 100
    ];
    private $emailBlacklist = [];
    private $domainBlacklist = [];

    public function validate($subscriberData): bool
    {
        $this->clearErrors();
        if (!$this->validateDataType($subscriberData, 'array')) {
            return false;
        }

        $data = $this->sanitizeInput($subscriberData);
        $isValid = true;

        // Validate required fields
        foreach ($this->requiredFields as $field) {
            if (!$this->validateRequiredField($data, $field)) {
                $isValid = false;
            }
        }

        // Validate individual fields if present
        if (isset($data['email'])) {
            $isValid = $this->validateEmailComprehensive($data['email']) && $isValid;
        }
        if (isset($data['tier'])) {
            $isValid = $this->validateTier($data['tier']) && $isValid;
        }
        if (isset($data['first_name'])) {
            $isValid = $this->validateName($data['first_name'], 'First name') && $isValid;
        }
        if (isset($data['last_name'])) {
            $isValid = $this->validateName($data['last_name'], 'Last name') && $isValid;
        }
        if (isset($data['campaign'])) {
            $isValid = $this->validateCampaign($data['campaign']) && $isValid;
        }
        if (isset($data['source'])) {
            $isValid = $this->validateSource($data['source']) && $isValid;
        }

        // Validate field lengths
        foreach ($this->maxFieldLengths as $field => $maxLength) {
            if (isset($data[$field])) {
                $isValid = $this->validateLength($data[$field], $maxLength, $field) && $isValid;
            }
        }

        return $isValid;
    }

    private function validateRequiredField(array $data, string $field): bool
    {
        if (!isset($data[$field]) || trim($data[$field]) === '') {
            $this->addError("Missing required field: $field", ['field' => $field]);
            return false;
        }
        return true;
    }

    private function validateEmailComprehensive(string $email): bool
    {
        $email = strtolower(trim($email));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->addError("Invalid email format: $email", ['email' => $email]);
            return false;
        }

        if ($this->isBlacklistedEmail($email)) {
            $this->addError("Email address is blacklisted", ['email' => $email]);
            return false;
        }

        $domain = substr(strrchr($email, "@"), 1);
        if ($this->isBlacklistedDomain($domain)) {
            $this->addError("Email domain is blacklisted", ['domain' => $domain]);
            return false;
        }

        if (!checkdnsrr($domain, 'MX')) {
            $this->addWarning("Email domain has no valid MX record", ['domain' => $domain]);
            if ($this->validateMode === 'strict') {
                return false;
            }
        }

        return true;
    }

    private function validateTier(string $tier): bool
    {
        if (!in_array($tier, $this->validTiers)) {
            $this->addError("Invalid tier: $tier", ['tier' => $tier]);
            return false;
        }
        return true;
    }

    private function validateName(string $name, string $fieldName): bool
    {
        $name = trim($name);
        if (empty($name)) {
            return true; // Names are optional
        }

        if (!$this->validateLength($name, $this->maxFieldLengths['first_name'], $fieldName)) {
            return false;
        }

        if (!preg_match('/^[\p{L}\s\'-]+$/u', $name)) {
            $this->addError("$fieldName contains invalid characters", ['field' => $fieldName, 'value' => $name]);
            return false;
        }

        return true;
    }

    private function validateCampaign(string $campaign): bool
    {
        if (!preg_match('/^\d{4}_[A-Z]+_[A-Z0-9]+$/', $campaign)) {
            $this->addError("Invalid campaign format", ['campaign' => $campaign]);
            return false;
        }
        return true;
    }

    private function validateSource(string $source): bool
    {
        if (!preg_match('/^[a-zA-Z0-9_\-\s]+$/', $source)) {
            $this->addError("Invalid source format", ['source' => $source]);
            return false;
        }
        return true;
    }

    private function isBlacklistedEmail(string $email): bool
    {
        return in_array($email, $this->emailBlacklist);
    }

    private function isBlacklistedDomain(string $domain): bool
    {
        return in_array($domain, $this->domainBlacklist);
    }

    public function setEmailBlacklist(array $blacklist): void
    {
        $this->emailBlacklist = array_map('strtolower', $blacklist);
    }

    public function setDomainBlacklist(array $blacklist): void
    {
        $this->domainBlacklist = array_map('strtolower', $blacklist);
    }
}
