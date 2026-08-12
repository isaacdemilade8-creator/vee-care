<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates a branding color.
 *
 * Accepts the strict hexadecimal formats only (#RGB, #RRGGBB, #RRGGBBAA — and
 * their 4/8 digit variants). Arbitrary CSS expressions (calc(), var(), urls,
 * named colors, hsl()) are rejected so a user-controlled value can never be
 * interpreted as CSS.
 */
class SafeColor implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('The :attribute must be a valid hexadecimal color.');

            return;
        }

        $color = trim($value);

        if (! preg_match('/^#(?:[0-9a-f]{3}|[0-9a-f]{4}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $color)) {
            $fail('The :attribute must be a hexadecimal color such as #0f766e.');
        }
    }
}
