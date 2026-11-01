<?php

namespace App\Modules\Sales\Models;

use App\Modules\Sales\Services\Calls\CallProviderManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A workspace's own telephony credentials (BYO). Credentials are encrypted at
 * rest and resolved by {@see CallProviderManager}.
 */
class CallProviderConfig extends Model
{
    public const PROVIDER_TWILIO = 'twilio';

    public const PROVIDER_TELNYX = 'telnyx';

    public const PROVIDERS = [self::PROVIDER_TWILIO, self::PROVIDER_TELNYX];

    /**
     * Fields the settings form renders for each provider. Keeping them here means
     * the controller, validation and UI share one definition.
     *
     * @var array<string, array<int, array{key: string, label: string, type: string, required: bool, hint?: string}>>
     */
    public const FIELDS = [
        self::PROVIDER_TWILIO => [
            ['key' => 'account_sid', 'label' => 'Account SID', 'type' => 'text', 'required' => true],
            ['key' => 'auth_token', 'label' => 'Auth Token', 'type' => 'password', 'required' => true],
            ['key' => 'twiml_url', 'label' => 'Voice URL (TwiML)', 'type' => 'url', 'required' => true, 'hint' => 'A TwiML URL that dials your agent, e.g. https://handler.twilio.com/twiml/EH…'],
        ],
        self::PROVIDER_TELNYX => [
            ['key' => 'api_key', 'label' => 'API Key', 'type' => 'password', 'required' => true],
            ['key' => 'connection_id', 'label' => 'Connection / Application ID', 'type' => 'text', 'required' => true],
            ['key' => 'public_key', 'label' => 'Webhook Public Key', 'type' => 'text', 'required' => true, 'hint' => 'Base64 Ed25519 public key from the Telnyx portal (used to verify status callbacks).'],
        ],
    ];

    protected $fillable = [
        'workspace_id',
        'provider',
        'credentials',
        'caller_id',
        'default',
    ];

    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'default' => 'boolean',
        ];
    }

    public function scopeForWorkspace(Builder $query, int $workspaceId): Builder
    {
        return $query->where('workspace_id', $workspaceId);
    }

    public function scopeDefault(Builder $query): Builder
    {
        return $query->where('default', true);
    }

    /** The config new calls should use: the default row, else the first row. */
    public static function resolveForWorkspace(int $workspaceId): ?self
    {
        return static::forWorkspace($workspaceId)
            ->orderByDesc('default')
            ->orderBy('id')
            ->first();
    }
}
