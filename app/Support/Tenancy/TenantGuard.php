<?php

namespace App\Support\Tenancy;

use App\Exceptions\TenantMismatchException;
use Illuminate\Database\Eloquent\Model;

/**
 * Defence-in-depth helpers for workspace ownership checks.
 *
 * Only 60 models carry a `workspace_id` column and there are no global scopes,
 * so a job or service that loads a model by id alone has no structural guarantee
 * that the record belongs to the tenant that queued the work. Prefer these
 * helpers over `Model::find($id)` whenever an id crosses a trust boundary
 * (queue payload, webhook body, signed URL).
 */
class TenantGuard
{
    /**
     * Whether the model belongs to the given workspace.
     */
    public static function owns(int $workspaceId, ?Model $model): bool
    {
        if (! $model) {
            return false;
        }

        $modelWorkspaceId = $model->getAttribute('workspace_id');

        return $modelWorkspaceId !== null && (int) $modelWorkspaceId === $workspaceId;
    }

    /**
     * Throw when the model does not belong to the given workspace.
     */
    public static function assertOwned(int $workspaceId, ?Model $model, string $context = 'resource'): void
    {
        if (! self::owns($workspaceId, $model)) {
            throw new TenantMismatchException(sprintf(
                'Tenant mismatch: %s does not belong to workspace %d.',
                $context,
                $workspaceId,
            ));
        }
    }

    /**
     * Find a model by id, scoped to the workspace that must own it.
     *
     * Returns null when the record does not exist or belongs to another
     * workspace, letting callers abort without leaking the distinction.
     *
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $modelClass
     * @return TModel|null
     */
    public static function findOwned(int $workspaceId, string $modelClass, int $id): ?Model
    {
        if ($workspaceId <= 0 || $id <= 0) {
            return null;
        }

        return $modelClass::query()
            ->where('workspace_id', $workspaceId)
            ->find($id);
    }
}
