<?php

namespace App\Modules\Whatsapp\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Integrations\Services\CredentialResolver;
use App\Modules\Integrations\Services\Credentials\MetaCredentials;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Whatsapp\Jobs\TemplateSyncJob;
use App\Modules\Whatsapp\Models\WhatsappBusinessAccount;
use App\Modules\Whatsapp\Models\WhatsappPhoneNumber;
use App\Modules\Whatsapp\Services\CloudApiClient;
use App\Modules\Whatsapp\Services\WhatsappTokenInspector;
use App\Services\AuditLogService;
use App\Services\FeatureGate;
use App\Support\MetaGraph;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class WhatsappEmbeddedSignupController extends Controller
{
    public function __construct(
        private readonly WhatsappTokenInspector $tokenInspector,
        private readonly AuditLogService $audit,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:2048'],
            'waba_id' => ['required', 'string', 'max:64'],
            'phone_number_id' => ['nullable', 'string', 'max:64'],
            'coexistence' => ['nullable', 'boolean'],
            'session_event' => ['nullable', 'string', 'max:64'],
        ]);

        $workspaceId = $request->user()->current_workspace_id ?? $request->user()->workspace_id;
        $isCoexistence = $request->boolean('coexistence');

        if ($isCoexistence && (! config('whatsapp.coexistence.enabled', false)
            || ! FeatureGate::forUser($request->user())->enabled('whatsapp_coexistence'))) {
            return response()->json([
                'message' => 'WhatsApp Coexistence is not available on this plan. Upgrade to Pro or Business to connect an existing WhatsApp Business app number.',
            ], 403);
        }

        $meta = CredentialResolver::system()->meta();
        if (! $meta || ! $meta->appId() || ! $meta->appSecret()) {
            return response()->json(['message' => 'Meta App credentials are not configured. Please ask your administrator to configure them in Admin → Integrations → Meta App.'], 422);
        }

        $redirectUri = rtrim((string) config('app.url'), '/');

        // Exchange the short-lived auth code for an access token
        $tokenParams = [
            'client_id' => $meta->appId(),
            'client_secret' => $meta->appSecret(),
            'code' => $validated['code'],
        ];
        if ($redirectUri !== '') {
            $tokenParams['redirect_uri'] = $redirectUri;
        }

        $tokenRes = Http::get(MetaGraph::url('oauth/access_token'), $tokenParams);

        // Some Meta app configs reject redirect_uri on embedded-signup codes — retry without it.
        if ((! $tokenRes->successful() || empty($tokenRes->json('access_token'))) && isset($tokenParams['redirect_uri'])) {
            unset($tokenParams['redirect_uri']);
            $tokenRes = Http::get(MetaGraph::url('oauth/access_token'), $tokenParams);
        }

        if (! $tokenRes->successful() || empty($tokenRes->json('access_token'))) {
            Log::warning('WhatsApp embedded signup: code exchange failed', [
                'workspace_id' => $workspaceId,
                'response' => $tokenRes->json(),
            ]);

            return response()->json([
                'message' => 'Failed to exchange authorization code: '.($tokenRes->json('error.message') ?? 'unknown error'),
            ], 422);
        }

        $shortToken = $tokenRes->json('access_token');

        // Exchange short-lived token for a long-lived token
        $longTokenRes = Http::get(MetaGraph::url('oauth/access_token'), [
            'grant_type' => 'fb_exchange_token',
            'client_id' => $meta->appId(),
            'client_secret' => $meta->appSecret(),
            'fb_exchange_token' => $shortToken,
        ]);

        $accessToken = $longTokenRes->successful() && $longTokenRes->json('access_token')
            ? $longTokenRes->json('access_token')
            : $shortToken;

        // Fetch WABA details from Meta. The ID comes from Meta's own signup
        // session, so no /phone_numbers type check is needed here.
        $wabaFetch = $this->fetchWabaMetadata($validated['waba_id'], $accessToken, verifyIsWaba: false);

        if ($wabaFetch['error'] !== null) {
            Log::warning('WhatsApp embedded signup: WABA fetch failed', [
                'workspace_id' => $workspaceId,
                'waba_id' => $validated['waba_id'],
                'attempts' => $wabaFetch['attempts'],
            ]);

            return response()->json([
                'message' => 'Connected but could not fetch WABA details: '.$wabaFetch['error'],
            ], 422);
        }

        $wabaData = $wabaFetch['data'];

        if (WhatsappBusinessAccount::where('waba_id', $validated['waba_id'])
            ->where('workspace_id', '!=', $workspaceId)
            ->exists()) {
            return response()->json(['message' => 'This WhatsApp Business Account is already connected to another workspace.'], 409);
        }

        $existing = WhatsappBusinessAccount::where('waba_id', $validated['waba_id'])
            ->where('workspace_id', $workspaceId)
            ->first();

        // Coexistence is only valid for a number that Meta reports as linked to
        // both the WhatsApp Business app and Cloud API. Never trust the JS
        // session alone: verify on the server before accepting the connection.
        $coexistencePhoneId = null;
        if ($isCoexistence) {
            $verification = $this->verifyCoexistenceNumber(
                $validated['waba_id'],
                $validated['phone_number_id'] ?? null,
                $accessToken,
            );

            if (! $verification['ok']) {
                Log::warning('WhatsApp embedded signup: coexistence verification failed', [
                    'workspace_id' => $workspaceId,
                    'waba_id' => $validated['waba_id'],
                    'phone_number_id' => $validated['phone_number_id'] ?? null,
                    'error' => $verification['error'],
                ]);

                return response()->json(['message' => $verification['error']], 422);
            }

            $coexistencePhoneId = $verification['phone_number_id'];
        }

        $verifyToken = $existing?->webhook_verify_token ?? Str::random(48);

        // Coexistence needs the extra webhook fields, so a failed subscription
        // is a hard failure here instead of a warning on a half-working number.
        if ($isCoexistence) {
            $webhookError = $this->subscribeWabaWebhooks($validated['waba_id'], $accessToken, $verifyToken, $meta);

            if ($webhookError !== null) {
                return response()->json([
                    'message' => 'Could not subscribe this account to WhatsApp webhooks: '.$webhookError,
                ], 422);
            }
        }

        $coexistenceAttributes = [
            'is_coexistence' => true,
            'coexistence_status' => WhatsappBusinessAccount::COEXISTENCE_ACTIVE,
            'coexistence_linked_at' => $existing?->coexistence_linked_at ?? now(),
            'coexistence_disconnected_at' => null,
            'coexistence_disconnect_reason' => null,
            // The history request can only be sent once per business. Keep a
            // terminal Meta outcome instead of resetting it on reconnect.
            'history_sync_status' => in_array($existing?->history_sync_status, ['requested', 'completed', 'declined'], true)
                ? $existing->history_sync_status
                : 'pending_consent',
        ];

        $waba = WhatsappBusinessAccount::updateOrCreate(
            ['waba_id' => $validated['waba_id'], 'workspace_id' => $workspaceId],
            array_merge([
                'credentials' => [
                    'system_user_token' => $accessToken,
                    'access_token' => $accessToken,
                    'token_source' => 'embedded_signup',
                ],
                'webhook_verify_token' => $verifyToken,
                'status' => 'active',
                'last_error_at' => null,
                'last_error_code' => null,
                'last_error_message' => null,
                'meta_json' => array_merge($existing?->meta_json ?? [], [
                    'display_name' => $wabaData['name'] ?? $validated['waba_id'],
                    'currency' => $wabaData['currency'] ?? null,
                    'timezone_id' => $wabaData['timezone_id'] ?? null,
                    'connected_via' => 'embedded_signup',
                    'coexistence_session_event' => $isCoexistence ? $validated['session_event'] ?? null : null,
                    'coexistence_phone_number_id' => $isCoexistence ? $coexistencePhoneId : null,
                ]),
            ], $isCoexistence ? $coexistenceAttributes : [])
        );

        $tokenExpiresAt = $this->tokenInspector->expiresAt($accessToken, $meta);

        $waba->forceFill([
            'token_expires_at' => $tokenExpiresAt,
            'token_checked_at' => now(),
        ])->save();

        // Subscribe the app to this WABA for webhooks and register callback URL.
        // Coexistence already subscribed before the account was saved.
        $webhookError = null;
        if (! $isCoexistence) {
            $webhookError = $this->subscribeWabaWebhooks($validated['waba_id'], $accessToken, $waba->webhook_verify_token, $meta);
        }

        // Sync phone numbers (try user token, then app token, then admin system user)
        $phoneCount = 0;
        $syncError = null;
        try {
            $phoneCount = $this->syncPhoneNumbers($waba->fresh(), $accessToken, $meta, register: ! $isCoexistence);
        } catch (\Throwable $e) {
            $syncError = $e->getMessage();
            Log::warning('WhatsApp embedded signup: phone sync failed', [
                'workspace_id' => $workspaceId,
                'waba_id' => $validated['waba_id'],
                'error' => $e->getMessage(),
            ]);
        }

        $sessionPhoneNumberId = $validated['phone_number_id'] ?? $coexistencePhoneId;

        if (! empty($sessionPhoneNumberId)) {
            try {
                $details = CloudApiClient::fetchPhoneNumberDetails($sessionPhoneNumberId, $accessToken);
                $this->attachPhoneNumber($waba->fresh(), $sessionPhoneNumberId, $details ?? ['id' => $sessionPhoneNumberId]);
                if (! $isCoexistence) {
                    $this->registerNumber($sessionPhoneNumberId, $accessToken, $validated['waba_id']);
                }
                $phoneCount = max($phoneCount, 1);
            } catch (\Throwable $e) {
                Log::warning('WhatsApp embedded signup: session phone attach failed', [
                    'phone_number_id' => $sessionPhoneNumberId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($phoneCount > 0) {
            TemplateSyncJob::dispatch($waba->id)->onQueue('whatsapp');
        }

        $warnings = array_filter([$webhookError, $syncError, $phoneCount === 0 ? 'No phone numbers were synced. Use Sync from Meta on Channel Setup or reconnect.' : null]);

        $this->audit->log('whatsapp.connected', $waba, null, [
            'waba_id' => $validated['waba_id'],
            'workspace_id' => $workspaceId,
            'via' => 'embedded_signup',
            'coexistence' => $isCoexistence,
            'coexistence_phone_number_id' => $coexistencePhoneId,
            'phone_count' => $phoneCount,
            'token_expires_at' => $tokenExpiresAt?->toIso8601String(),
        ]);

        return response()->json([
            'success' => true,
            'waba_id' => $validated['waba_id'],
            'name' => $wabaData['name'] ?? $validated['waba_id'],
            'phone_count' => $phoneCount,
            'coexistence' => $isCoexistence,
            'history_sync_status' => $waba->fresh()->history_sync_status,
            'sync_error' => $syncError,
            'webhook_warning' => $warnings !== [] ? implode(' ', $warnings) : null,
        ]);
    }

    /**
     * Manually connect a WABA with credentials the user pastes from their own
     * Meta App (WABA ID + permanent System User access token). Mirrors store()
     * but skips the OAuth code exchange — the pasted token is validated against
     * the Graph API and stored as-is.
     */
    public function manual(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'waba_id' => ['required', 'string', 'max:64'],
            'access_token' => ['required', 'string', 'max:1024'],
            'phone_number_id' => ['nullable', 'string', 'max:64'],
        ]);

        $workspaceId = $request->user()->current_workspace_id ?? $request->user()->workspace_id;
        $accessToken = trim($validated['access_token']);
        $wabaId = trim($validated['waba_id']);

        // Validate the token by fetching the WABA it claims access to
        $wabaFetch = $this->fetchWabaMetadata($wabaId, $accessToken);

        if ($wabaFetch['error'] !== null) {
            Log::warning('WhatsApp manual connect: WABA fetch failed', [
                'workspace_id' => $workspaceId,
                'waba_id' => $wabaId,
                'attempts' => $wabaFetch['attempts'],
            ]);

            return response()->json([
                'message' => 'Could not verify the WABA with Meta: '.$wabaFetch['error']
                    .' — '.$this->wabaRejectionHint($wabaFetch),
            ], 422);
        }

        $wabaData = $wabaFetch['data'];

        if (WhatsappBusinessAccount::where('waba_id', $wabaId)
            ->where('workspace_id', '!=', $workspaceId)
            ->exists()) {
            return response()->json(['message' => 'This WhatsApp Business Account is already connected to another workspace.'], 409);
        }

        $existing = WhatsappBusinessAccount::where('waba_id', $wabaId)
            ->where('workspace_id', $workspaceId)
            ->first();

        $verifyToken = $existing?->webhook_verify_token ?? Str::random(48);

        $waba = WhatsappBusinessAccount::updateOrCreate(
            ['waba_id' => $wabaId, 'workspace_id' => $workspaceId],
            [
                'credentials' => [
                    'system_user_token' => $accessToken,
                    'access_token' => $accessToken,
                    'token_source' => 'manual',
                ],
                'webhook_verify_token' => $verifyToken,
                'status' => 'active',
                'last_error_at' => null,
                'last_error_code' => null,
                'last_error_message' => null,
                'meta_json' => array_merge($existing?->meta_json ?? [], [
                    'display_name' => $wabaData['name'] ?? $wabaId,
                    'currency' => $wabaData['currency'] ?? null,
                    'timezone_id' => $wabaData['timezone_id'] ?? null,
                    'connected_via' => 'manual',
                ]),
            ]
        );

        $meta = CredentialResolver::system()->meta();
        $hasAppCreds = $meta && $meta->appId() && $meta->appSecret();

        $tokenExpiresAt = $this->tokenInspector->expiresAt($accessToken, $meta);

        $waba->forceFill([
            'token_expires_at' => $tokenExpiresAt,
            'token_checked_at' => now(),
        ])->save();

        // Best-effort webhook wiring. With platform Meta App credentials we reuse
        // the embedded-signup registration (app subscription + global callback).
        // Without them we can only subscribe the token's own app to the WABA —
        // the callback URL must be configured in the user's Meta App dashboard.
        if ($hasAppCreds) {
            $webhookWarning = $this->subscribeWabaWebhooks($wabaId, $accessToken, $waba->webhook_verify_token, $meta);
        } else {
            $webhookWarning = 'Add the webhook URL and verify token shown on this page to your Meta App → WhatsApp → Configuration so inbound messages reach the inbox.';
            try {
                $subRes = Http::post(MetaGraph::url("{$wabaId}/subscribed_apps"), [
                    'access_token' => $accessToken,
                ]);
                if (! $subRes->successful()) {
                    Log::warning('WhatsApp manual connect: subscribed_apps failed', [
                        'waba_id' => $wabaId,
                        'response' => $subRes->json(),
                    ]);
                }
            } catch (\Throwable $e) {
                Log::warning('WhatsApp manual connect: subscribed_apps call failed', [
                    'waba_id' => $wabaId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $phoneCount = 0;
        $syncError = null;
        try {
            $phoneCount = $this->syncPhoneNumbers($waba->fresh(), $accessToken, $meta);
        } catch (\Throwable $e) {
            $syncError = $e->getMessage();
            Log::warning('WhatsApp manual connect: phone sync failed', [
                'workspace_id' => $workspaceId,
                'waba_id' => $wabaId,
                'error' => $e->getMessage(),
            ]);
        }

        if (! empty($validated['phone_number_id'])) {
            $phoneNumberId = trim($validated['phone_number_id']);
            try {
                $details = CloudApiClient::fetchPhoneNumberDetails($phoneNumberId, $accessToken);
                $this->attachPhoneNumber($waba->fresh(), $phoneNumberId, $details ?? ['id' => $phoneNumberId]);
                $this->registerNumber($phoneNumberId, $accessToken, $wabaId);
                $phoneCount = max($phoneCount, 1);
            } catch (\Throwable $e) {
                Log::warning('WhatsApp manual connect: phone attach failed', [
                    'phone_number_id' => $phoneNumberId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($phoneCount > 0) {
            TemplateSyncJob::dispatch($waba->id)->onQueue('whatsapp');
        }

        $warnings = array_filter([
            $webhookWarning,
            $wabaFetch['warning'] ?? $syncError,
            $wabaFetch['warning'] === null && $phoneCount === 0
                ? 'No phone numbers were synced. Check the Phone Number ID or use Sync from Meta on Channel Setup.'
                : null,
        ]);

        $this->audit->log('whatsapp.connected', $waba, null, [
            'waba_id' => $wabaId,
            'workspace_id' => $workspaceId,
            'via' => 'manual',
            'phone_count' => $phoneCount,
            'token_expires_at' => $tokenExpiresAt?->toIso8601String(),
        ]);

        return response()->json([
            'success' => true,
            'waba_id' => $wabaId,
            'name' => $wabaData['name'] ?? $wabaId,
            'phone_count' => $phoneCount,
            'sync_error' => $syncError,
            'webhook_warning' => $warnings !== [] ? implode(' ', $warnings) : null,
            'webhook_url' => route('webhooks.whatsapp.receive', ['token' => $verifyToken]),
            'webhook_verify_token' => $verifyToken,
        ]);
    }

    /**
     * Fetch and validate the WABA node from Meta.
     *
     * /phone_numbers only exists on a WhatsApp Business Account. A Business
     * Portfolio ID from Business Settings (which also has `id` and `name`) or a
     * phone number ID answers
     * "(#100) Tried accessing nonexisting field (phone_numbers)", so probing it
     * is what tells users they pasted the wrong ID.
     *
     * A probe failure is only fatal when the ID is provably a Business
     * portfolio or a phone number. Otherwise the token may just lack
     * whatsapp_business_management for this account, so the connection is
     * accepted with a warning rather than blocking a messaging-only setup.
     *
     * `currency` / `timezone_id` are Business-level fields and answer
     * "(#200) Requires business_management permission to manage the object"
     * for tokens scoped to WhatsApp only, so they are requested separately and
     * never block the connection.
     *
     * @return array{
     *     data: array<string, mixed>,
     *     error: string|null,
     *     attempts: list<string>,
     *     warning: string|null,
     *     reason?: string,
     *     wabas?: list<array<string, mixed>>
     * }
     */
    private function fetchWabaMetadata(string $wabaId, string $accessToken, bool $verifyIsWaba = true): array
    {
        $attempts = [];
        $warning = null;
        $probedPhone = null;

        if ($verifyIsWaba) {
            $probe = $this->fetchFirstWabaPhone($wabaId, $accessToken);

            if ($probe['error'] === null) {
                $probedPhone = $probe['phone'];
            } else {
                $attempts[] = "{$wabaId}/phone_numbers → {$probe['error']}";

                $businessWabas = $this->fetchBusinessWabas($wabaId, $accessToken);

                if ($businessWabas !== null) {
                    return [
                        'data' => [],
                        'error' => $probe['error'],
                        'attempts' => $attempts,
                        'warning' => null,
                        'reason' => 'business_portfolio',
                        'wabas' => $businessWabas,
                    ];
                }

                if ($this->looksLikePhoneNumberId($wabaId, $accessToken)) {
                    return [
                        'data' => [],
                        'error' => $probe['error'],
                        'attempts' => $attempts,
                        'warning' => null,
                        'reason' => 'phone_number_id',
                    ];
                }

                $warning = "Meta did not allow listing this account's phone numbers: {$probe['error']} "
                    .'The account was connected — verify the WABA ID and that the System User is assigned to it, '
                    .'then use Sync from Meta.';
            }
        }

        $node = Http::withToken($accessToken)
            ->timeout(20)
            ->get(MetaGraph::url($wabaId), ['fields' => 'id,name']);

        if ($node->successful()) {
            return [
                'data' => array_merge((array) $node->json(), $this->fetchOptionalWabaFields($wabaId, $accessToken)),
                'error' => null,
                'attempts' => $attempts,
                'warning' => $warning,
            ];
        }

        $nodeError = (string) ($node->json('error.message') ?? 'HTTP '.$node->status());
        $attempts[] = "{$wabaId}?fields=id,name → {$nodeError}";

        if ($probedPhone !== null) {
            // The phone edge proved this is a WABA even though the token cannot
            // read the node itself; use the number's verified name.
            return [
                'data' => array_filter([
                    'id' => $wabaId,
                    'name' => $probedPhone['verified_name'] ?? null,
                ], fn ($value) => $value !== null),
                'error' => null,
                'attempts' => $attempts,
                'warning' => $warning,
            ];
        }

        return ['data' => [], 'error' => $nodeError, 'attempts' => $attempts, 'warning' => $warning];
    }

    /**
     * @return array{phone: array<string, mixed>|null, error: string|null}
     */
    private function fetchFirstWabaPhone(string $wabaId, string $accessToken): array
    {
        $response = Http::withToken($accessToken)
            ->timeout(20)
            ->get(MetaGraph::url("{$wabaId}/phone_numbers"), [
                'fields' => 'id,display_phone_number,verified_name',
                'limit' => 1,
            ]);

        if (! $response->successful()) {
            return [
                'phone' => null,
                'error' => (string) ($response->json('error.message') ?? 'HTTP '.$response->status()),
            ];
        }

        $first = $response->json('data.0');

        return ['phone' => is_array($first) ? $first : null, 'error' => null];
    }

    /**
     * Turn a rejected fetch into an actionable hint. When the pasted ID is a
     * Business Portfolio, show the WABA IDs the token can actually use.
     *
     * @param  array{error: string|null, reason?: string, wabas?: list<array<string, mixed>>}  $fetch
     */
    private function wabaRejectionHint(array $fetch): string
    {
        if (($fetch['reason'] ?? null) === 'business_portfolio') {
            $businessWabas = $fetch['wabas'] ?? [];

            if ($businessWabas === []) {
                return 'That is a Business Portfolio ID, not a WhatsApp Business Account, and the token cannot list its WABAs. '
                    .'Open WhatsApp Manager → Account overview and copy the WhatsApp Business Account ID.';
            }

            $list = collect($businessWabas)
                ->filter(fn ($waba) => ! empty($waba['id']))
                ->map(fn ($waba) => trim(($waba['name'] ?? 'WABA').' ('.$waba['id'].')'))
                ->implode('; ');

            return 'That is a Business Portfolio ID, not a WhatsApp Business Account. '
                .'This token can access these WABAs — use one of these IDs: '.$list.'.';
        }

        if (($fetch['reason'] ?? null) === 'phone_number_id') {
            return 'That is a Phone Number ID, not a WhatsApp Business Account. Open WhatsApp Manager → Account overview '
                .'and copy the WhatsApp Business Account ID.';
        }

        return 'Make sure the System User has this WABA assigned with full control in Business Settings and that the '
            .'token includes whatsapp_business_management (Advanced access is required for WABAs your app does not own).';
    }

    /**
     * Only a Business Portfolio node exposes these edges. Returns null when the
     * ID is not a Business portfolio.
     *
     * @return list<array<string, mixed>>|null
     */
    private function fetchBusinessWabas(string $businessId, string $accessToken): ?array
    {
        foreach (['owned_whatsapp_business_accounts', 'client_whatsapp_business_accounts'] as $edge) {
            $response = Http::withToken($accessToken)
                ->timeout(15)
                ->get(MetaGraph::url("{$businessId}/{$edge}"), [
                    'fields' => 'id,name',
                    'limit' => 25,
                ]);

            if ($response->successful()) {
                return array_values((array) $response->json('data', []));
            }
        }

        return null;
    }

    private function looksLikePhoneNumberId(string $id, string $accessToken): bool
    {
        $response = Http::withToken($accessToken)
            ->timeout(15)
            ->get(MetaGraph::url($id), ['fields' => 'display_phone_number']);

        return $response->successful() && $response->json('display_phone_number') !== null;
    }

    /**
     * Enrichment only: `currency` is exposed only for WABAs with a payment
     * method configured and both fields need business_management, so failure is
     * expected for WhatsApp-only tokens and must stay non-fatal.
     *
     * @return array<string, mixed>
     */
    private function fetchOptionalWabaFields(string $wabaId, string $accessToken): array
    {
        $response = Http::withToken($accessToken)
            ->timeout(20)
            ->get(MetaGraph::url($wabaId), ['fields' => 'timezone_id']);

        if (! $response->successful()) {
            Log::info('WhatsApp WABA optional fields unavailable', [
                'waba_id' => $wabaId,
                'error' => $response->json('error.message'),
            ]);

            return [];
        }

        return array_filter(
            (array) $response->json(),
            fn ($value) => $value !== null
        );
    }

    public function reregisterWebhook(Request $request, WhatsappBusinessAccount $waba): JsonResponse
    {
        $workspaceId = $request->user()->current_workspace_id ?? $request->user()->workspace_id;
        if ($waba->workspace_id !== $workspaceId) {
            abort(403);
        }

        $meta = CredentialResolver::system()->meta();
        if (! $meta || ! $meta->appId() || ! $meta->appSecret()) {
            return response()->json(['message' => 'Meta App credentials not configured.'], 422);
        }

        $token = $waba->accessToken() ?? $meta->systemUserToken();
        if (! $token) {
            return response()->json(['message' => 'No access token available for this WABA.'], 422);
        }

        $webhookError = $this->subscribeWabaWebhooks($waba->waba_id, $token, $waba->webhook_verify_token, $meta);

        if ($webhookError) {
            return response()->json(['success' => false, 'message' => $webhookError], 422);
        }

        return response()->json(['success' => true, 'message' => 'Webhook re-registered with Meta.']);
    }

    private function subscribeWabaWebhooks(string $wabaId, string $userToken, string $verifyToken, MetaCredentials $meta): ?string
    {
        $appId = $meta->appId();
        $appSecret = $meta->appSecret();
        // App Access Token: {app_id}|{app_secret} — must be passed as query param, not Bearer header
        $appToken = $appId.'|'.$appSecret;

        // Step 1: Subscribe our Meta App to this WABA's events.
        // Use the App Access Token (more reliable than the short-lived user token).
        try {
            $subRes = Http::post(MetaGraph::url("{$wabaId}/subscribed_apps"), [
                'access_token' => $appToken,
            ]);

            if (! $subRes->successful()) {
                $fallback = Http::post(MetaGraph::url("{$wabaId}/subscribed_apps"), [
                    'access_token' => $userToken,
                ]);
                if (! $fallback->successful()) {
                    Log::warning('WhatsApp embedded signup: subscribed_apps failed', [
                        'waba_id' => $wabaId,
                        'app_response' => $subRes->json(),
                        'user_response' => $fallback->json(),
                    ]);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('WhatsApp embedded signup: subscribed_apps call failed', [
                'waba_id' => $wabaId,
                'error' => $e->getMessage(),
            ]);
        }

        // Step 2: Register the global webhook callback URL with Meta.
        // /{app_id}/subscriptions sets ONE URL for the entire Meta App; all WABAs share it.
        // We use the stable global endpoint so multiple embedded-signup WABAs don't overwrite each other.
        // The App Access Token must be passed as access_token body param (Bearer header not accepted here).
        try {
            $callbackUrl = route('webhooks.whatsapp.global.receive');
            $globalVerify = hash('sha256', $appId.$appSecret.'wh_global_verify');

            $res = Http::post(MetaGraph::url("{$appId}/subscriptions"), [
                'access_token' => $appToken,
                'object' => 'whatsapp_business_account',
                'callback_url' => $callbackUrl,
                'verify_token' => $globalVerify,
                'fields' => 'messages,message_template_status_update,phone_number_name_update,phone_number_quality_update,account_update,history,smb_app_state_sync,smb_message_echoes',
            ]);

            if (! $res->successful()) {
                $errMsg = $res->json('error.message') ?? 'unknown error';
                $errCode = $res->json('error.code') ?? $res->status();
                Log::warning('WhatsApp embedded signup: app subscription registration failed', [
                    'waba_id' => $wabaId,
                    'callback_url' => $callbackUrl,
                    'http_status' => $res->status(),
                    'error_code' => $errCode,
                    'error_msg' => $errMsg,
                    'full_response' => $res->json(),
                    'hint' => match (true) {
                        $errCode == 10 => 'App lacks whatsapp_business_management permission or is not approved for this WABA',
                        $errCode == 100 => 'Callback URL could not be verified — Meta sent GET challenge to callback_url and did not get hub.challenge back. Check the URL is publicly accessible.',
                        $errCode == 190 => 'App Access Token invalid — check App ID and App Secret in Admin → Integrations → Meta App',
                        $errCode == 200 => 'App permission error — ensure the app has webhook subscriptions permission',
                        default => 'Check developers.facebook.com/docs/graph-api/webhooks for error code '.$errCode,
                    },
                ]);

                return "Webhook registration with Meta failed (code {$errCode}): {$errMsg}. Check laravel.log for details.";
            }

            Log::info('WhatsApp embedded signup: global webhook registered', [
                'waba_id' => $wabaId,
                'callback_url' => $callbackUrl,
            ]);
        } catch (\Throwable $e) {
            Log::warning('WhatsApp embedded signup: app subscription registration exception', [
                'waba_id' => $wabaId,
                'error' => $e->getMessage(),
            ]);

            return "Webhook registration with Meta failed: {$e->getMessage()}. Messages will not be received until this is resolved.";
        }

        return null;
    }

    /**
     * Confirm that a phone number is really linked to both the WhatsApp Business
     * app and Cloud API. Meta documents that coexistence numbers answer
     * `is_on_biz_app=true` and `platform_type=CLOUD_API`; anything else must be
     * rejected instead of creating a half-connected account.
     *
     * @return array{ok: bool, phone_number_id: string|null, error: string|null}
     */
    private function verifyCoexistenceNumber(
        string $wabaId,
        ?string $sessionPhoneNumberId,
        string $accessToken,
    ): array {
        $candidates = [];

        if ($sessionPhoneNumberId !== null && $sessionPhoneNumberId !== '') {
            $candidates[] = $sessionPhoneNumberId;
        } else {
            try {
                foreach (CloudApiClient::fetchWabaPhoneNumbers($wabaId, $accessToken) as $row) {
                    if (! empty($row['id'])) {
                        $candidates[] = (string) $row['id'];
                    }
                }
            } catch (\Throwable $e) {
                Log::info('WhatsApp coexistence: phone number list unavailable', [
                    'waba_id' => $wabaId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $candidates = array_values(array_unique($candidates));

        if ($candidates === []) {
            return [
                'ok' => false,
                'phone_number_id' => null,
                'error' => 'Meta did not return a phone number for this account. Finish the WhatsApp Business app connection in Meta, then try again.',
            ];
        }

        $lastError = null;

        foreach ($candidates as $phoneNumberId) {
            $response = Http::withToken($accessToken)
                ->timeout(20)
                ->get(MetaGraph::url($phoneNumberId), ['fields' => 'is_on_biz_app,platform_type']);

            if (! $response->successful()) {
                $lastError = (string) ($response->json('error.message') ?? 'HTTP '.$response->status());

                continue;
            }

            $onBusinessApp = filter_var($response->json('is_on_biz_app'), FILTER_VALIDATE_BOOLEAN);
            $platform = strtoupper((string) $response->json('platform_type'));

            if ($onBusinessApp && $platform === 'CLOUD_API') {
                return ['ok' => true, 'phone_number_id' => $phoneNumberId, 'error' => null];
            }
        }

        return [
            'ok' => false,
            'phone_number_id' => null,
            'error' => $lastError !== null
                ? 'Meta could not verify this number as a WhatsApp Business app coexistence number: '.$lastError
                : 'This number is not linked to WhatsApp Business app and Cloud API together yet. Open the WhatsApp Business app → Settings → Account → Business Platform, finish the connection, then try again.',
        ];
    }

    private function syncPhoneNumbers(
        WhatsappBusinessAccount $waba,
        string $userToken,
        ?MetaCredentials $meta,
        bool $register = true,
    ): int {
        $tokens = array_values(array_unique(array_filter([
            $userToken,
            $meta?->appId() && $meta?->appSecret() ? $meta->appId().'|'.$meta->appSecret() : null,
            $meta?->systemUserToken(),
        ])));

        $rows = [];
        $lastError = null;

        foreach ($tokens as $token) {
            try {
                $rows = CloudApiClient::fetchWabaPhoneNumbers($waba->waba_id, $token);
                if ($rows !== []) {
                    break;
                }
            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
            }
        }

        if ($rows === [] && $lastError) {
            throw new \RuntimeException($lastError);
        }

        $syncToken = $userToken;
        $count = 0;

        foreach ($rows as $row) {
            if (empty($row['id'])) {
                continue;
            }

            $details = CloudApiClient::fetchPhoneNumberDetails((string) $row['id'], $syncToken);
            if (is_array($details)) {
                $row = array_merge($row, $details);
            }

            $this->attachPhoneNumber($waba, (string) $row['id'], $row);
            if ($register) {
                $this->registerNumber((string) $row['id'], $syncToken, $waba->waba_id);
            }
            $count++;
        }

        return $count;
    }

    /**
     * Register a phone number with the Cloud API so it leaves the PENDING state
     * and becomes CONNECTED (active). New numbers added via Embedded Signup are
     * created PENDING and stay that way until registered. Best-effort: a number
     * that is already registered returns an error we simply log.
     */
    private function registerNumber(string $phoneNumberId, string $accessToken, string $wabaId): void
    {
        try {
            $result = CloudApiClient::registerPhoneNumber($phoneNumberId, $accessToken);

            if (! $result['success']) {
                Log::info('WhatsApp embedded signup: phone register skipped/failed', [
                    'waba_id' => $wabaId,
                    'phone_number_id' => $phoneNumberId,
                    'status' => $result['status'],
                    'response' => $result['response'],
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('WhatsApp embedded signup: phone register exception', [
                'waba_id' => $wabaId,
                'phone_number_id' => $phoneNumberId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function attachPhoneNumber(WhatsappBusinessAccount $waba, string $phoneNumberId, array $metaRow): void
    {
        $throughput = is_array($metaRow['throughput'] ?? null) ? $metaRow['throughput'] : [];
        $tier = $metaRow['messaging_limit_tier'] ?? ($throughput['level'] ?? null);

        // Only the Meta-sourced descriptive fields, with nulls filtered out so a
        // detail-less attach (right after embedded signup, before Meta has
        // propagated the new number node) never overwrites values an earlier
        // phone_numbers-edge sync already populated. The waba link is always set.
        $details = array_filter([
            'display_phone' => $metaRow['display_phone_number'] ?? null,
            'verified_name' => $metaRow['verified_name'] ?? null,
            'quality_rating' => $metaRow['quality_rating'] ?? null,
            'messaging_limit_tier' => is_string($tier) ? $tier : null,
            'code_verification_status' => $metaRow['code_verification_status'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        WhatsappPhoneNumber::updateOrCreate(
            ['phone_number_id' => $phoneNumberId],
            array_merge(['waba_id_fk' => $waba->id], $details),
        );

        $this->upsertChannelAccount($waba, $phoneNumberId, $metaRow);
    }

    /**
     * Upsert the inbox-facing ChannelAccount without clobbering an existing
     * display name when this attach carries no usable label.
     *
     * @param  array<string, mixed>  $metaRow
     */
    private function upsertChannelAccount(WhatsappBusinessAccount $waba, string $phoneNumberId, array $metaRow): void
    {
        $account = ChannelAccount::firstOrNew([
            'workspace_id' => $waba->workspace_id,
            'phone_number_id' => $phoneNumberId,
        ]);

        $account->channel = 'whatsapp';
        $account->provider = 'meta';
        $account->business_account_id = $waba->waba_id;
        $account->status = 'active';

        $label = $metaRow['verified_name'] ?? $metaRow['display_phone_number'] ?? null;
        if ($label !== null && $label !== '') {
            $account->display_name = mb_substr((string) $label, 0, 128);
        } elseif (! $account->exists) {
            $account->display_name = 'WhatsApp';
        }

        $account->save();
    }
}
