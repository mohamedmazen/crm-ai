<?php

namespace App\Modules\Sales\Services;

use App\Events\MessageSent;
use App\Models\SmtpConfiguration;
use App\Modules\Broadcasting\Models\WorkspaceSmtpConfig;
use App\Modules\Leads\Models\Lead;
use App\Modules\Sales\Models\Sequence;
use App\Modules\Sales\Models\SequenceEnrollment;
use App\Modules\Sales\Models\SequenceStep;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use App\Modules\Whatsapp\Services\CloudApiClient;
use App\Services\Mail\MailService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Delivers one sequence step over its channel and mirrors the outbound message
 * into the inbox so agents see it and replies land in the same conversation.
 *
 * Kept separate from {@see SequenceEngine} so the delivery mechanics (SMTP,
 * WhatsApp, personalisation) never leak into the scheduling/stop logic.
 */
class SequenceMessageSender
{
    /**
     * @return string|null Provider message id when the channel returns one.
     *
     * @throws \RuntimeException when the lead cannot be reached or the provider fails
     */
    public function send(Sequence $sequence, SequenceStep $step, SequenceEnrollment $enrollment): ?string
    {
        $lead = $enrollment->lead;

        if (! $lead) {
            throw new \RuntimeException('The enrolled lead no longer exists.');
        }

        $contact = $lead->contact;

        if ($contact && ! $this->isOptedIn($contact, $step->channel)) {
            throw new SequenceRecipientUnavailable('Lead opted out of '.$step->channel.'.', 'opted_out');
        }

        return $step->channel === Sequence::CHANNEL_WHATSAPP
            ? $this->sendWhatsApp($sequence, $step, $lead, $contact)
            : $this->sendEmail($sequence, $step, $lead, $contact, $enrollment);
    }

    private function isOptedIn(Contact $contact, string $channel): bool
    {
        return match ($channel) {
            Sequence::CHANNEL_EMAIL => (bool) $contact->opt_in_email,
            Sequence::CHANNEL_WHATSAPP => (bool) $contact->opt_in_whatsapp,
            default => false,
        };
    }

    private function sendWhatsApp(Sequence $sequence, SequenceStep $step, Lead $lead, ?Contact $contact): ?string
    {
        $phone = $contact?->phone_e164 ?: $lead->phone;
        if (! $phone) {
            throw new SequenceRecipientUnavailable('Lead has no phone number.', 'no_recipient');
        }

        $client = CloudApiClient::forWorkspace($sequence->workspace_id);
        if (! $client) {
            throw new \RuntimeException('No WhatsApp account is configured for this workspace.');
        }

        $to = str_starts_with($phone, '+') ? $phone : '+'.$phone;
        $body = $this->render($step->body, $lead, $contact);

        $response = $client->sendText($to, $body);
        if (! $response->successful()) {
            throw new \RuntimeException('WhatsApp send failed: '.($response->json('error.message') ?? $response->body()));
        }

        $messageId = (string) $response->json('messages.0.id', '');

        $this->mirrorToInbox(
            workspaceId: (int) $sequence->workspace_id,
            channel: Sequence::CHANNEL_WHATSAPP,
            contact: $contact,
            body: $body,
            payload: ['body' => $body],
            providerMessageId: $messageId !== '' ? $messageId : null,
        );

        return $messageId !== '' ? $messageId : null;
    }

