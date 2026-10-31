<?php

namespace App\Modules\Leads\Services;

use App\Models\Workspace;
use App\Modules\Leads\Models\Company;
use App\Modules\Leads\Models\Lead;
use App\Services\FeatureGate;
use Illuminate\Support\Str;

/**
 * Attaches a freshly captured lead to a company based on its email domain.
 *
 * The lead's email is the only signal available at capture time, so a domain
 * match is a strong hint but never a certainty: mailbox providers (gmail.com,
 * outlook.com, ...) are skipped because matching them would fold unrelated
 * people into one company record. The whole match is gated behind the
 * `companies` plan feature, so a workspace without it is left untouched.
 */
class CompanyMatcher
{
    /**
     * Consumer mailbox providers whose domains never identify a company.
     *
     * @var list<string>
     */
    public const FREE_EMAIL_DOMAINS = [
        'gmail.com', 'googlemail.com',
        'yahoo.com', 'yahoo.co.uk', 'yahoo.fr', 'yahoo.de', 'ymail.com',
        'hotmail.com', 'hotmail.co.uk', 'outlook.com', 'live.com', 'msn.com',
        'icloud.com', 'me.com', 'mac.com',
        'aol.com', 'protonmail.com', 'proton.me', 'gmx.com', 'gmx.net',
        'mail.com', 'zoho.com', 'yandex.com', 'yandex.ru', 'mail.ru',
        'qq.com', '163.com', '126.com', 'sina.com',
    ];

    /**
     * Match (or create) the company for this lead and attach it.
     *
     * A lead that already points at a company is left alone so an explicit
     * choice is never overwritten by a domain guess.
     */
    public function matchFor(Lead $lead): ?Company
    {
        if ($lead->company_id) {
            return null;
        }

        $domain = $this->domainForEmail($lead->email);

        if ($domain === null || ! $lead->workspace_id) {
            return null;
        }

        $workspace = Workspace::with('client')->find($lead->workspace_id);

        if (! FeatureGate::forWorkspace($workspace)->enabled('companies')) {
            return null;
        }

        // Domains are stored lower-cased, but existing rows created before this
        // feature may not be, so compare case-insensitively.
        $company = Company::forWorkspace((int) $lead->workspace_id)
            ->whereRaw('LOWER(domain) = ?', [$domain])
            ->first();

        $company ??= Company::create([
            'workspace_id' => $lead->workspace_id,
            'name' => $this->companyNameFor($domain),
            'domain' => $domain,
        ]);

        $lead->forceFill(['company_id' => $company->id])->saveQuietly();

        return $company;
    }

    /**
     * The company domain implied by an email address, or null when it is a
     * personal mailbox or the address is unusable.
     */
    public function domainForEmail(?string $email): ?string
    {
        $domain = Company::extractDomain($email);

        if ($domain === null || in_array($domain, self::FREE_EMAIL_DOMAINS, true)) {
            return null;
        }

        return $domain;
    }

    /**
     * A readable placeholder name derived from the domain, e.g. "acme-corp.com"
     * becomes "Acme Corp". Users can rename it later.
     */
    private function companyNameFor(string $domain): string
    {
        return Str::headline(Str::before($domain, '.'));
    }
}
