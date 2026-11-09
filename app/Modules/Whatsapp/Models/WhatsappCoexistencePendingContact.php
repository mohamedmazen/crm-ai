<?php

namespace App\Modules\Whatsapp\Models;

use App\Models\Workspace;
use App\Modules\Shared\Models\Contact;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A contact imported from the WhatsApp Business app that did not fit in the
 * workspace's plan contact cap. Kept until an upgrade frees a slot.
 */
class WhatsappCoexistencePendingContact extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_IMPORTED = 'imported';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_FAILED = 'failed';

    protected $table = 'whatsapp_coexistence_pending_contacts';

    protected $fillable = [
        'workspace_id', 'waba_id', 'phone', 'display_name', 'wa_user_id',
        'source', 'status', 'reason', 'imported_contact_id', 'payload',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function importedContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'imported_contact_id');
    }
}
