<x-mail::message>
# {{ __('Quote :number', ['number' => $quote->number]) }}

{{ __('Hi :name, please find your quote below.', ['name' => $quote->lead?->name ?? __('there')]) }}

**{{ __('Total') }}:** {{ number_format((float) $quote->total, 2) }} {{ $quote->currency ?? '' }}

@if ($quote->valid_until)
**{{ __('Valid until') }}:** {{ $quote->valid_until->toFormattedDateString() }}
@endif

<x-mail::button :url="route('quotes.public.show', $quote->public_token)">
{{ __('Review and accept the quote') }}
</x-mail::button>

{{ __('You can view, accept or reject the quote from the link above.') }}

{{ __('Thanks,') }}<br>
{{ config('app.name') }}
</x-mail::message>