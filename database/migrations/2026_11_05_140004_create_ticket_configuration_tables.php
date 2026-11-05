<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_priorities', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->unsignedTinyInteger('level')->comment('Ordenação: maior = mais crítico');
            // Peso usado no balanceamento de carga do round-robin
            $table->unsignedSmallInteger('weight')->default(1);
            $table->string('color', 9)->default('#8dbea3');
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index('level');
        });

        Schema::create('ticket_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('icon')->nullable();
            $table->string('color', 9)->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // EAV — Atributos (definição dos campos)
        Schema::create('custom_fields', function (Blueprint $table) {
            $table->id();
            $table->string('label');
            $table->string('key')->unique()->comment('Identificador estável usado em API/automação');
            $table->string('type', 32)->comment('Ver App\Enums\CustomFieldType');
            $table->json('options')->nullable()->comment('[{value,label}] para select/multiselect/radio');
            $table->json('validation')->nullable()->comment('Regras extras: min, max, regex, max_length');
            $table->string('placeholder')->nullable();
            $table->string('help_text')->nullable();
            $table->text('default_value')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Quais campos cada tipo de ticket renderiza (e em que ordem)
        Schema::create('custom_field_ticket_type', function (Blueprint $table) {
            $table->id();
            $table->foreignId('custom_field_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ticket_type_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_required')->default(false);
            $table->boolean('visible_to_customer')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->unsignedTinyInteger('column_span')->default(1);
            $table->timestamps();

            $table->unique(['ticket_type_id', 'custom_field_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_field_ticket_type');
        Schema::dropIfExists('custom_fields');
        Schema::dropIfExists('ticket_types');
        Schema::dropIfExists('ticket_priorities');
    }
};
