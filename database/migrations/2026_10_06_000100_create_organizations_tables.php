<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Une organisation est un nœud de la hiérarchie : une église indépendante,
        // ou le siège, une région, un secteur, une paroisse d'une dénomination.
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('organizations')->restrictOnDelete();
            // Chemin matérialisé des identifiants, ex. "1/4/9/" : sert à trouver les descendants.
            $table->string('path', 500)->default('')->index();
            $table->unsignedTinyInteger('depth')->default(0);
            // Libellé du niveau, choisi par la communauté : Siège, Région, Paroisse…
            $table->string('level_label', 60);
            $table->string('name');
            $table->string('short_name', 60)->nullable();
            $table->string('slug')->unique();
            $table->string('country', 2)->default('CD');
            $table->string('province')->nullable();
            $table->string('city')->nullable();
            $table->string('address')->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('email')->nullable();
            $table->string('logo_path')->nullable();
            $table->string('locale', 5)->default('fr');
            $table->string('timezone', 64)->default('Africa/Lubumbashi');
            // Libellés renommés par la communauté : {"pasteur": "Imam", ...}
            $table->json('terminology')->nullable();
            $table->json('settings')->nullable();
            // trial, active, grace, read_only, suspended
            $table->string('status', 20)->default('trial');
            $table->timestamp('trial_ends_at')->nullable();
            // Le support Genius ICT ne peut entrer qu'avec cet accord, révocable.
            $table->timestamp('support_access_until')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreign('current_organization_id')->references('id')->on('organizations')->nullOnDelete();
        });

        // Une paroisse inscrite seule demande à rejoindre son siège.
        Schema::create('attachment_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('target_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('status', 20)->default('pending'); // pending, accepted, refused, cancelled
            $table->text('message')->nullable();
            $table->text('decision_note')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachment_requests');
        Schema::table('users', fn (Blueprint $table) => $table->dropForeign(['current_organization_id']));
        Schema::dropIfExists('organizations');
    }
};
