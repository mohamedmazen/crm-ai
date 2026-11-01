<?php

namespace Tests\Feature\Security;

use App\Models\Client;
use App\Models\User;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Media uploads are written to the public disk and served back to browsers, so
 * the validation rules must never accept executable or script-bearing files.
 * These tests pin the allow-list against a `.php` shell and an SVG carrying
 * inline JavaScript.
 */
class UploadValidationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{user: User, conversation: Conversation}
     */
    private function conversationContext(): array
    {
        $ctx = $this->createWorkspaceContext();

        $contact = Contact::factory()->create(['workspace_id' => $ctx['workspace']->id]);

        $account = ChannelAccount::create([
            'workspace_id' => $ctx['workspace']->id,
            'channel' => 'whatsapp',
            'provider' => 'meta',
            'display_name' => 'WA',
            'status' => 'active',
        ]);

        $conversation = Conversation::create([
            'workspace_id' => $ctx['workspace']->id,
            'channel_account_id' => $account->id,
            'contact_id' => $contact->id,
            'status' => 'open',
        ]);

        return ['user' => $ctx['user'], 'conversation' => $conversation];
    }

    #[Test]
    public function inbox_upload_rejects_a_php_script(): void
    {
        $ctx = $this->conversationContext();

        $file = UploadedFile::fake()->createWithContent('shell.php', '<?php echo "pwned";');

        $this->actingAs($ctx['user'])
            ->post(route('client.inbox.upload-media', $ctx['conversation']), ['file' => $file], [
                'Accept' => 'application/json',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');
    }

    #[Test]
    public function inbox_upload_rejects_an_svg_with_inline_javascript(): void
    {
        $ctx = $this->conversationContext();

        $file = UploadedFile::fake()->createWithContent(
            'logo.svg',
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(document.cookie)</script></svg>',
        );

        $this->actingAs($ctx['user'])
            ->post(route('client.inbox.upload-media', $ctx['conversation']), ['file' => $file], [
                'Accept' => 'application/json',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');
    }

    #[Test]
    public function branding_logo_upload_rejects_an_svg(): void
    {
        $admin = $this->createSuperAdmin();

        $file = UploadedFile::fake()->createWithContent(
            'logo.svg',
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
        );

        $this->actingAs($admin, 'admin')
            ->post(route('admin.settings.logo.upload'), ['logo' => $file], [
                'Accept' => 'application/json',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('logo');
    }

    #[Test]
    public function favicon_upload_rejects_an_svg(): void
    {
        $admin = $this->createSuperAdmin();

        $file = UploadedFile::fake()->createWithContent(
            'favicon.svg',
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
        );

        $this->actingAs($admin, 'admin')
            ->post(route('admin.settings.favicon.upload'), ['favicon' => $file], [
                'Accept' => 'application/json',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('favicon');
    }

    #[Test]
    public function client_branding_upload_rejects_a_php_script_disguised_as_an_image(): void
    {
        $admin = $this->createSuperAdmin();
        $client = Client::create([
            'name' => 'Acme',
            'email' => 'acme@example.com',
            'status' => Client::STATUS_ACTIVE,
        ]);

        $file = UploadedFile::fake()->createWithContent('logo.php', '<?php echo "pwned";');

        $this->actingAs($admin, 'admin')
            ->post(route('admin.clients.branding', $client), ['logo' => $file], [
                'Accept' => 'application/json',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('logo');
    }
}
