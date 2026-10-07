<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->text('verse_text')->nullable()->after('welcome_text'); // le verset du moment, sur l'accueil
            $table->string('verse_reference', 80)->nullable()->after('verse_text');
            $table->json('leaders')->nullable()->after('pastor_message'); // les responsables : nom, fonction, photo
            $table->json('public_groups')->nullable()->after('giving_categories'); // les groupes montrés sur le site
        });

        // La galerie photos du site.
        Schema::create('website_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('thumb_path');
            $table->string('caption', 160)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['organization_id', 'created_at']);
        });

        // Les demandes de prière et les nouveaux venus peuvent arriver par le site.
        Schema::table('prayer_requests', function (Blueprint $table) {
            $table->string('requester_phone', 30)->nullable()->after('requester_name');
            $table->string('source', 10)->default('app')->after('is_private');
        });
        Schema::table('pastoral_cases', function (Blueprint $table) {
            $table->string('source', 10)->default('app')->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('pastoral_cases', fn (Blueprint $table) => $table->dropColumn('source'));
        Schema::table('prayer_requests', fn (Blueprint $table) => $table->dropColumn(['requester_phone', 'source']));
        Schema::dropIfExists('website_photos');
        Schema::table('websites', fn (Blueprint $table) => $table->dropColumn(['verse_text', 'verse_reference', 'leaders', 'public_groups']));
    }
};
