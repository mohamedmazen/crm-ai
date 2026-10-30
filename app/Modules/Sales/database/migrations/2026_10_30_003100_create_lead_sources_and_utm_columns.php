<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('lead_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->string('name', 64);
            $table->enum('type', [
                'form',
                'webhook',
                'api',
                'whatsapp',
                'meta_ads',
                'imap',
                'manual',
                'scraper',
                'import',
            ])->default('webhook');
            $table->enum('status', ['active', 'error', 'inactive'])->default('active');
            $table->string('webhook_token', 64)->nullable()->unique();
            $table->timestamp('last_lead_at')->nullable();
            $table->text('last_error')->nullable();
            $table->json('config')->nullable();
            $table->timestamps();

            $table->index('workspace_id');
            $table->index(['workspace_id', 'type']);
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->string('source', 64)->nullable()->after('whatsapp_status')->index();
            $table->foreignId('lead_source_id')->nullable()->after('source')->constrained('lead_sources')->nullOnDelete();
            $table->string('utm_source', 128)->nullable()->after('lead_source_id')->index();
            $table->string('utm_medium', 128)->nullable()->after('utm_source');
            $table->string('utm_campaign', 128)->nullable()->after('utm_medium');
            $table->string('utm_term', 128)->nullable()->after('utm_campaign');
            $table->string('utm_content', 128)->nullable()->after('utm_term');
            $table->string('referrer', 512)->nullable()->after('utm_content');
            $table->string('landing_page', 512)->nullable()->after('referrer');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropForeign(['lead_source_id']);
            $table->dropColumn([
                'source',
                'lead_source_id',
                'utm_source',
                'utm_medium',
                'utm_campaign',
                'utm_term',
                'utm_content',
                'referrer',
                'landing_page',
            ]);
        });

        Schema::dropIfExists('lead_sources');
    }
};

