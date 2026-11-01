<?php

namespace App\Modules\Sales\Services\Documents;

use Illuminate\Support\Facades\DB;

/**
 * Sequential per-workspace document numbers, e.g. QT-2026-0001 / INV-2026-0001.
 *
 * Call inside a transaction: the latest row is locked so two requests in the
 * same workspace cannot pick the same number. A unique index backs this up.
 */
class DocumentNumber
{
    public static function next(string $prefix, string $table, int $workspaceId): string
    {
        $year = now()->year;
        $pattern = "{$prefix}-{$year}-%";

        $latest = DB::table($table)
            ->where('workspace_id', $workspaceId)
            ->where('number', 'like', $pattern)
            ->orderByDesc('number')
            ->lockForUpdate()
            ->value('number');

        $sequence = is_string($latest) ? ((int) substr($latest, -4)) + 1 : 1;

        return sprintf('%s-%d-%04d', $prefix, $year, $sequence);
    }
}
