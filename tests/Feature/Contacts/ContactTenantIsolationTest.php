<?php

namespace Tests\Feature\Contacts;

use App\Models\Plan;
use App\Modules\Shared\Models\Contact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The duplicate-contact check must never look outside the workspace.
     *
     * Before the fix, `Contact::where('workspace_id', ...)->orWhere('phone', ...)`
     * matched a contact owned by another tenant, so a workspace that had used up
     * its contact allowance could still create contacts whose phone/email
     * happened to exist elsewhere.
     */
    public function test_contact_limit_cannot_be_bypassed_by_a_contact_in_another_workspace(): void
    {
        $plan = Plan::factory()->create([
            'currency_code' => 'USD',
            'limits' => ['max_contacts' => 0],
        ]);

        $ctx = $this->createWorkspaceContext();
        $this->attachPlanToClient($ctx['client'], $plan);

        $other = $this->createWorkspaceContext();
        Contact::create([
            'workspace_id' => $other['workspace']->id,
            'phone_e164' => '+201000000001',
            'first_name' => 'Other tenant',
        ]);

        $response = $this->actingAs($ctx['user'])->post(route('client.contacts.store'), [
            'phone_e164' => '+201000000001',
            'first_name' => 'Mine',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error');

        $this->assertSame(0, Contact::where('workspace_id', $ctx['workspace']->id)->count());
    }

    public function test_an_existing_contact_in_the_same_workspace_still_skips_the_cap(): void
    {
        $plan = Plan::factory()->create([
            'currency_code' => 'USD',
            'limits' => ['max_contacts' => 0],
        ]);

        $ctx = $this->createWorkspaceContext();
        $this->attachPlanToClient($ctx['client'], $plan);

        $existing = Contact::create([
            'workspace_id' => $ctx['workspace']->id,
            'phone_e164' => '+201000000002',
            'first_name' => 'Existing',
        ]);

        $response = $this->actingAs($ctx['user'])->post(route('client.contacts.store'), [
            'phone_e164' => '+201000000002',
            'first_name' => 'Updated',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        // Upsert updates the existing row instead of creating a second one.
        $this->assertSame(1, Contact::where('workspace_id', $ctx['workspace']->id)->count());
        $this->assertSame('Updated', $existing->fresh()->first_name);
    }
}
