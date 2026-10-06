<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Statuts des membres : définis par le siège (ou l'église indépendante).
        Schema::create('member_statuses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->string('color', 20)->default('ink');
            // Compte-t-il dans l'effectif ? (oui pour « Membre », non pour « Décédé »)
            $table->boolean('counts_as_member')->default(true);
            $table->boolean('is_default')->default(false);
            // Statut gardé pour harmonisation après un rattachement à un siège.
            $table->boolean('needs_harmonization')->default(false);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        // Fonctions et titres : diacre, ancien, évangéliste, choriste…
        Schema::create('member_functions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        // Champs ajoutés par la communauté à la fiche des membres.
        Schema::create('member_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('key', 60);
            $table->string('label', 80);
            $table->string('type', 20); // text, number, date, select, boolean, phone
            $table->json('options')->nullable();
            $table->boolean('required')->default(false);
            $table->boolean('sensitive')->default(false);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
            $table->unique(['organization_id', 'key']);
        });

        Schema::create('households', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('district', 100)->nullable(); // quartier
            $table->string('street', 120)->nullable();   // avenue
            $table->string('house_number', 30)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('phone', 20)->nullable();
            $table->unsignedBigInteger('head_member_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('number', 60)->nullable();
            $table->unsignedSmallInteger('number_year')->nullable();
            $table->unsignedInteger('number_sequence')->nullable();
            $table->string('last_name', 80);              // nom
            $table->string('middle_name', 80)->nullable(); // post-nom
            $table->string('first_name', 80)->nullable();  // prénom
            $table->char('gender', 1)->nullable();          // M, F
            $table->date('birth_date')->nullable();
            $table->string('birth_place', 100)->nullable();
            $table->string('phone', 20)->nullable()->index();
            $table->string('phone2', 20)->nullable();
            $table->string('email')->nullable();
            $table->string('district', 100)->nullable();
            $table->string('street', 120)->nullable();
            $table->string('house_number', 30)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('profession', 100)->nullable();
            $table->string('marital_status', 20)->nullable();
            $table->string('education_level', 60)->nullable();
            $table->string('origin_church', 150)->nullable();
            $table->string('emergency_contact_name', 120)->nullable();
            $table->string('emergency_contact_phone', 20)->nullable();
            $table->string('preferred_language', 5)->nullable();
            $table->string('photo_path')->nullable();
            $table->date('joined_on')->nullable();
            $table->foreignId('status_id')->nullable()->constrained('member_statuses')->nullOnDelete();
            $table->foreignId('household_id')->nullable()->constrained()->nullOnDelete();
            $table->string('household_role', 20)->nullable(); // chef, conjoint, enfant, dependant
            $table->json('custom')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'last_name']);
            $table->unique(['organization_id', 'number']);
        });

        Schema::table('households', function (Blueprint $table) {
            $table->foreign('head_member_id')->references('id')->on('members')->nullOnDelete();
        });

        Schema::create('member_status_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_status_id')->nullable()->constrained('member_statuses')->nullOnDelete();
            $table->foreignId('to_status_id')->nullable()->constrained('member_statuses')->nullOnDelete();
            $table->date('changed_on');
            $table->string('reason')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('member_function_terms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->foreignId('function_id')->constrained('member_functions')->cascadeOnDelete();
            $table->date('started_on')->nullable();
            $table->date('ended_on')->nullable();
            $table->string('note')->nullable();
            $table->timestamps();
        });

        // Étapes de vie : baptême, mariage… avec le numéro du registre officiel (même ancien).
        Schema::create('life_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30);
            $table->string('label', 80)->nullable(); // pour le type « autre »
            $table->date('occurred_on')->nullable();
            $table->string('place', 150)->nullable();
            $table->string('officiant', 120)->nullable();
            $table->string('witnesses')->nullable();
            $table->string('register_number', 60)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('kind', 20)->default('ministry'); // ministry, administrative
            $table->text('description')->nullable();
            $table->string('color', 20)->default('ink');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('department_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->string('role', 20)->default('member'); // leader, deputy, member
            $table->date('joined_on')->nullable();
            $table->timestamps();
            $table->unique(['department_id', 'member_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('department_members');
        Schema::dropIfExists('departments');
        Schema::dropIfExists('life_events');
        Schema::dropIfExists('member_function_terms');
        Schema::dropIfExists('member_status_changes');
        Schema::table('households', fn (Blueprint $table) => $table->dropForeign(['head_member_id']));
        Schema::dropIfExists('members');
        Schema::dropIfExists('households');
        Schema::dropIfExists('member_fields');
        Schema::dropIfExists('member_functions');
        Schema::dropIfExists('member_statuses');
    }
};
