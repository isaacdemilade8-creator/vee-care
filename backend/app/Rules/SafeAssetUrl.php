<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates a branding asset URL (logo / favicon).
 *
 * Allows https URLs and relative asset paths (/, ./, ../) only. In local
 * development plain-HTTP URLs on a loopback host (e.g.
 * http://127.0.0.1:8000/storage/...) are also allowed so the same-origin
 * upload store keeps working; production never accepts http. The javascript:,
 * data: and other unsupported schemes are rejected so a user-controlled URL
 * can never execute in the browser.
 */
class SafeAssetUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('The :attribute must be a valid URL.');

            return;
        }

        $url = trim($value);

        if ($url === '' || mb_strlen($url) > 512) {
            $fail('The :attribute must be a URL of at most 512 characters.');

            return;
        }

        if (preg_match('#^(?:/|\.\./)#', $url)) {
            return;
        }

        if (preg_match('#^https://#i', $url) && filter_var($url, FILTER_VALIDATE_URL)) {
            return;
        }

        $loopback = '(?:127\.0\.0\.1|localhost|0\.0\.0\.0)(?::\d+)?';

        if (
            ! app()->isProduction()
            && preg_match("#^http://{$loopback}/#i", $url)
            && filter_var($url, FILTER_VALIDATE_URL)
        ) {
            return;
        }

        $fail('The :attribute must be an https URL or a relative asset path.');
    }
}
