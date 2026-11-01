<?php

namespace App\Modules\AI\Exceptions;

use RuntimeException;

/**
 * The workspace's plan does not include the requested AI feature.
 *
 * HTTP controllers normally reach this through the `feature:` middleware; the
 * exception exists so queued work (AI SDR, auto-summaries) can make the same
 * decision without an HTTP request to hang the gate on.
 */
class AiFeatureNotIncludedException extends RuntimeException
{
    public function __construct(public readonly string $feature)
    {
        parent::__construct("The current plan does not include the {$feature} feature.");
    }
}
