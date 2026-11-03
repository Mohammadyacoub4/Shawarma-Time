<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Standardizes branch timezone default and existing records to fixed UTC-07:00 (Etc/GMT+7).
     */
    public function up(): void
    {
        DB::table('branches')
            ->where('timezone', 'Canada/Pacific')
            ->orWhere('timezone', 'America/Vancouver')
            ->update(['timezone' => 'Etc/GMT+7']);

        Schema::table('branches', function (Blueprint $table) {
            $table->string('timezone')->default('Etc/GMT+7')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('branches')
            ->where('timezone', 'Etc/GMT+7')
            ->update(['timezone' => 'Canada/Pacific']);

        Schema::table('branches', function (Blueprint $table) {
            $table->string('timezone')->default('Canada/Pacific')->change();
        });
    }
};

