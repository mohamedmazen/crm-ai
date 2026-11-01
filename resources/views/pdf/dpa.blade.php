<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ __('Data Processing Agreement') }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: DejaVu Sans, sans-serif; color: #1f2937; font-size: 10.5px; line-height: 1.55; margin: 0; }
        .sheet { padding: 46px; }
        .header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 26px; border-bottom: 1px solid #e5e7eb; padding-bottom: 14px; }
        .brand { font-size: 18px; font-weight: bold; color: #467235; }
        .muted { color: #6b7280; font-size: 10px; }
        h1 { font-size: 20px; margin: 0 0 4px; }
        h2 { font-size: 11px; text-transform: uppercase; letter-spacing: .05em; color: #467235; margin: 20px 0 6px; }
        p { margin: 0 0 7px; }
        ul { margin: 0 0 7px; padding-left: 16px; }
        li { margin-bottom: 3px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
        th, td { text-align: left; vertical-align: top; padding: 6px 8px; border: 1px solid #e5e7eb; }
        th { background: #f9fafb; font-size: 9px; text-transform: uppercase; letter-spacing: .04em; color: #6b7280; }
        .signature { margin-top: 26px; width: 100%; }
        .signature td { border: 0; border-top: 1px solid #9ca3af; padding-top: 5px; width: 45%; }
        .signature td:first-child { padding-right: 8%; }
        .footer { margin-top: 28px; border-top: 1px solid #e5e7eb; padding-top: 10px; font-size: 9px; color: #9ca3af; }
    </style>
</head>
<body>
<div class="sheet">
    <div class="header">
        <div>
            <div class="brand">{{ $appName }}</div>
            <div class="muted">{{ __('Data Processing Agreement') }}</div>
        </div>
        <div style="text-align: right;">
            <h1>{{ __('Data Processing Agreement') }}</h1>
            <div class="muted">{{ __('Generated') }}: {{ $generatedAt->toFormattedDateString() }}</div>
        </div>
    </div>

    <p>
        {{ __('This Data Processing Agreement ("DPA") forms part of the agreement between :client ("Controller") and :app ("Processor") for the processing of personal data through the :app platform.', [
            'client' => $client->name,
            'app' => $appName,
        ]) }}
    </p>

    <h2>{{ __('1. Parties and roles') }}</h2>
    <table>
        <tr>
            <th style="width: 22%;">{{ __('Controller') }}</th>
            <td>
                <strong>{{ $client->name }}</strong><br>
                @if ($client->email) {{ $client->email }}<br> @endif
                @if ($client->address) {{ $client->address }} @endif
            </td>
        </tr>
        <tr>
            <th>{{ __('Processor') }}</th>
            <td>
                <strong>{{ $appName }}</strong><br>
                {{ __('Contact') }}: {{ $supportEmail }}
            </td>
        </tr>
    </table>

    <h2>{{ __('2. Scope and purpose') }}</h2>
    <p>
        {{ __('The Processor processes personal data only to provide the services requested by the Controller: CRM records, sales pipeline management, messaging, automation and reporting. Processing is carried out on documented instructions from the Controller and for the duration of the subscription.') }}
    </p>

    <h2>{{ __('3. Categories of data and data subjects') }}</h2>
    <ul>
        <li>{{ __('Data subjects: the Controller\'s customers, leads, contacts and authorised team members.') }}</li>
        <li>{{ __('Data categories: names, email addresses, phone numbers, company details, message content, conversation metadata and usage records.') }}</li>
        <li>{{ __('Special categories of data are not intentionally collected. The Controller must not submit them unless a lawful basis exists.') }}</li>
    </ul>

    <h2>{{ __('4. Processor obligations') }}</h2>
    <ul>
        <li>{{ __('Process personal data only on documented instructions from the Controller.') }}</li>
        <li>{{ __('Ensure that personnel authorised to process the data are bound by confidentiality.') }}</li>
        <li>{{ __('Implement appropriate technical and organisational measures, including encryption in transit, access controls and audit logging.') }}</li>
        <li>{{ __('Assist the Controller in responding to data-subject requests within the statutory deadline.') }}</li>
        <li>{{ __('Notify the Controller without undue delay after becoming aware of a personal-data breach.') }}</li>
    </ul>

    <h2>{{ __('5. Sub-processors') }}</h2>
    <p>
        {{ __('The Controller authorises the Processor to engage sub-processors for hosting, email delivery, messaging and payment processing. The Processor remains responsible for their performance and will give notice of material changes so the Controller can object.') }}
    </p>

    <h2>{{ __('6. Retention and deletion') }}</h2>
    <ul>
        <li>{{ __('Workspace data is retained for the duration of the subscription and deleted or anonymised after termination, subject to statutory retention duties.') }}</li>
        <li>{{ __('Data-subject erasure requests are executed no later than 30 days after acceptance, unless the Controller cancels the request.') }}</li>
        <li>{{ __('Generated data-export archives are deleted automatically after :days days.', ['days' => $retentionDays]) }}</li>
    </ul>

    <h2>{{ __('7. Data-subject rights') }}</h2>
    <p>
        {{ __('The Controller can export, correct and erase data from the workspace settings. The Processor assists with requests that require access beyond the self-service tools.') }}
    </p>

    <h2>{{ __('8. International transfers') }}</h2>
    <p>
        {{ __('Where personal data is transferred outside the European Economic Area, the parties rely on the Standard Contractual Clauses or another lawful transfer mechanism.') }}
    </p>

    <h2>{{ __('9. Term and termination') }}</h2>
    <p>
        {{ __('This DPA remains in force while the Processor handles personal data on behalf of the Controller. On termination, personal data is deleted or returned as described in section 6.') }}
    </p>

    <table class="signature">
        <tr>
            <td>
                <strong>{{ $client->name }}</strong><br>
                <span class="muted">{{ __('Controller signature / date') }}</span>
            </td>
            <td>
                <strong>{{ $appName }}</strong><br>
                <span class="muted">{{ __('Processor signature / date') }}</span>
            </td>
        </tr>
    </table>

    <div class="footer">
        {{ __('This document was generated automatically from the platform settings. Review it with legal counsel before signing.') }}
    </div>
</div>
</body>
</html>
