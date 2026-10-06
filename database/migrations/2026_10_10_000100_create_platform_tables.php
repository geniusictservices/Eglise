<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Espace Genius ICT : équipe, réglages, offres et tarifs, abonnements, textes juridiques. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Rôle dans l'équipe Genius ICT : direction, commercial, support, contenu.
            $table->string('platform_role', 20)->nullable()->after('is_platform_staff');
            $table->unsignedInteger('terms_version')->nullable()->after('platform_role');
            $table->timestamp('terms_accepted_at')->nullable()->after('terms_version');
        });

        Schema::create('platform_settings', function (Blueprint $table) {
            $table->string('key', 60)->primary();
            $table->json('value')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('key', 30)->unique();
            $table->string('name', 60);
            $table->string('meaning', 60)->nullable();
            $table->string('description')->nullable();
            $table->json('modules')->nullable();
            $table->boolean('featured')->default(false);
            $table->boolean('quote_only')->default(false); // sur devis
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        // Chaque changement de tarif est une nouvelle ligne, datée : l'historique reste.
        Schema::create('plan_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $table->string('tier', 20);
            $table->decimal('monthly_usd', 10, 2);
            $table->date('effective_from');
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['plan_id', 'tier', 'effective_from']);
        });

        // Une ligne par période payée : le prix y est figé jusqu'au renouvellement.
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained()->restrictOnDelete();
            $table->string('tier', 20);
            $table->string('cycle', 10); // monthly, annual
            $table->unsignedSmallInteger('months');
            $table->decimal('monthly_usd', 10, 2);   // tarif mensuel appliqué
            $table->decimal('amount_usd', 10, 2);    // montant de la période
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('payment_method', 30)->nullable(); // M-Pesa, Airtel Money, Orange Money, espèces, banque
            $table->string('payment_reference', 100)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['organization_id', 'ends_on']);
        });

        Schema::create('legal_documents', function (Blueprint $table) {
            $table->id();
            $table->string('key', 30); // terms, privacy
            $table->unsignedInteger('version');
            $table->string('title');
            $table->longText('body');
            $table->string('summary')->nullable(); // ce qui a changé
            $table->timestamp('published_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['key', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_documents');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('plan_prices');
        Schema::dropIfExists('plans');
        Schema::dropIfExists('platform_settings');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['platform_role', 'terms_version', 'terms_accepted_at']));
    }
};
