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
        // L'ancien libellé proposé par défaut pour la quote-part versée, là où le nouveau n'existe pas déjà.
        // Les communautés sont lues d'abord : MySQL refuse une mise à jour qui relit la table qu'elle modifie.
        $already = DB::table('finance_categories')->where('code', 'quota_sent')->pluck('organization_id')->all();
        DB::table('finance_categories')->where('type', 'expense')->where('name', 'Quote-part au niveau supérieur')->whereNull('code')
            ->whereNotIn('organization_id', $already)
            ->update(['code' => 'quota_sent']);
    }

    public function down(): void
    {
        // L'index composé sert aussi la clé étrangère vers organizations (MariaDB et MySQL retirent alors
        // l'index simple qu'elle avait) : un index simple doit revenir avant de retirer l'index composé.
        if (! Schema::hasIndex('finance_categories', ['organization_id'])) {
            Schema::table('finance_categories', fn (Blueprint $table) => $table->index('organization_id'));
        }
        Schema::table('finance_categories', function (Blueprint $table) {
            $table->dropUnique(['organization_id', 'code']);
            $table->dropColumn('code');
        });
    }
};
