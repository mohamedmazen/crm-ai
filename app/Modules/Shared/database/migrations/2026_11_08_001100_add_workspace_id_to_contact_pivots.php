<?php

use App\Support\Tenancy\PivotHardening;
use Illuminate\Database\Migrations\Migration;

/**
 * Contacts, tags and segments may only be linked inside a single workspace.
 *
 * Both pivots are written through bulk inserts and `sync`, and neither had a
 * foreign key. The composite keys below stop a tag or a segment from pulling in
 * another tenant's contacts, which is how a campaign could otherwise reach the
 * wrong audience.
 */
return new class extends Migration
{
    /** @var array<int, array{column: string, table: string, constraint: string}> */
    private const CONTACT_TAG_LINKS = [
        ['column' => 'contact_id', 'table' => 'contacts', 'constraint' => 'ctp_contact_ws_foreign'],
        ['column' => 'tag_id', 'table' => 'contact_tags', 'constraint' => 'ctp_tag_ws_foreign'],
    ];

    /** @var array<int, array{column: string, table: string, constraint: string}> */
    private const SEGMENT_CONTACT_LINKS = [
        ['column' => 'segment_id', 'table' => 'segments', 'constraint' => 'sc_segment_ws_foreign'],
        ['column' => 'contact_id', 'table' => 'contacts', 'constraint' => 'sc_contact_ws_foreign'],
    ];

    public function up(): void
    {
        PivotHardening::apply('contact_tag_pivot', 'contact_id', self::CONTACT_TAG_LINKS);
        PivotHardening::apply('segment_contact', 'segment_id', self::SEGMENT_CONTACT_LINKS);
    }

    public function down(): void
    {
        PivotHardening::revert('segment_contact', self::SEGMENT_CONTACT_LINKS);
        PivotHardening::revert('contact_tag_pivot', self::CONTACT_TAG_LINKS);
    }
};
