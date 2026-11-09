<?php

namespace App\Modules\Whatsapp\Models;

use App\Modules\Integrations\Services\CredentialResolver;
use App\Modules\Shared\Models\ChannelAccount;
use App\Support\Tenancy\Byok;
use Database\Factories\WhatsappBusinessAccountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WhatsappBusinessAccount extends Model
{
    use HasFactory;

    /** Standard Cloud API account, unrelated to the WhatsApp Business app. */
    public const COEXISTENCE_NONE = 'none';

    /** Embedded Signup started for a Business-app number but not confirmed yet. */
    public const COEXISTENCE_PENDING = 'pending';

    /** Number is linked to both the Business app and Cloud API. */
    public const COEXISTENCE_ACTIVE = 'active';

    /** Meta reported the link is gone or unreachable; data is kept for reconnect. */
    public const COEXISTENCE_SUSPENDED = 'suspended';

    /** Customer explicitly disconnected the number; data is archived, not deleted. */
    public const COEXISTENCE_DISCONNECTED = 'disconnected';

    protected static function newFactory()
    {
        return WhatsappBusinessAccountFactory::new();
    }

    protected $table = 'whatsapp_business_accounts';

    protected $fillable = [
        'workspace_id', 'waba_id', 'credentials', 'webhook_verify_token', 'webhook_verify_token_hash', 'status', 'meta_json',
        'token_expires_at', 'token_checked_at', 'last_error_at', 'last_error_code', 'last_error_message',
        'is_coexistence', 'coexistence_status', 'coexistence_linked_at', 'coexistence_disconnected_at',
        'coexistence_disconnect_reason', 'history_sync_status', 'history_sync_consented_at', 'coexistence_last_sync_at',
    ];

    protected $hidden = ['credentials', 'webhook_verify_token'];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'webhook_verify_token' => 'encrypted',
            'meta_json' => 'array',
            'token_expires_at' => 'datetime',
            'token_checked_at' => 'datetime',
            'last_error_at' => 'datetime',
            'is_coexistence' => 'boolean',
            'coexistence_linked_at' => 'datetime',
            'coexistence_disconnected_at' => 'datetime',
            'history_sync_consented_at' => 'datetime',
            'coexistence_last_sync_at' => 'datetime',
        ];
    }

    public function phoneNumbers(): HasMany
    {
        return $this->hasMany(WhatsappPhoneNumber::class, 'waba_id_fk');
    }

    public function templates(): HasMany
    {
        return $this->hasMany(WhatsappTemplate::class, 'waba_id', 'waba_id');
    }

    public static function hashWebhookToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /** O(1) lookup for per-WABA webhook routes (token is stored encrypted). */
    public static function findByWebhookToken(string $token): ?self
    {
        return static::where('webhook_verify_token_hash', static::hashWebhookToken($token))->first();
    }

    /** Access token for Graph API (embedded OAuth or manual system user). */
    public function accessToken(): ?string
    {
        $creds = $this->credentials ?? [];

        return $creds['system_user_token'] ?? $creds['access_token'] ?? null;
    }

    public static function resolveAccessTokenForWorkspace(int $workspaceId): ?string
    {
        $waba = static::where('workspace_id', $workspaceId)
            ->where('status', 'active')
            ->first();

        if ($waba) {
            $token = $waba->accessToken();
            if ($token) {
                return $token;
            }
        }

        // BYOK-only: the platform system-user token is the operator's WABA
        // token — falling back to it would bill WhatsApp conversation fees to
        // the operator. A workspace must connect (and authenticate) its own WABA.
        if (Byok::only()) {
            return null;
        }

        return CredentialResolver::system()->meta()?->systemUserToken();
    }

    public static function defaultPhoneNumberIdForWorkspace(int $workspaceId): ?string
    {
        $fromChannel = ChannelAccount::where('workspace_id', $workspaceId)
            ->where('channel', 'whatsapp')
            ->where('status', 'active')
            ->whereNotNull('phone_number_id')
            ->orderBy('id')
            ->value('phone_number_id');

        if ($fromChannel) {
            return (string) $fromChannel;
        }

        $waba = static::where('workspace_id', $workspaceId)
            ->where('status', 'active')
            ->with('phoneNumbers')
            ->first();

        return $waba?->phoneNumbers->first()?->phone_number_id;
    }

    protected static function booted(): void
    {
        static::saving(function (self $waba) {
            if ($waba->isDirty('webhook_verify_token') && $waba->webhook_verify_token) {
                $waba->webhook_verify_token_hash = static::hashWebhookToken($waba->webhook_verify_token);
            }
        });
    }
}
