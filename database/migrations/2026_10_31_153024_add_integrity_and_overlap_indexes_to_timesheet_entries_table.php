<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // STEP 4: Inspect existing non-null duplicates.
        // If found, do not delete or alter historical records; report and stop migration.
        $duplicates = DB::table('timesheet_entries')
            ->select('shift_id', DB::raw('COUNT(*) as count'))
            ->whereNotNull('shift_id')
            ->groupBy('shift_id')
            ->having('count', '>', 1)
            ->get();

        if ($duplicates->isNotEmpty()) {
            $duplicateIds = $duplicates->pluck('shift_id')->implode(', ');
            throw new \RuntimeException(
                "Cannot apply unique constraint: Duplicate shift_id values found in historical timesheet_entries table (shift_ids: {$duplicateIds}). Existing records must not be deleted or altered automatically. Migration stopped."
            );
        }

        // Add unique index on nullable shift_id (multiple NULLs remain allowed by MySQL / relational semantics)
        // Add suitable overlap index beginning with user_id, starts_at: ['user_id', 'starts_at', 'ends_at', 'status']
        Schema::table('timesheet_entries', function (Blueprint $table) {
            $table->unique('shift_id', 'timesheet_entries_shift_id_unique');
            $table->index(['user_id', 'starts_at', 'ends_at', 'status'], 'timesheet_entries_user_starts_ends_status_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('timesheet_entries', function (Blueprint $table) {
            $table->dropUnique('timesheet_entries_shift_id_unique');
            $table->dropIndex('timesheet_entries_user_starts_ends_status_idx');
        });
    }
};
