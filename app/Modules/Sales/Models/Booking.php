<?php

namespace App\Modules\Sales\Models;

use App\Models\User;
use App\Models\Workspace;
use App\Modules\Leads\Models\Lead;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Booking extends Model
{
    use HasFactory;

    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_NO_SHOW = 'no_show';

    protected $fillable = [
        'uuid',
        'workspace_id',
        'meeting_type_id',
        'lead_id',
        'host_user_id',
        'starts_at',
        'ends_at',
        'status',
        'meet_link',
        'cancel_token',
        'reschedule_token',
        'guest_name',
        'guest_email',
        'guest_phone',
        'guest_timezone',
        'answers',
        'cancelled_at',
        'cancel_reason',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'answers' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Booking $booking) {
            if (empty($booking->uuid)) {
                $booking->uuid = (string) Str::uuid();
            }
            if (empty($booking->cancel_token)) {
                $booking->cancel_token = Str::random(40);
            }
            if (empty($booking->reschedule_token)) {
                $booking->reschedule_token = Str::random(40);
            }
            if (empty($booking->meet_link)) {
                $booking->meet_link = 'https://meet.google.com/' . Str::lower(Str::random(3)) . '-' . Str::lower(Str::random(4)) . '-' . Str::lower(Str::random(3));
            }
        });
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function meetingType(): BelongsTo
    {
        return $this->belongsTo(MeetingType::class);
    }

    public function host(): BelongsTo
    {
        return $this->belongsTo(User::class, 'host_user_id');
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }
}

