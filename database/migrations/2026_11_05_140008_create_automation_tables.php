<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            // Escopo (null = global)
            $table->foreignId('board_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('event', 64)->comment('Ver config helpdesk.automation.events');
            $table->string('match', 8)->default('all')->comment('all | any');
            $table->json('conditions')->nullable()->comment('[{field, operator, value}]');
            $table->json('actions')->comment('[{type, data:{...}}]');
            $table->boolean('stop_processing')->default(false);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('run_count')->default(0);
            $table->timestamp('last_run_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['event', 'is_active', 'sort']);
        });

        Schema::create('automation_rule_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('automation_rule_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ticket_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('event', 64);
            $table->string('status', 16)->comment('success | failed | skipped');
            $table->json('executed_actions')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['automation_rule_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_rule_runs');
        Schema::dropIfExists('automation_rules');
    }
};
