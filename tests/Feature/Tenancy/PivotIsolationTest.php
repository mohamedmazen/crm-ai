<?php

namespace Tests\Feature\Tenancy;

use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\ContactTag;
use App\Modules\Shared\Models\Segment;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The database, not only the controllers, refuses to link rows from two
 * different workspaces through a pivot table. Each pivot carries its tenant and
 * composite foreign keys tie that column to both ends of the link.
 */
class PivotIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_contact_tag_links_cannot_cross_workspaces(): void
    {
        ['workspace' => $owner] = $this->createWorkspaceContext();
        ['workspace' => $other] = $this->createWorkspaceContext();

        $contact = Contact::factory()->create(['workspace_id' => $owner->id]);
        $tag = ContactTag::create(['workspace_id' => $other->id, 'name' => 'Foreign']);

        $this->assertRowRejected('contact_tag_pivot', [
            'contact_id' => $contact->id,
            'tag_id' => $tag->id,
            'workspace_id' => $owner->id,
        ]);
    }

    public function test_segment_links_cannot_cross_workspaces(): void
    {
        ['workspace' => $owner] = $this->createWorkspaceContext();
        ['workspace' => $other] = $this->createWorkspaceContext();

        $segment = Segment::create(['workspace_id' => $owner->id, 'name' => 'VIPs', 'type' => 'static']);
        $contact = Contact::factory()->create(['workspace_id' => $other->id]);

        $this->assertRowRejected('segment_contact', [
            'segment_id' => $segment->id,
            'contact_id' => $contact->id,
            'workspace_id' => $owner->id,
        ]);
    }

    public function test_lead_tag_links_cannot_cross_workspaces(): void
    {
        ['workspace' => $owner] = $this->createWorkspaceContext();
        ['workspace' => $other] = $this->createWorkspaceContext();

        $leadId = DB::table('leads')->insertGetId(['workspace_id' => $owner->id]);
        $tagId = DB::table('lead_tags')->insertGetId(['workspace_id' => $other->id, 'name' => 'Foreign']);

        $this->assertRowRejected('lead_tag_pivot', [
            'lead_id' => $leadId,
            'tag_id' => $tagId,
            'workspace_id' => $owner->id,
        ]);
    }

    public function test_duplicate_group_members_cannot_cross_workspaces(): void
    {
        ['workspace' => $owner] = $this->createWorkspaceContext();
        ['workspace' => $other] = $this->createWorkspaceContext();

        $groupId = DB::table('lead_duplicate_groups')->insertGetId(['workspace_id' => $owner->id]);
        $leadId = DB::table('leads')->insertGetId(['workspace_id' => $other->id]);

        $this->assertRowRejected('lead_duplicate_group_members', [
            'group_id' => $groupId,
            'lead_id' => $leadId,
            'workspace_id' => $owner->id,
        ]);
    }

    public function test_inbox_labels_cannot_be_attached_to_another_workspaces_conversation(): void
    {
        ['workspace' => $owner] = $this->createWorkspaceContext();
        ['workspace' => $other] = $this->createWorkspaceContext();

        $contact = Contact::factory()->create(['workspace_id' => $owner->id]);
        $conversationId = DB::table('conversations')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'workspace_id' => $owner->id,
            'contact_id' => $contact->id,
        ]);
        $labelId = DB::table('inbox_labels')->insertGetId(['workspace_id' => $other->id, 'name' => 'Foreign']);

        $this->assertRowRejected('inbox_label_conversation', [
            'label_id' => $labelId,
            'conversation_id' => $conversationId,
            'workspace_id' => $owner->id,
        ]);
    }

    public function test_social_posts_cannot_target_another_workspaces_account(): void
    {
        ['workspace' => $owner] = $this->createWorkspaceContext();
        ['workspace' => $other] = $this->createWorkspaceContext();

        $postId = DB::table('social_media_posts')->insertGetId(['workspace_id' => $owner->id]);
        $accountId = DB::table('social_media_accounts')->insertGetId([
            'workspace_id' => $other->id,
            'network' => 'facebook',
            'account_id' => 'foreign-account',
            'access_token' => 'token',
        ]);

        $this->assertRowRejected('social_media_post_accounts', [
            'post_id' => $postId,
            'social_account_id' => $accountId,
            'workspace_id' => $owner->id,
        ]);
    }

    public function test_eloquent_attach_stamps_the_owning_workspace(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();

        $contact = Contact::factory()->create(['workspace_id' => $workspace->id]);
        $tag = ContactTag::create(['workspace_id' => $workspace->id, 'name' => 'VIP']);
        $segment = Segment::create(['workspace_id' => $workspace->id, 'name' => 'VIPs', 'type' => 'static']);

        $contact->tags()->attach($tag->id);
        $contact->segments()->attach($segment->id);

        $this->assertSame($workspace->id, (int) DB::table('contact_tag_pivot')->value('workspace_id'));
        $this->assertSame($workspace->id, (int) DB::table('segment_contact')->value('workspace_id'));
    }

    public function test_eloquent_attach_cannot_import_a_foreign_row(): void
    {
        ['workspace' => $owner] = $this->createWorkspaceContext();
        ['workspace' => $other] = $this->createWorkspaceContext();

        $contact = Contact::factory()->create(['workspace_id' => $owner->id]);
        $tag = ContactTag::create(['workspace_id' => $other->id, 'name' => 'Foreign']);

        $this->expectException(QueryException::class);

        $contact->tags()->attach($tag->id);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function assertRowRejected(string $table, array $row): void
    {
        try {
            DB::table($table)->insert($row);
        } catch (QueryException $e) {
            $this->assertSame(
                '23000',
                (string) $e->getCode(),
                "Unexpected failure while inserting into [{$table}]: {$e->getMessage()}",
            );
            $this->assertSame(0, DB::table($table)->count(), "A rejected row was still stored in [{$table}].");

            return;
        }

        $this->fail("The database accepted a cross-tenant row in [{$table}].");
    }
}
