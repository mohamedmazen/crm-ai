<?php

namespace App\Console\Commands;

use App\Modules\Sales\Models\LeadSource;
use App\Modules\Sales\Services\ImapLeadParser;
use Illuminate\Console\Command;

class FetchImapLeadsCommand extends Command
{
    protected $signature = 'leads:fetch-imap {--workspace= : Optional workspace ID filter}';

    protected $description = 'Poll configured IMAP lead sources for new incoming email inquiries and convert them to leads.';

    public function handle(ImapLeadParser $parser): int
    {
        $workspaceId = $this->option('workspace');

        $query = LeadSource::where('type', LeadSource::TYPE_IMAP)
            ->where('status', LeadSource::STATUS_ACTIVE);

        if ($workspaceId) {
            $query->where('workspace_id', (int) $workspaceId);
        }

        $sources = $query->get();

        if ($sources->isEmpty()) {
            $this->info('No active IMAP lead sources found.');

            return self::SUCCESS;
        }

        $this->info("Found {$sources->count()} active IMAP lead source(s). Polling inboxes...");

        foreach ($sources as $source) {
            $config = $source->config ?? [];
            $host = $config['host'] ?? null;
            $username = $config['username'] ?? null;
            $password = $config['password'] ?? null;

            if (! $host || ! $username || ! $password) {
                $this->warn("Skipping source #{$source->id} ({$source->name}): incomplete credentials.");

                continue;
            }

            // If PHP IMAP extension is installed, fetch unread emails
            if (function_exists('imap_open')) {
                $port = $config['port'] ?? 993;
                $encryption = $config['encryption'] ?? 'ssl';
                $mailboxStr = sprintf('{%s:%d/imap/%s}INBOX', $host, $port, $encryption);

                $inbox = @imap_open($mailboxStr, $username, $password);
                if (! $inbox) {
                    $error = imap_last_error();
                    $source->update(['last_error' => $error]);
                    $this->error("Failed to connect to IMAP for {$source->name}: {$error}");

                    continue;
                }

                $emails = imap_search($inbox, 'UNSEEN');
                $count = 0;

                if ($emails) {
                    foreach ($emails as $emailNumber) {
                        $header = imap_headerinfo($inbox, $emailNumber);

                        // A message without a parseable sender cannot become a
                        // lead; skip it rather than writing a "null@null" lead.
                        if (! $header || empty($header->from[0]->mailbox) || empty($header->from[0]->host)) {
                            $this->warn("Skipping message #{$emailNumber} on {$source->name}: no sender address.");

                            continue;
                        }

                        $body = imap_body($inbox, $emailNumber);

                        $fromEmail = $header->from[0]->mailbox.'@'.$header->from[0]->host;
                        $fromName = $header->from[0]->personal ?? '';
                        $subject = $header->subject ?? 'New Inquiry';

                        try {
                            $parser->ingest($source->workspace_id, [
                                'body' => $body,
                                'subject' => $subject,
                                'from_email' => $fromEmail,
                                'from_name' => $fromName,
                                'message_id' => $header->message_id ?? null,
                            ], $source);

                            // Mark seen only after a successful ingest so a
                            // transient failure is retried on the next poll.
                            imap_setflag_full($inbox, (string) $emailNumber, '\\Seen');
                            $count++;
                        } catch (\Throwable $e) {
                            $source->update(['last_error' => $e->getMessage()]);
                            $this->error("Failed to ingest message #{$emailNumber} on {$source->name}: {$e->getMessage()}");
                        }
                    }
                }

                imap_close($inbox);
                $this->info("Processed {$count} email(s) from {$source->name}.");
            } else {
                $this->line("IMAP extension not loaded in PHP CLI for source {$source->name}. Ready for simulated or external ingestion.");
            }
        }

        return self::SUCCESS;
    }
}
