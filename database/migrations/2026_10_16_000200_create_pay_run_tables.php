<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // La paie d'une période pour un rythme : préparée, présentée, approuvée, payée.
        Schema::create('pay_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pay_schedule_id')->constrained()->cascadeOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->string('status', 12)->default('draft'); // draft, submitted, approved, paid, cancelled
            $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->string('approval_note')->nullable();
            $table->string('return_note')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });

        // Le bulletin d'une personne dans une paie.
        Schema::create('pay_slips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pay_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payee_id')->constrained()->cascadeOnDelete();
            $table->char('currency', 3);
            $table->decimal('quantity', 8, 2)->default(1); // prestations, pour un rythme à la prestation
            $table->json('adjustments')->nullable(); // primes ou retenues ponctuelles
            $table->decimal('base', 14, 2)->default(0);
            $table->decimal('gross', 14, 2)->default(0);
            $table->decimal('deductions', 14, 2)->default(0);
            $table->decimal('advance_total', 14, 2)->default(0);
            $table->decimal('net', 14, 2)->default(0);
            $table->json('details')->nullable(); // lignes calculées
            $table->foreignId('cash_account_id')->nullable()->constrained()->nullOnDelete();
            $table->char('paid_currency', 3)->nullable();
            $table->decimal('paid_amount', 16, 2)->nullable();
            $table->foreignId('finance_transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });

        // Les avances sur salaire : demandées, approuvées, payées, puis retenues sur les paies.
        Schema::create('salary_advances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payee_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 14, 2);
            $table->char('currency', 3);
            $table->unsignedTinyInteger('installments')->default(1);
            $table->text('reason')->nullable();
            $table->string('status', 12)->default('requested'); // requested, approved, paid, repaid, refused, cancelled
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->string('decision_note')->nullable();
            $table->foreignId('cash_account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('finance_transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });

        Schema::create('salary_advance_repayments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('salary_advance_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pay_slip_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 14, 2);
            $table->timestamps();
        });

        Schema::table('finance_transactions', function (Blueprint $table) {
            $table->foreignId('pay_slip_id')->nullable()->after('expense_request_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('finance_transactions', fn (Blueprint $table) => $table->dropConstrainedForeignId('pay_slip_id'));
        foreach (['salary_advance_repayments', 'salary_advances', 'pay_slips', 'pay_runs'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
