<?php

namespace Database\Factories;

use App\Modules\Reporting\Models\ReportSchedule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReportSchedule>
 */
class ReportScheduleFactory extends Factory
{
    protected $model = ReportSchedule::class;

    public function definition(): array
    {
        return [
            'workspace_id' => 1,
            'report_type' => 'volume',
            'frequency' => 'weekly',
            'format' => 'csv',
            'recipients' => [$this->faker->safeEmail()],
            'filters' => [],
            'next_run_at' => now()->addDay(),
        ];
    }

    public function due(): static
    {
        return $this->state(fn (): array => ['next_run_at' => now()->subMinute()]);
    }
}
