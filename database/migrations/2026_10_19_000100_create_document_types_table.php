<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Les modèles de documents : ceux du siège valent pour toutes ses paroisses ; chacune peut ajouter les siens.
        Schema::create('document_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            // Une paroisse qui adapte un modèle du siège en garde une copie qui le remplace chez elle.
            $table->foreignId('replaces_id')->nullable()->constrained('document_types')->nullOnDelete();
            $table->string('key', 40)->nullable();
            $table->string('name', 120);
            $table->string('title', 160);
            // Pour qui : un membre, une personne inscrite dans un ancien registre, ou un destinataire libre.
            $table->string('subject', 10)->default('member');
            $table->string('life_event_type', 30)->nullable();
            $table->text('body');
            $table->json('fields')->nullable();
            $table->string('code', 12);
            $table->string('number_format', 60)->default('{CODE}/{SIGLE}/{ANNEE}/{NUMERO}');
            $table->string('signatory_title', 80)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_types');
    }
};
