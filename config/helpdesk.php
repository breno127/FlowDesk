<?php

/*
|--------------------------------------------------------------------------
| Helpdesk — configuração central
|--------------------------------------------------------------------------
| Tudo o que não é dado de negócio (que vive no banco) mas precisa ser
| ajustável sem tocar em código fica aqui, com override via .env.
*/

return [

    'ticket_number' => [
        // Tokens: {prefix} {year} {month} {seq}
        'format' => env('HELPDESK_TICKET_FORMAT', '{prefix}-{year}-{seq}'),
        'prefix' => env('HELPDESK_TICKET_PREFIX', 'HD'),
        'pad' => (int) env('HELPDESK_TICKET_PAD', 6),
        // Reinicia a sequência a cada ano quando o formato contém {year}
        'reset_yearly' => (bool) env('HELPDESK_TICKET_RESET_YEARLY', true),
    ],

    // Canais aceitos em tickets/mensagens. Rótulo exibido na UI.
    'channels' => [
        'portal' => 'Portal',
        'email' => 'E-mail',
        'whatsapp' => 'WhatsApp',
        'phone' => 'Telefone',
        'api' => 'API',
        'jira' => 'Jira',
    ],

    // DDI aplicado a telefones digitados sem "+" (normalização para E.164)
    'default_country_code' => env('HELPDESK_DEFAULT_COUNTRY_CODE', '55'),

    // Provedores públicos: não servem como "domínio da empresa" (vincularia
    // qualquer contato @gmail.com à mesma empresa)
    'public_email_domains' => array_filter(explode(',', env(
        'HELPDESK_PUBLIC_EMAIL_DOMAINS',
        'gmail.com,googlemail.com,hotmail.com,outlook.com,live.com,msn.com,yahoo.com,yahoo.com.br,icloud.com,me.com,uol.com.br,bol.com.br,terra.com.br,ig.com.br,proton.me,protonmail.com'
    ))),

    'sla' => [
        // % do tempo-alvo consumido a partir do qual o timer entra em "warning"
        'default_warning_percent' => (int) env('HELPDESK_SLA_WARNING_PERCENT', 75),
        // % a partir do qual entra em "critical" (cor mais forte no card)
        'critical_percent' => (int) env('HELPDESK_SLA_CRITICAL_PERCENT', 90),
        // Tamanho do lote processado pelo job de varredura
        'scan_chunk' => (int) env('HELPDESK_SLA_SCAN_CHUNK', 500),
        // Limite de dias que o calculador de horário útil percorre (proteção contra loop)
        'max_lookahead_days' => (int) env('HELPDESK_SLA_MAX_LOOKAHEAD_DAYS', 730),
        // Fila usada pelos jobs de SLA
        'queue' => env('HELPDESK_SLA_QUEUE', 'sla'),
    ],

    'automation' => [
        'queue' => env('HELPDESK_AUTOMATION_QUEUE', 'automation'),
        // Profundidade máxima de cascata (regra que dispara evento que dispara regra...)
        'max_depth' => (int) env('HELPDESK_AUTOMATION_MAX_DEPTH', 3),
        // Dias de retenção do log de execuções (automation_rule_runs)
        'retention_days' => (int) env('HELPDESK_AUTOMATION_RETENTION_DAYS', 90),

        // Eventos que podem disparar regras => rótulo
        'events' => [
            'ticket.created' => 'Ticket criado',
            'ticket.updated' => 'Ticket atualizado',
            'ticket.moved' => 'Ticket movido de coluna',
            'ticket.assigned' => 'Ticket atribuído',
            'ticket.priority_changed' => 'Prioridade alterada',
            'ticket.resolved' => 'Ticket resolvido',
            'message.created' => 'Nova mensagem/nota',
            'sla.warning' => 'SLA em alerta',
            'sla.breached' => 'SLA violado',
        ],

        // Campos que as condições podem inspecionar => [rótulo, tipo]
        'condition_fields' => [
            'board_column_id' => ['Coluna (destino)', 'column'],
            'from_column_id' => ['Coluna (origem)', 'column'],
            'ticket_priority_id' => ['Prioridade', 'priority'],
            'ticket_type_id' => ['Tipo de ticket', 'ticket_type'],
            'department_id' => ['Departamento', 'department'],
            'team_id' => ['Equipe', 'team'],
            'assignee_id' => ['Responsável', 'user'],
            'client_company_id' => ['Empresa cliente', 'company'],
            'channel' => ['Canal', 'channel'],
            'subject' => ['Assunto', 'text'],
            'message.visibility' => ['Visibilidade da mensagem', 'text'],
            'sla.type' => ['Tipo de SLA', 'text'],
        ],

        'operators' => [
            'equals' => 'é igual a',
            'not_equals' => 'é diferente de',
            'in' => 'está em',
            'not_in' => 'não está em',
            'contains' => 'contém',
            'not_contains' => 'não contém',
            'is_empty' => 'está vazio',
            'is_not_empty' => 'não está vazio',
            'changed' => 'foi alterado',
        ],

        // Ações disponíveis => classe handler (App\Contracts\AutomationAction)
        'actions' => [
            'notify_users' => App\Services\Automation\Actions\NotifyUsersAction::class,
            'notify_assignee' => App\Services\Automation\Actions\NotifyAssigneeAction::class,
            'notify_team' => App\Services\Automation\Actions\NotifyTeamAction::class,
            'set_priority' => App\Services\Automation\Actions\SetPriorityAction::class,
            'assign_team' => App\Services\Automation\Actions\AssignTeamAction::class,
            'auto_assign' => App\Services\Automation\Actions\AutoAssignAction::class,
            'move_to_column' => App\Services\Automation\Actions\MoveToColumnAction::class,
            'add_internal_note' => App\Services\Automation\Actions\AddInternalNoteAction::class,
            'pause_sla' => App\Services\Automation\Actions\PauseSlaAction::class,
            'resume_sla' => App\Services\Automation\Actions\ResumeSlaAction::class,
            'fire_webhook' => App\Services\Automation\Actions\FireWebhookAction::class,
        ],
    ],

    'webhooks' => [
        'queue' => env('HELPDESK_WEBHOOK_QUEUE', 'webhooks'),
        'timeout' => (int) env('HELPDESK_WEBHOOK_TIMEOUT', 10),
        'tries' => (int) env('HELPDESK_WEBHOOK_TRIES', 5),
        // Segredo para assinatura HMAC-SHA256 (header X-Helpdesk-Signature)
        'signing_secret' => env('HELPDESK_WEBHOOK_SECRET'),
    ],

    'notifications' => [
        'queue' => env('HELPDESK_NOTIFICATION_QUEUE', 'notifications'),
        // Canais Laravel usados pelas notificações de agentes
        'channels' => array_filter(explode(',', env('HELPDESK_NOTIFICATION_CHANNELS', 'database,mail'))),
    ],

    'kanban' => [
        // Intervalo de polling do quadro (segundos) até a etapa de Reverb
        'poll_seconds' => (int) env('HELPDESK_KANBAN_POLL', 20),
        // Máximo de cards carregados por coluna
        'cards_per_column' => (int) env('HELPDESK_KANBAN_CARDS_PER_COLUMN', 100),
    ],

    'rbac' => [
        // Papel que ignora checagens (Gate::before)
        'super_admin_role' => env('HELPDESK_SUPER_ADMIN_ROLE', 'super-admin'),

        // Catálogo de permissões: grupo => [permissão => rótulo].
        // Sincronizado com o banco via `php artisan helpdesk:sync-permissions`.
        'permissions' => [
            'Tickets' => [
                'tickets.view_own' => 'Ver tickets atribuídos a si',
                'tickets.view_team' => 'Ver tickets das suas equipes',
                'tickets.view_all' => 'Ver todos os tickets',
                'tickets.create' => 'Criar tickets',
                'tickets.update' => 'Editar tickets',
                'tickets.delete' => 'Excluir tickets',
                'tickets.restore' => 'Restaurar tickets excluídos',
                'tickets.assign' => 'Atribuir tickets a outros agentes',
                'tickets.move' => 'Mover tickets no Kanban',
                'tickets.reply' => 'Responder ao cliente',
                'tickets.internal_note' => 'Criar notas internas',
                'tickets.change_priority' => 'Alterar prioridade',
            ],
            'Kanban' => [
                'kanban.view' => 'Acessar o Kanban',
                'kanban.manage_boards' => 'Gerenciar quadros',
                'kanban.manage_columns' => 'Criar/editar/remover colunas',
                'kanban.bypass_wip' => 'Ignorar limite WIP',
            ],
            'SLA' => [
                'sla.manage' => 'Gerenciar políticas de SLA',
                'sla.manage_business_hours' => 'Gerenciar horários e feriados',
            ],
            'Configuração' => [
                'config.ticket_types' => 'Gerenciar tipos de ticket',
                'config.custom_fields' => 'Gerenciar campos customizados',
                'config.priorities' => 'Gerenciar prioridades',
                'config.automations' => 'Gerenciar regras de automação',
            ],
            'Clientes' => [
                'customers.view' => 'Ver clientes',
                'customers.manage' => 'Gerenciar clientes e contatos',
            ],
            'Organização' => [
                'org.manage_departments' => 'Gerenciar departamentos',
                'org.manage_teams' => 'Gerenciar equipes',
                'org.manage_users' => 'Gerenciar usuários/agentes',
                'org.manage_roles' => 'Gerenciar perfis de acesso',
            ],
            'Dashboards' => [
                'dashboards.view_own' => 'Ver suas próprias métricas',
                'dashboards.view_others' => 'Ver dashboards de outros agentes',
                'dashboards.view_operation' => 'Ver métricas da operação',
                'dashboards.build' => 'Criar dashboards customizados',
            ],
        ],
    ],

    /*
    | Dados iniciais (usados apenas pelo HelpdeskBootstrapSeeder na primeira
    | instalação). Depois disso, tudo é administrado pelo painel.
    */
    'bootstrap' => [
        'priorities' => [
            ['name' => 'Baixa', 'slug' => 'low', 'level' => 1, 'weight' => 1, 'color' => '#8fb3c9', 'is_default' => false],
            ['name' => 'Normal', 'slug' => 'normal', 'level' => 2, 'weight' => 2, 'color' => '#8dbea3', 'is_default' => true],
            ['name' => 'Alta', 'slug' => 'high', 'level' => 3, 'weight' => 4, 'color' => '#d9b76a', 'is_default' => false],
            ['name' => 'Urgente', 'slug' => 'urgent', 'level' => 4, 'weight' => 8, 'color' => '#d48a8a', 'is_default' => false],
        ],
        'board' => [
            'name' => 'Service Desk',
            'columns' => [
                ['name' => 'Novo', 'category' => 'new', 'sla_behavior' => 'run', 'color' => '#8fb3c9', 'is_initial' => true],
                ['name' => 'Em Análise', 'category' => 'open', 'sla_behavior' => 'run', 'color' => '#a99cc9'],
                ['name' => 'Aguardando Cliente', 'category' => 'pending', 'sla_behavior' => 'pause', 'color' => '#d9b76a'],
                ['name' => 'Resolvido', 'category' => 'resolved', 'sla_behavior' => 'stop', 'color' => '#8dbea3'],
                ['name' => 'Fechado', 'category' => 'closed', 'sla_behavior' => 'stop', 'color' => '#a3a8a6'],
            ],
        ],
        'business_hours' => [
            'name' => 'Comercial',
            'timezone' => env('APP_TIMEZONE_BUSINESS', 'America/Sao_Paulo'),
            // weekday ISO-8601 (1 = segunda ... 7 = domingo)
            'intervals' => [
                ['weekday' => 1, 'starts_at' => '08:00', 'ends_at' => '18:00'],
                ['weekday' => 2, 'starts_at' => '08:00', 'ends_at' => '18:00'],
                ['weekday' => 3, 'starts_at' => '08:00', 'ends_at' => '18:00'],
                ['weekday' => 4, 'starts_at' => '08:00', 'ends_at' => '18:00'],
                ['weekday' => 5, 'starts_at' => '08:00', 'ends_at' => '18:00'],
            ],
        ],
        'roles' => [
            'super-admin' => '*',
            'gestor' => ['tickets.*', 'kanban.*', 'sla.*', 'config.*', 'customers.*', 'org.manage_teams', 'dashboards.*'],
            'agente-n2' => ['tickets.view_team', 'tickets.create', 'tickets.update', 'tickets.assign', 'tickets.move', 'tickets.reply', 'tickets.internal_note', 'tickets.change_priority', 'kanban.view', 'customers.view', 'dashboards.view_own'],
            'agente-n1' => ['tickets.view_own', 'tickets.view_team', 'tickets.create', 'tickets.update', 'tickets.move', 'tickets.reply', 'tickets.internal_note', 'kanban.view', 'customers.view', 'dashboards.view_own'],
        ],
    ],
];
