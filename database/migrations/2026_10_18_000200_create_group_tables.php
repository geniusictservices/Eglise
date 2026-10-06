<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Les groupes : cellules de quartier, chorales, groupes de prière… toujours avec un responsable.
        Schema::create('groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('kind', 20)->default('cell');
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('leader_member_id')->constrained('members')->cascadeOnDelete();
            $table->text('description')->nullable();
            $table->unsignedTinyInteger('meeting_day')->nullable();
            $table->time('meeting_time')->nullable();
            $table->string('place')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('group_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->string('role', 10)->default('member');
            $table->date('joined_on')->nullable();
            $table->timestamps();
            $table->unique(['group_id', 'member_id']);
        });

        Schema::create('group_meetings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->date('held_on');
            $table->string('topic')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedSmallInteger('visitors')->default(0);
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['group_id', 'held_on']);
        });

        Schema::create('group_attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_meeting_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->string('status', 10);
            $table->unique(['group_meeting_id', 'member_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_attendances');
        Schema::dropIfExists('group_meetings');
        Schema::dropIfExists('group_members');
        Schema::dropIfExists('groups');
    }
};
