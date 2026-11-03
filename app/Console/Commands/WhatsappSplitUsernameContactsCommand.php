<?php

namespace App\Console\Commands;

use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use Illuminate\Console\Command;

/**
 * Repairs inbox data written before WhatsApp usernames/BSUIDs were supported.
 *
 * Older inbound handling stored `'+' . $fromPhone`; when a user hid their phone
 * number, `from` was empty and every such sender collapsed into a single contact
 * with phone_e164 = "+" (and therefore a single shared conversation). This command
 * groups those messages by their real sender identity
 * (`payload.from_user_id` or `payload.from`) and moves each group onto its own
 * contact/conversation. Idempotent: run it as often as needed.
 */
class WhatsappSplitUsernameContactsCommand extends Command
{
    protected $signature = 'whatsapp:split-username-contacts
                            {--dry-run : Report what would change without writing}
                            {--workspace= : Only repair a single workspace id}';

    protected $description = 'Split contacts merged under the placeholder phone "+" by WhatsApp BSUID/phone';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $query = Contact::where('phone_e164', '+');

        if ($workspaceId = $this->option('workspace')) {
            $query->where('workspace_id', (int) $workspaceId);
        }

        $placeholders = $query->get();

        if ($placeholders->isEmpty()) {
            $this->info('No merged WhatsApp contacts (phone_e164 = "+") found.');

            return self::SUCCESS;
        }

        $stats = ['contacts' => 0, 'conversations' => 0, 'messages' => 0, 'skipped' => 0];

        foreach ($placeholders as $placeholder) {
            $result = $this->splitContact($placeholder, $dryRun);
            foreach ($stats as $key => $value) {
                $stats[$key] = $value + $result[$key];
            }
        }

        $this->info(sprintf(
            '%s %d merged contact(s): %d new contact(s), %d conversation(s), %d message(s)%s.',
            $dryRun ? 'Would split' : 'Split',
            $placeholders->count(),
            $stats['contacts'],
            $stats['conversations'],
            $stats['messages'],
            $stats['skipped'] > 0 ? ", {$stats['skipped']} message(s) left untouched (no sender identity)" : '',
        ));

        if ($dryRun) {
            $this->comment('Dry run — nothing was written.');
        }

        return self::SUCCESS;
    }

    /**
     * @return array{contacts: int, conversations: int, messages: int, skipped: int}
     */
    private function splitContact(Contact $placeholder, bool $dryRun): array
    {
        $conversations = Conversation::where('contact_id', $placeholder->id)->get();
        $messages = Message::with('conversation')
            ->whereIn('conversation_id', $conversations->pluck('id'))
            ->orderBy('sent_at')
            ->get();

        $groups = $messages->groupBy(function (Message $message) {
            $payload = $message->payload ?? [];

            return (string) ($payload['from_user_id'] ?? $payload['from'] ?? '');
        });

        $stats = ['contacts' => 0, 'conversations' => 0, 'messages' => 0, 'skipped' => 0];
        $touchedConversations = [];

        foreach ($groups as $identity => $group) {
            $address = (string) $identity;

            // A message we cannot attribute to a sender stays where it is.
            if ($address === '') {
                $stats['skipped'] += $group->count();

                continue;
            }

            $isBsuid = str_contains($address, '.');
            $phone = $isBsuid ? null : '+'.ltrim($address, '+');

            $target = $isBsuid
                ? Contact::where('workspace_id', $placeholder->workspace_id)->where('wa_user_id', $address)->first()
                : Contact::where('workspace_id', $placeholder->workspace_id)->where('phone_e164', $phone)->first();

            if (! $target) {
                $stats['contacts']++;

                if (! $dryRun) {
                    $target = Contact::create(array_filter([
                        'workspace_id' => $placeholder->workspace_id,
                        'wa_user_id' => $isBsuid ? $address : null,
                        'phone_e164' => $phone,
                        'opt_in_whatsapp' => $placeholder->opt_in_whatsapp,
                        'source' => 'whatsapp_inbound',
                    ], fn ($value) => $value !== null));
                }
            }

            if ($dryRun) {
                $stats['messages'] += $group->count();
                $stats['conversations'] += $group->pluck('conversation_id')->unique()->count();

                continue;
            }

            // One conversation per (contact, channel account): keep replies on the
            // same WhatsApp number they arrived on.
            $byChannel = $group->groupBy(
                fn (Message $message) => (string) ($message->conversation?->channel_account_id ?? '')
            );

            foreach ($byChannel as $channelMessages) {
                $sourceConversation = $channelMessages->first()->conversation;

                $newConversation = Conversation::firstOrCreate(
                    [
                        'workspace_id' => $placeholder->workspace_id,
                        'contact_id' => $target->id,
                        'channel_account_id' => $sourceConversation?->channel_account_id,
                    ],
                    [
                        'status' => 'open',
                        'external_thread_id' => $address,
                    ]
                );

                Message::whereIn('id', $channelMessages->pluck('id'))
                    ->update(['conversation_id' => $newConversation->id]);

                $stats['messages'] += $channelMessages->count();
                $stats['conversations']++;
                $touchedConversations[] = $newConversation->id;
                $touchedConversations[] = $sourceConversation?->id;
            }
        }

        if ($dryRun) {
            return $stats;
        }

        // Recompute cached counters for every conversation the move touched.
        foreach (array_filter(array_unique($touchedConversations)) as $conversationId) {
            $this->refreshConversation((int) $conversationId);
        }

        // Remove conversations left empty, then soft-delete the placeholder so it
        // no longer appears in the inbox (kept recoverable).
        foreach ($conversations as $conversation) {
            if (! $conversation->messages()->exists()) {
                $conversation->delete();
            }
        }

        if (! $placeholder->conversations()->exists()) {
            $placeholder->delete();
        }

        return $stats;
    }

    private function refreshConversation(int $conversationId): void
    {
        $messages = Message::where('conversation_id', $conversationId)->get();

        if ($messages->isEmpty()) {
            return;
        }

        $inbound = $messages->where('direction', 'in');

        Conversation::whereKey($conversationId)->update([
            'last_message_at' => $messages->max('sent_at'),
            'last_inbound_at' => $inbound->max('sent_at'),
            'unread_count' => $inbound->count(),
        ]);
    }
}
