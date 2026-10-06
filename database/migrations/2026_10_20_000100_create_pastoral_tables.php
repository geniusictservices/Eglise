<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Le suivi pastoral : une personne accompagnée (malade, endeuillée, catéchumène…), avec son fil de visites et de notes.
        Schema::create('pastoral_cases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->nullable()->constrained()->nullOnDelete();
            $table->string('person_name', 150)->nullable();
            $table->string('person_phone', 30)->nullable();
            $table->string('kind', 20);
            $table->string('title', 160);
            $table->string('status', 10)->default('open');
            $table->date('opened_on');
            $table->date('next_on')->nullable();
            $table->date('closed_on')->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['organization_id', 'status']);
        });

        // Une visite, un appel, une note. Une note confidentielle est chiffrée et ne se lit que par son auteur.
        Schema::create('pastoral_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pastoral_case_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kind', 10)->default('note');
            $table->date('happened_on');
            $table->text('body');
            $table->boolean('is_confidential')->default(false);
            $table->timestamps();
        });

        // Les demandes de prière : reçues par l'équipe pastorale, depuis le secrétariat ou l'espace membre.
        Schema::create('prayer_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->nullable()->constrained()->nullOnDelete();
            $table->string('requester_name', 150)->nullable();
            $table->string('subject', 160);
            $table->text('body')->nullable();
            $table->boolean('is_private')->default(true);
            $table->string('status', 10)->default('open');
            $table->text('answer')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('handled_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prayer_requests');
        Schema::dropIfExists('pastoral_notes');
        Schema::dropIfExists('pastoral_cases');
    }
};
