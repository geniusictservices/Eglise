<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Les besoins d'un département pour un exercice, proposés à la finance.
        Schema::create('budget_proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('fiscal_year');
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();
            $table->string('status', 12)->default('draft'); // draft, submitted
            $table->text('note')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->string('return_note')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'fiscal_year', 'department_id'], 'budget_proposals_unique');
        });

        Schema::create('budget_proposal_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('budget_proposal_id')->constrained()->cascadeOnDelete();
            $table->string('type', 8); // income, expense
            $table->foreignId('category_id')->constrained('finance_categories')->cascadeOnDelete();
            $table->string('label');
            $table->decimal('amount', 14, 2); // en dollars
            $table->decimal('original_amount', 16, 2)->nullable();
            $table->char('original_currency', 3)->nullable();
            $table->text('justification')->nullable();
            $table->timestamps();
        });

        // Le budget d'un exercice, version par version : chaque révision est une nouvelle version.
        Schema::create('budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('fiscal_year');
            $table->unsignedSmallInteger('version');
            $table->string('status', 12)->default('draft'); // draft, submitted, adopted, superseded
            $table->string('reason')->nullable(); // motif d'une révision
            $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->string('approval_note')->nullable();
            $table->string('return_note')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'fiscal_year', 'version'], 'budgets_version_unique');
        });

        Schema::create('budget_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('budget_id')->constrained()->cascadeOnDelete();
            $table->string('type', 8);
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('category_id')->constrained('finance_categories')->cascadeOnDelete();
            $table->string('label');
            $table->decimal('amount', 14, 2);
            $table->decimal('proposed_amount', 14, 2)->nullable();
            $table->foreignId('proposal_line_id')->nullable()->constrained('budget_proposal_lines')->nullOnDelete();
            $table->string('note')->nullable(); // remarque de l'arbitrage
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_lines');
        Schema::dropIfExists('budgets');
        Schema::dropIfExists('budget_proposal_lines');
        Schema::dropIfExists('budget_proposals');
    }
};
