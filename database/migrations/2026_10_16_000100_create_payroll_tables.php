<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Les rythmes de paie, créés par chaque église : tous les N mois, toutes les N semaines, ou à la prestation.
        Schema::create('pay_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('unit', 8); // month, week, service
            $table->unsignedTinyInteger('every')->default(1);
            $table->string('service_label')->nullable(); // « culte », « prédication »
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Les personnes payées : un membre, ou une personne extérieure.
        Schema::create('payees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('position')->nullable(); // « Pasteur titulaire », « Sentinelle »
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('pay_schedule_id')->constrained();
            $table->char('currency', 3)->default('USD');
            $table->decimal('base_amount', 14, 2); // par période, ou par prestation
            $table->string('payment_method', 8)->default('cash');
            $table->string('payment_number')->nullable(); // numéro mobile money ou compte
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        // Les éléments de paie : gains et retenues, fixes ou en pourcentage.
        Schema::create('pay_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('kind', 10); // earning, deduction
            $table->string('calculation', 16); // fixed, percent_base, percent_gross
            $table->decimal('default_value', 12, 2)->default(0);
            $table->boolean('applies_to_all')->default(false);
            $table->boolean('is_statutory')->default(false); // cotisation légale
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        // Les éléments d'un bénéficiaire, avec sa valeur propre s'il y a lieu.
        Schema::create('payee_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pay_item_id')->constrained()->cascadeOnDelete();
            $table->decimal('value', 12, 2)->nullable(); // null : la valeur par défaut
            $table->boolean('is_excluded')->default(false); // élément « pour tous » retiré à cette personne
            $table->timestamps();
            $table->unique(['payee_id', 'pay_item_id']);
        });
    }

    public function down(): void
    {
        foreach (['payee_items', 'pay_items', 'payees', 'pay_schedules'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