    private function sendEmail(
        Sequence $sequence,
        SequenceStep $step,
        Lead $lead,
        ?Contact $contact,
        SequenceEnrollment $enrollment,
    ): ?string {
        $to = $contact?->email ?: $lead->email;
        if (! $to) {
            throw new SequenceRecipientUnavailable('Lead has no email address.', 'no_recipient');
        }

        $subject = trim($this->render($step->subject ?? '', $lead, $contact));
        $subject = $subject !== '' ? $subject : $sequence->name;

        $unsubscribeUrl = URL::signedRoute('sequences.unsubscribe', ['enrollment' => $enrollment->id]);
        $context = ['unsubscribe_url' => $unsubscribeUrl];

        $body = $this->render($step->body, $lead, $contact, $context);
        $body = $this->appendUnsubscribeFooter($body, $unsubscribeUrl);

        $smtp = WorkspaceSmtpConfig::forWorkspace($sequence->workspace_id)
            ?? SmtpConfiguration::getActive();

        $headers = [
            'List-Unsubscribe' => '<'.$unsubscribeUrl.'>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ];

        if ($smtp) {
            app(MailService::class)->sendRaw($smtp, $to, $subject, $body, $headers);
        } else {
            Mail::html($body, function ($message) use ($to, $subject, $headers) {
                $message->to($to)->subject($subject);
                foreach ($headers as $name => $value) {
                    $message->getHeaders()->addTextHeader($name, $value);
                }
            });
        }

        $messageId = 'email:'.uniqid();

        $this->mirrorToInbox(
            workspaceId: (int) $sequence->workspace_id,
            channel: Sequence::CHANNEL_EMAIL,
            contact: $contact,
            body: $subject,
            payload: ['subject' => $subject, 'body' => $body],
            providerMessageId: $messageId,
        );

        return $messageId;
    }

    /**
     * Replace sequence tokens with the lead's / linked contact's values.
     *
     * @param  array<string, string>  $extra
     */
    private function render(string $text, Lead $lead, ?Contact $contact, array $extra = []): string
    {
        $name = $contact?->full_name ?: (string) $lead->name;
        $firstName = $contact?->first_name ?: (Str::before($name, ' ') ?: $name);

        $tokens = [
            'contact.name' => $name,
            'contact.first_name' => $firstName,
            'contact.email' => (string) ($contact?->email ?: $lead->email),
            'contact.phone' => (string) ($contact?->phone_e164 ?: $lead->phone),
            'lead.name' => (string) $lead->name,
            'lead.email' => (string) $lead->email,
            'lead.phone' => (string) $lead->phone,
        ];

        foreach ($extra as $key => $value) {
            $tokens[$key] = $value;
        }

        return preg_replace_callback('/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/', function (array $matches) use ($tokens) {
            return $tokens[$matches[1]] ?? $matches[0];
        }, $text) ?? $text;
    }

    private function appendUnsubscribeFooter(string $body, string $unsubscribeUrl): string
    {
        if (str_contains($body, $unsubscribeUrl)) {
            return $body;
        }

        $footer = '<div style="margin-top:32px;padding-top:16px;border-top:1px solid #e5e7eb;'
            .'text-align:center;font-family:sans-serif;font-size:12px;color:#6b7280;">'
            .'<a href="'.e($unsubscribeUrl).'" style="color:#6b7280;text-decoration:underline;">Unsubscribe</a>'
            .'</div>';

        return str_contains($body, '</body>')
            ? str_replace('</body>', $footer.'</body>', $body)
            : $body.$footer;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function mirrorToInbox(
        int $workspaceId,
        string $channel,
        ?Contact $contact,
        string $body,
        array $payload,
        ?string $providerMessageId,
    ): void {
        if (! $contact) {
            return; // Lead has not been pushed to contacts yet — nothing to thread.
        }

        try {
            $externalThreadId = $channel === Sequence::CHANNEL_WHATSAPP
                ? ltrim((string) $contact->phone_e164, '+')
                : (string) $contact->email;

            $conversation = Conversation::firstOrCreate(
                [
                    'workspace_id' => $workspaceId,
                    'contact_id' => $contact->id,
                    'channel_account_id' => $this->resolveChannelAccount($workspaceId, $channel)?->id,
                ],
                [
                    'status' => 'open',
                    'external_thread_id' => $externalThreadId,
                ],
            );

            $sentAt = now();

            $message = Message::create([
                'conversation_id' => $conversation->id,
                'direction' => 'out',
                'channel' => $channel,
                'type' => 'text',
                'payload' => $payload,
                'body' => $body,
                'status' => 'sent',
                'provider_message_id' => $providerMessageId,
                'sent_by' => 'automation',
                'sent_at' => $sentAt,
            ]);

            $conversation->update(['last_message_at' => $sentAt]);

            MessageSent::dispatch($message);
        } catch (\Throwable $e) {
            // The provider send already succeeded; never fail the sequence because
            // the inbox mirror could not be written.
            Log::channel('json')->warning('sequence.inbox.sync_failed', [
                'workspace_id' => $workspaceId,
                'channel' => $channel,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function resolveChannelAccount(int $workspaceId, string $channel): ?ChannelAccount
    {
        return ChannelAccount::where('workspace_id', $workspaceId)
            ->where('channel', $channel)
            ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")
            ->orderBy('id')
            ->first();
    }
}
