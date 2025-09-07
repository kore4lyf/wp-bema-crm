<?php

namespace Bema\Validators;

use Bema\Bema_CRM_Logger;
use Bema\Interfaces\Validator_Interface;

if (!defined('ABSPATH')) {
    exit;
}

abstract class Base_Validator implements Validator_Interface
{
    protected $errors = [];
    protected $warnings = [];
    protected $logger;
    protected $validateMode = 'strict';
    protected $maxErrors = 100;

    public function __construct(\Bema\Bema_CRM_Logger $logger)
    {
        $this->logger = $logger;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function getWarnings(): array
    {
        return $this->warnings;
    }

    public function clearErrors(): void
    {
        $this->errors = [];
        $this->warnings = [];
    }

    public function addError(string $error, array $context = []): void
    {
        if (count($this->errors) < $this->maxErrors) {
            $this->errors[] = $error;
            $this->logger->log($error, 'validation-error', $context);
        }
    }

    public function addWarning(string $warning, array $context = []): void
    {
        $this->warnings[] = $warning;
        $this->logger->warning($warning, $context);
    }

    public function setValidateMode(string $mode): void
    {
        if (in_array($mode, ['strict', 'lenient'])) {
            $this->validateMode = $mode;
        }
    }

    public function hasErrors(): bool
    {
        return !empty($this->errors);
    }

    public function hasWarnings(): bool
    {
        return !empty($this->warnings);
    }

    protected function validateDataType($value, string $expectedType): bool
    {
        $actualType = gettype($value);
        if ($actualType !== $expectedType) {
            $this->addError("Invalid data type. Expected {$expectedType}, got {$actualType}");
            return false;
        }
        return true;
    }

    protected function validateLength(string $value, int $maxLength, string $fieldName): bool
    {
        if (mb_strlen($value) > $maxLength) {
            $this->addError("{$fieldName} exceeds maximum length of {$maxLength} characters");
            return false;
        }
        return true;
    }

    protected function sanitizeInput($input)
    {
        if (is_array($input)) {
            return array_map([$this, 'sanitizeInput'], $input);
        }
        return is_string($input) ? sanitize_text_field($input) : $input;
    }

    abstract public function validate($data): bool;
}
