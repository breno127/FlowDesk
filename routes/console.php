<?php

use App\Jobs\ScanSlaTimers;
use Illuminate\Support\Facades\Schedule;

// Detecção de alertas/violações de SLA (consulta indexada por due_at)
Schedule::job(new ScanSlaTimers)->everyMinute()->withoutOverlapping()->onOneServer();

// Limpeza do log de execuções de automação
Schedule::command('model:prune', ['--model' => [App\Models\AutomationRuleRun::class]])->daily();
