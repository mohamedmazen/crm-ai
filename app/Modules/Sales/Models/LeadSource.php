<?php

namespace App\Modules\Sales\Models;

use App\Models\Workspace;
use App\Modules\Leads\Models\Lead;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class LeadSource extends Model
{
    use HasFactory;

    public const TYPE_FORM = 'form';

    public const TYPE_WEBHOOK = 'webhook';

    public const TYPE_API = 'api';

    public const TYPE_WHATSAPP = 'whatsapp';

    public const TYPE_META_ADS = 'meta_ads';

    public const TYPE_IMAP = 'imap';

    public const TYPE_MANUAL = 'manual';

    public const TYPE_SCRAPER = 'scraper';

    public const TYPE_IMPORT = 'import';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ERROR = 'error';

    public const STATUS_INACTIVE = 'inactive';

    protected $fillable = [
        'workspace_id',
        'name',
        'type',
        'status',
        'webhook_token',
        'last_lead_at',
        'last_error',
        'config',
    ];

    protected $appends = [
        'webhook_url',
    ];

    protected function casts(): array
    {
        return [
            'config' => 'array',
            'last_lead_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (LeadSource $source) {
            if (empty($source->webhook_token) && in_array($source->type, [self::TYPE_WEBHOOK, self::TYPE_API, self::TYPE_META_ADS])) {
                $source->webhook_token = self::generateUniqueToken();
            }
        });
    }

    public static function generateUniqueToken(): string
    {
        do {
            $token = Str::random(40);
        } while (self::where('webhook_token', $token)->exists());

        return $token;
    }

    public function regenerateWebhookToken(): string
    {
        $this->webhook_token = self::generateUniqueToken();
        $this->save();

        return $this->webhook_token;
    }

    public function getWebhookUrlAttribute(): ?string
    {
        if (! $this->webhook_token) {
            return null;
        }

        if ($this->type === self::TYPE_META_ADS) {
            return url('/webhooks/meta-leadgen/'.$this->webhook_token);
        }

        return url('/webhooks/lead-source/'.$this->webhook_token);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class, 'lead_source_id');
    }
}
