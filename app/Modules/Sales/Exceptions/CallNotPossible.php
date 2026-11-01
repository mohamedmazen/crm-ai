<?php

namespace App\Modules\Sales\Exceptions;

use RuntimeException;

/**
 * Raised when a call cannot be placed: no telephony provider is connected, the
 * lead has no number, or the provider rejected the request. Carries a short
 * reason the controller can turn into a field error.
 */
class CallNotPossible extends RuntimeException
{
    public const NO_PROVIDER = 'no_provider';

    public const NO_RECIPIENT = 'no_recipient';

    public const PROVIDER_FAILED = 'provider_failed';

    public function __construct(string $message, public readonly string $reason = self::PROVIDER_FAILED)
    {
        parent::__construct($message);
    }
}
