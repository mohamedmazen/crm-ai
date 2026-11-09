<?php

namespace Database\Factories;

use App\Modules\Whatsapp\Models\WhatsappBusinessAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

class WhatsappBusinessAccountFactory extends Factory
{
    protected $model = WhatsappBusinessAccount::class;

    public function definition(): array
    {
        return [
            'workspace_id' => 1,
            'waba_id' => $this->faker->numerify('##############'),
            'webhook_verify_token' => 'test-verify-token',
            'status' => 'active',
            'credentials' => ['system_user_token' => 'test-token', 'phone_number_id' => '12345'],
        ];
    }

    /** A number linked to both the WhatsApp Business app and Cloud API. */
    public function coexistence(): static
    {
        return $this->state(fn (): array => [
            'is_coexistence' => true,
            'coexistence_status' => WhatsappBusinessAccount::COEXISTENCE_ACTIVE,
            'coexistence_linked_at' => now(),
            'history_sync_status' => 'none',
        ]);
    }
}
