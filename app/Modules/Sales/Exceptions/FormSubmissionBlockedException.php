<?php

namespace App\Modules\Sales\Exceptions;

use RuntimeException;

/**
 * Raised when a public form submission must be refused for a reason that is not
 * a field validation failure — spam verification, or the plan's monthly
 * submission cap. Carries the HTTP status the controller should answer with so
 * the public page can show a message instead of an empty field error.
 */
class FormSubmissionBlockedException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 429)
    {
        parent::__construct($message);
    }
}
