<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->unsignedSmallInteger('grace_minutes')->default(15)->after('timezone');
        });

        Schema::table('shifts', function (Blueprint $table) {
            $table->unsignedSmallInteger('grace_minutes')->default(15)->after('ends_at');
        });

        Schema::table('timesheet_entries', function (Blueprint $table) {
            $table->dateTime('ends_at')->nullable()->change();
            $table->unsignedInteger('total_minutes')->default(0)->change();
            $table->boolean('is_late')->default(false)->after('total_minutes');
            $table->unsignedInteger('late_minutes')->default(0)->after('is_late');
            $table->string('clock_in_ip', 45)->nullable()->after('employee_notes');
            $table->string('device_info', 255)->nullable()->after('clock_in_ip');
        });
    }

    public function down(): void
    {
        Schema::table('timesheet_entries', function (Blueprint $table) {
            $table->dropColumn(['is_late', 'late_minutes', 'clock_in_ip', 'device_info']);
            $table->dateTime('ends_at')->nullable(false)->change();
            $table->unsignedInteger('total_minutes')->default(null)->change();
        });

        Schema::table('shifts', function (Blueprint $table) {
            $table->dropColumn('grace_minutes');
        });

        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn('grace_minutes');
        });
    }
};
