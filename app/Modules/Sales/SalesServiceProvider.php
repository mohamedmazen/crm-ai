<?php

namespace App\Modules\Sales;

use App\Modules\Sales\Console\ProcessSequenceStepsCommand;
use Illuminate\Support\ServiceProvider;

class SalesServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/routes/web.php');
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([
                ProcessSequenceStepsCommand::class,
            ]);
        }
    }
}
