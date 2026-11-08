<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlanPrice extends Model
{
    protected $fillable = [
        'plan_id',
        'currency_code',
        'monthly_price_cents',
        'yearly_price_cents',
    ];

    protected function casts(): array
    {
        return [
            'monthly_price_cents' => 'integer',
            'yearly_price_cents' => 'integer',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
