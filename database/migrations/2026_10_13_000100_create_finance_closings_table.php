<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Clôtures : un mois (month 1 à 12) ou l'exercice entier (month 0).
        Schema::create('finance_closings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month')->default(0);
            $table->string('status', 12)->default('closed'); // closed, reopened
            $table->json('snapshot')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('reopened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reopened_at')->nullable();
            $table->string('reopen_reason')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'year', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_closings');
    }
};
