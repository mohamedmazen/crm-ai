<?php

use App\Support\Tenancy\PivotHardening;
use Illuminate\Database\Migrations\Migration;

/**
 * A post may only target social accounts from its own workspace.
 *
 * `social_media_post_accounts` used to trust the publisher to scope accounts,
 * and the table had no foreign keys at all. The composite keys below make a
 * cross-tenant link impossible to store, which matters because publishing is
 * an external, irreversible action.
 */
return new class extends Migration
{
    /** @var array<int, array{column: string, table: string, constraint: string}> */
    private const LINKS = [
        ['column' => 'post_id', 'table' => 'social_media_posts', 'constraint' => 'smpa_post_ws_foreign'],
        ['column' => 'social_account_id', 'table' => 'social_media_accounts', 'constraint' => 'smpa_account_ws_foreign'],
    ];

    public function up(): void
    {
        PivotHardening::apply('social_media_post_accounts', 'post_id', self::LINKS);
    }

    public function down(): void
    {
        PivotHardening::revert('social_media_post_accounts', self::LINKS);
    }
};
