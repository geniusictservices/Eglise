<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Le site vitrine d'une communauté : ses textes et ses réglages ; le reste vient de Waumini (calendrier, annonces…).
        Schema::create('websites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('is_published')->default(false);
            $table->string('theme', 20)->default('chaleureux');
            $table->json('pages')->nullable();
            $table->string('tagline')->nullable();
            $table->string('welcome_title')->nullable();
            $table->text('welcome_text')->nullable();
            $table->string('cover_path')->nullable();
            $table->text('about_text')->nullable();
            $table->text('beliefs_text')->nullable();
            $table->string('pastor_name')->nullable();
            $table->text('pastor_message')->nullable();
            $table->text('giving_text')->nullable();
            $table->json('giving_accounts')->nullable();
            $table->json('giving_categories')->nullable();
            $table->string('whatsapp', 20)->nullable();
            $table->string('map_url', 500)->nullable();
            $table->string('facebook_url', 300)->nullable();
            $table->string('youtube_url', 300)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });

        // Une prédication publiée : un lien vidéo (YouTube, Facebook) ou un audio léger.
        Schema::create('sermons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('preacher')->nullable();
            $table->date('preached_on');
            $table->string('passage')->nullable();
            $table->text('summary')->nullable();
            $table->string('video_url', 500)->nullable();
            $table->string('audio_path')->nullable();
            $table->unsignedInteger('audio_size')->nullable();
            $table->boolean('is_published')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['organization_id', 'preached_on']);
        });

        // Ce qui paraît sur le site : les activités cochées (d'office celles de toute la communauté) et les annonces choisies.
        Schema::table('events', fn (Blueprint $table) => $table->boolean('is_public')->default(false)->after('audience'));
        DB::table('events')->where('audience', 'all')->update(['is_public' => true]);
        Schema::table('announcements', fn (Blueprint $table) => $table->boolean('is_public')->default(false)->after('audience'));
    }

    public function down(): void
    {
        Schema::table('announcements', fn (Blueprint $table) => $table->dropColumn('is_public'));
        Schema::table('events', fn (Blueprint $table) => $table->dropColumn('is_public'));
        Schema::dropIfExists('sermons');
        Schema::dropIfExists('websites');
    }
};
