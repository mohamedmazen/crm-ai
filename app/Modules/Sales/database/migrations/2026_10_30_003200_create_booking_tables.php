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
        // 1. Meeting Types
        Schema::create('meeting_types', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 128);
            $table->string('slug', 128)->unique();
            $table->text('description')->nullable();
            $table->unsignedInteger('duration_minutes')->default(30);
            $table->unsignedInteger('buffer_before')->default(0);
            $table->unsignedInteger('buffer_after')->default(0);
            $table->enum('location_type', ['google_meet', 'phone', 'in_person', 'custom'])->default('google_meet');
            $table->string('location_details', 256)->nullable();
            $table->unsignedInteger('max_per_day')->nullable();
            $table->unsignedInteger('min_notice_hours')->default(4);
            $table->unsignedInteger('booking_window_days')->default(14);
            $table->string('color', 16)->default('#6366f1');
            $table->json('questions')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();

            $table->index(['workspace_id', 'status']);
        });

        // 2. Availability Rules (Weekly working hours)
        Schema::create('availability_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedTinyInteger('day_of_week'); // 0=Sunday, 1=Monday ... 6=Saturday
            $table->time('start_time');
            $table->time('end_time');
            $table->string('timezone', 64)->default('UTC');
            $table->timestamps();

            $table->index(['workspace_id', 'user_id', 'day_of_week']);
        });

        // 3. Availability Overrides (Specific dates off or custom hours)
        Schema::create('availability_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->date('date');
            $table->boolean('available')->default(false);
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'user_id', 'date']);
        });

        // 4. Bookings
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignId('meeting_type_id')->constrained('meeting_types')->cascadeOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained('leads')->nullOnDelete();
            $table->foreignId('host_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->enum('status', ['confirmed', 'cancelled', 'completed', 'no_show'])->default('confirmed');
            $table->string('meet_link', 512)->nullable();
            $table->string('cancel_token', 64)->unique();
            $table->string('reschedule_token', 64)->unique();
            $table->string('guest_name', 256)->nullable();
            $table->string('guest_email', 256)->nullable();
            $table->string('guest_phone', 32)->nullable();
            $table->string('guest_timezone', 64)->default('UTC');
            $table->json('answers')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->timestamps();

            $table->index(['meeting_type_id', 'starts_at']);
            $table->index(['workspace_id', 'status', 'starts_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bookings');
        Schema::dropIfExists('availability_overrides');
        Schema::dropIfExists('availability_rules');
        Schema::dropIfExists('meeting_types');
    }
};
