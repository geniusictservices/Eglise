<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Une dépense au-delà du budget (ou hors budget) : autorisée, avec la source de l'argent.
        Schema::create('budget_overruns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('fiscal_year');
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('category_id')->constrained('finance_categories')->cascadeOnDelete();
            $table->decimal('amount', 14, 2); // en dollars
            $table->string('source', 16); // transfer, reserves, new_income
            $table->foreignId('source_department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('source_category_id')->nullable()->constrained('finance_categories')->nullOnDelete();
            $table->string('source_detail')->nullable();
            $table->text('reason');
            $table->foreignId('expense_request_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 12)->default('pending'); // pending, authorized, refused
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'fiscal_year', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_overruns');
    }
};
