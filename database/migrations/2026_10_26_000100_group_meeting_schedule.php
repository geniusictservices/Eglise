<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Un groupe peut se réunir plusieurs fois par semaine : la chorale répète le mardi et le samedi.
        Schema::table('groups', function (Blueprint $table) {
            $table->json('schedule')->nullable()->after('description'); // [{day, time, label}]
        });
        foreach (DB::table('groups')->whereNotNull('meeting_day')->get(['id', 'meeting_day', 'meeting_time']) as $group) {
            DB::table('groups')->where('id', $group->id)->update(['schedule' => json_encode([[
                'day' => (int) $group->meeting_day, 'time' => $group->meeting_time ? substr($group->meeting_time, 0, 5) : null, 'label' => null,
            ]])]);
        }
        Schema::table('groups', function (Blueprint $table) {
            $table->dropColumn(['meeting_day', 'meeting_time']);
        });
    }

    public function down(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            $table->unsignedTinyInteger('meeting_day')->nullable()->after('description');
            $table->time('meeting_time')->nullable()->after('meeting_day');
        });
        foreach (DB::table('groups')->whereNotNull('schedule')->get(['id', 'schedule']) as $group) {
            $first = json_decode($group->schedule, true)[0] ?? null;
            DB::table('groups')->where('id', $group->id)->update(['meeting_day' => $first['day'] ?? null, 'meeting_time' => $first['time'] ?? null]);
        }
        Schema::table('groups', fn (Blueprint $table) => $table->dropColumn('schedule'));
    }
};
