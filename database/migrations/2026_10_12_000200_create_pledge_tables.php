<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Campagnes et projets, promesses (en argent ou en nature), versements et relances. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 150);
            $table->string('kind', 20)->default('project'); // project, campaign, regular
            $table->text('description')->nullable();
            $table->decimal('goal_amount', 20, 2)->nullable();
            $table->char('goal_currency', 3)->default('USD');
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->foreignId('category_id')->nullable()->constrained('finance_categories')->nullOnDelete();
            $table->string('status', 20)->default('active'); // active, closed
            $table->timestamps();
        });

        Schema::create('pledges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained()->nullOnDelete();
            // Qui promet : un membre, un ménage, un département ou une personne extérieure.
            $table->foreignId('member_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('household_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->string('pledger_name', 150)->nullable();
            $table->string('pledger_phone', 20)->nullable();
            $table->string('kind', 10)->default('money'); // money, in_kind
            $table->decimal('amount', 20, 2);            // montant promis, ou valeur estimée en nature
            $table->char('currency', 3);
            $table->string('in_kind_description')->nullable(); // 20 sacs de ciment, 10 tôles…
            $table->string('frequency', 10)->default('once'); // once, weekly, monthly
            $table->unsignedSmallInteger('installments')->default(1);
            $table->date('pledged_on');
            $table->date('first_due_on')->nullable();
            $table->string('status', 20)->default('active'); // active, fulfilled, cancelled
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['organization_id', 'status']);
        });

        // Dons en nature reçus pour une promesse (ciment, tôles, chaises…).
        Schema::create('pledge_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pledge_id')->constrained()->cascadeOnDelete();
            $table->date('received_on');
            $table->string('description');
            $table->decimal('value', 20, 2); // valeur estimée, dans la devise de la promesse
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('pledge_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pledge_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('channel', 20)->default('whatsapp');
            $table->timestamps();
        });

        Schema::table('finance_transactions', function (Blueprint $table) {
            $table->foreignId('pledge_id')->nullable()->after('collection_id')->constrained()->nullOnDelete();
            // Part du versement comptée sur la promesse, dans la devise de la promesse.
            $table->decimal('pledge_amount', 20, 2)->nullable()->after('pledge_id');
        });
    }

    public function down(): void
    {
        Schema::table('finance_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pledge_id');
            $table->dropColumn('pledge_amount');
        });
        Schema::dropIfExists('pledge_reminders');
        Schema::dropIfExists('pledge_deliveries');
        Schema::dropIfExists('pledges');
        Schema::dropIfExists('campaigns');
    }
};
