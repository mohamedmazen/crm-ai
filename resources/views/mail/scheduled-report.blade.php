@component('mail::message')
# {{ $reportLabel }} — {{ $workspaceName }}

Here is your scheduled sales report for **{{ $period }}**.

@component('mail::table')
| Delivery | Value |
|----------|-------|
| Report | {{ $reportLabel }} |
| Frequency | {{ ucfirst($frequency) }} |
| Period | {{ $period }} |
@endcomponent

The CSV file is attached to this email.

@component('mail::button', ['url' => $reportsUrl])
Open sales reports
@endcomponent

You are receiving this because you are listed as a recipient of a scheduled report for {{ $workspaceName }}.

@endcomponent
