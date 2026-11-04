<?php

namespace App\Modules\Whatsapp\Console;

use App\Modules\Whatsapp\Models\WhatsappBusinessAccount;
use App\Modules\Whatsapp\Services\WhatsappConnectionHealth;
use App\Modules\Whatsapp\Services\WhatsappTokenInspector;
use Illuminate\Console\Command;

class WhatsappCheckConnectionHealthCommand extends Command
{
    protected $signature = 'whatsapp:check-connection-health
                            {--workspace= : Only check accounts in this workspace id}';

    protected $description = 'Inspect WhatsApp access tokens with Meta and flag expiring or invalid connections.';

    public function handle(WhatsappTokenInspector $inspector): int
    {
        $checked = 0;
        $invalid = 0;

        WhatsappBusinessAccount::query()
            ->when($this->option('workspace'), fn ($query, $workspaceId) => $query->where('workspace_id', $workspaceId))
            ->orderBy('id')
            ->chunkById(100, function ($wabas) use ($inspector, &$checked, &$invalid) {
                foreach ($wabas as $waba) {
                    $checked++;

                    $result = $inspector->inspect($waba);

                    if ($result === null) {
                        $waba->forceFill(['token_checked_at' => now()])->save();

                        continue;
                    }

                    $patch = ['token_checked_at' => now()];

                    if ($result['expires_at'] > 0) {
                        $patch['token_expires_at'] = WhatsappConnectionHealth::expiresAtFromMeta($result['expires_at']);
                    }

                    if ($result['valid']) {
                        $patch['status'] = $waba->status === 'error' ? 'active' : $waba->status;
                        $patch['last_error_at'] = null;
                        $patch['last_error_code'] = null;
                        $patch['last_error_message'] = null;
                    } else {
                        $invalid++;
                        $patch['status'] = 'error';
                        $patch['last_error_at'] = now();
                        $patch['last_error_code'] = 'token_invalid';
                        $patch['last_error_message'] = $result['error']
                            ?? 'Meta reports this access token is no longer valid.';
                    }

                    $waba->forceFill($patch)->save();
                }
            });

        $this->info("Checked {$checked} WhatsApp account(s); {$invalid} invalid token(s).");

        return self::SUCCESS;
    }
}
