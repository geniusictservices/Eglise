<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Bacs à sable de la démo publique : effacés automatiquement à expiration.
        Schema::table('organizations', function (Blueprint $table) {
            $table->boolean('is_demo')->default(false)->index()->after('status');
            $table->timestamp('demo_expires_at')->nullable()->after('is_demo');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_demo')->default(false)->index()->after('is_platform_staff');
        });

        // Le journal des démos forme une chaîne à part, qu'on peut effacer
        // sans casser la chaîne des vraies communautés.
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->string('chain', 10)->default('main')->index()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', fn (Blueprint $table) => $table->dropColumn('chain'));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('is_demo'));
        Schema::table('organizations', fn (Blueprint $table) => $table->dropColumn(['is_demo', 'demo_expires_at']));
    }
};
