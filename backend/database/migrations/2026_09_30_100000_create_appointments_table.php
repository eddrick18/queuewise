<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('queue_entries', function (Blueprint $table) {
            $table->dateTime('priority_at')->nullable()->index();
        });
        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('service_id')->constrained()->restrictOnDelete();
            $table->dateTime('scheduled_at')->index();
            $table->dateTime('reserved_slot')->nullable();
            $table->string('status')->default('booked');
            $table->foreignId('queue_entry_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->timestamps();
            $table->unique(['service_id', 'reserved_slot']);
            $table->index(['user_id', 'scheduled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointments');
        Schema::table('queue_entries', function (Blueprint $table) {
            $table->dropIndex(['priority_at']);
            $table->dropColumn('priority_at');
        });
    }
};
