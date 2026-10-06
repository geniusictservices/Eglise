<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Feuille de collecte du culte : billets comptés, offrandes collectives, enveloppes nominatives. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->date('service_date');
            $table->string('service_label', 120); // Culte du dimanche, 2e culte, culte des jeunes…
            $table->foreignId('cash_account_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('draft'); // draft, validated, cancelled
            $table->json('counters')->nullable(); // personnes qui ont compté
            $table->json('counts')->nullable();   // {"USD": {"20": 3, "10": 5}, "CDF": {...}}
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('validated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('validated_at')->nullable();
            $table->string('cancel_reason')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'service_date']);
        });

        // Montant de chaque offrande collective, par devise.
        Schema::create('collection_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('collection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('finance_categories')->cascadeOnDelete();
            $table->char('currency', 3);
            $table->decimal('amount', 20, 2);
            $table->timestamps();
        });

        // Enveloppes au nom d'un membre (dîme, action de grâce…).
        Schema::create('collection_envelopes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('collection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('finance_categories')->cascadeOnDelete();
            $table->foreignId('member_id')->nullable()->constrained()->nullOnDelete();
            $table->string('payer_name', 150)->nullable();
            $table->char('currency', 3);
            $table->decimal('amount', 20, 2);
            $table->timestamps();
        });

        Schema::table('finance_transactions', function (Blueprint $table) {
            $table->foreignId('collection_id')->nullable()->after('group_uuid')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('finance_transactions', fn (Blueprint $table) => $table->dropConstrainedForeignId('collection_id'));
        Schema::dropIfExists('collection_envelopes');
        Schema::dropIfExists('collection_lines');
        Schema::dropIfExists('collections');
    }
};
