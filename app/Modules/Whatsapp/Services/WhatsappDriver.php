<?php

namespace App\Modules\Whatsapp\Services;

use App\Events\MessageReceived;
use App\Events\MessageStatusUpdated;
use App\Modules\Broadcasting\Models\CampaignRecipient;
use App\Modules\Sales\Services\LeadAutoCaptureService;
use App\Modules\Shared\Contracts\ChannelDriverInterface;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use App\Modules\Shared\Services\ContactService;
use App\Modules\Whatsapp\Models\WhatsappBusinessAccount;
use App\Modules\Whatsapp\Models\WhatsappPhoneNumber;
use App\Modules\Whatsapp\Models\WhatsappTemplate;
use App\Services\WebhookIdempotencyService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class WhatsappDriver implements ChannelDriverInterface
{
    public function __construct(
        private readonly ContactService $contactService,
        private readonly WhatsappCoexistenceContactImporter $coexistenceContacts,
    ) {}

    public function send(Message $message): string
    {
        $conversation = $message->conversation;
        $contact = $conversation->contact;

        // Prefer the phone number; fall back to the business-scoped user ID
        // (BSUID) for users who hide their number.
        $phone = $contact->phone_e164 ?: $contact->wa_user_id;

        if (! $phone) {
            throw new \RuntimeException('Contact has no WhatsApp phone number or user ID.');
        }

        // Prefer the phone number tied to this conversation's channel account so
        // outbound replies go from the same number the customer wrote to.
        $phoneNumberId = $conversation->channelAccount?->phone_number_id;
        $client = $phoneNumberId
            ? CloudApiClient::forPhoneNumber($phoneNumberId, $conversation->workspace_id)
            : null;
        $client ??= CloudApiClient::forWorkspace($conversation->workspace_id);

        if (! $client) {
            throw new \RuntimeException('No active WhatsApp account for workspace.');
        }

        $payload = $message->payload ?? [];

        $resp = match ($message->type) {
            'template' => $client->sendTemplate($phone, $payload['template']['name'] ?? '', $payload['template']['language'] ?? 'en', $payload['template']['components'] ?? []),
            'interactive' => $client->sendInteractive($phone, $payload['interactive'] ?? []),
            'image' => $client->sendMedia($phone, 'image', $payload['media_id'] ?? '', $payload['caption'] ?? null, null, $payload['link'] ?? null),
            'video' => $client->sendMedia($phone, 'video', $payload['media_id'] ?? '', $payload['caption'] ?? null, null, $payload['link'] ?? null),
            'document' => $client->sendMedia($phone, 'document', $payload['media_id'] ?? '', $payload['caption'] ?? null, $payload['filename'] ?? null, $payload['link'] ?? null),
            'audio' => $client->sendMedia($phone, 'audio', $payload['media_id'] ?? ''),
            'location' => $client->sendLocation(
                $phone,
                (float) ($payload['location']['latitude'] ?? 0),
                (float) ($payload['location']['longitude'] ?? 0),
                $payload['location']['name'] ?? null,
                $payload['location']['address'] ?? null,
            ),
            default => $client->sendText($phone, $message->body ?? ''),
        };

        if (! $resp->successful()) {
            throw new \RuntimeException('WhatsApp send failed: '.$resp->body());
        }

        return $resp->json('messages.0.id', '');
    }

    public function receiveWebhook(Request $request): array
    {
        return $this->processWebhookPayload($request->all());
    }

    public function processWebhookPayload(array $payload, string $verifyToken = ''): array
    {
        $processed = [];

        foreach ($payload['entry'] ?? [] as $entry) {
            $wabaId = (string) ($entry['id'] ?? '');

            foreach ($entry['changes'] ?? [] as $change) {
                $field = $change['field'] ?? '';
                $value = $change['value'] ?? [];

                if ($field === 'message_template_status_update') {
                    $this->processTemplateStatusUpdate($wabaId, $value);

                    continue;
                }

                if ($field === 'account_update') {
                    $this->processAccountUpdate($wabaId, $value);

                    continue;
                }

                if ($field === 'history') {
                    $this->processHistorySync($wabaId, $value);

                    continue;
                }

                if ($field === 'smb_app_state_sync') {
                    $this->processStateSync($wabaId, $value);

                    continue;
                }

                if ($field === 'smb_message_echoes') {
                    $this->processMessageEchoes($wabaId, $value);

                    continue;
                }

                if (in_array($field, ['phone_number_quality_update', 'phone_number_name_update'], true)) {
                    $this->processPhoneNumberUpdate($value);

                    continue;
                }

                foreach ($value['messages'] ?? [] as $msg) {
                    try {
                        if (($msg['type'] ?? '') === 'system') {
                            $this->processSystemMessage($value, $msg);

                            continue;
                        }

                        $processed[] = $this->processInboundMessage($value, $msg);
                    } catch (\Throwable $e) {
                        Log::error('WhatsApp webhook processing failed', ['error' => $e->getMessage(), 'msg' => $msg]);
                    }
                }

                foreach ($value['statuses'] ?? [] as $status) {
                    $this->processStatusUpdate($status);
                }
            }
        }

        return $processed;
    }

    private function processTemplateStatusUpdate(string $wabaId, array $value): void
    {
        $event = strtoupper((string) ($value['event'] ?? ''));
        $name = $value['message_template_name'] ?? null;
        $language = $value['message_template_language'] ?? 'en';

        if (! $wabaId || ! $name || ! $event) {
            return;
        }

        $statusMap = [
            'APPROVED' => 'APPROVED',
            'REJECTED' => 'REJECTED',
            'PENDING' => 'PENDING',
            'PAUSED' => 'PAUSED',
            'DISABLED' => 'PAUSED',
        ];
        $status = $statusMap[$event] ?? null;
        if (! $status) {
            return;
        }

        $reason = $value['reason'] ?? $value['rejection_reason'] ?? null;

        WhatsappTemplate::where('waba_id', $wabaId)
            ->where('name', $name)
            ->where('language', $language)
            ->update(array_filter([
                'status' => $status,
                'rejection_reason' => $status === 'REJECTED' ? (is_string($reason) ? $reason : json_encode($reason)) : null,
                'meta_template_id' => isset($value['message_template_id'])
                    ? (string) $value['message_template_id']
                    : null,
            ]));
    }

    private function processPhoneNumberUpdate(array $value): void
    {
        $phoneNumberId = $value['phone_number_id'] ?? null;
        if (! $phoneNumberId) {
            return;
        }

        // Map Meta's name decision to our name_status values
        $decision = strtoupper((string) ($value['decision'] ?? ''));
        $nameStatus = match ($decision) {
            'APPROVED' => 'APPROVED',
            'REJECTED' => 'DECLINED',
            default => null,
        };

        $patch = array_filter([
            'quality_rating' => $value['current_quality_rating'] ?? $value['quality_rating'] ?? null,
            'messaging_limit_tier' => $value['current_limit'] ?? $value['messaging_limit_tier'] ?? null,
            'display_phone' => $value['display_phone_number'] ?? null,
            // When a name is approved, verified_name updates to the new name
            'verified_name' => $nameStatus === 'APPROVED'
                                          ? ($value['requested_verified_name'] ?? $value['verified_name'] ?? null)
                                          : ($value['verified_name'] ?? null),
            'name_status' => $nameStatus,
            // Clear requested_verified_name once the decision is made
            'requested_verified_name' => in_array($nameStatus, ['APPROVED', 'DECLINED'], true) ? null : null,
        ], fn ($v) => $v !== null && $v !== '');

        if ($patch === []) {
            return;
        }

        WhatsappPhoneNumber::where('phone_number_id', (string) $phoneNumberId)->update($patch);

        Log::info('whatsapp.phone_number.updated', [
            'phone_number_id' => $phoneNumberId,
            'patch' => $patch,
        ]);
    }

    /**
     * Coexistence account lifecycle events. Meta reports a revoked link through
     * `PARTNER_REMOVED` / `ACCOUNT_OFFBOARDED`, and a re-onboard through
     * `ACCOUNT_RECONNECTED`. We suspend local routing but never delete data.
     */
    private function processAccountUpdate(string $entryWabaId, array $value): void
    {
        $event = strtoupper((string) ($value['event'] ?? ''));
        $wabaId = (string) ($value['waba_info']['waba_id'] ?? $entryWabaId);

        $waba = $wabaId !== ''
            ? WhatsappBusinessAccount::where('waba_id', $wabaId)->first()
            : null;

        if ($waba && $waba->is_coexistence
            && in_array($event, ['PARTNER_REMOVED', 'ACCOUNT_OFFBOARDED'], true)) {
            $reason = (string) ($value['disconnection_info']['reason'] ?? strtolower($event));

            $waba->update([
                'coexistence_status' => WhatsappBusinessAccount::COEXISTENCE_SUSPENDED,
                'coexistence_disconnected_at' => now(),
                'coexistence_disconnect_reason' => mb_substr($reason, 0, 64),
            ]);

            ChannelAccount::where('workspace_id', $waba->workspace_id)
                ->where('channel', 'whatsapp')
                ->where('business_account_id', $waba->waba_id)
                ->update(['status' => 'inactive']);

            Log::warning('whatsapp.coexistence.suspended', [
                'workspace_id' => $waba->workspace_id,
                'waba_id' => $waba->waba_id,
                'event' => $event,
                'reason' => $reason,
                'initiated_by' => $value['disconnection_info']['initiated_by'] ?? null,
            ]);

            return;
        }

        if ($waba && $waba->is_coexistence && $event === 'ACCOUNT_RECONNECTED') {
            $waba->update([
                'coexistence_status' => WhatsappBusinessAccount::COEXISTENCE_ACTIVE,
                'coexistence_disconnected_at' => null,
                'coexistence_disconnect_reason' => null,
                'status' => 'active',
            ]);

            ChannelAccount::where('workspace_id', $waba->workspace_id)
                ->where('channel', 'whatsapp')
                ->where('business_account_id', $waba->waba_id)
                ->update(['status' => 'active']);

            Log::info('whatsapp.coexistence.reconnected', [
                'workspace_id' => $waba->workspace_id,
                'waba_id' => $waba->waba_id,
            ]);

            return;
        }

        // Other account_update payloads still describe phone-number changes.
        $this->processPhoneNumberUpdate($value);
    }

    /**
     * Resolve the coexistence WABA and channel account for a webhook value, or
     * null when this is not a coexistence account (or it is disconnected).
     *
     * @return array{0: WhatsappBusinessAccount, 1: ChannelAccount}|null
     */
    private function resolveCoexistenceContext(string $wabaId, array $value): ?array
    {
        $waba = WhatsappBusinessAccount::where('waba_id', $wabaId)->first();

        if (! $waba || ! $waba->is_coexistence) {
            return null;
        }

        if ($waba->coexistence_status === WhatsappBusinessAccount::COEXISTENCE_DISCONNECTED) {
            return null;
        }

        $phoneNumberId = (string) ($value['metadata']['phone_number_id'] ?? '');

        $channelAccount = $phoneNumberId !== ''
            ? ChannelAccount::where('phone_number_id', $phoneNumberId)
                ->where('channel', 'whatsapp')
                ->first()
            : null;

        if (! $channelAccount) {
            $channelAccount = ChannelAccount::where('workspace_id', $waba->workspace_id)
                ->where('channel', 'whatsapp')
                ->where('business_account_id', $waba->waba_id)
                ->orderBy('id')
                ->first();
        }

        if (! $channelAccount) {
            Log::warning('whatsapp.coexistence.no_channel_account', [
                'waba_id' => $wabaId,
                'phone_number_id' => $phoneNumberId,
            ]);

            return null;
        }

        return [$waba, $channelAccount];
    }

    /**
     * Import the WhatsApp Business app address book (initial sync and later
     * add/edit events). `remove` is ignored on purpose: removing a number from
     * the phone's address book must never delete a CRM contact.
     */
    private function processStateSync(string $wabaId, array $value): void
    {
        $context = $this->resolveCoexistenceContext($wabaId, $value);

        if (! $context) {
            return;
        }

        [$waba] = $context;
        $imported = 0;
        $skipped = 0;

        foreach ((array) ($value['state_sync'] ?? []) as $row) {
            if (! is_array($row) || ($row['type'] ?? 'contact') !== 'contact') {
                continue;
            }

            if (strtolower((string) ($row['action'] ?? 'add')) === 'remove') {
                $skipped++;

                continue;
            }

            $contact = is_array($row['contact'] ?? null) ? $row['contact'] : [];

            if ($this->coexistenceContacts->import($waba, $contact, 'state_sync') !== null) {
                $imported++;
            }
        }

        $waba->update(['coexistence_last_sync_at' => now()]);

        Log::info('whatsapp.coexistence.state_sync', [
            'workspace_id' => $waba->workspace_id,
            'waba_id' => $waba->waba_id,
            'imported' => $imported,
            'removed_ignored' => $skipped,
        ]);
    }

    /**
     * Mirror messages the business sends from the WhatsApp Business app into the
     * inbox as outbound messages, badged with their source.
     */
    private function processMessageEchoes(string $wabaId, array $value): void
    {
        $context = $this->resolveCoexistenceContext($wabaId, $value);

        if (! $context) {
            return;
        }

        [$waba, $channelAccount] = $context;
        $workspaceId = (int) $channelAccount->workspace_id;

        $contactEntry = is_array($value['contacts'][0] ?? null) ? $value['contacts'][0] : [];
        $fallbackPhone = $this->normalizePhone($contactEntry['wa_id'] ?? null);
        $fallbackUserId = $contactEntry['user_id'] ?? null;
        $username = $contactEntry['profile']['username'] ?? null;

        $imported = 0;

        foreach ((array) ($value['message_echoes'] ?? []) as $echo) {
            if (! is_array($echo)) {
                continue;
            }

            $messageId = $echo['id'] ?? null;

            if (! is_string($messageId) || $messageId === '') {
                continue;
            }

            if (Message::where('provider_message_id', $messageId)->exists()) {
                continue;
            }

            $phone = $this->normalizePhone($echo['to'] ?? null) ?? $fallbackPhone;
            $userId = $echo['to_user_id'] ?? $fallbackUserId;

            $contact = $this->coexistenceContacts->import($waba, [
                'phone_number' => $phone,
                'user_id' => $userId,
                'username' => $username,
            ], 'whatsapp_business_app');

            if (! $contact) {
                continue;
            }

            $conversation = Conversation::firstOrCreate(
                [
                    'workspace_id' => $workspaceId,
                    'contact_id' => $contact->id,
                    'channel_account_id' => $channelAccount->id,
                ],
                ['status' => 'open', 'external_thread_id' => $phone ?? $userId],
            );

            $type = (string) ($echo['type'] ?? 'text');
            $body = $this->extractMessageBody($echo, $type);
            $sentAt = $this->webhookTimestamp($echo['timestamp'] ?? null);

            // Meta can replay an echo under a new id when the app retries, so
            // also drop an identical outbound body sent within five minutes.
            if ($body !== null && $body !== '' && Message::where('conversation_id', $conversation->id)
                ->where('direction', 'out')
                ->where('body', $body)
                ->whereBetween('sent_at', [$sentAt->copy()->subMinutes(5), $sentAt->copy()->addMinutes(5)])
                ->exists()) {
                continue;
            }

            Message::create([
                'conversation_id' => $conversation->id,
                'direction' => 'out',
                'channel' => 'whatsapp',
                'type' => $this->normaliseMessageType($type),
                'payload' => $echo,
                'body' => $body,
                'status' => 'sent',
                'provider_message_id' => $messageId,
                'sent_by' => 'human',
                'source' => 'whatsapp_business_app',
                'is_history_import' => false,
                'sent_at' => $sentAt,
            ]);

            if (! $conversation->last_message_at || $sentAt->greaterThan($conversation->last_message_at)) {
                $conversation->update(['last_message_at' => $sentAt]);
            }

            $imported++;
        }

        Log::info('whatsapp.coexistence.message_echoes', [
            'workspace_id' => $workspaceId,
            'waba_id' => $waba->waba_id,
            'imported' => $imported,
        ]);
    }

    /**
     * Import a chunk of WhatsApp Business app chat history. History is silent:
     * no unread counters, notifications, automations or lead capture.
     */
    private function processHistorySync(string $wabaId, array $value): void
    {
        $context = $this->resolveCoexistenceContext($wabaId, $value);

        if (! $context) {
            return;
        }

        [$waba, $channelAccount] = $context;
        $workspaceId = (int) $channelAccount->workspace_id;
        $businessPhone = $this->normalizePhone($value['metadata']['display_phone_number'] ?? null);

        // Media assets in old messages arrive in a follow-up webhook under the
        // same `history` field but with `messages` instead of `history` chunks.
        foreach ((array) ($value['messages'] ?? []) as $message) {
            if (is_array($message)) {
                $this->upgradeHistoryMediaMessage($message);
            }
        }

        $maxProgress = null;
        $imported = 0;

        foreach ((array) ($value['history'] ?? []) as $chunk) {
            if (! is_array($chunk)) {
                continue;
            }

            if (! empty($chunk['errors'])) {
                $code = (int) ($chunk['errors'][0]['code'] ?? 0);

                if ($code === 2593109) {
                    $waba->update([
                        'history_sync_status' => WhatsappCoexistenceSyncService::STATUS_DECLINED,
                        'coexistence_last_sync_at' => now(),
                    ]);
                }

                Log::info('whatsapp.coexistence.history_chunk_error', [
                    'workspace_id' => $workspaceId,
                    'waba_id' => $waba->waba_id,
                    'code' => $code,
                    'title' => $chunk['errors'][0]['title'] ?? null,
                ]);

                continue;
            }

            $progress = isset($chunk['metadata']['progress']) ? (int) $chunk['metadata']['progress'] : null;
            $maxProgress = $progress === null ? $maxProgress : max($maxProgress ?? 0, $progress);

            foreach ((array) ($chunk['threads'] ?? []) as $thread) {
                if (is_array($thread)) {
                    $imported += $this->importHistoryThread($waba, $channelAccount, $thread, $businessPhone);
                }
            }
        }

        if ($maxProgress !== null && $maxProgress >= 100) {
            $waba->update([
                'history_sync_status' => WhatsappCoexistenceSyncService::STATUS_COMPLETED,
                'coexistence_last_sync_at' => now(),
            ]);
        }

        Log::info('whatsapp.coexistence.history_chunk', [
            'workspace_id' => $workspaceId,
            'waba_id' => $waba->waba_id,
            'imported' => $imported,
            'progress' => $maxProgress,
        ]);
    }

    /**
     * Import every message in one history thread.
     *
     * @param  array<string, mixed>  $thread
     */
    private function importHistoryThread(
        WhatsappBusinessAccount $waba,
        ChannelAccount $channelAccount,
        array $thread,
        ?string $businessPhone,
    ): int {
        $context = is_array($thread['context'] ?? null) ? $thread['context'] : [];
        $phone = $this->normalizePhone($context['wa_id'] ?? $thread['id'] ?? null);
        $userId = $context['user_id'] ?? null;

        if ($phone === null && $userId === null) {
            return 0;
        }

        $contact = $this->coexistenceContacts->import($waba, [
            'phone_number' => $phone,
            'user_id' => $userId,
            'username' => $context['username'] ?? null,
        ], 'history_sync');

        if (! $contact) {
            return 0;
        }

        $conversation = Conversation::firstOrCreate(
            [
                'workspace_id' => $channelAccount->workspace_id,
                'contact_id' => $contact->id,
                'channel_account_id' => $channelAccount->id,
            ],
            ['status' => 'open', 'external_thread_id' => $phone ?? $userId],
        );

        $imported = 0;
        $latestSentAt = null;
        $latestInboundAt = null;

        foreach ((array) ($thread['messages'] ?? []) as $message) {
            if (! is_array($message)) {
                continue;
            }

            $created = $this->importHistoryMessage($conversation->id, $message, $businessPhone);

            if (! $created) {
                continue;
            }

            $imported++;
            $sentAt = $created->sent_at;

            if ($sentAt !== null && ($latestSentAt === null || $sentAt->greaterThan($latestSentAt))) {
                $latestSentAt = $sentAt;
            }

            if ($created->direction === 'in' && $sentAt !== null
                && ($latestInboundAt === null || $sentAt->greaterThan($latestInboundAt))) {
                $latestInboundAt = $sentAt;
            }
        }

        if ($latestSentAt !== null && (! $conversation->last_message_at || $latestSentAt->greaterThan($conversation->last_message_at))) {
            $conversation->update(['last_message_at' => $latestSentAt]);
        }

        if ($latestInboundAt !== null && (! $conversation->last_inbound_at || $latestInboundAt->greaterThan($conversation->last_inbound_at))) {
            $conversation->update(['last_inbound_at' => $latestInboundAt]);
        }

        return $imported;
    }

    /**
     * Persist one history message. Returns the new message, or null when it was
     * already imported or carries no usable id.
     *
     * @param  array<string, mixed>  $message
     */
    private function importHistoryMessage(int $conversationId, array $message, ?string $businessPhone): ?Message
    {
        $messageId = $message['id'] ?? null;

        if (! is_string($messageId) || $messageId === '') {
            return null;
        }

        $type = (string) ($message['type'] ?? 'text');
        $body = $this->extractMessageBody($message, $type);
        $existing = Message::where('provider_message_id', $messageId)->first();

        if ($existing) {
            // A media placeholder can be completed by a later detail webhook.
            if (in_array($existing->type, ['unsupported', 'media_placeholder'], true) && $type !== 'media_placeholder') {
                $existing->update([
                    'type' => $this->normaliseMessageType($type),
                    'payload' => $message,
                    'body' => $body,
                ]);
            }

            return null;
        }

        $direction = $this->historyDirection($message, $businessPhone);
        $status = $this->historyStatus($message['history_context']['status'] ?? null);

        return Message::create([
            'conversation_id' => $conversationId,
            'direction' => $direction,
            'channel' => 'whatsapp',
            'type' => $this->normaliseMessageType($type),
            'payload' => $message,
            'body' => $body,
            'status' => $status,
            'provider_message_id' => $messageId,
            'sent_by' => 'human',
            'source' => 'coexistence_history',
            'is_history_import' => true,
            'sent_at' => $this->webhookTimestamp($message['timestamp'] ?? null),
        ]);
    }

    /**
     * A follow-up webhook that adds the media asset to an already imported
     * history message.
     *
     * @param  array<string, mixed>  $message
     */
    private function upgradeHistoryMediaMessage(array $message): void
    {
        $messageId = $message['id'] ?? null;

        if (! is_string($messageId) || $messageId === '') {
            return;
        }

        $existing = Message::where('provider_message_id', $messageId)->first();

        if (! $existing || ! in_array($existing->type, ['unsupported', 'media_placeholder'], true)) {
            return;
        }

        $type = (string) ($message['type'] ?? 'text');

        $existing->update([
            'type' => $this->normaliseMessageType($type),
            'payload' => $message,
            'body' => $this->extractMessageBody($message, $type),
        ]);
    }

    /**
     * Outbound history messages either carry a `to` (SMB echo) or are sent by
     * the business display number; everything else came from the contact.
     *
     * @param  array<string, mixed>  $message
     */
    private function historyDirection(array $message, ?string $businessPhone): string
    {
        if (! empty($message['to'])) {
            return 'out';
        }

        $from = $this->normalizePhone($message['from'] ?? null);

        if ($from !== null && $businessPhone !== null && $from === $businessPhone) {
            return 'out';
        }

        return 'in';
    }

    private function historyStatus(mixed $status): string
    {
        return match (strtolower((string) $status)) {
            'pending' => 'queued',
            'sent' => 'sent',
            'delivered' => 'delivered',
            'read', 'played' => 'read',
            'error', 'failed' => 'failed',
            default => 'delivered',
        };
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function extractMessageBody(array $message, string $type): ?string
    {
        $text = is_array($message['text'] ?? null) ? ($message['text']['body'] ?? null) : null;

        if (is_string($text) && $text !== '') {
            return $text;
        }

        $interactive = is_array($message['interactive'] ?? null) ? $message['interactive'] : [];

        $body = ($message['button']['text'] ?? null)
            ?? ($interactive['button_reply']['title'] ?? null)
            ?? ($interactive['list_reply']['title'] ?? null)
            ?? ($message['caption'] ?? null)
            ?? (is_array($message[$type] ?? null) && ! isset($message[$type][0])
                ? ($message[$type]['caption'] ?? null)
                : null)
            ?? ($message['errors'][0]['title'] ?? null);

        if (is_string($body) && $body !== '') {
            return $body;
        }

        return match ($type) {
            'location' => implode(', ', array_filter([
                $message['location']['name'] ?? null,
                $message['location']['address'] ?? null,
                isset($message['location']['latitude'], $message['location']['longitude'])
                    ? ($message['location']['latitude'].','.$message['location']['longitude'])
                    : null,
            ])) ?: '📍 Location',
            'contacts' => isset($message['contacts'][0]['name']['formatted_name'])
                ? ('👤 '.$message['contacts'][0]['name']['formatted_name'])
                : '👤 Contact',
            'poll' => '📊 '.($message['poll']['question'] ?? 'Poll'),
            'event' => '📅 '.($message['event']['title'] ?? $message['event']['name'] ?? 'Event'),
            'image' => '🖼 Image',
            'video' => '🎬 Video',
            'audio' => '🎤 Audio',
            'document' => '📄 '.($message['document']['filename'] ?? 'Document'),
            'sticker' => '😊 Sticker',
            'reaction' => $message['reaction']['emoji'] ?? '👍',
            'media_placeholder' => '📎 Attachment',
            default => null,
        };
    }

    private function normaliseMessageType(string $type): string
    {
        $allowed = [
            'text', 'template', 'media', 'interactive', 'reaction',
            'image', 'video', 'document', 'audio', 'location', 'contacts',
            'sticker', 'order', 'poll', 'event', 'unsupported',
        ];

        return in_array($type, $allowed, true) ? $type : 'unsupported';
    }

    private function normalizePhone(mixed $phone): ?string
    {
        if (! is_string($phone) || trim($phone) === '') {
            return null;
        }

        return '+'.ltrim(trim($phone), '+');
    }

    private function webhookTimestamp(mixed $timestamp): Carbon
    {
        $seconds = is_numeric($timestamp) ? (int) $timestamp : time();

        return now()->createFromTimestamp($seconds);
    }

    public function verifyCreds(): bool
    {
        return true;
    }

    private function processInboundMessage(array $value, array $msg): Message
    {
        $msgId = $msg['id'] ?? null;

        // Idempotency guard — skip if already processed or being processed concurrently.
        // insertOrIgnore is atomic, so only one concurrent request gets affected=1.
        // If affected=0 (already seen), never fall through — throw so the outer
        // try-catch skips this duplicate without creating a second message or auto-reply.
        if ($msgId && ! app(WebhookIdempotencyService::class)->isNewEvent('whatsapp_msg', $msgId)) {
            $existing = Message::where('provider_message_id', $msgId)->first();
            if ($existing) {
                return $existing;
            }
            // Race condition: the first request hasn't committed the message yet.
            // Skip rather than fall through and create a duplicate.
            throw new \RuntimeException("Duplicate webhook skipped (concurrent): {$msgId}");
        }

        // TEMP DIAGNOSTIC (remove once incoming poll shape is confirmed):
        // log the raw payload of unsupported / error-bearing messages so we can
        // see exactly how WhatsApp delivers polls and other unsupported types.
        if (($msg['type'] ?? '') === 'unsupported' || ! empty($msg['errors'])) {
            Log::info('whatsapp.inbound.unsupported_payload', [
                'type' => $msg['type'] ?? null,
                'msg' => $msg,
            ]);
        }

        $phoneId = $value['metadata']['phone_number_id'] ?? '';

        $channelAccount = ChannelAccount::where('phone_number_id', $phoneId)
            ->where('channel', 'whatsapp')
            ->first();

        if (! $channelAccount) {
            Log::warning('WhatsApp inbound dropped — no channel_account match', [
                'phone_number_id' => $phoneId,
                'from' => $msg['from'] ?? null,
                'msg_id' => $msg['id'] ?? null,
                'hint' => 'The phone_number_id received from Meta does not exist in channel_accounts. Re-run the WhatsApp setup or verify the configured number id.',
            ]);

            // Skip persisting — without a workspace the message would be invisible
            // and would corrupt the inbox queries that filter by workspace_id.
            throw new \RuntimeException("No channel_account found for phone_number_id={$phoneId}");
        }

        $workspaceId = (int) $channelAccount->workspace_id;

        $identity = $this->resolveWhatsappIdentity($value, $msg);

        if ($identity['phone_e164'] === null && $identity['wa_user_id'] === null) {
            throw new \RuntimeException('WhatsApp inbound message has no sender identity');
        }

        $contact = $this->contactService->upsert($workspaceId, array_filter([
            'phone_e164' => $identity['phone_e164'],
            'wa_user_id' => $identity['wa_user_id'],
            'wa_username' => $identity['wa_username'],
            'opt_in_whatsapp' => true,
            'source' => 'whatsapp_inbound',
        ], fn ($value) => $value !== null && $value !== ''));

        // Never clobber a manually edited name: only backfill when empty.
        if ($identity['profile_name'] && ! $contact->first_name) {
            $contact->update(['first_name' => $identity['profile_name']]);
        }

        $conversation = Conversation::firstOrCreate(
            ['workspace_id' => $workspaceId, 'contact_id' => $contact->id, 'channel_account_id' => $channelAccount?->id],
            ['status' => 'open', 'external_thread_id' => $identity['address']]
        );

        $type = $msg['type'] ?? 'text';
        $interactive = is_array($msg['interactive'] ?? null) ? $msg['interactive'] : [];
        $textBlock = is_array($msg['text'] ?? null) ? $msg['text'] : [];

        // Extract a human-readable body for every message type
        $body = ($textBlock['body'] ?? null)
            ?? (($msg['button'] ?? [])['text'] ?? null)
            ?? (($interactive['button_reply'] ?? [])['title'] ?? null)
            ?? (($interactive['list_reply'] ?? [])['title'] ?? null)
            ?? (is_array($msg[$type] ?? null) && ! isset($msg[$type][0]) ? ($msg[$type]['caption'] ?? null) : null)
            ?? ($msg['caption'] ?? null)
            ?? ($msg['errors'][0]['title'] ?? null);

        // Type-specific body fallbacks so conversation preview is meaningful
        if ($body === null || $body === '') {
            $body = match ($type) {
                'location' => implode(', ', array_filter([
                    $msg['location']['name'] ?? null,
                    $msg['location']['address'] ?? null,
                    isset($msg['location']['latitude'], $msg['location']['longitude'])
                        ? ($msg['location']['latitude'].','.$msg['location']['longitude'])
                        : null,
                ])) ?: '📍 Location',
                'contacts' => isset($msg['contacts'][0]['name']['formatted_name'])
                    ? ('👤 '.$msg['contacts'][0]['name']['formatted_name'])
                    : '👤 Contact',
                'poll' => '📊 '.($msg['poll']['question'] ?? ($msg['interactive']['poll_creation']['name'] ?? 'Poll')),
                'event' => '📅 '.($msg['event']['title'] ?? ($msg['event']['name'] ?? 'Event')),
                'image' => '🖼 Image',
                'video' => '🎬 Video',
                'audio' => '🎤 Audio',
                'document' => '📄 '.($msg['document']['filename'] ?? 'Document'),
                'sticker' => '😊 Sticker',
                'reaction' => $msg['reaction']['emoji'] ?? '👍',
                default => '',
            };
        }

        // Auto-capture or link lead in Sales CRM
        try {
            app(LeadAutoCaptureService::class)->captureFromWhatsapp(
                $workspaceId,
                $contact,
                $identity['phone_e164'] ?? $identity['address'],
                $body,
                $conversation->id
            );
        } catch (\Throwable $e) {
            Log::warning('Failed to auto-capture WhatsApp lead', ['error' => $e->getMessage()]);
        }

        $allowedTypes = ['text', 'template', 'media', 'interactive', 'reaction', 'image', 'video',
            'document', 'audio', 'location', 'contacts', 'sticker', 'order', 'poll', 'event', 'unsupported'];

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'in',
            'channel' => 'whatsapp',
            'type' => in_array($type, $allowedTypes, true) ? $type : 'unsupported',
            'payload' => $msg,
            'body' => $body,
            'status' => 'delivered',
            'provider_message_id' => $msg['id'] ?? null,
            'sent_by' => 'human',
            'sent_at' => now()->createFromTimestamp($msg['timestamp'] ?? time()),
        ]);

        $conversation->update([
            'last_message_at' => $message->sent_at,
            'status' => 'open',
            'unread_count' => $conversation->unread_count + 1,
            'last_inbound_at' => $message->sent_at,
            // If contact replies after we responded, reset first_response_at for next cycle
            'first_response_at' => $conversation->first_response_at && $conversation->last_inbound_at
                ? ($message->sent_at > $conversation->first_response_at ? null : $conversation->first_response_at)
                : $conversation->first_response_at,
        ]);

        // Fire typed event for automations / AI
        MessageReceived::dispatch($message);

        return $message;
    }

    /**
     * Resolve the sender identity from a WhatsApp inbound message.
     *
     * Modern webhooks put the sender in value.contacts[0]:
     *   { profile: { name, username }, user_id: <BSUID>, wa_id: <phone> }
     * `wa_id` (and the message's `from`) are omitted when the user hides their
     * phone number, so the BSUID becomes the only usable address.
     *
     * @return array{address: string, phone_e164: ?string, wa_user_id: ?string, wa_username: ?string, profile_name: ?string}
     */
    private function resolveWhatsappIdentity(array $value, array $msg): array
    {
        $contactEntry = $value['contacts'][0] ?? [];
        $from = (string) ($msg['from'] ?? '');
        $fromUserId = $msg['from_user_id'] ?? ($contactEntry['user_id'] ?? null);

        $isBsuid = static fn (?string $candidate): bool => is_string($candidate) && str_contains($candidate, '.');

        $waUserId = $fromUserId ?: ($isBsuid($from) ? $from : null);

        // Prefer contacts[].wa_id; fall back to the message `from` when it is a
        // plain phone number. Hidden-number users disclose neither.
        $phone = null;
        foreach ([$contactEntry['wa_id'] ?? null, $from] as $candidate) {
            if (is_string($candidate) && $candidate !== '' && ! $isBsuid($candidate)) {
                $phone = '+'.ltrim($candidate, '+');
                break;
            }
        }

        return [
            'address' => $phone ?? $waUserId ?? $from,
            'phone_e164' => $phone,
            'wa_user_id' => $waUserId,
            'wa_username' => $contactEntry['profile']['username'] ?? null,
            'profile_name' => $contactEntry['profile']['name'] ?? null,
        ];
    }

    /**
     * Apply a WhatsApp system message: the user changed their phone number
     * (`user_changed_number`) or their business-scoped user ID
     * (`user_changed_user_id`). System messages carry no `contacts` array.
     */
    private function processSystemMessage(array $value, array $msg): void
    {
        $msgId = $msg['id'] ?? null;
        if ($msgId && ! app(WebhookIdempotencyService::class)->isNewEvent('whatsapp_system', $msgId)) {
            return;
        }

        $phoneId = $value['metadata']['phone_number_id'] ?? '';
        $channelAccount = ChannelAccount::where('phone_number_id', $phoneId)
            ->where('channel', 'whatsapp')
            ->first();

        if (! $channelAccount) {
            Log::warning('WhatsApp system message dropped — no channel_account match', [
                'phone_number_id' => $phoneId,
                'type' => $msg['system']['type'] ?? null,
            ]);

            return;
        }

        $workspaceId = (int) $channelAccount->workspace_id;
        $system = is_array($msg['system'] ?? null) ? $msg['system'] : [];
        $type = $system['type'] ?? '';
        $from = (string) ($msg['from'] ?? '');

        if ($type === 'user_changed_user_id') {
            $newId = $system['user_id'] ?? null;
            $previousId = $system['previous_user_id'] ?? null;

            if (! $newId) {
                return;
            }

            $contact = $previousId
                ? Contact::where('workspace_id', $workspaceId)->where('wa_user_id', $previousId)->first()
                : $this->findContactByAddress($workspaceId, $from);

            if ($contact) {
                $contact->update(['wa_user_id' => $newId]);
                Log::info('whatsapp.contact.user_id_changed', [
                    'contact_id' => $contact->id,
                    'previous' => $previousId,
                    'current' => $newId,
                ]);
            }

            return;
        }

        if ($type === 'user_changed_number') {
            $waId = $system['wa_id'] ?? null;
            $userId = $system['user_id'] ?? null;

            if (! is_string($waId) || $waId === '') {
                return;
            }

            $newPhone = '+'.ltrim($waId, '+');

            $contact = $userId
                ? Contact::where('workspace_id', $workspaceId)->where('wa_user_id', $userId)->first()
                : $this->findContactByAddress($workspaceId, $from);

            if ($contact) {
                $contact->update(['phone_e164' => $newPhone]);
                Log::info('whatsapp.contact.number_changed', [
                    'contact_id' => $contact->id,
                    'phone_e164' => $newPhone,
                ]);
            }
        }
    }

    /** Find a contact by phone (E.164) or WhatsApp BSUID. */
    private function findContactByAddress(int $workspaceId, string $address): ?Contact
    {
        if ($address === '') {
            return null;
        }

        $query = Contact::where('workspace_id', $workspaceId);

        return str_contains($address, '.')
            ? $query->where('wa_user_id', $address)->first()
            : $query->where('phone_e164', '+'.ltrim($address, '+'))->first();
    }

    private function processStatusUpdate(array $status): void
    {
        $providerId = $status['id'] ?? null;
        $newStatus = $status['status'] ?? null;

        if (! $providerId || ! $newStatus) {
            return;
        }

        $statusMap = ['sent' => 'sent', 'delivered' => 'delivered', 'read' => 'read', 'failed' => 'failed'];
        $mapped = $statusMap[$newStatus] ?? null;
        if (! $mapped) {
            return;
        }

        // Status priority — never downgrade (e.g. delivered -> sent).
        $priority = ['queued' => 0, 'sent' => 1, 'delivered' => 2, 'read' => 3, 'failed' => 4];
        $newPriority = $priority[$mapped] ?? 0;

        // 1. Update inbox `messages` row for this wamid.
        $message = Message::where('provider_message_id', $providerId)->first();
        if ($message) {
            $current = $priority[$message->status] ?? 0;
            if ($newPriority >= $current) {
                $message->update(['status' => $mapped]);
                $message->load('conversation');
                MessageStatusUpdated::dispatch($message);
            }
        }

        // 2. Update campaign_recipients row for this wamid (separate table).
        $recipient = CampaignRecipient::where('provider_message_id', $providerId)->first();
        if ($recipient) {
            $current = $priority[$recipient->status] ?? 0;
            if ($newPriority < $current) {
                return;
            }

            $now = now();
            $patch = ['status' => $mapped];

            if ($mapped === 'sent' && ! $recipient->sent_at) {
                $patch['sent_at'] = $now;
            }
            if ($mapped === 'delivered') {
                if (! $recipient->sent_at) {
                    $patch['sent_at'] = $now;
                }
                if (! $recipient->delivered_at) {
                    $patch['delivered_at'] = $now;
                }
            }
            if ($mapped === 'read') {
                if (! $recipient->sent_at) {
                    $patch['sent_at'] = $now;
                }
                if (! $recipient->delivered_at) {
                    $patch['delivered_at'] = $now;
                }
                if (! $recipient->read_at) {
                    $patch['read_at'] = $now;
                }
            }
            if ($mapped === 'failed') {
                $patch['failed_reason'] = substr(
                    $status['errors'][0]['title']
                        ?? $status['errors'][0]['message']
                        ?? 'unknown',
                    0,
                    512,
                );
            }

            $recipient->update($patch);
        }
    }
}
