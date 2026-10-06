<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Un dépassement peut concerner une paie, pas seulement une dépense.
        Schema::table('budget_overruns', function (Blueprint $table) {
            $table->foreignId('pay_run_id')->nullable()->after('expense_request_id')->constrained()->cascadeOnDelete();
        });

        // Une ligne du budget reprise de la masse salariale : la personne qu'elle paie.
        Schema::table('budget_lines', function (Blueprint $table) {
            $table->foreignId('payee_id')->nullable()->after('proposal_line_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('budget_lines', fn (Blueprint $table) => $table->dropConstrainedForeignId('payee_id'));
        Schema::table('budget_overruns', fn (Blueprint $table) => $table->dropConstrainedForeignId('pay_run_id'));
    }
};
