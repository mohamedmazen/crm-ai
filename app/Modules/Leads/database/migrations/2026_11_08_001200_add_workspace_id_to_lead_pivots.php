<?php

use App\Support\Tenancy\PivotHardening;
use Illuminate\Database\Migrations\Migration;

/**
 * Leads, tags and duplicate groups may only be linked inside one workspace.
 *
 * Without the tenant on the pivot a tag could be shared across tenants, and a
 * duplicate group could be merged with a lead that belongs to someone else —
 * a merge deletes rows, so that link has to be impossible, not just unlikely.
 */
return new class extends Migration
{
    /** @var array<int, array{column: string, table: string, constraint: string}> */
    private const LEAD_TAG_LINKS = [
        ['column' => 'lead_id', 'table' => 'leads', 'constraint' => 'ltp_lead_ws_foreign'],
        ['column' => 'tag_id', 'table' => 'lead_tags', 'constraint' => 'ltp_tag_ws_foreign'],
    ];

    /** @var array<int, array{column: string, table: string, constraint: string}> */
    private const DUPLICATE_GROUP_LINKS = [
        ['column' => 'group_id', 'table' => 'lead_duplicate_groups', 'constraint' => 'ldgm_group_ws_foreign'],
        ['column' => 'lead_id', 'table' => 'leads', 'constraint' => 'ldgm_lead_ws_foreign'],
    ];

    public function up(): void
    {
        PivotHardening::apply('lead_tag_pivot', 'lead_id', self::LEAD_TAG_LINKS);
        PivotHardening::apply('lead_duplicate_group_members', 'group_id', self::DUPLICATE_GROUP_LINKS);
    }

    public function down(): void
    {
        PivotHardening::revert('lead_duplicate_group_members', self::DUPLICATE_GROUP_LINKS);
        PivotHardening::revert('lead_tag_pivot', self::LEAD_TAG_LINKS);
    }
};
