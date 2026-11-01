<?php

namespace App\Modules\Shared;

use App\Modules\Shared\Console\GdprProcessCommand;
use Illuminate\Support\ServiceProvider;

class SharedServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/routes/web.php');
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([
                GdprProcessCommand::class,
            ]);
        }
    }
}
