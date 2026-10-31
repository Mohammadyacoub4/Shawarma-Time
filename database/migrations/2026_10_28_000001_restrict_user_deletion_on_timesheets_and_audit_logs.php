<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Prevents cascade-deletion of historical timesheet entries and audit log records
     * when a user is deleted, ensuring payroll and labor compliance records are preserved.
     */
    public function up(): void
    {
        Schema::table('timesheet_entries', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::table('timesheet_audit_logs', function (Blueprint $table) {
            $table->dropForeign(['performed_by']);
            $table->foreign('performed_by')->references('id')->on('users')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('timesheet_audit_logs', function (Blueprint $table) {
            $table->dropForeign(['performed_by']);
            $table->foreign('performed_by')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::table('timesheet_entries', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }
};

