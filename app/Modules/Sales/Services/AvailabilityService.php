<?php

namespace App\Modules\Sales\Services;

use App\Models\User;
use App\Modules\Sales\Models\AvailabilityOverride;
use App\Modules\Sales\Models\AvailabilityRule;
use App\Modules\Sales\Models\Booking;
use App\Modules\Sales\Models\MeetingType;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class AvailabilityService
{
    /**
     * Calculate available booking time slots for a given meeting type on a specific date.
     *
     * @return array<int, array{time_utc: string, time_formatted: string, datetime_formatted: string}>
     */
    public function getAvailableSlots(MeetingType $meetingType, string $dateString, string $guestTimezone = 'UTC'): array
    {
        $host = $meetingType->user ?? User::where('workspace_id', $meetingType->workspace_id)->first();
        if (! $host) {
            return [];
        }

        $hostTimezone = $host->timezone ?: 'UTC';

        try {
            $targetDate = Carbon::parse($dateString, $hostTimezone)->startOfDay();
        } catch (\Throwable) {
            return [];
        }

        $today = Carbon::now($hostTimezone)->startOfDay();

        // 1. Cannot book in the past
        if ($targetDate->lt($today)) {
            return [];
        }

        // 2. Cannot book beyond booking window
        if ($targetDate->diffInDays($today) > $meetingType->booking_window_days) {
            return [];
        }

        // 3. Max bookings per day check
        if ($meetingType->max_per_day !== null && $meetingType->max_per_day > 0) {
            $dayStartUtc = $targetDate->copy()->utc();
            $dayEndUtc = $targetDate->copy()->endOfDay()->utc();

            $existingCount = Booking::where('meeting_type_id', $meetingType->id)
                ->where('status', Booking::STATUS_CONFIRMED)
                ->whereBetween('starts_at', [$dayStartUtc, $dayEndUtc])
                ->count();

            if ($existingCount >= $meetingType->max_per_day) {
                return [];
            }
        }

        // 4. Check for Specific Date Overrides
        $override = AvailabilityOverride::where('user_id', $host->id)
            ->where('date', $targetDate->format('Y-m-d'))
            ->first();

        $startTimeStr = null;
        $endTimeStr = null;

        if ($override) {
            if (! $override->available) {
                return []; // Day marked off
            }
            $startTimeStr = $override->start_time;
            $endTimeStr = $override->end_time;
        } else {
            // Check Weekly Rules
            $dayOfWeek = $targetDate->dayOfWeek; // 0=Sunday, 1=Monday ... 6=Saturday
            $rule = AvailabilityRule::where('user_id', $host->id)
                ->where('day_of_week', $dayOfWeek)
                ->first();

            if ($rule) {
                $startTimeStr = $rule->start_time;
                $endTimeStr = $rule->end_time;
            } else {
                // If user has not configured any rules yet, use standard default Mon-Fri (1-5) 09:00 - 17:00
                $hasAnyRules = AvailabilityRule::where('user_id', $host->id)->exists();
                if (! $hasAnyRules && in_array($dayOfWeek, [1, 2, 3, 4, 5], true)) {
                    $startTimeStr = '09:00:00';
                    $endTimeStr = '17:00:00';
                } else {
                    return [];
                }
            }
        }

        if (! $startTimeStr || ! $endTimeStr) {
            return [];
        }

        // 5. Generate candidate time slots
        $currentSlot = Carbon::parse($targetDate->format('Y-m-d') . ' ' . $startTimeStr, $hostTimezone);
        $dayEnd = Carbon::parse($targetDate->format('Y-m-d') . ' ' . $endTimeStr, $hostTimezone);
        $duration = $meetingType->duration_minutes;
        $bufferBefore = $meetingType->buffer_before;
        $bufferAfter = $meetingType->buffer_after;

        $nowUtc = Carbon::now('UTC');
        $minNoticeCutoff = $nowUtc->copy()->addHours($meetingType->min_notice_hours);

        $slots = [];

        // Fetch host's existing confirmed bookings for this day (with buffer leeway)
        $searchWindowStart = $currentSlot->copy()->utc()->subHours(2);
        $searchWindowEnd = $dayEnd->copy()->utc()->addHours(2);

        $existingBookings = Booking::where('host_user_id', $host->id)
            ->where('status', Booking::STATUS_CONFIRMED)
            ->where(function ($q) use ($searchWindowStart, $searchWindowEnd) {
                $q->whereBetween('starts_at', [$searchWindowStart, $searchWindowEnd])
                    ->orWhereBetween('ends_at', [$searchWindowStart, $searchWindowEnd]);
            })
            ->get();

        while ($currentSlot->copy()->addMinutes($duration)->lte($dayEnd)) {
            $slotStartUtc = $currentSlot->copy()->utc();
            $slotEndUtc = $currentSlot->copy()->addMinutes($duration)->utc();

            // Notice hours check
            if ($slotStartUtc->gt($minNoticeCutoff)) {
                // Buffer check against existing bookings
                $bufferedStart = $slotStartUtc->copy()->subMinutes($bufferBefore);
                $bufferedEnd = $slotEndUtc->copy()->addMinutes($bufferAfter);

                $collides = $existingBookings->contains(function (Booking $b) use ($bufferedStart, $bufferedEnd) {
                    return $b->starts_at->lt($bufferedEnd) && $b->ends_at->gt($bufferedStart);
                });

                if (! $collides) {
                    $guestSlot = $currentSlot->copy()->setTimezone($guestTimezone);
                    $slots[] = [
                        'time_utc' => $slotStartUtc->toIso8601String(),
                        'time_formatted' => $guestSlot->format('h:i A'),
                        'datetime_formatted' => $guestSlot->format('Y-m-d H:i:s'),
                    ];
                }
            }

            // Move to next slot
            $currentSlot->addMinutes($duration);
        }

        return $slots;
    }
}

