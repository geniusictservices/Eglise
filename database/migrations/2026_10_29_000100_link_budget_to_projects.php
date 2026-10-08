<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Le budget et les projets : les lignes d'un projet (sa tranche de l'année, son solde
 * reporté) portent sa marque. L'avancement d'un projet se mesure par ses indicateurs :
 * des chiffres avec une cible, des étapes à franchir, l'argent collecté ou dépensé.
 * Écrite en SQL simple : « php artisan migrate --pretend » donne le script pour phpMyAdmin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('budget_lines', function (Blueprint $table) {
            $table->foreignId('project_id')->nullable()->after('payee_id')->constrained()->nullOnDelete();
            $table->string('source', 12)->nullable()->after('project_id'); // project : tranche reprise du projet ; carryover : solde reporté
        });

        Schema::create('project_indicators', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('name', 150);
            $table->string('kind', 12); // measure : un chiffre et sa cible ; milestone : une étape ; collected, spent : l'argent du projet
            $table->string('unit', 30)->nullable();
            $table->decimal('baseline', 14, 2)->default(0);
            $table->decimal('target', 14, 2)->nullable();
            $table->decimal('current', 14, 2)->nullable();
            $table->unsignedTinyInteger('weight')->default(1);
            $table->date('due_on')->nullable();
            $table->date('reached_on')->nullable(); // étape franchie
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('project_indicator_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_indicator_id')->constrained()->cascadeOnDelete();
            $table->decimal('value', 14, 2);
            $table->date('measured_on');
            $table->text('note')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        // Les projets déjà avancés gardent leur avancement, sous la forme d'un indicateur en pour cent.
        DB::statement("INSERT INTO project_indicators (project_id, name, kind, unit, baseline, target, current, weight, position, created_at, updated_at)
            SELECT id, 'Avancement des travaux', 'measure', '%', 0, 100, progress, 1, 1, updated_at, updated_at FROM projects WHERE progress > 0");
        DB::statement('INSERT INTO project_indicator_values (project_indicator_id, value, measured_on, note, user_id, created_at, updated_at)
            SELECT i.id, u.progress, DATE(u.created_at), u.note, u.user_id, u.created_at, u.updated_at
            FROM project_updates u JOIN project_indicators i ON i.project_id = u.project_id AND i.kind = \'measure\' AND i.name = \'Avancement des travaux\'');
    }

    public function down(): void
    {
        Schema::dropIfExists('project_indicator_values');
        Schema::dropIfExists('project_indicators');
        Schema::table('budget_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('project_id');
            $table->dropColumn('source');
        });
    }
};
