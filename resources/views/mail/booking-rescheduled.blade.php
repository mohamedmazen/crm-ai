<x-mail::message>
# {{ __('Rescheduled: :meeting', ['meeting' => $booking->meetingType?->name ?? __('your meeting')]) }}

{{ __('Hi :name, your meeting has been moved to a new time.', ['name' => $booking->guest_name ?? __('there')]) }}

**{{ __('When') }}:** {{ $booking->starts_at->toDayDateTimeString() }} ({{ $booking->guest_timezone }})

@if ($booking->meet_link)
**{{ __('Join') }}:** [{{ $booking->meet_link }}]({{ $booking->meet_link }})
@endif

{{ __('Hosted by :name.', ['name' => $booking->host?->name ?? config('app.name')]) }}

<x-mail::button :url="$booking->meet_link ?: config('app.url')">
{{ __('Join the meeting') }}
</x-mail::button>

{{ __('If you need to change it again, use one of these links:') }}

- [{{ __('Reschedule this meeting') }}]({{ route('booking.public.reschedule.show', $booking->reschedule_token) }})
- [{{ __('Cancel this meeting') }}]({{ route('booking.public.cancel.show', $booking->cancel_token) }})

{{ __('See you soon!') }}
</x-mail::message>
