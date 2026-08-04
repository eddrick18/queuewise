<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('queue_entries', function (Blueprint $table) {
            $table->id();

            $table
                ->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            $table
                ->foreignId('service_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->unsignedInteger('queue_number');

            $table->date('queue_date');

            $table
                ->string('status')
                ->default('waiting');

            $table
                ->timestamp('joined_at')
                ->useCurrent();

            $table
                ->timestamp('called_at')
                ->nullable();

            $table
                ->timestamp('completed_at')
                ->nullable();

            $table->timestamps();

            $table->unique([
                'service_id',
                'queue_date',
                'queue_number',
            ]);

            $table->index([
                'service_id',
                'queue_date',
                'status',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('queue_entries');
    }
};