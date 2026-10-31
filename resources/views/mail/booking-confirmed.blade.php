<x-mail::message>
# {{ __('Your booking is confirmed') }}

{{ __('Hi :name, your :meeting is booked. Here are the details.', ['name' => $booking->guest_name ?? __('there'), 'meeting' => $booking->meetingType?->name ?? __('meeting')]) }}

**{{ __('When') }}:** {{ $booking->starts_at->toDayDateTimeString() }} ({{ $booking->guest_timezone }})

@if ($booking->meet_link)
**{{ __('Join') }}:** [{{ $booking->meet_link }}]({{ $booking->meet_link }})
@endif

{{ __('Hosted by :name.', ['name' => $booking->host?->name ?? config('app.name')]) }}

<x-mail::button :url="$booking->meet_link ?: config('app.url')">
{{ __('Join the meeting') }}
</x-mail::button>

{{ __('Need to make a change?') }}

- [{{ __('Reschedule this meeting') }}]({{ route('booking.public.reschedule.show', $booking->reschedule_token) }})
- [{{ __('Cancel this meeting') }}]({{ route('booking.public.cancel.show', $booking->cancel_token) }})

{{ __('See you soon!') }}
</x-mail::message>
