<?php

namespace App\Modules\Leads\Models;

use App\Models\User;
use App\Models\Workspace;
use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Company extends Model
{
    use HasFactory;

    public const SIZES = [
        '1-10' => '1-10 employees',
        '11-50' => '11-50 employees',
        '51-200' => '51-200 employees',
        '201-500' => '201-500 employees',
        '500+' => '500+ employees',
    ];

    public const INDUSTRIES = [
        'Technology & Software',
        'Marketing & Advertising',
        'Real Estate & Construction',
        'E-Commerce & Retail',
        'Healthcare & Medical',
        'Financial Services & Banking',
        'Education & Training',
        'Manufacturing & Logistics',
        'Consulting & Professional Services',
        'Hospitality & Tourism',
        'Other',
    ];

    protected $fillable = [
        'workspace_id',
        'name',
        'domain',
        'industry',
        'size',
        'phone',
        'website',
        'address',
        'owner_id',
    ];

    protected static function newFactory(): CompanyFactory
    {
        return CompanyFactory::new();
    }

    /**
     * Scope query to a specific workspace.
     */
    public function scopeForWorkspace(Builder $query, int $workspaceId): Builder
    {
        return $query->where('workspace_id', $workspaceId);
    }

    /**
     * Extract normalized domain from a URL or email address.
     */
    public static function extractDomain(?string $urlOrEmail): ?string
    {
        if (empty($urlOrEmail)) {
            return null;
        }

        $input = trim(strtolower($urlOrEmail));

        if (str_contains($input, '@')) {
            $domain = Str::after($input, '@');
        } else {
            if (! str_starts_with($input, 'http://') && ! str_starts_with($input, 'https://')) {
                $input = 'https://'.$input;
            }
            $host = parse_url($input, PHP_URL_HOST);
            $domain = $host ?: $input;
        }

        // Strip leading www.
        if (str_starts_with($domain, 'www.')) {
            $domain = substr($domain, 4);
        }

        return $domain ?: null;
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class, 'company_id');
    }
}
