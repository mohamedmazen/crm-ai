<?php

use App\Support\Tenancy\PivotHardening;
use Illuminate\Database\Migrations\Migration;

/**
 * A label may only be attached to conversations from its own workspace.
 *
 * Label names are visible in the inbox UI, so a cross-tenant row would leak
 * another workspace's vocabulary and let agents filter on it. The label attach
 * endpoint already scopes both sides; the composite keys make it structural.
 */
return new class extends Migration
{
    /** @var array<int, array{column: string, table: string, constraint: string}> */
    private const LINKS = [
        ['column' => 'label_id', 'table' => 'inbox_labels', 'constraint' => 'ilc_label_ws_foreign'],
        ['column' => 'conversation_id', 'table' => 'conversations', 'constraint' => 'ilc_conversation_ws_foreign'],
    ];

    public function up(): void
    {
        PivotHardening::apply('inbox_label_conversation', 'conversation_id', self::LINKS);
    }

    public function down(): void
    {
        PivotHardening::revert('inbox_label_conversation', self::LINKS);
    }
};
