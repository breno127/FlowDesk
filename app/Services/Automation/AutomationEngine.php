<?php

namespace App\Services\Automation;

use App\Contracts\AutomationAction;
use App\Models\AutomationRule;
use App\Models\AutomationRuleRun;
use App\Models\Ticket;
use App\Support\AutomationDepth;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Motor de regras "quando EVENTO e CONDIÇÕES então AÇÕES".
 *
 * - Cada regra roda na sua própria transação: falha numa regra não desfaz as demais.
 * - Toda execução fica registrada em automation_rule_runs (auditoria/depuração).
 * - Proteção contra cascata infinita via AutomationDepth.
 */
class AutomationEngine
{
    public function __construct(
        private readonly ConditionEvaluator $conditions,
        private readonly Container $container,
    ) {}

    /** @return int quantidade de regras executadas */
    public function run(string $event, Ticket $ticket, array $payload = [], int $depth = 0): int
    {
        $maxDepth = (int) config('helpdesk.automation.max_depth', 3);

        if ($depth >= $maxDepth) {
            Log::warning('Automação interrompida: profundidade máxima atingida.', [
                'event' => $event, 'ticket_id' => $ticket->id, 'depth' => $depth,
            ]);

            return 0;
        }

        $context = new AutomationContext($event, $payload, $depth);
        $executed = 0;

        $rules = AutomationRule::query()->applicableTo($event, $ticket)->get();

        foreach ($rules as $rule) {
            $ticket->refresh();
            $ruleContext = $context->forRule($rule);

            if (! $this->conditions->passes($rule->conditions ?? [], $rule->match, $ticket, $ruleContext)) {
                continue;
            }

            $this->execute($rule, $ticket, $ruleContext);
            $executed++;

            if ($rule->stop_processing) {
                break;
            }
        }

        return $executed;
    }

    private function execute(AutomationRule $rule, Ticket $ticket, AutomationContext $context): void
    {
        $summaries = [];

        try {
            // Eventos gerados pelas ações herdam depth + 1
            AutomationDepth::run($context->depth + 1, function () use ($rule, $ticket, $context, &$summaries) {
                DB::transaction(function () use ($rule, $ticket, $context, &$summaries) {
                    foreach ($rule->actions ?? [] as $action) {
                        $type = $action['type'] ?? null;
                        $summaries[] = [
                            'type' => $type,
                            'result' => $this->action($type)->execute($ticket, $action['data'] ?? [], $context),
                        ];
                    }
                });
            });

            $this->record($rule, $ticket, $context, 'success', $summaries);
        } catch (Throwable $e) {
            report($e);
            $this->record($rule, $ticket, $context, 'failed', $summaries, $e->getMessage());
        }
    }

    private function action(?string $type): AutomationAction
    {
        $class = config("helpdesk.automation.actions.{$type}");

        if (! $class || ! is_subclass_of($class, AutomationAction::class)) {
            throw new InvalidArgumentException("Ação de automação desconhecida: {$type}");
        }

        return $this->container->make($class);
    }

    private function record(AutomationRule $rule, Ticket $ticket, AutomationContext $context, string $status, array $actions, ?string $error = null): void
    {
        AutomationRuleRun::query()->create([
            'automation_rule_id' => $rule->id,
            'ticket_id' => $ticket->id,
            'event' => $context->event,
            'status' => $status,
            'executed_actions' => $actions,
            'error' => $error,
        ]);

        $rule->newQuery()->whereKey($rule->id)->update([
            'run_count' => DB::raw('run_count + 1'),
            'last_run_at' => now(),
        ]);
    }
}
