<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Les nouveautés de chaque utilisateur : non lues tant qu'il ne les a pas ouvertes.
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            // L'objet concerné (« expense.12.approve ») : réglé, la nouveauté se range pour tous.
            $table->string('key')->nullable()->index();
            // La page de l'objet : l'ouvrir, d'où qu'on vienne, marque la nouveauté comme lue.
            $table->string('path')->index();
            $table->json('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index(['notifiable_type', 'notifiable_id', 'read_at']);
        });

        // Les téléphones et ordinateurs où l'utilisateur reçoit les nouveautés.
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('endpoint');
            $table->char('endpoint_hash', 64)->unique();
            $table->string('public_key');
            $table->string('auth_token');
            $table->string('content_encoding', 20)->default('aes128gcm');
            $table->string('device')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
        Schema::dropIfExists('notifications');
    }
};
