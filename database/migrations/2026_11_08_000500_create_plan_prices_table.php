<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $table->string('currency_code', 10);
            $table->unsignedBigInteger('monthly_price_cents')->nullable();
            $table->unsignedBigInteger('yearly_price_cents')->nullable();
            $table->timestamps();

            $table->unique(['plan_id', 'currency_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_prices');
    }
};
