<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Circuit des dépenses : demande, contrôle, approbation (une à trois
 * signatures), décaissement, justification. Les avances se justifient
 * après coup ; le reste non dépensé revient en caisse.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('number', 30);
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('finance_categories')->nullOnDelete();
            $table->string('title', 150);
            $table->text('description')->nullable();
            $table->decimal('amount', 20, 2);
            $table->char('currency', 3);
            $table->boolean('is_advance')->default(false);
            $table->foreignId('beneficiary_member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->string('beneficiary_name', 150)->nullable();
            $table->date('needed_on')->nullable();
            // submitted, checked, approved, disbursed, justified, rejected, cancelled
            $table->string('status', 20)->default('submitted');
            $table->unsignedTinyInteger('approvals_required')->default(2);
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('checked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('checked_at')->nullable();
            $table->string('check_note')->nullable();
            $table->foreignId('cash_account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('finance_transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('disbursed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('disbursed_at')->nullable();
            $table->date('justify_by')->nullable();
            $table->decimal('justified_amount', 20, 2)->nullable();
            $table->foreignId('return_transaction_id')->nullable()->constrained('finance_transactions')->nullOnDelete();
            $table->foreignId('justified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('justified_at')->nullable();
            $table->string('justification_note')->nullable();
            $table->string('reject_reason')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'number']);
            $table->index(['organization_id', 'status']);
        });

        Schema::create('expense_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expense_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('decision', 10); // approved, rejected
            $table->string('note')->nullable();
            $table->timestamps();
        });

        // Devis, factures, reçus, photos des achats.
        Schema::create('expense_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expense_request_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20)->default('invoice'); // quote, invoice, receipt, photo
            $table->string('path');
            $table->string('original_name');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('finance_transactions', function (Blueprint $table) {
            $table->foreignId('expense_request_id')->nullable()->after('pledge_amount')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('finance_transactions', fn (Blueprint $table) => $table->dropConstrainedForeignId('expense_request_id'));
        Schema::dropIfExists('expense_attachments');
        Schema::dropIfExists('expense_approvals');
        Schema::dropIfExists('expense_requests');
    }
};
