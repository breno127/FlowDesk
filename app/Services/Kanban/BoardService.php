<?php

namespace App\Services\Kanban;

use App\Enums\ColumnCategory;
use App\Enums\SlaBehavior;
use App\Exceptions\Helpdesk\HelpdeskException;
use App\Models\Board;
use App\Models\BoardColumn;
use App\Repositories\Contracts\TicketRepositoryInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Gestão das colunas dinâmicas de cada quadro.
 */
class BoardService
{
    public function __construct(private readonly TicketRepositoryInterface $tickets) {}

    /**
     * Sincroniza as colunas do quadro com a lista recebida (na ordem desejada).
     * Itens com `id` são atualizados, sem `id` são criados e os ausentes removidos
     * — remoção bloqueada se a coluna tiver tickets.
     *
     * @param  list<array{id?: ?int, name: string, color?: ?string, category?: string, sla_behavior?: string, wip_limit?: ?int, is_initial?: bool}>  $columns
     */
    public function syncColumns(Board $board, array $columns): Board
    {
        if ($columns === []) {
            throw HelpdeskException::boardWithoutColumns();
        }

        return DB::transaction(function () use ($board, $columns) {
            $existing = $board->columns()->lockForUpdate()->get()->keyBy('id');
            $keptIds = collect($columns)->pluck('id')->filter()->map(fn ($id) => (int) $id)->all();

            foreach ($existing->except($keptIds) as $column) {
                $count = $this->tickets->countInColumn($column->id);
                if ($count > 0) {
                    throw HelpdeskException::columnNotEmpty($column->name, $count);
                }
                $column->delete();
            }

            $hasInitial = collect($columns)->contains(fn ($c) => ! empty($c['is_initial']));

            foreach (array_values($columns) as $index => $data) {
                $attributes = [
                    'name' => trim($data['name']),
                    'color' => $data['color'] ?? '#8dbea3',
                    'category' => $data['category'] ?? ColumnCategory::Open->value,
                    'sla_behavior' => $data['sla_behavior'] ?? SlaBehavior::Run->value,
                    'wip_limit' => filled($data['wip_limit'] ?? null) ? (int) $data['wip_limit'] : null,
                    'is_initial' => $hasInitial ? (bool) ($data['is_initial'] ?? false) : $index === 0,
                    'position' => $index,
                ];

                $id = (int) ($data['id'] ?? 0);

                if ($id && $existing->has($id)) {
                    $existing[$id]->update($attributes);
                } else {
                    $board->columns()->create($attributes);
                }
            }

            // Garante exatamente uma coluna inicial
            $initials = $board->columns()->where('is_initial', true)->orderBy('position')->pluck('id');
            if ($initials->count() > 1) {
                BoardColumn::query()->whereIn('id', $initials->slice(1)->all())->update(['is_initial' => false]);
            }

            return $board->load('columns');
        });
    }

    /** Cria um quadro com colunas a partir de um modelo (ex.: config helpdesk.bootstrap.board). */
    public function createFromTemplate(array $template, array $attributes = []): Board
    {
        return DB::transaction(function () use ($template, $attributes) {
            $name = $attributes['name'] ?? $template['name'];

            $board = Board::query()->create(array_merge([
                'name' => $name,
                'slug' => $this->uniqueSlug($name),
                'is_active' => true,
            ], Arr::except($attributes, ['columns'])));

            return $this->syncColumns($board, $template['columns']);
        });
    }

    public function reorderColumns(Board $board, array $orderedIds): void
    {
        DB::transaction(function () use ($board, $orderedIds) {
            foreach (array_values($orderedIds) as $position => $id) {
                $board->columns()->whereKey((int) $id)->update(['position' => $position]);
            }
        });
    }

    public function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'quadro';
        $slug = $base;
        $i = 2;

        while (Board::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}
