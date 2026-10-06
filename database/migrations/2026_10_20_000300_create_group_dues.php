<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // La cotisation d'un groupe (facultative) : un montant par mois, tenu par le responsable.
        Schema::table('groups', function (Blueprint $table) {
            $table->decimal('dues_amount', 12, 2)->nullable()->after('place');
            $table->char('dues_currency', 3)->nullable()->after('dues_amount');
        });

        Schema::create('group_dues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->char('period', 7);
            $table->decimal('amount', 12, 2);
            $table->char('currency', 3);
            $table->date('paid_on');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['group_id', 'member_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_dues');
        Schema::table('groups', fn (Blueprint $table) => $table->dropColumn(['dues_amount', 'dues_currency']));
    }
};
