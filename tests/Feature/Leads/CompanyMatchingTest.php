<?php

namespace Tests\Feature\Leads;

use App\Models\Plan;
use App\Modules\Leads\Models\Company;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Services\CompanyMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CompanyMatchingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    /**
     * @return array{0: int, 1: int} [workspaceId, clientId]
     */
    private function workspaceWithCompanies(bool $enabled = true): array
    {
        ['client' => $client, 'workspace' => $workspace] = $this->createWorkspaceContext();

        $plan = Plan::factory()->create([
            'feature_flags' => ['companies' => $enabled],
        ]);

        $this->attachPlanToClient($client, $plan);

        return [(int) $workspace->id, (int) $client->id];
    }

    #[Test]
    public function a_lead_is_attached_to_an_existing_company_by_email_domain(): void
    {
        [$workspaceId] = $this->workspaceWithCompanies();

        $company = Company::factory()->create([
            'workspace_id' => $workspaceId,
            'name' => 'Acme Corp',
            'domain' => 'acme.com',
        ]);

        $lead = Lead::factory()->create([
            'workspace_id' => $workspaceId,
            'email' => 'jane.doe@acme.com',
            'company_id' => null,
        ]);

        $this->assertSame($company->id, $lead->fresh()->company_id);
    }

    #[Test]
    public function the_domain_match_ignores_case(): void
    {
        [$workspaceId] = $this->workspaceWithCompanies();

        // Stored mixed-case, as an older record might be.
        $company = Company::factory()->create([
            'workspace_id' => $workspaceId,
            'domain' => 'ACME.com',
        ]);

        $lead = Lead::factory()->create([
            'workspace_id' => $workspaceId,
            'email' => 'Jane@Acme.com',
            'company_id' => null,
        ]);

        $this->assertSame($company->id, $lead->fresh()->company_id);
    }

    #[Test]
    public function a_company_is_created_when_no_match_exists(): void
    {
        [$workspaceId] = $this->workspaceWithCompanies();

        $lead = Lead::factory()->create([
            'workspace_id' => $workspaceId,
            'email' => 'founder@newco.io',
            'company_id' => null,
        ]);

        $company = Company::forWorkspace($workspaceId)->where('domain', 'newco.io')->first();

        $this->assertNotNull($company);
        $this->assertSame('Newco', $company->name);
        $this->assertSame($company->id, $lead->fresh()->company_id);
    }

    #[Test]
    public function matching_is_a_noop_when_the_feature_is_disabled(): void
    {
        [$workspaceId] = $this->workspaceWithCompanies(enabled: false);

        $lead = Lead::factory()->create([
            'workspace_id' => $workspaceId,
            'email' => 'founder@newco.io',
            'company_id' => null,
        ]);

        $this->assertNull($lead->fresh()->company_id);
        $this->assertSame(0, Company::forWorkspace($workspaceId)->count());
    }

    #[Test]
    public function matching_is_a_noop_when_no_plan_is_attached(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();

        $lead = Lead::factory()->create([
            'workspace_id' => $workspace->id,
            'email' => 'founder@newco.io',
            'company_id' => null,
        ]);

        $this->assertNull($lead->fresh()->company_id);
        $this->assertSame(0, Company::forWorkspace((int) $workspace->id)->count());
    }

    #[Test]
    public function consumer_mailbox_domains_are_skipped(): void
    {
        [$workspaceId] = $this->workspaceWithCompanies();

        $lead = Lead::factory()->create([
            'workspace_id' => $workspaceId,
            'email' => 'jane.doe@gmail.com',
            'company_id' => null,
        ]);

        $this->assertNull($lead->fresh()->company_id);
        $this->assertSame(0, Company::forWorkspace($workspaceId)->count());
    }

    #[Test]
    public function an_existing_company_choice_is_never_overwritten(): void
    {
        [$workspaceId] = $this->workspaceWithCompanies();

        Company::factory()->create([
            'workspace_id' => $workspaceId,
            'domain' => 'acme.com',
        ]);

        $chosen = Company::factory()->create([
            'workspace_id' => $workspaceId,
            'domain' => 'other.com',
        ]);

        $lead = Lead::factory()->create([
            'workspace_id' => $workspaceId,
            'email' => 'jane@acme.com',
            'company_id' => $chosen->id,
        ]);

        $this->assertSame($chosen->id, $lead->fresh()->company_id);
    }

    #[Test]
    public function a_lead_without_an_email_is_left_alone(): void
    {
        [$workspaceId] = $this->workspaceWithCompanies();

        $lead = Lead::factory()->create([
            'workspace_id' => $workspaceId,
            'email' => null,
            'company_id' => null,
        ]);

        $this->assertNull($lead->fresh()->company_id);
        $this->assertSame(0, Company::forWorkspace($workspaceId)->count());
    }

    #[Test]
    public function matching_is_scoped_to_the_lead_workspace(): void
    {
        [$workspaceA] = $this->workspaceWithCompanies();
        [$workspaceB] = $this->workspaceWithCompanies();

        Company::factory()->create([
            'workspace_id' => $workspaceB,
            'name' => 'Other Workspace Co',
            'domain' => 'shared.io',
        ]);

        $lead = Lead::factory()->create([
            'workspace_id' => $workspaceA,
            'email' => 'jane@shared.io',
            'company_id' => null,
        ]);

        $company = Company::forWorkspace($workspaceA)->where('domain', 'shared.io')->first();

        // A company was created fresh in workspace A rather than reusing B's.
        $this->assertNotNull($company);
        $this->assertSame($workspaceA, (int) $company->workspace_id);
        $this->assertSame($company->id, $lead->fresh()->company_id);
    }

    #[Test]
    public function domain_for_email_normalizes_and_rejects_free_providers(): void
    {
        $matcher = app(CompanyMatcher::class);

        $this->assertSame('acme.com', $matcher->domainForEmail('Jane@ACME.com'));
        $this->assertSame('acme.com', $matcher->domainForEmail('jane@www.acme.com'));
        $this->assertNull($matcher->domainForEmail('jane@outlook.com'));
        $this->assertNull($matcher->domainForEmail(null));
        $this->assertNull($matcher->domainForEmail(''));
    }
}
