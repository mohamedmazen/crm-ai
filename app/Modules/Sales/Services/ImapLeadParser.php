<?php

namespace App\Modules\Sales\Services;

use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\LeadActivity;
use App\Modules\Sales\Models\LeadSource;

class ImapLeadParser
{
    public function __construct(
        private readonly LeadAutoCaptureService $autoCaptureService,
    ) {}

    /**
     * Parse unstructured or structured inquiry email content into lead fields.
     *
     * @return array{name: ?string, email: ?string, phone: ?string, subject: string, body: string}
     */
    public function parseEmail(string $body, string $subject = '', string $fromEmail = '', string $fromName = ''): array
    {
        $name = ! empty($fromName) ? trim($fromName) : null;
        $email = ! empty($fromEmail) ? trim($fromEmail) : null;
        $phone = null;

        $lines = preg_split("/\r\n|\n|\r/", $body);

        foreach ($lines as $line) {
            $line = trim($line);

            // Match "Name: John Doe" or "Full Name: John Doe"
            if (preg_match('/^(?:name|full\s*name|contact\s*name)\s*:\s*(.+)$/i', $line, $matches)) {
                $name = trim($matches[1]);
            }

            // Match "Email: john@example.com"
            if (preg_match('/^(?:email|e-mail)\s*:\s*([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})$/i', $line, $matches)) {
                $email = trim($matches[1]);
            }

            // Match "Phone: +123456789" or "Mobile: ..." or "Tel: ..."
            if (preg_match('/^(?:phone|telephone|mobile|tel|whatsapp)\s*:\s*([\+0-9\s\-\(\).]{7,25})$/i', $line, $matches)) {
                $phone = trim($matches[1]);
            }
        }

        // If phone not explicitly labeled, search body for international / local phone pattern
        if (! $phone && preg_match('/(?:\+|00)[1-9][0-9\s\-\(\)]{7,18}[0-9]/', $body, $phoneMatches)) {
            $phone = trim($phoneMatches[0]);
        }

        return [
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'subject' => $subject,
            'body' => $body,
        ];
    }

    /**
     * Ingest an email into a Lead.
     *
     * Guarded by the RFC Message-ID: the scheduled poller may see the same
     * message more than once (a crashed run, or a mailbox that stays UNSEEN),
     * and re-ingesting it would create a duplicate lead every five minutes.
     *
     * @param  array<string, mixed>  $emailData
     */
    public function ingest(int $workspaceId, array $emailData, ?LeadSource $source = null): Lead
    {
        $messageId = $emailData['message_id'] ?? null;

        if (! empty($messageId) && ($existing = $this->findLeadByMessageId($workspaceId, $messageId))) {
            return $existing;
        }

        $parsed = $this->parseEmail(
            $emailData['body'] ?? '',
            $emailData['subject'] ?? '',
            $emailData['from_email'] ?? '',
            $emailData['from_name'] ?? ''
        );

        // Carry the Message-ID through so captureFromEmail records it and the
        // next poll recognises the message as already ingested.
        $parsed['message_id'] = $messageId;

        return $this->autoCaptureService->captureFromEmail($workspaceId, $parsed, $source);
    }

    private function findLeadByMessageId(int $workspaceId, string $messageId): ?Lead
    {
        $activity = LeadActivity::where('workspace_id', $workspaceId)
            ->where('meta->message_id', $messageId)
            ->latest('id')
            ->first();

        return $activity?->lead;
    }
}
