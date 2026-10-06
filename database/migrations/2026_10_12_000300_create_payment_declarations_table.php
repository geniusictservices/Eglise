<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Paiements mobile money déclarés (ID de transaction et capture), validés par la finance. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_declarations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->nullable()->constrained()->nullOnDelete();
            $table->string('declarant_name', 150)->nullable();
            $table->string('declarant_phone', 20)->nullable();
            $table->decimal('amount', 20, 2);
            $table->char('currency', 3);
            $table->string('operator', 40);                 // M-Pesa, Airtel Money, Orange Money…
            $table->string('transaction_reference', 100);  // ID de la transaction
            $table->date('paid_on');
            $table->foreignId('category_id')->nullable()->constrained('finance_categories')->nullOnDelete();
            $table->foreignId('pledge_id')->nullable()->constrained()->nullOnDelete();
            $table->string('message')->nullable();
            $table->string('screenshot_path')->nullable();
            $table->string('source', 20)->default('staff'); // staff (saisie par l'église), member (espace membre)
            $table->string('status', 20)->default('pending'); // pending, validated, rejected
            $table->foreignId('cash_account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('finance_transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('reject_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'transaction_reference']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_declarations');
    }
};
