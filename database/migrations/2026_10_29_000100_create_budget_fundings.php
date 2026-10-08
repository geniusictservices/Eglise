<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chaque dépense prévue dit d'où viendra son argent : une ou plusieurs recettes prévues
 * (dîmes, offrandes, promesses, collecte d'un projet, solde reporté…), sans qu'une recette
 * finance plus que ce qu'elle prévoit. Les lignes d'un projet portent sa marque.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('budget_lines', function (Blueprint $table) {
            $table->foreignId('project_id')->nullable()->after('payee_id')->constrained()->nullOnDelete();
            $table->string('source', 12)->nullable()->after('project_id'); // project : tranche reprise du projet ; carryover : solde reporté
        });

        Schema::create('budget_fundings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('budget_id')->constrained()->cascadeOnDelete();
            $table->foreignId('expense_line_id')->constrained('budget_lines')->cascadeOnDelete();
            $table->foreignId('income_line_id')->constrained('budget_lines')->cascadeOnDelete();
            $table->decimal('amount', 14, 2); // en dollars
            $table->timestamps();
            $table->unique(['expense_line_id', 'income_line_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_fundings');
        Schema::table('budget_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('project_id');
            $table->dropColumn('source');
        });
    }
};
