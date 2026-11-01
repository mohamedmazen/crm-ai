<?php

namespace App\Modules\AI\Exceptions;

use RuntimeException;

/**
 * No usable AI provider is configured for the workspace.
 *
 * Extends RuntimeException so the controllers and jobs that already catch a
 * RuntimeException for this case keep working unchanged.
 */
class AiNotConfiguredException extends RuntimeException
{
    public static function forWorkspace(int $workspaceId): self
    {
        return new self('No AI provider configured for this workspace.');
    }
}
