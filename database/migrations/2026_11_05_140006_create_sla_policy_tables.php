<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sla_policies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            // null = 24x7 (corrido)
            $table->foreignId('business_hour_schedule_id')->nullable()->constrained()->nullOnDelete();
            // Critérios de aplicação (null = qualquer). A política mais específica vence.
            $table->foreignId('client_company_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('ticket_type_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('precedence')->default(100)->comment('Desempate: menor vence');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'client_company_id', 'department_id', 'ticket_type_id'], 'sla_policies_match_idx');
        });

        // Metas por prioridade dentro de uma política
        Schema::create('sla_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sla_policy_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ticket_priority_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('first_response_minutes')->nullable();
            $table->unsignedInteger('resolution_minutes')->nullable();
            $table->unsignedTinyInteger('warning_percent')->nullable()->comment('null = config helpdesk.sla.default_warning_percent');
            $table->timestamps();

            $table->unique(['sla_policy_id', 'ticket_priority_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sla_targets');
        Schema::dropIfExists('sla_policies');
    }
};
