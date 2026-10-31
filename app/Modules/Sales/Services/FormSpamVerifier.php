<?php

namespace App\Modules\Sales\Services;

use App\Modules\Sales\Exceptions\FormSubmissionBlockedException;
use App\Modules\Sales\Models\Form;
use Illuminate\Support\Facades\Http;

/**
 * Verifies the CAPTCHA token a public form submits. Forms opt in through
 * forms.spam_protection; a form set to "none" is verified as a no-op.
 */
class FormSpamVerifier
{
    /**
     * @param  array<string, mixed>  $input
     *
     * @throws FormSubmissionBlockedException
     */
    public function verify(Form $form, array $input, ?string $ip = null): void
    {
        $protection = $form->spam_protection ?: 'none';

        if ($protection === 'none') {
            return;
        }

        $tokenKey = $protection === 'recaptcha' ? 'g-recaptcha-response' : 'cf-turnstile-response';
        $token = $input[$tokenKey] ?? $input['spam_token'] ?? null;

        if (! is_string($token) || trim($token) === '') {
            throw new FormSubmissionBlockedException(
                __('Please complete the spam verification and try again.'),
                422,
            );
        }

        $verifyUrl = match ($protection) {
            'turnstile' => 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
            'recaptcha' => 'https://www.google.com/recaptcha/api/siteverify',
            default => null,
        };

        $secret = config("services.{$protection}.secret");

        if (! $verifyUrl || empty($secret)) {
            // Fail closed. A form that asked for CAPTCHA must not silently
            // accept unverified submissions just because the key is missing.
            throw new FormSubmissionBlockedException(
                __('Spam verification is not configured for this form.'),
                422,
            );
        }

        $response = Http::asForm()->timeout(10)->post($verifyUrl, array_filter([
            'secret' => $secret,
            'response' => $token,
            'remoteip' => $ip,
        ]));

        if (! $response->successful() || $response->json('success') !== true) {
            throw new FormSubmissionBlockedException(
                __('Spam verification failed. Please try again.'),
                422,
            );
        }
    }
}
