<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->string('name', 256);
            $table->string('domain', 256)->nullable();
            $table->string('industry', 128)->nullable();
            $table->string('size', 64)->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('website', 512)->nullable();
            $table->text('address')->nullable();
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->timestamps();

            $table->index('workspace_id');
            $table->index(['workspace_id', 'domain']);
            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('owner_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->unsignedBigInteger('company_id')->nullable()->after('workspace_id');

            $table->index(['workspace_id', 'company_id']);
            $table->foreign('company_id')->references('id')->on('companies')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
            $table->dropIndex(['workspace_id', 'company_id']);
            $table->dropColumn('company_id');
        });

        Schema::dropIfExists('companies');
    }
};
