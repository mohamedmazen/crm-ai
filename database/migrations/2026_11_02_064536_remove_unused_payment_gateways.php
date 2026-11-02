<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Gateways removed from the application. */
    private const REMOVED_GATEWAYS = [
        'paddle',
        'razorpay',
        'cashfree',
        'tap',
        'paystack',
        'xendit',
        'mollie',
        'square',
        'iyzico',
        'mercadopago',
    ];

    public function up(): void
    {
        DB::table('payment_gateway_configs')
            ->whereIn('gateway', self::REMOVED_GATEWAYS)
            ->delete();

        Schema::dropIfExists('iyzico_checkout_sessions');
        Schema::dropIfExists('iyzico_pricing_plans');

        if (Schema::hasColumn('plans', 'paddle_monthly_id')) {
            Schema::table('plans', function (Blueprint $table) {
                $table->dropColumn(['paddle_monthly_id', 'paddle_yearly_id']);
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('plans', 'paddle_monthly_id')) {
            Schema::table('plans', function (Blueprint $table) {
                $table->string('paddle_monthly_id')->nullable()->after('stripe_yearly_id');
                $table->string('paddle_yearly_id')->nullable()->after('paddle_monthly_id');
            });
        }

        if (! Schema::hasTable('iyzico_pricing_plans')) {
            Schema::create('iyzico_pricing_plans', function (Blueprint $table) {
                $table->id();
                $table->foreignId('plan_id')->constrained('plans')->cascadeOnDelete();
                $table->string('billing_cycle', 10);
                $table->string('mode', 10);
                $table->string('fingerprint', 64)->unique();
                $table->string('product_reference_code');
                $table->string('pricing_plan_reference_code');
                $table->timestamps();

                $table->index(['mode', 'pricing_plan_reference_code'], 'iyzico_plans_mode_ref_idx');
            });
        }

        if (! Schema::hasTable('iyzico_checkout_sessions')) {
            Schema::create('iyzico_checkout_sessions', function (Blueprint $table) {
                $table->id();
                $table->string('token', 191)->unique();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('plan_id')->constrained('plans')->cascadeOnDelete();
                $table->string('billing_cycle', 10);
                $table->string('pricing_plan_reference_code');
                $table->longText('checkout_form_content');
                $table->timestamp('expires_at');
                $table->timestamp('consumed_at')->nullable();
                $table->timestamps();

                $table->index('expires_at');
            });
        }
    }
};
