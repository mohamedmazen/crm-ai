<?php

namespace App\Modules\Ecommerce\Jobs;

use App\Modules\Broadcasting\Models\UsageMeter;
use App\Modules\Ecommerce\Models\EcommerceStore;
use App\Modules\Ecommerce\Services\Clients\StoreClientFactory;
use App\Modules\Ecommerce\Services\ContactCapacity;
use App\Modules\Ecommerce\Services\ContactEnricher;
use App\Modules\Shared\Services\ContactService;
use App\Support\Queue\Middleware\LimitTenantConcurrency;
use App\Support\Queue\TenantAwareJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Imports store customers into Contacts, one page per job invocation, chaining
 * the next page via re-dispatch to stay within queue timeouts. After the final
 * page, kicks off the order backfill (which recomputes enrichment metrics).
 */
class SyncStoreCustomersJob implements ShouldQueue, TenantAwareJob
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    /** Backoff (seconds) between retries — rides out rate limits / transient errors. */
    public array $backoff = [30, 120, 300];

    /**
     * Owning workspace; null only for jobs queued before this field existed.
     *
     * Declared outside the constructor so legacy payloads restore the default
     * instead of leaving a promoted property uninitialized.
     */
    public ?int $workspaceId = null;

    public function __construct(
        public readonly int $storeId,
        public readonly ?string $cursor = null,
        ?int $workspaceId = null,
    ) {
        $this->workspaceId = $workspaceId;
    }

    public function tenantWorkspaceId(): ?int
    {
        return $this->workspaceId ?? EcommerceStore::find($this->storeId)?->workspace_id;
    }

    /**
     * Contact import competes with product/order syncs for the same store API;
     * one page per workspace at a time keeps the budget predictable.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            new LimitTenantConcurrency(
                maxConcurrent: (int) config('queue.tenant_limits.concurrent_ecommerce_jobs', 1),
                bucket: 'ecommerce',
                lockSeconds: 180,
            ),
        ];
    }

    public function handle(ContactService $contacts, ContactEnricher $enricher, ContactCapacity $capacity): void
    {
        $store = EcommerceStore::find($this->storeId);
        if (! $store) {
            return;
        }

        // Respect the plan's optional contact cap so a large store can't balloon
        // the contacts table past plan limits. null = unlimited.
        $remaining = $capacity->remaining($store->workspace_id);
        if ($remaining !== null && $remaining <= 0) {
            Log::warning('ecommerce.sync.contact_limit_reached', ['store' => $store->id, 'workspace' => $store->workspace_id]);
            $store->update(['customers_synced_at' => now()]);
            BackfillStoreOrdersJob::dispatch($store->id, null, $this->workspaceId);

            return;
        }

        $page = StoreClientFactory::for($store)->fetchCustomers($this->cursor);
        $imported = 0;

        foreach ($page['customers'] as $customer) {
            if ($remaining !== null && $remaining <= 0) {
                break;
            }

            $data = $this->mapCustomer($store->platform, $customer);
            if (empty($data['email']) && empty($data['phone_e164'])) {
                continue;
            }

            $contact = $contacts->upsert($store->workspace_id, array_filter([
                'phone_e164' => $data['phone_e164'],
                'email' => $data['email'],
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'source' => $store->platform,
            ], fn ($v) => $v !== null && $v !== ''), dispatchCreatedEvent: false);

            $enricher->markAsCustomer($contact, $store, $data['external_id']);
            $imported++;
            if ($remaining !== null) {
                $remaining--;
            }
        }

        if ($imported > 0) {
            UsageMeter::track($store->workspace_id, 'contacts', $imported);
        }

        // Stop chaining if the cap was hit this page, or guard against a repeating cursor.
        $capReached = $remaining !== null && $remaining <= 0;
        if (! $capReached && $page['next'] !== null && $page['next'] !== $this->cursor) {
            self::dispatch($store->id, $page['next'], $this->workspaceId);

            return;
        }

        $store->update(['customers_synced_at' => now()]);
        BackfillStoreOrdersJob::dispatch($store->id, null, $this->workspaceId);
    }

    /**
     * @param  array<string, mixed>  $c
     * @return array{external_id: string, email: ?string, phone_e164: ?string, first_name: ?string, last_name: ?string}
     */
    private function mapCustomer(string $platform, array $c): array
    {
        if ($platform === 'woocommerce') {
            $billing = $c['billing'] ?? [];

            return [
                'external_id' => (string) ($c['id'] ?? ''),
                'email' => $c['email'] ?? ($billing['email'] ?? null),
                'phone_e164' => $this->phone($billing['phone'] ?? null),
                'first_name' => $c['first_name'] ?? ($billing['first_name'] ?? null),
                'last_name' => $c['last_name'] ?? ($billing['last_name'] ?? null),
            ];
        }

        // Shopify and BigCommerce v3 customers share a flat {id,email,first_name,last_name,phone} shape.
        return [
            'external_id' => (string) ($c['id'] ?? ''),
            'email' => $c['email'] ?? null,
            'phone_e164' => $this->phone($c['phone'] ?? null),
            'first_name' => $c['first_name'] ?? null,
            'last_name' => $c['last_name'] ?? null,
        ];
    }

    private function phone(?string $raw): ?string
    {
        if (! $raw) {
            return null;
        }
        $plus = str_starts_with(trim($raw), '+');
        $digits = preg_replace('/\D+/', '', $raw);

        return $digits === '' ? null : ($plus ? '+' : '').$digits;
    }
}
