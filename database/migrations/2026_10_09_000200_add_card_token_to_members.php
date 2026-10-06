<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Jeton du QR code de la carte de membre (vérification publique).
        Schema::table('members', function (Blueprint $table) {
            $table->string('card_token', 40)->nullable()->unique()->after('photo_path');
        });
    }

    public function down(): void
    {
        Schema::table('members', fn (Blueprint $table) => $table->dropColumn('card_token'));
    }
};
