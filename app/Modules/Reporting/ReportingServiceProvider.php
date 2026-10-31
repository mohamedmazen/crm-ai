<?php

namespace App\Modules\Reporting;

use App\Modules\Reporting\Console\ProcessScheduledReportsCommand;
use Illuminate\Support\ServiceProvider;

class ReportingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/routes/web.php');
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([
                ProcessScheduledReportsCommand::class,
            ]);
        }
    }
}
