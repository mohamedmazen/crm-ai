<?php

namespace App\Support\Tenancy;

use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Pivot row that carries the tenant it belongs to.
 *
 * Relations that use this class get `workspace_id` copied from their owning
 * model the moment a link is attached. That keeps call sites unchanged and
 * stops a future `attach()`/`sync()` from forgetting the column, while the
 * composite foreign keys on the pivot table still reject a row whose two ends
 * belong to different workspaces.
 */
class WorkspacePivot extends Pivot
{
    protected static function booted(): void
    {
        static::creating(function (self $pivot): void {
            if ($pivot->getAttribute('workspace_id') !== null) {
                return;
            }

            $pivot->setAttribute(
                'workspace_id',
                $pivot->pivotParent?->getAttribute('workspace_id'),
            );
        });
    }
}
