<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les projets du siège (ou d'une région) portés par les paroisses : chaque paroisse a sa part,
 * sous la forme d'un projet relais chez elle (même nom, objectif = sa part). Elle collecte
 * localement, puis verse au siège ; le siège confirme la réception, et l'argent entre dans son projet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->foreignId('parent_project_id')->nullable()->after('organization_id')->constrained('projects')->nullOnDelete();
        });

        Schema::create('project_remittances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete(); // le projet relais de la paroisse
            $table->foreignId('parent_project_id')->constrained('projects')->cascadeOnDelete(); // le projet du siège
            $table->foreignId('from_organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('to_organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->decimal('amount', 14, 2);
            $table->char('currency', 3);
            $table->decimal('usd_amount', 14, 2);
            $table->date('paid_on');
            $table->string('reference', 100)->nullable();
            $table->string('status', 10)->default('sent'); // sent, received
            $table->foreignId('expense_transaction_id')->nullable()->constrained('finance_transactions')->nullOnDelete();
            $table->foreignId('income_transaction_id')->nullable()->constrained('finance_transactions')->nullOnDelete();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();
            $table->index(['to_organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_remittances');
        Schema::table('projects', fn (Blueprint $table) => $table->dropConstrainedForeignId('parent_project_id'));
    }
};
