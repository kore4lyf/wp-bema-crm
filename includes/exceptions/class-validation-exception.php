<?php
namespace Bema\Exceptions;

use \Throwable;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Exception class for validation errors with enhanced validation handling
 */
class Validation_Exception extends Sync_Exception
{
    /** @var array */
    protected $validationErrors = [];

    /** @var array */
    protected $validationRules = [];

    /** @var string */
    protected $validatedEntity;

    /** @var array */
    protected $invalidFields = [];

    /**
     * Enhanced constructor for validation exceptions
     * 
     * @param string $message Exception message
     * @param array $validationErrors Array of validation errors
     * @param array $validationRules Rules that were applied
     * @param string $validatedEntity Entity being validated
     * @param array $context Additional context
     * @param int $code Exception code
     * @param Throwable|null $previous Previous exception
     */
    public function __construct(
        string $message,
        array $validationErrors = [],
        array $validationRules = [],
        string $validatedEntity = '',
        array $context = [],
        int $code = 0,
        Throwable $previous = null
    ) {
        $this->validationErrors = $this->formatValidationErrors($validationErrors);
        $this->validationRules = $validationRules;
        $this->validatedEntity = $validatedEntity;
        $this->invalidFields = array_keys($validationErrors);

        // Enhance context with validation information
        $context = array_merge($context, [
            'validation_errors' => $this->validationErrors,
            'validation_rules' => $this->validationRules,
            'validated_entity' => $this->validatedEntity,
            'invalid_fields' => $this->invalidFields,
            'error_count' => count($this->validationErrors)
        ]);

        parent::__construct($message, false, $context, $code, $previous);
    }

    /**
     * Get validation errors
     */
    public function getValidationErrors(): array
    {
        return $this->validationErrors;
    }

    /**
     * Get validation rules that were applied
     */
    public function getValidationRules(): array
    {
        return $this->validationRules;
    }

    /**
     * Get entity that was being validated
     */
    public function getValidatedEntity(): string
    {
        return $this->validatedEntity;
    }

    /**
     * Get list of invalid fields
     */
    public function getInvalidFields(): array
    {
        return $this->invalidFields;
    }

    /**
     * Check if a specific field has validation errors
     */
    public function hasFieldError(string $fieldName): bool
    {
        return isset($this->validationErrors[$fieldName]);
    }

    /**
     * Get errors for a specific field
     */
    public function getFieldErrors(string $fieldName): array
    {
        return $this->validationErrors[$fieldName] ?? [];
    }

    /**
     * Format validation errors into a consistent structure
     */
    private function formatValidationErrors(array $errors): array
    {
        $formatted = [];
        foreach ($errors as $field => $error) {
            if (is_string($error)) {
                $formatted[$field] = [$error];
            } elseif (is_array($error)) {
                $formatted[$field] = array_values($error);
            }
        }
        return $formatted;
    }

    /**
     * Get a summary of validation errors
     */
    public function getErrorSummary(): array
    {
        return [
            'total_errors' => count($this->validationErrors),
            'invalid_fields' => $this->invalidFields,
            'entity' => $this->validatedEntity,
            'errors' => $this->validationErrors
        ];
    }

    /**
     * Convert validation errors to string
     */
    public function getErrorsAsString(): string
    {
        $output = [];
        foreach ($this->validationErrors as $field => $errors) {
            $output[] = sprintf(
                "%s: %s",
                $field,
                implode(', ', (array)$errors)
            );
        }
        return implode('; ', $output);
    }
}
