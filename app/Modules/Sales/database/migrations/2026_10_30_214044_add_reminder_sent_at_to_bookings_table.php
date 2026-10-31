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
        Schema::table('bookings', function (Blueprint $table) {
            // Set once a reminder has gone out so an hourly sweep cannot send
            // the same guest the same reminder twice.
            $table->timestamp('reminder_sent_at')->nullable()->after('cancelled_at');
            $table->index(['status', 'starts_at', 'reminder_sent_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex(['status', 'starts_at', 'reminder_sent_at']);
            $table->dropColumn('reminder_sent_at');
        });
    }
};
