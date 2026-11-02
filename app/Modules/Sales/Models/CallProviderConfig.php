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

    public const PROVIDER_CEQUENS = 'cequens';

    public const PROVIDER_UNIFONIC = 'unifonic';

    public const PROVIDERS = [
        self::PROVIDER_TWILIO,
        self::PROVIDER_TELNYX,
        self::PROVIDER_CEQUENS,
        self::PROVIDER_UNIFONIC,
    ];

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
        self::PROVIDER_CEQUENS => [
            ['key' => 'username', 'label' => 'Service Username', 'type' => 'text', 'required' => true],
            ['key' => 'api_key', 'label' => 'API Key', 'type' => 'password', 'required' => true],
            ['key' => 'message', 'label' => 'Message', 'type' => 'textarea', 'required' => true, 'hint' => 'Text-to-speech message played to the lead. CEQUENS plays this message; it does not bridge to an agent.'],
            ['key' => 'voice', 'label' => 'Voice', 'type' => 'text', 'required' => false, 'hint' => 'Optional TTS voice, e.g. Zeina (default) or Hala for Arabic.'],
        ],
        self::PROVIDER_UNIFONIC => [
            ['key' => 'app_sid', 'label' => 'AppSid (Application ID)', 'type' => 'text', 'required' => true],
            ['key' => 'message', 'label' => 'Greeting', 'type' => 'textarea', 'required' => true, 'hint' => 'Played to the lead before the call is handed over.'],
            ['key' => 'agent_number', 'label' => 'Transfer to agent number', 'type' => 'text', 'required' => false, 'hint' => 'Optional. The answered call is transferred to this number so your agent takes over.'],
        ],
    ];

    /**
     * Where each provider can actually terminate voice traffic, with the
     * Egypt / Gulf caveats that decide which one a workspace picks. Shown in
     * Call settings as guidance only: providers change coverage without notice,
     * so nothing here is enforced in code.
     *
     * @var array<string, array<int, array{country: string, coverage: string, requirement: string}>>
     */
    public const COVERAGE = [
        self::PROVIDER_TWILIO => [
            ['country' => 'Egypt', 'coverage' => 'Toll-free (+20 800) only — no local numbers', 'requirement' => 'Signed letter of authorization'],
            ['country' => 'Saudi Arabia', 'coverage' => 'Toll-free (+966 800) only — no local numbers', 'requirement' => 'Letter of authorization and business registration'],
            ['country' => 'United Arab Emirates', 'coverage' => 'Toll-free, plus geographic numbers that are inbound-only', 'requirement' => 'Outbound calls from a UAE number are prohibited'],
        ],
        self::PROVIDER_TELNYX => [
            ['country' => 'Egypt', 'coverage' => 'Local numbers and local voice coverage', 'requirement' => 'Local company registration document'],
            ['country' => 'Saudi Arabia', 'coverage' => 'Local numbers available', 'requirement' => 'Minimum 12-month commitment'],
            ['country' => 'United Arab Emirates', 'coverage' => 'Local numbers and local voice coverage', 'requirement' => 'Local rules apply; telemarketing calls 9am–6pm'],
            ['country' => 'Qatar', 'coverage' => 'Local numbers and local voice coverage', 'requirement' => 'Number documentation required'],
        ],
        self::PROVIDER_CEQUENS => [
            ['country' => 'Egypt', 'coverage' => 'Direct carrier interconnect; local and mobile voice', 'requirement' => 'Numbers subject to CEQUENS account approval'],
            ['country' => 'Saudi Arabia', 'coverage' => 'Direct carrier interconnect; local and mobile voice', 'requirement' => 'Numbers subject to CEQUENS account approval'],
            ['country' => 'United Arab Emirates', 'coverage' => 'Direct carrier interconnect; local and mobile voice', 'requirement' => 'Numbers subject to CEQUENS account approval'],
            ['country' => 'Kuwait / Bahrain / Jordan', 'coverage' => 'Direct carrier interconnect', 'requirement' => 'Numbers subject to CEQUENS account approval'],
        ],
        self::PROVIDER_UNIFONIC => [
            ['country' => 'Saudi Arabia', 'coverage' => 'Local numbers and voice (Unifonic is KSA-founded)', 'requirement' => 'Caller ID subscription; CST content labels apply'],
            ['country' => 'United Arab Emirates', 'coverage' => 'Local numbers and voice', 'requirement' => 'Caller ID subscription'],
            ['country' => 'Egypt', 'coverage' => 'Voice termination', 'requirement' => 'Caller ID must be provisioned with Unifonic'],
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
