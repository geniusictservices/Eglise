<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Imports du registre depuis Excel : chaque import peut être annulé.
        Schema::create('member_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('file_name');
            $table->string('status', 20)->default('analysed'); // analysed, imported, cancelled
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('valid_rows')->default(0);
            $table->unsignedInteger('error_rows')->default(0);
            $table->unsignedInteger('duplicate_rows')->default(0);
            $table->unsignedInteger('imported_rows')->default(0);
            $table->unsignedInteger('households_created')->default(0);
            $table->json('options')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
        });

        Schema::table('members', function (Blueprint $table) {
            $table->foreignId('import_id')->nullable()->after('created_by')->constrained('member_imports')->nullOnDelete();
        });

        Schema::table('households', function (Blueprint $table) {
            $table->foreignId('import_id')->nullable()->after('head_member_id')->constrained('member_imports')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('households', fn (Blueprint $table) => $table->dropConstrainedForeignId('import_id'));
        Schema::table('members', fn (Blueprint $table) => $table->dropConstrainedForeignId('import_id'));
        Schema::dropIfExists('member_imports');
    }
};
