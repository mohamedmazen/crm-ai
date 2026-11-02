<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use Illuminate\Console\Command;

/**
 * Flags subscriptions on one-time gateways (e.g. Fawry) as past_due once their paid cycle
 * has elapsed.
 *
 * These gateways have no merchant-initiated recurring charge, so a subscription silently
 * stays 'active' after ends_at/renews_at unless something flips it. Access is already
 * blocked by ends_at (User::activeSubscription), but marking past_due keeps admin reporting
 * and dunning accurate. Gateways with scheduled renewals (Stripe/PayPal/Paymob/MyFatoorah/
 * Tap) are untouched.
 */
class FlagOverdueOnetimeSubscriptionsCommand extends Command
{
    /** Gateways that sell a single cycle and never auto-charge the customer. */
    private const ONE_TIME_GATEWAYS = ['fawry'];

    protected $signature = 'billing:flag-overdue-onetime {--dry-run : List overdue subscriptions without updating}';

    protected $description = 'Mark one-time gateway subscriptions past_due once their paid cycle has elapsed.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $due = Subscription::query()
            ->whereIn('gateway', self::ONE_TIME_GATEWAYS)
            ->whereIn('status', ['active', 'trialing'])
            ->where(function ($q) {
                $q->where(fn ($inner) => $inner->whereNotNull('ends_at')->where('ends_at', '<=', now()))
                    ->orWhere(fn ($inner) => $inner->whereNotNull('renews_at')->where('renews_at', '<=', now()));
            })
            ->get();

        $this->info("Found {$due->count()} overdue one-time subscription(s).");

        if ($dryRun) {
            foreach ($due as $sub) {
                $this->line("  [dry-run] Sub #{$sub->id} (gateway {$sub->gateway}, user {$sub->user_id})");
            }

            return self::SUCCESS;
        }

        foreach ($due as $sub) {
            $sub->update(['status' => 'past_due']);
        }

        $this->info("Marked {$due->count()} subscription(s) past_due.");

        return self::SUCCESS;
    }
}
