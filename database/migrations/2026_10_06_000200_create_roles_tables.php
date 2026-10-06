<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Les rôles appartiennent à une organisation et sont utilisables
        // dans toute sa descendance.
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('key', 60)->nullable(); // clé du modèle d'origine (administrateur, tresorier…)
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('permissions');
            // Le rôle Administrateur ne peut être ni supprimé ni restreint.
            $table->boolean('is_locked')->default(false);
            $table->timestamps();

            $table->unique(['organization_id', 'name']);
        });

        // Un utilisateur reçoit un rôle sur un nœud précis de la hiérarchie.
        Schema::create('role_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            // Le rôle s'étend-il aux niveaux inférieurs (régions, paroisses) ?
            $table->boolean('includes_descendants')->default(false);
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'role_id', 'organization_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_assignments');
        Schema::dropIfExists('roles');
    }
};
