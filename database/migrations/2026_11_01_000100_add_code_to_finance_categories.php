<?php

use App\Models\FinanceCategory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Les catégories que l'application utilise elle-même (salaires, retours sur avance,
 * quotes-parts, versements au siège…) étaient retrouvées par leur nom, que chaque
 * communauté peut renommer. Elles reçoivent un code stable, que le nom ne touche pas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_categories', function (Blueprint $table) {
            $table->string('code', 40)->nullable()->after('type');
            $table->unique(['organization_id', 'code']);
        });

        foreach (FinanceCategory::SYSTEM as $code => [$type, $name]) {
            DB::table('finance_categories')->where('type', $type)->where('name', $name)->whereNull('code')->update(['code' => $code]);
        }
        // L'ancien libellé proposé par défaut pour la quote-part versée.
        DB::table('finance_categories')->where('type', 'expense')->where('name', 'Quote-part au niveau supérieur')->whereNull('code')
            ->whereNotIn('organization_id', DB::table('finance_categories')->where('code', 'quota_sent')->select('organization_id'))
            ->update(['code' => 'quota_sent']);
    }

    public function down(): void
    {
        Schema::table('finance_categories', function (Blueprint $table) {
            $table->dropUnique(['organization_id', 'code']);
            $table->dropColumn('code');
        });
    }
};
