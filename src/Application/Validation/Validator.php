<?php

declare(strict_types=1);

namespace App\Application\Validation;

use App\Presentation\Exceptions\ValidationException;

class Validator
{
    public function validate(array $data, array $rules): void
    {
        $errors = [];

        foreach ($rules as $field => $ruleString) {
            $fieldRules = explode('|', $ruleString);
            foreach ($fieldRules as $rule) {
                $params = [];
                if (str_contains($rule, ':')) {
                    [$rule, $paramStr] = explode(':', $rule);
                    $params = explode(',', $paramStr);
                }

                $value = $data[$field] ?? null;
                if ($rule !== 'required' && ($value === null || $value === '')) {
                    continue;
                }

                if (!$this->validateRule($rule, $value, $params, $data)) {
                    $errors[$field][] = $this->getErrorMessage($field, $rule, $params);
                }
            }
        }

        if (!empty($errors)) {
            throw new ValidationException($errors, 'Validation Failed');
        }
    }

    private function validateRule(string $rule, mixed $value, array $params, array $data): bool
    {
        return match ($rule) {
            'required' => $value !== null && $value !== '',
            'email' => filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            'string' => is_string($value),
            'numeric' => is_numeric($value),
            'money' => is_string($value) && preg_match('/^\d+([.,]\d{1,2})?$/', $value),
            'int' => filter_var($value, FILTER_VALIDATE_INT) !== false,
            'min' => is_string($value) ? strlen($value) >= (int)$params[0] : $value >= (int)$params[0],
            'max' => is_string($value) ? strlen($value) <= (int)$params[0] : $value <= (int)$params[0],
            default => true,
        };
    }

    private function getErrorMessage(string $field, string $rule, array $params): string
    {
        return match ($rule) {
            'required' => "The $field field is required.",
            'email' => "The $field must be a valid email address.",
            'string' => "The $field must be a string.",
            'numeric' => "The $field must be a number.",
            'money' => "The $field must be a valid monetary value.",
            'int' => "The $field must be an integer.",
            'min' => "The $field must be at least {$params[0]}.",
            'max' => "The $field must not be greater than {$params[0]}.",
            default => "The $field field is invalid.",
        };
    }
}
