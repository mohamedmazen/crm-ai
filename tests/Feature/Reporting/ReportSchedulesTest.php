<?php

namespace Tests\Feature\Reporting;

use App\Mail\ScheduledReportMail;
use App\Models\Client;
use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;
use App\Modules\Leads\Models\LeadPipeline;
use App\Modules\Reporting\Jobs\SendScheduledReportJob;
use App\Modules\Reporting\Models\ReportSchedule;
use App\Modules\Reporting\Services\SalesReportExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReportSchedulesTest extends TestCase
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

    private function schedule(int $workspaceId, array $attributes = []): ReportSchedule
    {
        return ReportSchedule::factory()->create([
            'workspace_id' => $workspaceId,
        ] + $attributes);
    }

    #[Test]
    public function the_schedules_page_is_gated_by_the_sales_reports_feature(): void
    {
        [$user] = $this->actorWithSalesReports(false);

        $this->actingAs($user)
            ->get(route('client.reports.sales.schedules'))
            ->assertRedirect(route('client.pricing'))
            ->assertSessionHas('upgrade_required', true);
    }

    #[Test]
    public function the_schedules_page_lists_the_workspaces_schedules(): void
    {
        [$user, $workspace] = $this->actorWithSalesReports();

        $this->schedule($workspace->id, [
            'report_type' => 'pipeline',
            'frequency' => 'weekly',
            'recipients' => ['owner@example.com', 'sales@example.com'],
            'filters' => ['source' => 'Website'],
        ]);

        $response = $this->actingAs($user)->get(route('client.reports.sales.schedules'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('client/Reports/Sales/Schedules')
            ->where('schedules.0.report_type', 'pipeline')
            ->where('schedules.0.frequency', 'weekly')
            ->where('schedules.0.recipients', ['owner@example.com', 'sales@example.com'])
            ->where('schedules.0.filters.source', 'Website')
            ->where('reportTypes', SalesReportExporter::REPORT_TYPES)
            ->where('frequencies', ReportSchedule::FREQUENCIES)
            ->where('defaultRecipients', $user->email));
    }

    #[Test]
    public function a_schedule_is_created_with_a_normalised_recipient_list_and_a_next_slot(): void
    {
        [$user, $workspace] = $this->actorWithSalesReports();

        $pipeline = LeadPipeline::create([
            'workspace_id' => $workspace->id,
            'name' => 'Sales',
            'is_default' => true,
            'sort_order' => 0,
        ]);

        $this->actingAs($user)
            ->post(route('client.reports.sales.schedules.store'), [
                'report_type' => 'pipeline',
                'frequency' => 'daily',
                'recipients' => 'Owner@Example.com, sales@example.com; sales@example.com',
                'pipeline_id' => $pipeline->id,
                'source' => 'Website',
            ])
            ->assertRedirect();

        $schedule = ReportSchedule::sole();

        $this->assertSame($workspace->id, $schedule->workspace_id);
        $this->assertSame('pipeline', $schedule->report_type);
        $this->assertSame('daily', $schedule->frequency);
        $this->assertSame('csv', $schedule->format);
        $this->assertSame(['owner@example.com', 'sales@example.com'], $schedule->recipients);
        $this->assertEquals(['pipeline_id' => $pipeline->id, 'source' => 'Website'], $schedule->filters);
        $this->assertSame(ReportSchedule::DELIVERY_HOUR, $schedule->next_run_at->hour);
        $this->assertTrue($schedule->next_run_at->isFuture());
    }

    #[Test]
    public function a_schedule_rejects_unknown_reports_and_frequencies(): void
    {
        [$user] = $this->actorWithSalesReports();

        $response = $this->actingAs($user)
            ->post(route('client.reports.sales.schedules.store'), [
                'report_type' => 'nope',
                'frequency' => 'hourly',
                'recipients' => 'owner@example.com',
            ]);

        $response->assertSessionHasErrors(['report_type', 'frequency']);
        $this->assertDatabaseCount('report_schedules', 0);
    }

    #[Test]
    public function a_schedule_rejects_invalid_recipients(): void
    {
        [$user] = $this->actorWithSalesReports();

        $response = $this->actingAs($user)
            ->post(route('client.reports.sales.schedules.store'), [
                'report_type' => 'volume',
                'frequency' => 'weekly',
                'recipients' => 'not-an-email',
            ]);

        $response->assertSessionHasErrors(['recipients']);
        $this->assertDatabaseCount('report_schedules', 0);
    }

    #[Test]
    public function a_schedule_can_be_edited_and_deleted(): void
    {
        [$user, $workspace] = $this->actorWithSalesReports();

        $schedule = $this->schedule($workspace->id, [
            'filters' => ['agent_id' => 7],
        ]);

        $this->actingAs($user)
            ->put(route('client.reports.sales.schedules.update', $schedule->id), [
                'report_type' => 'agents',
                'frequency' => 'monthly',
                'recipients' => 'new@example.com',
            ])
            ->assertRedirect();

        $schedule->refresh();

        $this->assertSame('agents', $schedule->report_type);
        $this->assertSame('monthly', $schedule->frequency);
        $this->assertSame(['new@example.com'], $schedule->recipients);
        $this->assertSame([], $schedule->filters);

        $this->actingAs($user)
            ->delete(route('client.reports.sales.schedules.destroy', $schedule->id))
            ->assertRedirect();

        $this->assertDatabaseMissing('report_schedules', ['id' => $schedule->id]);
    }

    #[Test]
    public function a_schedule_from_another_workspace_cannot_be_changed(): void
    {
        [$user] = $this->actorWithSalesReports();
        [, $otherWorkspace] = $this->actorWithSalesReports();

        $schedule = $this->schedule($otherWorkspace->id);

        $this->actingAs($user)
            ->put(route('client.reports.sales.schedules.update', $schedule->id), [
                'report_type' => 'volume',
                'frequency' => 'weekly',
                'recipients' => 'owner@example.com',
            ])
            ->assertNotFound();

        $this->actingAs($user)
            ->delete(route('client.reports.sales.schedules.destroy', $schedule->id))
            ->assertNotFound();
    }

    #[Test]
    public function the_command_queues_only_due_schedules_and_advances_them(): void
    {
        Queue::fake();

        [$user, $workspace] = $this->actorWithSalesReports();

        $due = $this->schedule($workspace->id, ['next_run_at' => now()->subMinutes(5)]);
        $future = $this->schedule($workspace->id, ['next_run_at' => now()->addDay()]);
        $dueTimestamp = $future->next_run_at->timestamp;

        $this->artisan('reports:process-scheduled')->assertExitCode(0);

        Queue::assertPushed(SendScheduledReportJob::class, 1);
        Queue::assertPushed(SendScheduledReportJob::class, fn (SendScheduledReportJob $job): bool => $job->scheduleId === $due->id);

        $this->assertTrue($due->fresh()->next_run_at->isFuture());
        $this->assertSame($dueTimestamp, $future->fresh()->next_run_at->timestamp);
    }

    #[Test]
    public function the_command_skips_workspaces_whose_plan_lost_the_feature(): void
    {
        Queue::fake();

        [, $workspace] = $this->actorWithSalesReports(false);

        $due = $this->schedule($workspace->id, ['next_run_at' => now()->subMinutes(5)]);

        $this->artisan('reports:process-scheduled')->assertExitCode(0);

        Queue::assertNothingPushed();
        $this->assertTrue($due->fresh()->next_run_at->isFuture());
    }

    #[Test]
    public function the_job_emails_the_csv_report_to_every_recipient(): void
    {
        Mail::fake();

        [, $workspace] = $this->actorWithSalesReports();

        $schedule = $this->schedule($workspace->id, [
            'report_type' => 'volume',
            'frequency' => 'weekly',
            'recipients' => ['owner@example.com', 'sales@example.com'],
        ]);

        (new SendScheduledReportJob($schedule->id))->handle(app(SalesReportExporter::class));

        Mail::assertQueued(ScheduledReportMail::class, function (ScheduledReportMail $mail) use ($schedule): bool {
            return $mail->schedule->is($schedule)
                && $mail->hasTo('owner@example.com')
                && $mail->hasTo('sales@example.com')
                && $mail->filename === 'lead-volume.csv'
                && str_contains($mail->csv, 'Date,Leads');
        });
    }

    #[Test]
    public function the_job_does_not_email_when_the_plan_disables_reports(): void
    {
        Mail::fake();

        [, $workspace] = $this->actorWithSalesReports(false);

        $schedule = $this->schedule($workspace->id);

        (new SendScheduledReportJob($schedule->id))->handle(app(SalesReportExporter::class));

        Mail::assertNothingQueued();
    }

    #[Test]
    public function the_scheduled_mail_renders_with_its_csv_attachment(): void
    {
        [, $workspace] = $this->actorWithSalesReports();

        $schedule = $this->schedule($workspace->id)->load('workspace');

        $mail = new ScheduledReportMail(
            schedule: $schedule,
            period: 'Oct 20 – Oct 26, 2026',
            filename: 'lead-volume.csv',
            csv: "Date,Leads\n2026-10-20,3\n",
        );

        $this->assertStringContainsString('Lead Volume', $mail->render());

        $attachments = $mail->attachments();
        $this->assertCount(1, $attachments);
        $this->assertSame('lead-volume.csv', $attachments[0]->as);
        $this->assertSame('text/csv', $attachments[0]->mime);
    }

    #[Test]
    public function next_slots_and_report_windows_follow_the_frequency(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-16 09:00:00'));

        try {
            $daily = ReportSchedule::nextRunAt('daily', now());
            $weekly = ReportSchedule::nextRunAt('weekly', now());
            $monthly = ReportSchedule::nextRunAt('monthly', now());

            $this->assertSame('2026-11-17 08:00:00', $daily->toDateTimeString());
            $this->assertSame('2026-11-23 08:00:00', $weekly->toDateTimeString());
            $this->assertSame('2026-12-01 08:00:00', $monthly->toDateTimeString());

            $this->assertSame('2026-11-15 00:00:00', ReportSchedule::periodFor('daily', now())['from']->toDateTimeString());
            $this->assertSame('2026-11-15 23:59:59', ReportSchedule::periodFor('daily', now())['to']->toDateTimeString());
            $this->assertSame('2026-11-09 00:00:00', ReportSchedule::periodFor('weekly', now())['from']->toDateTimeString());
            $this->assertSame('2026-11-15 23:59:59', ReportSchedule::periodFor('weekly', now())['to']->toDateTimeString());
            $this->assertSame('2026-10-01 00:00:00', ReportSchedule::periodFor('monthly', now())['from']->toDateTimeString());
            $this->assertSame('2026-10-31 23:59:59', ReportSchedule::periodFor('monthly', now())['to']->toDateTimeString());
        } finally {
            Carbon::setTestNow();
        }
    }
}
