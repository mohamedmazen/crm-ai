<?php

namespace App\Modules\Whatsapp;

use App\Modules\Shared\Services\ChannelManager;
use App\Modules\Whatsapp\Console\WhatsappCheckConnectionHealthCommand;
use App\Modules\Whatsapp\Console\WhatsappPruneWebhookEventsCommand;
use App\Modules\Whatsapp\Console\WhatsappReplayWebhookEventsCommand;
use App\Modules\Whatsapp\Services\WhatsappDriver;
use Illuminate\Support\ServiceProvider;

class WhatsappServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/routes/web.php');
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');

        // Register the WhatsApp channel driver so the inbox can resolve it
        // when sending outbound replies via ChannelManager::driver('whatsapp').
        $this->app->make(ChannelManager::class)
            ->register('whatsapp', WhatsappDriver::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                WhatsappCheckConnectionHealthCommand::class,
                WhatsappReplayWebhookEventsCommand::class,
                WhatsappPruneWebhookEventsCommand::class,
            ]);
        }
    }
}
