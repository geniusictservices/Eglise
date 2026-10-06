<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Finances : comptes (caisses, mobile money, banques) multi-devises, catégories, opérations. */
return new class extends Migration
{
    public function up(): void
    {
        // Comptes de la communauté : caisse physique, mobile money ou banque.
        // Un compte peut tenir plusieurs devises (M-Pesa en USD et en CDF, caisse en dollars et en francs).
        Schema::create('cash_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('kind', 20)->default('cash'); // cash, mobile, bank
            $table->string('provider', 60)->nullable();  // M-Pesa, Airtel Money, Orange Money, Rawbank, Equity BCDC…
            $table->string('account_number', 60)->nullable();
            $table->string('holder', 120)->nullable();   // titulaire ou signataires
            $table->string('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        // Les devises d'un compte, chacune avec son solde de départ.
        Schema::create('cash_account_currencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cash_account_id')->constrained()->cascadeOnDelete();
            $table->char('currency', 3);
            $table->decimal('opening_balance', 20, 2)->default(0);
            $table->date('opened_on');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['cash_account_id', 'currency']);
        });

        Schema::create('finance_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('type', 10); // income, expense
            $table->string('name', 100);
            // Recettes : collective (la boîte, total seul), personal (au nom du membre), group (d'un département)
            $table->string('nature', 20)->nullable();
            $table->string('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        // Chaque mouvement d'argent. On n'efface jamais : une erreur s'annule, avec son motif.
        Schema::create('finance_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cash_account_id')->constrained()->cascadeOnDelete(); // un compte ne se supprime pas dans l'application : il se ferme
            $table->string('type', 20); // income, expense, transfer_in, transfer_out, exchange_in, exchange_out
            $table->decimal('amount', 20, 2);       // toujours positif, dans la devise de l'opération
            $table->char('currency', 3);
            $table->decimal('rate', 20, 8);          // unités de la devise pour 1 USD, le jour de l'opération
            $table->decimal('usd_amount', 20, 2);    // équivalent en dollars
            $table->date('occurred_on');
            $table->foreignId('category_id')->nullable()->constrained('finance_categories')->nullOnDelete();
            $table->foreignId('member_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->string('payer_name', 150)->nullable(); // donateur de passage, non inscrit
            $table->string('description')->nullable();
            $table->string('payment_method', 20)->default('cash'); // cash, mobile, bank
            $table->string('external_reference', 100)->nullable(); // ID de la transaction mobile money, n° de chèque…
            $table->string('receipt_number', 40)->nullable();
            $table->uuid('group_uuid')->nullable()->index(); // relie les deux côtés d'un virement ou d'un change
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancel_reason')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'occurred_on']);
            $table->unique(['organization_id', 'receipt_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_transactions');
        Schema::dropIfExists('finance_categories');
        Schema::dropIfExists('cash_account_currencies');
        Schema::dropIfExists('cash_accounts');
    }
};
