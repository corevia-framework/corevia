<?php

declare(strict_types=1);

namespace Corevia\Core;

final class Validator
{
    /** Rules: required, string/integer/number/boolean/array/email, min/max or callable. */
    public function validate(array $input, array $rules): array
    {
        $errors = [];
        foreach ($rules as $field => $checks) {
            $present = array_key_exists($field, $input);
            $value = $input[$field] ?? null;
            foreach ($checks as $key => $check) {
                if (!$present && $check !== 'required') {
                    continue;
                }
                $size = is_string($value) ? strlen($value) : (is_array($value) ? count($value) : $value);
                $valid = is_callable($check) && !is_string($check) ? (bool) $check($value) : match (is_string($key) ? $key : $check) {
                    'required' => $present && $value !== null,
                    'string' => is_string($value), 'integer' => is_int($value),
                    'number' => is_int($value) || (is_float($value) && is_finite($value)),
                    'boolean' => is_bool($value), 'array' => is_array($value),
                    'email' => is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
                    'min' => is_numeric($size) && $size >= $check,
                    'max' => is_numeric($size) && $size <= $check,
                    default => throw new \InvalidArgumentException('Unknown validation rule.'),
                };
                if (!$valid) {
                    $errors[$field][] = is_string($key) ? $key : (is_string($check) ? $check : 'custom');
                }
            }
        }
        return $errors;
    }
}
