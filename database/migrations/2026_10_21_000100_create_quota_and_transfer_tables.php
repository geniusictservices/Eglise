<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // La règle de quote-part qu'un niveau demande à ses niveaux directement en dessous :
        // un pourcentage de leurs recettes, ou un montant fixe par mois.
        Schema::create('quota_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('mode', 10);
            $table->decimal('percent', 5, 2)->nullable();
            $table->decimal('amount', 14, 2)->nullable();
            $table->char('currency', 3)->nullable();
            // Premier mois dû : une règle ne s'applique pas aux mois d'avant.
            $table->char('starts_period', 7);
            $table->string('notes')->nullable();
            $table->timestamps();
        });

        // Un versement de quote-part : envoyé par le niveau inférieur, puis reçu par le niveau supérieur.
        Schema::create('quota_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('to_organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->char('period', 7);
            $table->decimal('amount', 14, 2);
            $table->char('currency', 3);
            $table->decimal('usd_amount', 14, 2);
            $table->date('paid_on');
            $table->string('reference', 100)->nullable();
            $table->string('status', 10)->default('sent');
            $table->foreignId('expense_transaction_id')->nullable()->constrained('finance_transactions')->nullOnDelete();
            $table->foreignId('income_transaction_id')->nullable()->constrained('finance_transactions')->nullOnDelete();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();
            $table->index(['to_organization_id', 'period']);
        });

        // Le transfert d'un membre d'une paroisse à une autre de la même dénomination.
        Schema::create('member_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('to_organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('status', 10)->default('pending');
            $table->string('reason')->nullable();
            $table->string('decision_note')->nullable();
            $table->string('old_number', 40)->nullable();
            $table->string('new_number', 40)->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_transfers');
        Schema::dropIfExists('quota_payments');
        Schema::dropIfExists('quota_rules');
    }
};
