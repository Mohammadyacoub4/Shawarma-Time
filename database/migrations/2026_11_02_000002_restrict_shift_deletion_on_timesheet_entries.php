<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Prevents setting timesheet_entries.shift_id to NULL when a shift is deleted,
     * ensuring historical scheduled shift associations are preserved.
     */
    public function up(): void
    {
        Schema::table('timesheet_entries', function (Blueprint $table) {
            $table->dropForeign(['shift_id']);
            $table->foreign('shift_id')->references('id')->on('shifts')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('timesheet_entries', function (Blueprint $table) {
            $table->dropForeign(['shift_id']);
            $table->foreign('shift_id')->references('id')->on('shifts')->nullOnDelete();
        });
    }
};

