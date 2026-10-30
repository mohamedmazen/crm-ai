<?php

namespace Database\Factories;

use App\Models\Workspace;
use App\Modules\Leads\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Modules\Leads\Models\Company>
 */
class CompanyFactory extends Factory
{
    protected $model = Company::class;

    public function definition(): array
    {
        $name = fake()->company();
        $domain = fake()->unique()->domainName();

        return [
            'workspace_id' => Workspace::factory(),
            'name' => $name,
            'domain' => $domain,
            'industry' => fake()->randomElement(Company::INDUSTRIES),
            'size' => fake()->randomElement(array_keys(Company::SIZES)),
            'phone' => fake()->phoneNumber(),
            'website' => 'https://'.$domain,
            'address' => fake()->address(),
            'owner_id' => null,
        ];
    }
}

