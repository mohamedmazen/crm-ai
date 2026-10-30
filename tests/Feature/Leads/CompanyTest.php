<?php

namespace Tests\Feature\Leads;

use App\Models\Plan;
use App\Models\User;
use App\Modules\Leads\Models\Company;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Services\PipelineManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CompanyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function actorWithPlan(bool $hasCompanies = true): array
    {
        ['user' => $user, 'client' => $client, 'workspace' => $workspace] = $this->createWorkspaceContext();

        $plan = Plan::factory()->create([
            'name' => $hasCompanies ? 'Pro Plan' : 'Starter Plan',
            'feature_flags' => [
                'companies' => $hasCompanies,
            ],
        ]);

        $this->attachPlanToClient($client, $plan);

        return [$user, $workspace, $client];
    }

    #[Test]
    public function companies_feature_gate_blocks_starter_plan(): void
    {
        [$user] = $this->actorWithPlan(hasCompanies: false);

        $response = $this->actingAs($user)->get(route('client.leads.companies.index'));
        $response->assertRedirect(route('client.pricing'));
        $response->assertSessionHas('upgrade_required', true);

        // JSON requests receive HTTP 402 Payment Required
        $jsonResponse = $this->actingAs($user)->getJson(route('client.leads.companies.index'));
        $jsonResponse->assertStatus(402);
    }

    #[Test]
    public function companies_feature_gate_allows_pro_plan(): void
    {
        [$user] = $this->actorWithPlan(hasCompanies: true);

        $response = $this->actingAs($user)->get(route('client.leads.companies.index'));
        $response->assertStatus(200);
    }

    #[Test]
    public function can_create_company_with_auto_domain_extraction(): void
    {
        [$user, $workspace] = $this->actorWithPlan(hasCompanies: true);

        $response = $this->actingAs($user)->post(route('client.leads.companies.store'), [
            'name' => 'Acme Technologies Inc',
            'website' => 'https://www.acme-tech.io/solutions',
            'industry' => 'Technology & Software',
            'size' => '51-200',
            'phone' => '+1 800-555-0199',
            'address' => '742 Evergreen Terrace, Springfield',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('companies', [
            'workspace_id' => $workspace->id,
            'name' => 'Acme Technologies Inc',
            'domain' => 'acme-tech.io',
            'industry' => 'Technology & Software',
            'size' => '51-200',
            'phone' => '+1 800-555-0199',
        ]);
    }

    #[Test]
    public function can_update_company_details(): void
    {
        [$user, $workspace] = $this->actorWithPlan(hasCompanies: true);

        $company = Company::factory()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Original Name',
            'industry' => 'Other',
        ]);

        $response = $this->actingAs($user)->put(route('client.leads.companies.update', $company->id), [
            'name' => 'Renamed Global Corp',
            'industry' => 'Healthcare & Medical',
            'size' => '500+',
            'phone' => '+44 20 7946 0991',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $company->refresh();
        $this->assertSame('Renamed Global Corp', $company->name);
        $this->assertSame('Healthcare & Medical', $company->industry);
        $this->assertSame('500+', $company->size);
    }

    #[Test]
    public function deleting_a_company_nulls_leads_company_id_without_deleting_the_lead(): void
    {
        [$user, $workspace] = $this->actorWithPlan(hasCompanies: true);

        $company = Company::factory()->create(['workspace_id' => $workspace->id]);

        $pipeline = app(PipelineManager::class)->defaultPipelineFor($workspace->id);
        $stage = app(PipelineManager::class)->firstStage($workspace->id, $pipeline->id);

        $lead = Lead::factory()->create([
            'workspace_id' => $workspace->id,
            'company_id' => $company->id,
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
        ]);

        $response = $this->actingAs($user)->delete(route('client.leads.companies.destroy', $company->id));
        $response->assertRedirect();

        $this->assertDatabaseMissing('companies', ['id' => $company->id]);

        // Lead must still exist, with company_id set to null
        $lead->refresh();
        $this->assertNull($lead->company_id);
    }

    #[Test]
    public function search_json_endpoint_returns_matching_companies(): void
    {
        [$user, $workspace] = $this->actorWithPlan(hasCompanies: true);

        Company::factory()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Tesla Motors',
            'domain' => 'tesla.com',
        ]);

        Company::factory()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Microsoft Azure',
            'domain' => 'azure.microsoft.com',
        ]);

        $response = $this->actingAs($user)->getJson(route('client.leads.companies.search', ['q' => 'Tesla']));

        $response->assertStatus(200);
        $data = $response->json();

        $this->assertCount(1, $data);
        $this->assertSame('Tesla Motors', $data[0]['name']);
        $this->assertSame('tesla.com', $data[0]['domain']);
    }

    #[Test]
    public function can_attach_and_detach_lead_to_company(): void
    {
        [$user, $workspace] = $this->actorWithPlan(hasCompanies: true);

        $company = Company::factory()->create(['workspace_id' => $workspace->id]);

        $pipeline = app(PipelineManager::class)->defaultPipelineFor($workspace->id);
        $stage = app(PipelineManager::class)->firstStage($workspace->id, $pipeline->id);

        $lead = Lead::factory()->create([
            'workspace_id' => $workspace->id,
            'company_id' => null,
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
        ]);

        // Attach
        $response = $this->actingAs($user)->post(route('client.leads.companies.attach-lead', [$company->id, $lead->id]));
        $response->assertRedirect();

        $lead->refresh();
        $this->assertSame($company->id, $lead->company_id);

        // Detach
        $response = $this->actingAs($user)->post(route('client.leads.companies.detach-lead', [$company->id, $lead->id]));
        $response->assertRedirect();

        $lead->refresh();
        $this->assertNull($lead->company_id);
    }

    #[Test]
    public function company_show_page_loads_with_metrics_and_associated_leads(): void
    {
        [$user, $workspace] = $this->actorWithPlan(hasCompanies: true);

        $company = Company::factory()->create(['workspace_id' => $workspace->id]);

        $pipeline = app(PipelineManager::class)->defaultPipelineFor($workspace->id);
        $stage = app(PipelineManager::class)->firstStage($workspace->id, $pipeline->id);

        // Create 1 converted (won) lead and 1 open lead
        Lead::factory()->create([
            'workspace_id' => $workspace->id,
            'company_id' => $company->id,
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'status' => 'converted',
            'deal_value' => 5000,
        ]);

        Lead::factory()->create([
            'workspace_id' => $workspace->id,
            'company_id' => $company->id,
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'status' => 'new',
            'deal_value' => 3000,
        ]);

        $response = $this->actingAs($user)->get(route('client.leads.companies.show', $company->id));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Leads/Companies/Show')
            ->has('company')
            ->has('leads', 2)
            ->where('metrics.total_leads', 2)
            ->where('metrics.won_deals_value', 5000)
            ->where('metrics.open_deals_value', 3000)
        );
    }

    #[Test]
    public function workspace_isolation_is_strictly_enforced(): void
    {
        [$userA, $workspaceA] = $this->actorWithPlan(hasCompanies: true);
        [$userB, $workspaceB] = $this->actorWithPlan(hasCompanies: true);

        $companyA = Company::factory()->create([
            'workspace_id' => $workspaceA->id,
            'name' => 'Secret Org A',
        ]);

        // User B cannot view Company A
        $response = $this->actingAs($userB)->get(route('client.leads.companies.show', $companyA->id));
        $response->assertStatus(403);

        // User B cannot update Company A
        $response = $this->actingAs($userB)->put(route('client.leads.companies.update', $companyA->id), [
            'name' => 'Tampered Name',
        ]);
        $response->assertStatus(403);

        // User B cannot delete Company A
        $response = $this->actingAs($userB)->delete(route('client.leads.companies.destroy', $companyA->id));
        $response->assertStatus(403);

        $this->assertDatabaseHas('companies', [
            'id' => $companyA->id,
            'name' => 'Secret Org A',
        ]);
    }
}

