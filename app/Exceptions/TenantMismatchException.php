<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised when a record is used outside the workspace that owns it.
 *
 * Tenancy in this application is enforced manually with `where('workspace_id')`
 * clauses rather than global scopes, so any code that resolves a model from an
 * id alone (queue payloads, webhooks, signed URLs) must assert ownership before
 * touching it. This exception is the loud failure for that assertion.
 */
class TenantMismatchException extends RuntimeException {}
