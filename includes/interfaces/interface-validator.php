<?php
namespace Bema\Interfaces;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

interface Validator_Interface
{
    public function validate($data): bool;
    public function getErrors(): array;
    public function clearErrors(): void;
    public function addError(string $error): void;
}
