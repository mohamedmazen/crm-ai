<?php

namespace App\Modules\Sales\Models;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class MeetingType extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const LOCATION_GOOGLE_MEET = 'google_meet';

    public const LOCATION_PHONE = 'phone';

    public const LOCATION_IN_PERSON = 'in_person';

    public const LOCATION_CUSTOM = 'custom';

    protected $fillable = [
        'uuid',
        'workspace_id',
        'user_id',
        'name',
        'slug',
        'description',
        'duration_minutes',
        'buffer_before',
        'buffer_after',
        'location_type',
        'location_details',
        'max_per_day',
        'min_notice_hours',
        'booking_window_days',
        'color',
        'questions',
        'status',
    ];

    protected $appends = [
        'public_url',
    ];

    protected function casts(): array
    {
        return [
            'duration_minutes' => 'integer',
            'buffer_before' => 'integer',
            'buffer_after' => 'integer',
            'max_per_day' => 'integer',
            'min_notice_hours' => 'integer',
            'booking_window_days' => 'integer',
            'questions' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (MeetingType $meetingType) {
            if (empty($meetingType->uuid)) {
                $meetingType->uuid = (string) Str::uuid();
            }
            if (empty($meetingType->slug)) {
                $base = Str::slug($meetingType->name);
                $slug = $base;
                $counter = 1;
                while (self::where('slug', $slug)->exists()) {
                    $slug = $base.'-'.$counter++;
                }
                $meetingType->slug = $slug;
            }
        });
    }

    public function getPublicUrlAttribute(): string
    {
        return url('/b/'.$this->slug);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }
}
