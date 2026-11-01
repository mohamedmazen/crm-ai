<?php

namespace App\Modules\Sales\Exceptions;

use RuntimeException;

/**
 * Raised when a sequence step cannot reach the lead — no address on the lead,
 * or the linked contact has opted out of the step's channel.
 *
 * Carries a short reason so the enrollment can be stopped with something
 * meaningful in `stop_reason` instead of a generic failure.
 */
class SequenceRecipientUnavailable extends RuntimeException
{
    public function __construct(string $message, public readonly string $reason = 'no_recipient')
    {
        parent::__construct($message);
    }
}
