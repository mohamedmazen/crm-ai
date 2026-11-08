<?php

namespace App\Support\Tenancy;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * Moves tenant isolation on a pivot table into the database itself.
 *
 * The pivot keeps a `workspace_id` copied from its owning row, and composite
 * foreign keys tie that column to both ends of the link. MySQL then refuses any
 * row whose two sides belong to different workspaces, so a stale session, a
 * future bug or a manual insert cannot join two tenants together.
 *
 * Existing rows are backfilled from the owning side and re-checked. Rows that
 * link across tenants, and orphan rows whose owner is gone, are deleted before
 * the constraints are created because they are exactly the shape the keys
 * forbid. Every deletion is logged with its count.
 */
class PivotHardening
{
    /**
     * Harden one pivot table.
     *
     * Every side of the pivot must appear in $links, including the owner side.
     *
     * @param  string  $pivot  Pivot table to harden.
     * @param  string  $ownerColumn  Column that identifies the owning workspace.
     * @param  array<int, array{column: string, table: string, constraint: string}>  $links
     */
    public static function apply(string $pivot, string $ownerColumn, array $links): void
    {
        $owner = collect($links)->firstWhere('column', $ownerColumn);

        if ($owner === null) {
            throw new InvalidArgumentException("Owner column [{$ownerColumn}] is not one of the pivot links.");
        }

        if (! Schema::hasColumn($pivot, 'workspace_id')) {
            Schema::table($pivot, function (Blueprint $table): void {
                $table->unsignedBigInteger('workspace_id')->nullable();
            });
        }

        // Copy the tenant from the owning row. A single UPDATE ... JOIN keeps
        // this fast on large pivots and works on tables that only have a
        // composite primary key instead of an `id`. The assignment target is
        // table-qualified because the joined table has a `workspace_id` too.
        DB::statement(
            "UPDATE `{$pivot}` pivot"
            ." INNER JOIN `{$owner['table']}` owner ON owner.id = pivot.`{$ownerColumn}`"
            .' SET pivot.workspace_id = owner.workspace_id'
            .' WHERE pivot.workspace_id IS NULL'
        );

        // Anything still null points at a row that no longer exists. The
        // foreign keys would reject it, and nothing can reach it anyway.
        $orphans = DB::table($pivot)->whereNull('workspace_id')->delete();

        if ($orphans > 0) {
            Log::warning('Pivot hardening removed orphan rows.', [
                'pivot' => $pivot,
                'rows' => $orphans,
            ]);
        }

        // Drop rows that link a row in one workspace to a row in another. They
        // are unreachable through the application and would make the foreign
        // key creation fail on production data.
        foreach ($links as $link) {
            $deleted = DB::delete(
                "DELETE pivot FROM `{$pivot}` pivot"
                ." INNER JOIN `{$link['table']}` link ON link.id = pivot.`{$link['column']}`"
                .' WHERE link.workspace_id <> pivot.workspace_id'
            );

            if ($deleted > 0) {
                Log::warning('Pivot hardening removed cross-tenant rows.', [
                    'pivot' => $pivot,
                    'link' => $link['table'],
                    'rows' => $deleted,
                ]);
            }
        }

        Schema::table($pivot, function (Blueprint $table): void {
            $table->unsignedBigInteger('workspace_id')->nullable(false)->change();
            $table->index('workspace_id');
        });

        // A composite foreign key needs a matching unique key on the referenced
        // side: `id` alone is not enough because MySQL must be able to prove
        // that (id, workspace_id) is unique.
        foreach (collect($links)->pluck('table')->unique() as $parent) {
            $hasUnique = collect(Schema::getIndexes($parent))->contains(
                fn (array $index): bool => $index['unique'] && $index['columns'] === ['id', 'workspace_id'],
            );

            if ($hasUnique) {
                continue;
            }

            Schema::table($parent, function (Blueprint $table) use ($parent): void {
                $table->unique(['id', 'workspace_id'], "{$parent}_id_workspace_unique");
            });
        }

        foreach ($links as $link) {
            Schema::table($pivot, function (Blueprint $table) use ($link): void {
                $table->foreign([$link['column'], 'workspace_id'], $link['constraint'])
                    ->references(['id', 'workspace_id'])->on($link['table'])
                    ->cascadeOnDelete();
            });
        }
    }

    /**
     * Undo apply() for a pivot this package hardened.
     *
     * The `(id, workspace_id)` unique keys on the parents are intentionally
     * left in place: several pivots can share a parent (contacts is used by
     * both tag and segment links), so dropping one here would break the other
     * pivot's foreign key. A leftover unique key is harmless and makes a later
     * re-run of apply() a no-op.
     *
     * @param  array<int, array{column: string, table: string, constraint: string}>  $links
     */
    public static function revert(string $pivot, array $links): void
    {
        foreach ($links as $link) {
            Schema::table($pivot, function (Blueprint $table) use ($link): void {
                $table->dropForeign($link['constraint']);
            });
        }

        Schema::table($pivot, function (Blueprint $table): void {
            $table->dropIndex(['workspace_id']);
            $table->dropColumn('workspace_id');
        });
    }
}
