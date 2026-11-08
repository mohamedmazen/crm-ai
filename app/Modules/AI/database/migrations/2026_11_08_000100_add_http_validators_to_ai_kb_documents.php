<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_kb_documents', function (Blueprint $table) {
            // Cache validators for URL/sitemap sources, so a scheduled refresh
            // can send If-None-Match / If-Modified-Since and skip re-embedding
            // when the page has not changed.
            $table->string('http_etag', 255)->nullable()->after('source_ref');
            $table->string('http_last_modified', 64)->nullable()->after('http_etag');
        });
    }

    public function down(): void
    {
        Schema::table('ai_kb_documents', function (Blueprint $table) {
            $table->dropColumn(['http_etag', 'http_last_modified']);
        });
    }
};
