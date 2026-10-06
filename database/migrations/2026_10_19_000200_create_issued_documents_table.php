<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Le registre des documents délivrés : le texte imprimé est figé, le QR code renvoie à sa vérification.
        Schema::create('issued_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_type_id')->constrained()->cascadeOnDelete();
            $table->string('number', 60);
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('sequence');
            $table->foreignId('member_id')->nullable()->constrained()->nullOnDelete();
            $table->string('beneficiary', 200);
            $table->string('title', 160);
            $table->longText('body');
            $table->json('data')->nullable();
            $table->string('signatory', 120)->nullable();
            $table->string('signatory_title', 80)->nullable();
            $table->date('issued_on');
            $table->char('token', 32)->unique();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancel_reason')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'number']);
            $table->index(['document_type_id', 'year', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('issued_documents');
    }
};
