<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'price_cents',
        'currency_code',
        'interval',
        'sort_order',
        'enabled',
        'monthly_price_cents',
        'yearly_price_cents',
        'trial_days',
        'stripe_monthly_id',
        'stripe_yearly_id',
        'paddle_monthly_id',
        'paddle_yearly_id',
        'features',
        'limits',
        'feature_flags',
        'featured',
        'popular',
        'white_label_enabled',
    ];

    protected function casts(): array
    {
        return [
            'price_cents' => 'integer',
            'sort_order' => 'integer',
            'enabled' => 'boolean',
            'monthly_price_cents' => 'integer',
            'yearly_price_cents' => 'integer',
            'trial_days' => 'integer',
            'features' => 'array',
            'limits' => 'array',
            'feature_flags' => 'array',
            'featured' => 'boolean',
            'popular' => 'boolean',
            'white_label_enabled' => 'boolean',
        ];
    }

    /**
     * Price in cents for the given billing cycle.
     */
    public function priceCentsForCycle(string $cycle): ?int
    {
        return match ($cycle) {
            'month' => $this->monthly_price_cents ?? $this->price_cents,
            'year' => $this->yearly_price_cents,
            default => null,
        };
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_code', 'code');
    }

    /**
     * Whether the plan unlocks the given feature gate.
     *
     * A flag of null means the feature is enabled without a limit (for example
     * `custom_fields => null` on Business). A numeric flag is both a switch and
     * a cap: 0 disables the feature, a positive number enables it. Flags live in
     * `feature_flags`; `white_label_enabled` remains the fallback for installs
     * that predate the JSON column.
     */
    public function hasFeature(string $feature): bool
    {
        $flags = $this->feature_flags;

        if (is_array($flags) && array_key_exists($feature, $flags)) {
            return $this->flagIsEnabled($flags[$feature]);
        }

        return $feature === 'white_label' && (bool) $this->white_label_enabled;
    }

    /**
     * The numeric cap attached to a feature gate, or null when the flag is
     * missing or unlimited.
     */
    public function featureLimit(string $feature): ?int
    {
        $flags = $this->feature_flags;
        $value = is_array($flags) ? ($flags[$feature] ?? null) : null;

        return is_numeric($value) ? (int) $value : null;
    }

    private function flagIsEnabled(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value > 0;
        }

        return (bool) $value;
    }

    /**
     * Value from the plan's JSON limits column. Not named `limit` — that is the query builder.
     */
    public function limitValue(string $key): mixed
    {
        $limits = $this->limits;

        return is_array($limits) ? ($limits[$key] ?? null) : null;
    }

    public function clientSubscriptions(): HasMany
    {
        return $this->hasMany(ClientSubscription::class, 'plan_id');
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class, 'plan_id');
    }
}
