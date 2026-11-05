<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('boards', function (Blueprint $table) {
            $table->id();
            // Dono do quadro: departamento e/ou equipe
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('team_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->boolean('is_default')->default(false)->index();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Colunas dinâmicas = estados do ticket naquele quadro
        Schema::create('board_columns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('board_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('color', 9)->default('#8dbea3');
            $table->unsignedSmallInteger('position')->default(0);
            // Categoria semântica: mantém métricas consistentes mesmo com nomes livres
            $table->string('category', 16)->default('open')->comment('new | open | pending | resolved | closed');
            // Comportamento do SLA enquanto o ticket está nesta coluna
            $table->string('sla_behavior', 8)->default('run')->comment('run | pause | stop');
            $table->unsignedSmallInteger('wip_limit')->nullable();
            $table->boolean('is_initial')->default(false);
            $table->timestamps();

            $table->index(['board_id', 'position']);
            $table->index(['board_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('board_columns');
        Schema::dropIfExists('boards');
    }
};
