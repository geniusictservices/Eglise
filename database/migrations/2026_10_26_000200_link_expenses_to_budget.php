<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Une dépense se rattache à une ligne du budget adopté, sauf un imprévu, qui dit pourquoi il n'était pas prévu.
        Schema::table('expense_requests', function (Blueprint $table) {
            $table->foreignId('budget_line_id')->nullable()->after('category_id')->constrained()->nullOnDelete();
            $table->boolean('is_unforeseen')->default(false)->after('budget_line_id');
            $table->string('unforeseen_reason')->nullable()->after('is_unforeseen');
        });
    }

    public function down(): void
    {
        Schema::table('expense_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('budget_line_id');
            $table->dropColumn(['is_unforeseen', 'unforeseen_reason']);
        });
    }
};
