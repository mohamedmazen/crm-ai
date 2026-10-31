<?php

namespace Tests\Feature\Reporting;

use App\Models\Client;
use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\LeadCloseReason;
use App\Modules\Leads\Models\LeadPipeline;
use App\Modules\Leads\Models\LeadPipelineStage;
use App\Modules\Leads\Models\LeadStageHistory;
use App\Modules\Sales\Models\LeadSource;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SalesReportsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    /**
     * @return array{0: User, 1: Workspace, 2: Client}
     */
    private function actorWithSalesReports(bool $enabled = true): array
    {
        ['user' => $user, 'client' => $client, 'workspace' => $workspace] = $this->createWorkspaceContext();

        $plan = Plan::factory()->create([
            'feature_flags' => ['sales_reports' => $enabled],
        ]);

        $this->attachPlanToClient($client, $plan);

        return [$user, $workspace, $client];
    }

    private function lead(int $workspaceId, array $attributes = [], ?Carbon $createdAt = null): Lead
    {
        $lead = Lead::factory()->create(['workspace_id' => $workspaceId] + $attributes);

        if ($createdAt !== null) {
            DB::table('leads')->where('id', $lead->id)->update([
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);
        }

        return $lead->refresh();
    }

    private function conversation(int $workspaceId, int $contactId, array $attributes = [], ?Carbon $createdAt = null): Conversation
    {
        $conversation = Conversation::create([
            'workspace_id' => $workspaceId,
            'contact_id' => $contactId,
            'status' => 'open',
            'assigned_to' => 'human',
        ] + $attributes);

        if ($createdAt !== null) {
            DB::table('conversations')->where('id', $conversation->id)->update([
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);
        }

        return $conversation->refresh();
    }

    private function range(): array
    {
        return [
            'from' => now()->subDays(10)->toDateString(),
            'to' => now()->toDateString(),
        ];
    }

    #[Test]
    public function sales_reports_gate_blocks_when_the_plan_disables_it(): void
    {
        [$user] = $this->actorWithSalesReports(false);

        $response = $this->actingAs($user)->get(route('client.reports.sales.volume'));

        $response->assertRedirect(route('client.pricing'));
        $response->assertSessionHas('upgrade_required', true);
    }

    #[Test]
    public function volume_page_lists_leads_per_day_and_summary(): void
    {
        [$user, $workspace] = $this->actorWithSalesReports();

        $day = now()->subDays(2)->startOfDay();

        $this->lead($workspace->id, ['source' => 'Website'], $day->copy()->addHour());
        $this->lead($workspace->id, ['source' => 'Website'], $day->copy()->addHours(2));
        $this->lead($workspace->id, ['source' => 'Referral'], $day->copy()->addDay());

        $response = $this->actingAs($user)->get(route('client.reports.sales.volume', $this->range()));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('client/Reports/Sales/Volume')
            ->where('summary.leads', 3)
            ->where('filters.from', $this->range()['from'])
            ->where('filters.to', $this->range()['to'])
            ->where('rows', function ($rows) use ($day): bool {
                $row = collect($rows)->firstWhere('date', $day->toDateString());

                return $row !== null && $row['leads'] === 2;
            }));
    }

    #[Test]
    public function volume_excludes_leads_outside_the_date_range(): void
    {
        [$user, $workspace] = $this->actorWithSalesReports();

        $this->lead($workspace->id, [], now()->subDays(40));

        $response = $this->actingAs($user)->get(route('client.reports.sales.volume', $this->range()));

        $response->assertInertia(fn (Assert $page) => $page->where('summary.leads', 0));
    }

    #[Test]
    public function source_performance_groups_leads_and_computes_conversion(): void
    {
        [$user, $workspace] = $this->actorWithSalesReports();

        $this->lead($workspace->id, [
            'source' => 'Website',
            'status' => 'converted',
            'won_at' => now(),
            'deal_value' => 1000,
        ]);
        $this->lead($workspace->id, [
            'source' => 'Website',
            'status' => 'new',
            'deal_value' => 500,
        ]);
        $this->lead($workspace->id, [
            'source' => 'Referral',
            'status' => 'lost',
            'lost_at' => now(),
            'deal_value' => 300,
        ]);

        $response = $this->actingAs($user)->get(route('client.reports.sales.sources', $this->range()));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('client/Reports/Sales/Sources')
            ->where('rows', function ($rows): bool {
                $bySource = collect($rows)->keyBy('source');

                return $bySource->has('Website')
                    && $bySource['Website']['leads'] === 2
                    && $bySource['Website']['won'] === 1
                    && (float) $bySource['Website']['conversion_rate'] === 50.0
                    && (float) $bySource['Website']['won_value'] === 1000.0
                    && $bySource['Referral']['leads'] === 1
                    && $bySource['Referral']['lost'] === 1;
            }));
    }

    #[Test]
    public function source_performance_prefers_the_named_lead_source(): void
    {
        [$user, $workspace] = $this->actorWithSalesReports();

        $source = LeadSource::create([
            'workspace_id' => $workspace->id,
            'name' => 'Meta Ads',
            'type' => LeadSource::TYPE_META_ADS,
            'status' => LeadSource::STATUS_ACTIVE,
        ]);

        $this->lead($workspace->id, [
            'source' => 'raw import label',
            'lead_source_id' => $source->id,
        ]);

        $response = $this->actingAs($user)->get(route('client.reports.sales.sources', $this->range()));

        $response->assertInertia(fn (Assert $page) => $page
            ->where('rows', fn ($rows): bool => collect($rows)->contains(
                fn (array $row): bool => $row['source'] === 'Meta Ads' && $row['leads'] === 1
            )));
    }

    #[Test]
    public function win_loss_distribution_uses_close_reasons_and_ignores_older_deals(): void
    {
        [$user, $workspace] = $this->actorWithSalesReports();

        $wonReason = LeadCloseReason::create([
            'workspace_id' => $workspace->id,
            'type' => LeadCloseReason::TYPE_WON,
            'name' => 'Price',
        ]);
        $lostReason = LeadCloseReason::create([
            'workspace_id' => $workspace->id,
            'type' => LeadCloseReason::TYPE_LOST,
            'name' => 'Budget',
        ]);

        $this->lead($workspace->id, [
            'status' => 'converted',
            'won_at' => now(),
            'close_reason_id' => $wonReason->id,
            'deal_value' => 1000,
        ]);
        $this->lead($workspace->id, [
            'status' => 'lost',
            'lost_at' => now(),
            'close_reason_id' => $lostReason->id,
            'deal_value' => 500,
        ]);
        $this->lead($workspace->id, [
            'status' => 'lost',
            'lost_at' => now()->subDays(40),
            'close_reason_id' => $lostReason->id,
            'deal_value' => 900,
        ]);

        $response = $this->actingAs($user)->get(route('client.reports.sales.win-loss', $this->range()));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('client/Reports/Sales/WinLoss')
            ->where('rows', function ($rows): bool {
                $won = collect($rows)->firstWhere('type', 'won');
                $lost = collect($rows)->firstWhere('type', 'lost');

                return $won !== null && $won['reason'] === 'Price' && $won['count'] === 1
                    && $lost !== null && $lost['reason'] === 'Budget' && $lost['count'] === 1
                    && collect($rows)->sum('count') === 2;
            }));
    }

    #[Test]
    public function win_loss_uses_unspecified_when_no_reason_was_recorded(): void
    {
        [$user, $workspace] = $this->actorWithSalesReports();

        $this->lead($workspace->id, ['status' => 'lost', 'lost_at' => now()]);

        $response = $this->actingAs($user)->get(route('client.reports.sales.win-loss', $this->range()));

        $response->assertInertia(fn (Assert $page) => $page
            ->where('rows', fn ($rows): bool => collect($rows)->contains(
                fn (array $row): bool => $row['reason'] === 'Unspecified' && $row['count'] === 1
            )));
    }

    #[Test]
    public function agent_performance_reports_won_value_pipeline_value_and_win_rate(): void
    {
        [$user, $workspace, $client] = $this->actorWithSalesReports();

        $agent = User::factory()->create([
            'workspace_id' => $workspace->id,
            'client_id' => $client->id,
            'role' => User::ROLE_CLIENT,
            'client_role' => User::CLIENT_ROLE_STAFF,
            'status' => User::STATUS_ACTIVE,
        ]);

        $this->lead($workspace->id, [
            'assigned_user_id' => $agent->id,
            'status' => 'converted',
            'won_at' => now(),
            'deal_value' => 1000,
        ]);
        $this->lead($workspace->id, [
            'assigned_user_id' => $agent->id,
            'status' => 'new',
            'deal_value' => 500,
        ]);
        $this->lead($workspace->id, [
            'assigned_user_id' => $agent->id,
            'status' => 'lost',
            'lost_at' => now(),
            'deal_value' => 200,
        ]);

        $response = $this->actingAs($user)->get(route('client.reports.sales.agents', $this->range()));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('client/Reports/Sales/Agents')
            ->where('rows', function ($rows) use ($agent): bool {
                $row = collect($rows)->firstWhere('user_id', $agent->id);

                return $row !== null
                    && $row['leads'] === 3
                    && $row['won'] === 1
                    && $row['lost'] === 1
                    && (float) $row['win_rate'] === 50.0
                    && (float) $row['won_value'] === 1000.0
                    && (float) $row['pipeline_value'] === 500.0;
            }));
    }

    #[Test]
    public function shared_filters_narrow_the_reports_by_pipeline_agent_and_source(): void
    {
        [$user, $workspace, $client] = $this->actorWithSalesReports();

        $pipeline = LeadPipeline::create([
            'workspace_id' => $workspace->id,
            'name' => 'Enterprise',
            'is_default' => false,
            'sort_order' => 0,
        ]);
        $agent = User::factory()->create([
            'workspace_id' => $workspace->id,
            'client_id' => $client->id,
            'role' => User::ROLE_CLIENT,
            'status' => User::STATUS_ACTIVE,
        ]);

        $this->lead($workspace->id, [
            'pipeline_id' => $pipeline->id,
            'assigned_user_id' => $agent->id,
            'source' => 'Website',
        ]);
        $this->lead($workspace->id, [
            'source' => 'Referral',
        ]);

        $base = $this->range();

        $this->actingAs($user)
            ->get(route('client.reports.sales.volume', $base + ['pipeline_id' => $pipeline->id]))
            ->assertInertia(fn (Assert $page) => $page->where('summary.leads', 1));

        $this->actingAs($user)
            ->get(route('client.reports.sales.volume', $base + ['agent_id' => $agent->id]))
            ->assertInertia(fn (Assert $page) => $page->where('summary.leads', 1));

        $this->actingAs($user)
            ->get(route('client.reports.sales.volume', $base + ['source' => 'Website']))
            ->assertInertia(fn (Assert $page) => $page->where('summary.leads', 1));
    }

    #[Test]
    public function reports_are_isolated_to_the_current_workspace(): void
    {
        [$user, $workspace] = $this->actorWithSalesReports();
        [, $otherWorkspace] = $this->actorWithSalesReports();

        $this->lead($workspace->id, ['source' => 'Mine']);
        $this->lead($otherWorkspace->id, ['source' => 'Theirs']);

        $response = $this->actingAs($user)->get(route('client.reports.sales.sources', $this->range()));

        $response->assertInertia(fn (Assert $page) => $page
            ->where('summary.leads', 1)
            ->where('rows', function ($rows): bool {
                /** @var Collection $rows */
                $sources = collect($rows)->pluck('source');

                return $sources->contains('Mine') && ! $sources->contains('Theirs');
            }));
    }

    #[Test]
    public function sales_reports_export_their_csv(): void
    {
        [$user, $workspace] = $this->actorWithSalesReports();

        $this->lead($workspace->id, ['source' => 'Website']);

        $response = $this->actingAs($user)->get(route('client.reports.sales.export', ['report' => 'sources'] + $this->range()));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $content = $response->streamedContent();

        $this->assertStringContainsString('Source', $content);
        $this->assertStringContainsString('Website', $content);
    }

    #[Test]
    public function an_unknown_export_report_returns_not_found(): void
    {
        [$user] = $this->actorWithSalesReports();

        $this->actingAs($user)
            ->get(route('client.reports.sales.export', ['report' => 'nope'] + $this->range()))
            ->assertNotFound();
    }

    #[Test]
    public function response_time_report_returns_median_p90_and_distribution(): void
    {
        [$user, $workspace, $client] = $this->actorWithSalesReports();

        $agent = User::factory()->create([
            'workspace_id' => $workspace->id,
            'client_id' => $client->id,
            'role' => User::ROLE_CLIENT,
            'status' => User::STATUS_ACTIVE,
        ]);

        $contact = Contact::factory()->create(['workspace_id' => $workspace->id]);
        $created = now()->subHours(2);

        $this->conversation($workspace->id, $contact->id, ['first_response_at' => $created->copy()->addMinutes(10)], $created);
        $this->conversation($workspace->id, $contact->id, ['first_response_at' => $created->copy()->addMinutes(30)], $created);
        $this->conversation($workspace->id, $contact->id, [
            'first_response_at' => $created->copy()->addMinutes(90),
            'assigned_user_id' => $agent->id,
        ], $created);

        $response = $this->actingAs($user)->get(route('client.reports.sales.response', $this->range()));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('client/Reports/Sales/Response')
            ->where('responseSummary.count', 3)
            ->where('responseSummary.median_seconds', 1800)
            ->where('responseSummary.p90_seconds', 5400)
            ->where('distribution', function ($rows): bool {
                $byBucket = collect($rows)->pluck('count', 'bucket');

                return $byBucket['5m_30m'] === 1
                    && $byBucket['30m_2h'] === 2
                    && $byBucket['under_5m'] === 0;
            }));

        $this->actingAs($user)
            ->get(route('client.reports.sales.response', $this->range() + ['agent_id' => $agent->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('responseSummary.count', 1)
                ->where('responseSummary.median_seconds', 5400));
    }

    #[Test]
    public function pipeline_report_returns_funnel_counts_and_stage_bottlenecks(): void
    {
        [$user, $workspace] = $this->actorWithSalesReports();

        $pipeline = LeadPipeline::create([
            'workspace_id' => $workspace->id,
            'name' => 'Sales',
            'is_default' => true,
            'sort_order' => 0,
        ]);

        $new = LeadPipelineStage::create([
            'workspace_id' => $workspace->id,
            'pipeline_id' => $pipeline->id,
            'name' => 'New',
            'color' => 'neutral',
            'position' => 0,
            'is_won' => false,
            'is_lost' => false,
        ]);
        $qualified = LeadPipelineStage::create([
            'workspace_id' => $workspace->id,
            'pipeline_id' => $pipeline->id,
            'name' => 'Qualified',
            'color' => 'blue',
            'position' => 1,
            'is_won' => false,
            'is_lost' => false,
        ]);
        $won = LeadPipelineStage::create([
            'workspace_id' => $workspace->id,
            'pipeline_id' => $pipeline->id,
            'name' => 'Won',
            'color' => 'emerald',
            'position' => 2,
            'is_won' => true,
            'is_lost' => false,
        ]);

        $t0 = now()->subDays(10);
        $stageAttributes = ['pipeline_id' => $pipeline->id, 'stage_id' => $new->id, 'stage_changed_at' => $t0];

        $first = $this->lead($workspace->id, $stageAttributes, $t0);
        $second = $this->lead($workspace->id, $stageAttributes, $t0);
        $this->lead($workspace->id, $stageAttributes, $t0);

        LeadStageHistory::create([
            'lead_id' => $first->id,
            'from_stage_id' => $new->id,
            'to_stage_id' => $qualified->id,
            'changed_at' => $t0->copy()->addDays(2),
        ]);
        LeadStageHistory::create([
            'lead_id' => $first->id,
            'from_stage_id' => $qualified->id,
            'to_stage_id' => $won->id,
            'changed_at' => $t0->copy()->addDays(4),
        ]);
        LeadStageHistory::create([
            'lead_id' => $second->id,
            'from_stage_id' => $new->id,
            'to_stage_id' => $qualified->id,
            'changed_at' => $t0->copy()->addDays(6),
        ]);

        $response = $this->actingAs($user)->get(route('client.reports.sales.pipeline', $this->range()));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('client/Reports/Sales/Pipeline')
            ->where('pipelineName', 'Sales')
            ->where('rows', function ($rows): bool {
                $byStage = collect($rows)->keyBy('stage');

                return $byStage['New']['leads'] === 3
                    && $byStage['Qualified']['leads'] === 2
                    && $byStage['Won']['leads'] === 1
                    && (float) $byStage['Qualified']['conversion_rate'] === 66.67
                    && (float) $byStage['Won']['step_conversion_rate'] === 50.0;
            })
            ->where('bottlenecks', function ($rows): bool {
                $byStage = collect($rows)->keyBy('stage');

                return (float) $byStage['New']['avg_days'] === 6.0
                    && (float) $byStage['Qualified']['avg_days'] === 3.0
                    && $byStage['Won']['avg_days'] === null;
            }));
    }

    #[Test]
    public function source_attribution_maps_utm_tuples_to_won_revenue(): void
    {
        [$user, $workspace] = $this->actorWithSalesReports();

        $this->lead($workspace->id, [
            'utm_source' => 'google',
            'utm_medium' => 'cpc',
            'utm_campaign' => 'spring',
            'status' => 'converted',
            'won_at' => now(),
            'deal_value' => 1000,
        ]);
        $this->lead($workspace->id, [
            'utm_source' => 'google',
            'utm_medium' => 'cpc',
            'utm_campaign' => 'spring',
            'status' => 'new',
            'deal_value' => 500,
        ]);
        $this->lead($workspace->id, ['status' => 'new']);

        $response = $this->actingAs($user)->get(route('client.reports.sales.attribution', $this->range()));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('client/Reports/Sales/Attribution')
            ->where('rows', function ($rows): bool {
                $byTuple = collect($rows)->keyBy(
                    fn (array $row): string => $row['utm_source'].'|'.$row['utm_medium'].'|'.$row['utm_campaign']
                );

                $google = $byTuple->get('google|cpc|spring');
                $direct = $byTuple->get('Direct|—|—');

                return $google !== null
                    && $google['leads'] === 2
                    && $google['won'] === 1
                    && (float) $google['conversion_rate'] === 50.0
                    && (float) $google['won_value'] === 1000.0
                    && $direct !== null
                    && $direct['leads'] === 1;
            }));
    }

    #[Test]
    public function the_new_sales_reports_export_their_csv(): void
    {
        [$user] = $this->actorWithSalesReports();

        $reports = [
            'response' => 'Median (seconds)',
            'pipeline' => 'Avg Days in Stage',
            'attribution' => 'UTM Source',
        ];

        foreach ($reports as $report => $expectedHeader) {
            $response = $this->actingAs($user)->get(route('client.reports.sales.export', ['report' => $report] + $this->range()));

            $response->assertOk();
            $this->assertStringContainsString($expectedHeader, $response->streamedContent());
        }
    }
}
