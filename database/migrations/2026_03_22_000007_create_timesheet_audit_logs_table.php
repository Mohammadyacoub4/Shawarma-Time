<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('timesheet_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('timesheet_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('performed_by')->constrained('users')->cascadeOnDelete();
            $table->string('action');
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['timesheet_entry_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('timesheet_audit_logs');
    }
};
