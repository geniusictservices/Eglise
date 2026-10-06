<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // La vision : sur plusieurs années.
        Schema::create('visions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('statement')->nullable();
            $table->unsignedSmallInteger('starts_year');
            $table->unsignedSmallInteger('ends_year');
            $table->timestamps();
        });

        // Les objectifs d'un exercice, au service de la vision.
        Schema::create('plan_objectives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vision_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('fiscal_year');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('indicator')->nullable(); // « 100 nouveaux membres baptisés »
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
            $table->index(['organization_id', 'fiscal_year']);
        });

        // Les actions qui réalisent un objectif, avec leur avancement.
        Schema::create('plan_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_objective_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('responsible_member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->string('responsible_name')->nullable();
            $table->date('starts_on')->nullable();
            $table->date('due_on')->nullable();
            $table->decimal('estimated_cost', 14, 2)->nullable(); // en dollars
            $table->unsignedTinyInteger('progress')->default(0);
            $table->string('status', 12)->default('planned'); // planned, ongoing, done, cancelled
            $table->timestamps();
        });

        Schema::create('plan_action_updates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_action_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedTinyInteger('progress');
            $table->text('note')->nullable();
            $table->timestamps();
        });

        // Les réunions : conseil, comité, département, assemblée.
        Schema::create('meetings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('kind', 16)->default('council');
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->dateTime('held_at');
            $table->string('place')->nullable();
            $table->string('chair')->nullable();
            $table->string('secretary')->nullable();
            $table->text('agenda')->nullable();
            $table->longText('minutes')->nullable();
            $table->string('status', 12)->default('planned'); // planned, held
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('meeting_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meeting_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name')->nullable();
            $table->string('attendance', 12)->default('present'); // present, excused, absent
            $table->timestamps();
        });

        Schema::create('meeting_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meeting_id')->constrained()->cascadeOnDelete();
            $table->text('text');
            $table->string('responsible')->nullable();
            $table->date('due_on')->nullable();
            $table->foreignId('plan_action_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_done')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['meeting_decisions', 'meeting_participants', 'meetings', 'plan_action_updates', 'plan_actions', 'plan_objectives', 'visions'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
