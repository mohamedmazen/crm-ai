<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $quote->number }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: DejaVu Sans, sans-serif; color: #1f2937; font-size: 12px; margin: 0; }
        .sheet { padding: 40px; }
        .header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 32px; }
        .brand { font-size: 20px; font-weight: bold; color: #4f46e5; }
        .muted { color: #6b7280; font-size: 11px; }
        h1 { font-size: 22px; margin: 0 0 4px; }
        table { width: 100%; border-collapse: collapse; }
        .items th { text-align: left; font-size: 10px; text-transform: uppercase; letter-spacing: .04em; color: #6b7280; border-bottom: 1px solid #e5e7eb; padding: 8px 6px; }
        .items td { padding: 9px 6px; border-bottom: 1px solid #f3f4f6; vertical-align: top; }
        .items .num { text-align: right; white-space: nowrap; }
        .totals { width: 46%; margin-left: auto; margin-top: 18px; }
        .totals td { padding: 5px 0; }
        .totals .label { color: #6b7280; }
        .totals .value { text-align: right; }
        .totals .grand td { border-top: 1px solid #d1d5db; padding-top: 9px; font-size: 14px; font-weight: bold; }
        .notes { margin-top: 30px; }
        .notes h2 { font-size: 11px; text-transform: uppercase; color: #6b7280; margin-bottom: 4px; }
        .status { display: inline-block; padding: 3px 10px; border-radius: 999px; background: #eef2ff; color: #4338ca; font-size: 10px; text-transform: uppercase; }
    </style>
</head>
<body>
<div class="sheet">
    <div class="header">
        <div>
            <div class="brand">{{ config('app.name') }}</div>
            <div class="muted">{{ __('Quote') }}</div>
        </div>
        <div style="text-align: right;">
            <h1>{{ $quote->number }}</h1>
            <div class="muted">{{ __('Issued') }}: {{ $quote->created_at?->toFormattedDateString() }}</div>
            @if ($quote->valid_until)
                <div class="muted">{{ __('Valid until') }}: {{ $quote->valid_until->toFormattedDateString() }}</div>
            @endif
            <div style="margin-top: 8px;"><span class="status">{{ __($quote->status) }}</span></div>
        </div>
    </div>

    @if ($quote->lead || $quote->company)
        <div style="margin-bottom: 26px;">
            <div class="muted">{{ __('Prepared for') }}</div>
            <div style="font-weight: bold;">{{ $quote->company?->name ?? $quote->lead?->name }}</div>
            @if ($quote->lead?->email)
                <div class="muted">{{ $quote->lead->email }}</div>
            @endif
            @if ($quote->lead?->phone)
                <div class="muted">{{ $quote->lead->phone }}</div>
            @endif
        </div>
    @endif

    <table class="items">
        <thead>
        <tr>
            <th>{{ __('Description') }}</th>
            <th class="num">{{ __('Qty') }}</th>
            <th class="num">{{ __('Unit price') }}</th>
            <th class="num">{{ __('Tax') }}</th>
            <th class="num">{{ __('Total') }}</th>
        </tr>
        </thead>
        <tbody>
        @foreach ($quote->items as $item)
            <tr>
                <td>{{ $item->description }}</td>
                <td class="num">{{ rtrim(rtrim(number_format((float) $item->quantity, 2), '0'), '.') }}</td>
                <td class="num">{{ number_format((float) $item->unit_price, 2) }}</td>
                <td class="num">{{ number_format((float) $item->tax_rate, 2) }}%</td>
                <td class="num">{{ number_format((float) $item->total, 2) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr>
            <td class="label">{{ __('Subtotal') }}</td>
            <td class="value">{{ number_format((float) $quote->subtotal, 2) }}</td>
        </tr>
        @if ((float) $quote->discount_value > 0)
            <tr>
                <td class="label">{{ __('Discount') }}</td>
                <td class="value">
                    -{{ number_format((float) $quote->discount_value, 2) }}{{ $quote->discount_type === 'percent' ? '%' : '' }}
                </td>
            </tr>
        @endif
        <tr>
            <td class="label">{{ __('Tax') }}</td>
            <td class="value">{{ number_format((float) $quote->tax_total, 2) }}</td>
        </tr>
        <tr class="grand">
            <td>{{ __('Total') }}</td>
            <td class="value">{{ number_format((float) $quote->total, 2) }} {{ $quote->currency }}</td>
        </tr>
    </table>

    @if ($quote->notes)
        <div class="notes">
            <h2>{{ __('Notes') }}</h2>
            <div>{!! nl2br(e($quote->notes)) !!}</div>
        </div>
    @endif

    @if ($quote->terms)
        <div class="notes">
            <h2>{{ __('Terms') }}</h2>
            <div>{!! nl2br(e($quote->terms)) !!}</div>
        </div>
    @endif
</div>
</body>
</html>