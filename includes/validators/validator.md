# Data Validation System

## Overview
The validation system ensures data integrity across the BEMA CRM synchronization process through a hierarchical system of validators.

## Components

### 1. Base Validator
Abstract base class providing common validation functionality.

#### Key Features:
- Error and warning collection
- Logging integration
- Validation modes (strict/lenient)
- Data type validation
- Length validation

```php
abstract class Base_Validator {
    protected $validateMode = 'strict';
    protected $maxErrors = 100;
    
    public function addError(string $error, array $context = []): void
    public function addWarning(string $warning, array $context = []): void
}
```

### 2. Campaign Validator
Validates campaign data and structure.

#### Key Features:
- Campaign prefix validation
- MailerLite group verification
- Campaign timing constraints
- Campaign limit enforcement

```php
// Campaign Structure Validation
$validator = new Campaign_Validator($mailerLite, $logger);
$campaignData = [
    '2024_ETB_NBL' => 'New Album Name'
];

if (!$validator->validate($campaignData)) {
    $errors = $validator->getErrors();
    // Handle validation failures
}
```

#### Validation Rules:
```php
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

// Campaign prefix pattern: YYYY_ARTIST_CAMPAIGN
private $campaignPrefixPattern = '/^\d{4}_[A-Z]+_[A-Z0-9]+$/';
```

### 3. Subscriber Validator
Validates subscriber data and ensures data quality.

#### Key Features:
- Comprehensive email validation
- Field length validation
- Required field verification
- Domain blacklist checking
- Name format validation

```php
// Subscriber Data Validation
$validator = new Subscriber_Validator($logger);
$subscriberData = [
    'email' => 'example@domain.com',
    'tier' => 'opt-in',
    'campaign' => '2024_ETB_NBL'
];

if (!$validator->validate($subscriberData)) {
    $errors = $validator->getErrors();
    // Handle validation failures
}
```

#### Validation Rules:
```php
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

private $maxFieldLengths = [
    'first_name' => 100,
    'last_name' => 100,
    'email' => 255,
    'campaign' => 50,
    'source' => 100
];
```

### 4. Tier Validator
Manages tier transitions and enforces business rules.

#### Key Features:
- Tier transition validation
- Purchase requirement verification
- Transition frequency monitoring
- Transition history tracking

```php
// Tier Transition Validation
$validator = new Tier_Validator($logger);
$transitionData = [
    'current_tier' => 'opt-in',
    'new_tier' => 'silver',
    'has_purchased' => false,
    'timestamp' => time(),
    'reason' => 'inactivity'
];

if (!$validator->validate($transitionData)) {
    $errors = $validator->getErrors();
    // Handle validation failures
}
```

#### Transition Rules:
```php
private $validTransitions = [
    'unassigned' => ['opt-in', 'gold_purchased'],
    'opt-in' => ['silver', 'gold_purchased'],
    'silver' => ['bronze', 'silver_purchased'],
    'bronze' => ['wood', 'bronze_purchased'],
    'gold' => ['silver', 'gold_purchased']
    // ... additional transitions
];
```

## Validation Process

### 1. Data Flow
1. Raw data input
2. Type validation
3. Structure validation
4. Business rule validation
5. Error collection
6. Logging of validation results

### 2. Error Handling
```php
// Error collection
$errors = $validator->getErrors();
$warnings = $validator->getWarnings();

// Error logging
$logger->log('Validation failed', 'error', [
    'errors' => $errors,
    'context' => $validationContext
]);
```

### 3. Validation Modes
```php
// Strict mode (default)
$validator->setValidateMode('strict');

// Lenient mode (allows warnings)
$validator->setValidateMode('lenient');
```

## Integration Example

```php
class ValidationManager {
    private $campaignValidator;
    private $subscriberValidator;
    private $tierValidator;
    
    public function validateSync(array $data): bool {
        // Validate campaign
        if (!$this->campaignValidator->validate($data['campaign'])) {
            return false;
        }
        
        // Validate subscriber
        if (!$this->subscriberValidator->validate($data['subscriber'])) {
            return false;
        }
        
        // Validate tier transition
        if (!$this->tierValidator->validate($data['tier_transition'])) {
            return false;
        }
        
        return true;
    }
}
```

## Best Practices

### 1. Data Sanitization
```php
protected function sanitizeInput($input) {
    if (is_array($input)) {
        return array_map([$this, 'sanitizeInput'], $input);
    }
    return is_string($input) ? sanitize_text_field($input) : $input;
}
```

### 2. Length Validation
```php
protected function validateLength(string $value, int $maxLength, string $fieldName): bool {
    if (mb_strlen($value) > $maxLength) {
        $this->addError("{$fieldName} exceeds maximum length");
        return false;
    }
    return true;
}
```

### 3. Email Validation
```php
private function validateEmailComprehensive(string $email): bool {
    // Format validation
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    
    // Domain validation
    $domain = substr(strrchr($email, "@"), 1);
    if (!checkdnsrr($domain, 'MX')) {
        return false;
    }
    
    return true;
}
```

## Monitoring and Maintenance

### 1. Validation Metrics
- Error frequency tracking
- Validation performance monitoring
- Pattern recognition for failures

### 2. Regular Updates
- Blacklist maintenance
- Rule updates
- Performance optimization

### 3. Error Analysis
- Pattern recognition
- Failure point identification
- Rule effectiveness evaluation
