<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_hour_schedules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('timezone', 64);
            $table->boolean('is_default')->default(false)->index();
            $table->timestamps();
        });

        Schema::create('business_hour_intervals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_hour_schedule_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('weekday')->comment('ISO-8601: 1 = segunda ... 7 = domingo');
            $table->time('starts_at');
            $table->time('ends_at');
            $table->timestamps();

            $table->index(['business_hour_schedule_id', 'weekday']);
        });

        Schema::create('holidays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_hour_schedule_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->date('date');
            // Recorrente = repete todo ano no mesmo dia/mês (ex.: Natal)
            $table->boolean('is_recurring')->default(false);
            $table->timestamps();

            $table->index(['business_hour_schedule_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('holidays');
        Schema::dropIfExists('business_hour_intervals');
        Schema::dropIfExists('business_hour_schedules');
    }
};
