<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Demandes de démonstration envoyées depuis le site public, traitées par Genius ICT.
        Schema::create('demo_requests', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('phone', 20);
            $table->string('community', 150);
            $table->string('city', 100)->nullable();
            $table->string('members', 30)->nullable();
            $table->text('message')->nullable();
            $table->string('status', 20)->default('new'); // new, contacted, done
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_requests');
    }
};
