<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // La photo du membre sur ses documents : réglée par modèle, figée au moment de la délivrance.
        Schema::table('document_types', function (Blueprint $table) {
            $table->boolean('show_photo')->default(false)->after('signatory_title');
        });
        DB::table('document_types')->whereIn('key', ['membership', 'recommendation', 'service', 'mission'])->update(['show_photo' => true]);
        Schema::table('issued_documents', function (Blueprint $table) {
            $table->string('photo_path')->nullable()->after('body');
        });
    }

    public function down(): void
    {
        Schema::table('issued_documents', fn (Blueprint $table) => $table->dropColumn('photo_path'));
        Schema::table('document_types', fn (Blueprint $table) => $table->dropColumn('show_photo'));
    }
};
