<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Sequência transacional para numeração legível (HD-2026-000123)
        Schema::create('ticket_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('scope', 32)->unique()->comment('Ex.: 2026 ou "global"');
            $table->unsignedBigInteger('last_value')->default(0);
            $table->timestamps();
        });

        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->string('number', 32)->unique();
            $table->string('subject');
            $table->longText('description')->nullable();

            $table->foreignId('board_id')->constrained()->restrictOnDelete();
            $table->foreignId('board_column_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('position')->default(0);

            $table->foreignId('ticket_type_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ticket_priority_id')->constrained()->restrictOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('team_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('client_company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sla_policy_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('channel', 32)->default('portal');

            // Marcos de tempo (base para TMA/TME e funil)
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('first_response_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('last_customer_activity_at')->nullable();
            $table->timestamp('last_agent_activity_at')->nullable();

            // CSAT
            $table->unsignedTinyInteger('csat_score')->nullable();
            $table->text('csat_comment')->nullable();
            $table->timestamp('csat_answered_at')->nullable();

            // Integrações
            $table->string('jira_issue_key', 32)->nullable()->unique();
            $table->json('meta')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['board_column_id', 'position']);
            $table->index(['assignee_id', 'resolved_at']);
            $table->index(['team_id', 'resolved_at']);
            $table->index(['client_company_id', 'created_at']);
            $table->index(['ticket_priority_id']);
            $table->index('created_at');
        });

        // EAV — Valores. Colunas tipadas permitem índices e filtros reais no MySQL.
        Schema::create('ticket_field_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('custom_field_id')->constrained()->cascadeOnDelete();
            $table->string('value_string', 512)->nullable();
            $table->text('value_text')->nullable();
            $table->decimal('value_number', 20, 6)->nullable();
            $table->boolean('value_boolean')->nullable();
            $table->dateTime('value_datetime')->nullable();
            $table->json('value_json')->nullable();
            $table->timestamps();

            $table->unique(['ticket_id', 'custom_field_id']);
            $table->index(['custom_field_id', 'value_string']);
            $table->index(['custom_field_id', 'value_number']);
            $table->index(['custom_field_id', 'value_datetime']);
        });

        // Timeline unificada (cliente, agente, sistema; público ou interno)
        Schema::create('ticket_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->nullableMorphs('author'); // User | Contact | null (sistema)
            $table->string('visibility', 16)->default('public')->comment('public | internal');
            $table->string('direction', 16)->default('outbound')->comment('inbound | outbound | system');
            $table->string('channel', 32)->default('portal');
            $table->longText('body');
            $table->string('external_id')->nullable()->unique()->comment('wamid, id de e-mail, etc.');
            $table->string('delivery_status', 16)->nullable()->comment('queued | sent | delivered | read | failed');
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->text('delivery_error')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['ticket_id', 'created_at']);
            $table->index(['ticket_id', 'visibility']);
        });

        Schema::create('ticket_message_mentions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();

            $table->unique(['ticket_message_id', 'user_id']);
        });

        Schema::create('ticket_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ticket_message_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('disk', 32);
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type', 128)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->timestamps();
        });

        // Trilha de auditoria (quem fez o quê)
        Schema::create('ticket_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('causer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event', 64);
            $table->json('properties')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['ticket_id', 'created_at']);
            $table->index(['event', 'created_at']);
        });

        // Permanência em cada coluna — base para detectar gargalos no Kanban
        Schema::create('ticket_column_transitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('board_column_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_column_id')->nullable()->constrained('board_columns')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('entered_at');
            $table->timestamp('left_at')->nullable();
            $table->unsignedBigInteger('duration_seconds')->nullable();

            $table->index(['ticket_id', 'left_at']);
            $table->index(['board_column_id', 'entered_at']);
        });

        // Timers de SLA (primeira resposta / solução) com pausa e projeção de vencimento
        Schema::create('ticket_sla_timers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32)->comment('first_response | resolution');
            $table->foreignId('sla_policy_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('business_hour_schedule_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('target_seconds');
            $table->unsignedTinyInteger('warning_percent');
            $table->string('status', 16)->default('running')->comment('running | paused | completed | cancelled');

            // Segundos úteis consumidos ANTES do segmento em execução
            $table->unsignedBigInteger('elapsed_seconds')->default(0);
            $table->timestamp('started_at');
            $table->timestamp('segment_started_at')->nullable()->comment('Início do segmento em execução');
            $table->timestamp('paused_at')->nullable();
            $table->string('pause_reason')->nullable();

            // Projeções (recalculadas a cada start/resume) — permitem varredura indexada
            $table->timestamp('warning_due_at')->nullable();
            $table->timestamp('due_at')->nullable();

            $table->timestamp('warned_at')->nullable();
            $table->timestamp('breached_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['ticket_id', 'type']);
            $table->index(['status', 'due_at']);
            $table->index(['status', 'warning_due_at']);
            $table->index(['breached_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_sla_timers');
        Schema::dropIfExists('ticket_column_transitions');
        Schema::dropIfExists('ticket_activities');
        Schema::dropIfExists('ticket_attachments');
        Schema::dropIfExists('ticket_message_mentions');
        Schema::dropIfExists('ticket_messages');
        Schema::dropIfExists('ticket_field_values');
        Schema::dropIfExists('tickets');
        Schema::dropIfExists('ticket_sequences');
    }
};
