<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Les registres officiels, y compris les anciens cahiers papier recopiés dans Waumini.
        Schema::create('registers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 30);
            $table->string('name', 120);
            $table->unsignedSmallInteger('from_year')->nullable();
            $table->unsignedSmallInteger('to_year')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // Un acte : la personne (et son conjoint pour un mariage), la date, le lieu, l'officiant, les témoins.
        Schema::create('register_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('register_id')->constrained()->cascadeOnDelete();
            $table->string('entry_number', 20);
            $table->string('page', 20)->nullable();
            $table->date('event_date')->nullable();
            $table->string('place', 150)->nullable();
            $table->string('officiant', 120)->nullable();
            $table->string('last_name', 80);
            $table->string('middle_name', 80)->nullable();
            $table->string('first_name', 80)->nullable();
            $table->char('gender', 1)->nullable();
            $table->date('birth_date')->nullable();
            $table->string('birth_place', 100)->nullable();
            $table->string('father', 150)->nullable();
            $table->string('mother', 150)->nullable();
            $table->string('partner_name', 200)->nullable();
            $table->string('witnesses')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('member_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['register_id', 'entry_number']);
            $table->index(['organization_id', 'last_name']);
        });

        Schema::table('issued_documents', function (Blueprint $table) {
            $table->foreignId('register_entry_id')->nullable()->after('member_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('issued_documents', fn (Blueprint $table) => $table->dropConstrainedForeignId('register_entry_id'));
        Schema::dropIfExists('register_entries');
        Schema::dropIfExists('registers');
    }
};
