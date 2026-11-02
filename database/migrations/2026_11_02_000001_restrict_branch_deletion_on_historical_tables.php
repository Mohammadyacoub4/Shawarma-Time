<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Prevents cascade-deletion of historical timesheet entries, shifts, and schedule weeks
     * when a branch is deleted, ensuring payroll, attendance, and audit records are preserved.
     */
    public function up(): void
    {
        Schema::table('timesheet_entries', function (Blueprint $table) {
            $table->dropForeign(['branch_id']);
            $table->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();
        });

        Schema::table('shifts', function (Blueprint $table) {
            $table->dropForeign(['branch_id']);
            $table->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();
        });

        Schema::table('schedule_weeks', function (Blueprint $table) {
            $table->dropForeign(['branch_id']);
            $table->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('schedule_weeks', function (Blueprint $table) {
            $table->dropForeign(['branch_id']);
            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
        });

        Schema::table('shifts', function (Blueprint $table) {
            $table->dropForeign(['branch_id']);
            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
        });

        Schema::table('timesheet_entries', function (Blueprint $table) {
            $table->dropForeign(['branch_id']);
            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
        });
    }
};

