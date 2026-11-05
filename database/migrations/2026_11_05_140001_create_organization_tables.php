<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 64)->nullable()->unique()->after('name')->comment('Handle para @menções');
            $table->boolean('is_agent')->default(true)->after('password');
            $table->boolean('is_active')->default(true)->after('is_agent');
            $table->string('timezone', 64)->nullable()->after('is_active');
            $table->string('phone', 32)->nullable()->after('timezone');
            $table->string('avatar_path')->nullable()->after('phone');
            $table->timestamp('last_seen_at')->nullable()->after('avatar_path');

            $table->index(['is_agent', 'is_active']);
        });

        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('email')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();
            // Organograma: equipe-pai e equipe de escalonamento (N1 -> N2 -> N3)
            $table->foreignId('parent_id')->nullable()->constrained('teams')->nullOnDelete();
            $table->foreignId('escalation_team_id')->nullable()->constrained('teams')->nullOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->unsignedTinyInteger('tier')->default(1)->comment('Nível de suporte: 1, 2, 3...');
            $table->string('routing_strategy', 32)->default('load_balanced')->comment('load_balanced | round_robin | manual');
            $table->unsignedInteger('max_weight_per_agent')->nullable()->comment('Teto de carga (soma dos pesos) por agente; null = sem teto');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['department_id', 'tier']);
        });

        Schema::create('team_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_lead')->default(false);
            // Multiplicador de capacidade: 1.00 = carga padrão, 0.50 = meio período
            $table->decimal('capacity', 5, 2)->default(1.00);
            $table->boolean('is_available')->default(true);
            $table->timestamp('last_assigned_at')->nullable();
            $table->timestamps();

            $table->unique(['team_id', 'user_id']);
            $table->index(['team_id', 'is_available', 'last_assigned_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_user');
        Schema::dropIfExists('teams');
        Schema::dropIfExists('departments');

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['is_agent', 'is_active']);
            $table->dropUnique(['username']);
            $table->dropColumn(['username', 'is_agent', 'is_active', 'timezone', 'phone', 'avatar_path', 'last_seen_at']);
        });
    }
};
