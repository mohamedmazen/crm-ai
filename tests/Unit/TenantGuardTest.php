<?php

namespace Tests\Unit;

use App\Exceptions\TenantMismatchException;
use App\Modules\Shared\Models\Contact;
use App\Support\Tenancy\TenantGuard;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TenantGuardTest extends TestCase
{
    #[Test]
    public function owns_accepts_a_model_from_the_same_workspace(): void
    {
        $this->assertTrue(TenantGuard::owns(7, $this->model(7)));
    }

    #[Test]
    public function owns_rejects_a_model_from_another_workspace(): void
    {
        $this->assertFalse(TenantGuard::owns(7, $this->model(8)));
    }

    #[Test]
    public function owns_rejects_missing_models_and_missing_workspace_ids(): void
    {
        $this->assertFalse(TenantGuard::owns(7, null));
        $this->assertFalse(TenantGuard::owns(7, $this->model(null)));
    }

    #[Test]
    public function assert_owned_throws_on_a_cross_tenant_model(): void
    {
        $this->expectException(TenantMismatchException::class);

        TenantGuard::assertOwned(7, $this->model(8), 'contact');
    }

    #[Test]
    public function assert_owned_accepts_a_model_from_the_same_workspace(): void
    {
        TenantGuard::assertOwned(7, $this->model(7), 'contact');

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function find_owned_returns_null_for_non_positive_ids(): void
    {
        $this->assertNull(TenantGuard::findOwned(0, Contact::class, 5));
        $this->assertNull(TenantGuard::findOwned(1, Contact::class, 0));
    }

    private function model(?int $workspaceId): Model
    {
        $model = new class extends Model {};

        $model->setRawAttributes(['workspace_id' => $workspaceId]);

        return $model;
    }
}
