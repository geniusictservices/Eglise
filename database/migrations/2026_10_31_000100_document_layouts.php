<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Des documents à l'allure soignée : chaque modèle a son orientation (les attestations et
 * certificats en paysage, les lettres en portrait) et peut choisir son style ; sinon il prend
 * celui de l'église. Écrite en SQL simple, pour le script phpMyAdmin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_types', function (Blueprint $table) {
            $table->string('orientation', 10)->default('portrait')->after('show_photo');
            $table->string('style', 20)->nullable()->after('orientation'); // vide : le style choisi par l'église
        });
        DB::statement("UPDATE `document_types` SET `orientation` = 'landscape' WHERE `key` IN ('membership', 'baptism', 'marriage', 'child_presentation', 'service')");
    }

    public function down(): void
    {
        Schema::table('document_types', fn (Blueprint $table) => $table->dropColumn(['orientation', 'style']));
    }
};
