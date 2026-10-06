<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Le calendrier : cultes, prières, événements. Un culte se répète ; chaque date est une « occurrence ».
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('kind', 20)->default('service');
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->string('place')->nullable();
            $table->text('description')->nullable();
            // Pour qui : toute la communauté, un département ou un groupe.
            $table->string('audience', 12)->default('all');
            $table->foreignId('department_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('group_id')->nullable()->constrained()->cascadeOnDelete();
            // Répétition : aucune, chaque semaine, chaque mois à la même date, ou le n-ième jour du mois (« 1er dimanche »).
            $table->string('repeats', 20)->default('none');
            $table->date('repeat_until')->nullable();
            $table->json('skipped_dates')->nullable();
            $table->boolean('registration')->default(false);
            $table->unsignedInteger('capacity')->nullable();
            $table->boolean('tracks_attendance')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('event_registrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->date('occurs_on');
            $table->foreignId('member_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name')->nullable();
            $table->string('phone', 30)->nullable();
            $table->foreignId('registered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['event_id', 'occurs_on']);
        });

        // Les présences d'une occurrence : par effectifs, par pointage nominatif, ou les deux ; rien n'est obligatoire.
        Schema::create('attendance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->date('occurs_on');
            $table->unsignedInteger('men')->nullable();
            $table->unsignedInteger('women')->nullable();
            $table->unsignedInteger('children')->nullable();
            $table->unsignedInteger('visitors')->nullable();
            $table->unsignedInteger('total')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['event_id', 'occurs_on']);
        });

        Schema::create('attendance_checkins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendance_record_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->unique(['attendance_record_id', 'member_id']);
        });

        // Les visiteurs nommés : à accueillir et à revoir.
        Schema::create('attendance_visitors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendance_record_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('phone', 30)->nullable();
            $table->string('invited_by')->nullable();
            $table->string('notes')->nullable();
            $table->timestamp('followed_up_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_visitors');
        Schema::dropIfExists('attendance_checkins');
        Schema::dropIfExists('attendance_records');
        Schema::dropIfExists('event_registrations');
        Schema::dropIfExists('events');
    }
};
