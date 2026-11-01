<x-mail::message>
# {{ __('Invoice :number', ['number' => $invoice->number]) }}

{{ __('Hi :name, your invoice is ready.', ['name' => $invoice->lead?->name ?? __('there')]) }}

**{{ __('Amount due') }}:** {{ number_format((float) $invoice->total, 2) }} {{ $invoice->currency ?? '' }}

@if ($invoice->due_date)
**{{ __('Due date') }}:** {{ $invoice->due_date->toFormattedDateString() }}
@endif

<x-mail::button :url="route('invoices.public.show', $invoice->public_token)">
{{ __('View the invoice') }}
</x-mail::button>

{{ __('Thanks,') }}<br>
{{ config('app.name') }}
</x-mail::message>