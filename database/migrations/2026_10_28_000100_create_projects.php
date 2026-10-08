<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Les projets réunissent ce qui était éparpillé : les campagnes de promesses et les actions
 * du plan. Un projet (la parcelle, le temple, la convention) a son objectif, ses tranches
 * annuelles reprises par le budget, et tout l'argent qui le concerne porte sa marque.
 *
 * Les campagnes deviennent des projets (mêmes numéros) ; les actions du plan sont recopiées.
 * Les anciennes tables du plan restent, en lecture seule, pour l'historique.
 * Écrite en SQL simple : « php artisan migrate --pretend » donne le script pour phpMyAdmin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('campaigns', 'projects');
        Schema::table('pledges', fn (Blueprint $table) => $table->renameColumn('campaign_id', 'project_id'));

        Schema::table('projects', function (Blueprint $table) {
            $table->string('theme', 150)->nullable()->after('description'); // axe de la vision : « Jeunesse », « Infrastructures »
            $table->foreignId('department_id')->nullable()->after('theme')->constrained()->nullOnDelete();
            $table->foreignId('responsible_member_id')->nullable()->after('department_id')->constrained('members')->nullOnDelete();
            $table->string('responsible_name', 150)->nullable()->after('responsible_member_id');
            $table->foreignId('cash_account_id')->nullable()->after('category_id')->constrained()->nullOnDelete(); // le compte du projet, s'il en a un
            $table->unsignedTinyInteger('progress')->default(0)->after('status');
            $table->unsignedBigInteger('legacy_plan_action_id')->nullable()->after('progress');
        });

        // Les tranches annuelles : ce que le projet prévoit de collecter et de dépenser chaque exercice.
        Schema::create('project_years', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('fiscal_year');
            $table->decimal('income_planned', 14, 2)->default(0); // en dollars
            $table->decimal('expense_planned', 14, 2)->default(0);
            $table->string('note')->nullable();
            $table->timestamps();
            $table->unique(['project_id', 'fiscal_year']);
        });

        Schema::create('project_updates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedTinyInteger('progress');
            $table->text('note')->nullable();
            $table->timestamps();
        });

        // L'argent porte la marque de son projet : recettes, dépenses, demandes de dépense, décisions de réunion.
        Schema::table('finance_transactions', function (Blueprint $table) {
            $table->foreignId('project_id')->nullable()->after('pledge_amount')->constrained()->nullOnDelete();
        });
        Schema::table('expense_requests', function (Blueprint $table) {
            $table->foreignId('project_id')->nullable()->after('budget_line_id')->constrained()->nullOnDelete();
        });
        Schema::table('meeting_decisions', function (Blueprint $table) {
            $table->foreignId('project_id')->nullable()->after('plan_action_id')->constrained()->nullOnDelete();
        });

        // Les campagnes : actives, elles sont en cours ; closes, terminées.
        DB::statement("UPDATE projects SET status = CASE WHEN status = 'closed' THEN 'done' ELSE 'ongoing' END");

        // Les actions du plan deviennent des projets, avec leur objectif comme axe.
        DB::statement("INSERT INTO projects (organization_id, name, kind, description, theme, department_id, responsible_member_id, responsible_name,
                goal_currency, starts_on, ends_on, status, progress, legacy_plan_action_id, created_at, updated_at)
            SELECT a.organization_id, a.title, 'project', a.description, o.title, a.department_id, a.responsible_member_id, a.responsible_name,
                'USD', a.starts_on, a.due_on, a.status, a.progress, a.id, a.created_at, a.updated_at
            FROM plan_actions a JOIN plan_objectives o ON o.id = a.plan_objective_id");
        DB::statement('INSERT INTO project_years (project_id, fiscal_year, income_planned, expense_planned, created_at, updated_at)
            SELECT p.id, o.fiscal_year, 0, a.estimated_cost, a.created_at, a.updated_at
            FROM projects p JOIN plan_actions a ON a.id = p.legacy_plan_action_id JOIN plan_objectives o ON o.id = a.plan_objective_id
            WHERE a.estimated_cost IS NOT NULL');
        DB::statement('INSERT INTO project_updates (project_id, user_id, progress, note, created_at, updated_at)
            SELECT p.id, u.user_id, u.progress, u.note, u.created_at, u.updated_at
            FROM plan_action_updates u JOIN projects p ON p.legacy_plan_action_id = u.plan_action_id');
        DB::statement('UPDATE meeting_decisions d JOIN projects p ON p.legacy_plan_action_id = d.plan_action_id SET d.project_id = p.id');

        // L'argent déjà reçu pour une campagne : les versements des promesses, puis les dons de sa catégorie.
        DB::statement('UPDATE finance_transactions t JOIN pledges pl ON pl.id = t.pledge_id SET t.project_id = pl.project_id WHERE pl.project_id IS NOT NULL');
        DB::statement("UPDATE finance_transactions t JOIN projects p ON p.category_id = t.category_id AND p.organization_id = t.organization_id
            SET t.project_id = p.id WHERE t.project_id IS NULL AND t.type = 'income' AND p.legacy_plan_action_id IS NULL");
    }

    public function down(): void
    {
        Schema::table('meeting_decisions', fn (Blueprint $table) => $table->dropConstrainedForeignId('project_id'));
        Schema::table('expense_requests', fn (Blueprint $table) => $table->dropConstrainedForeignId('project_id'));
        Schema::table('finance_transactions', fn (Blueprint $table) => $table->dropConstrainedForeignId('project_id'));
        Schema::dropIfExists('project_updates');
        Schema::dropIfExists('project_years');
        DB::statement('DELETE FROM projects WHERE legacy_plan_action_id IS NOT NULL');
        DB::statement("UPDATE projects SET status = CASE WHEN status = 'done' THEN 'closed' ELSE 'active' END");
        Schema::table('projects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cash_account_id');
            $table->dropConstrainedForeignId('responsible_member_id');
            $table->dropConstrainedForeignId('department_id');
            $table->dropColumn(['theme', 'responsible_name', 'progress', 'legacy_plan_action_id']);
        });
        Schema::table('pledges', fn (Blueprint $table) => $table->renameColumn('project_id', 'campaign_id'));
        Schema::rename('projects', 'campaigns');
    }
};
