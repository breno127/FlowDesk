<?php

namespace App\Livewire;

use App\Enums\MessageVisibility;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\Tickets\TicketService;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Conversa do ticket estilo chat de suporte.
 *
 * - Editor sempre visível no topo (sem modal): resposta pública ou nota interna.
 * - Colar imagem no editor (Ctrl+V) → upload automático e <img> inline.
 * - Botão de anexos (FileUpload) → disco privado, servido por rota autorizada.
 * - Mensagens da MAIS RECENTE para a mais antiga, com "carregar anteriores".
 *
 * Toda escrita passa pelo TicketService::addMessage (SLA, eventos, menções).
 */
class TicketConversation extends Component implements HasForms
{
    use InteractsWithForms;

    private const PAGE_SIZE = 20;

    #[Locked]
    public Ticket $record;

    #[Locked]
    public int $limit = self::PAGE_SIZE;

    /** @var array<string, mixed> */
    public ?array $data = [];

    public function mount(Ticket $record): void
    {
        Gate::authorize('view', $record);

        $this->record = $record;
        $this->resetComposer();
    }

    // ================================================================== Formulário

    public function form(Form $form): Form
    {
        $config = config('helpdesk.attachments');

        return $form
            ->statePath('data')
            ->schema([
                Forms\Components\ToggleButtons::make('visibility')
                    ->hiddenLabel()
                    ->inline()
                    ->grouped()
                    ->options($this->visibilityOptions())
                    ->icons([
                        MessageVisibility::Public->value => 'heroicon-m-paper-airplane',
                        MessageVisibility::Internal->value => 'heroicon-m-lock-closed',
                    ])
                    ->colors([
                        MessageVisibility::Public->value => 'primary',
                        MessageVisibility::Internal->value => 'warning',
                    ])
                    ->required()
                    ->live()
                    ->hidden(fn () => count($this->visibilityOptions()) < 2),

                Forms\Components\RichEditor::make('body')
                    ->hiddenLabel()
                    ->placeholder(fn (Get $get) => $get('visibility') === MessageVisibility::Internal->value
                        ? 'Nota interna — invisível ao cliente. Mencione colegas com @username…'
                        : 'Escreva sua resposta ao cliente… (cole imagens com Ctrl+V)')
                    ->toolbarButtons(['bold', 'italic', 'strike', 'link', 'bulletList', 'orderedList', 'blockquote', 'codeBlock', 'attachFiles', 'undo', 'redo'])
                    // Imagens coladas/arrastadas no editor: precisam de URL pública para o <img>
                    ->fileAttachmentsDisk($config['inline_disk'])
                    ->fileAttachmentsDirectory(fn () => $config['directory'].'/'.$this->record->id.'/inline')
                    ->fileAttachmentsVisibility('public')
                    ->extraInputAttributes(['style' => 'min-height: 7rem'])
                    ->required(fn (Get $get) => blank($get('attachments')))
                    ->validationMessages(['required' => 'Escreva uma mensagem ou anexe um arquivo.']),

                Forms\Components\FileUpload::make('attachments')
                    ->hiddenLabel()
                    ->multiple()
                    ->disk($config['disk'])
                    ->directory(fn () => $config['directory'].'/'.$this->record->id)
                    ->visibility('private')
                    ->storeFileNamesIn('attachment_names')
                    ->maxFiles($config['max_files'])
                    ->maxSize($config['max_size_kb'])
                    ->acceptedFileTypes($config['accepted_types'])
                    ->panelLayout('grid')
                    ->previewable(false)
                    ->placeholder('📎 Arraste arquivos ou clique para anexar'),
            ]);
    }

    /** @return array<string, string> */
    private function visibilityOptions(): array
    {
        return array_filter([
            MessageVisibility::Public->value => Gate::allows('reply', $this->record) ? 'Responder ao cliente' : null,
            MessageVisibility::Internal->value => Gate::allows('addInternalNote', $this->record) ? 'Nota interna' : null,
        ]);
    }

    public function canWrite(): bool
    {
        return $this->visibilityOptions() !== [];
    }

    // ================================================================== Ações

    public function send(): void
    {
        abort_unless($this->canWrite(), 403);

        $state = $this->form->getState();
        $visibility = MessageVisibility::from($state['visibility']);

        Gate::authorize($visibility === MessageVisibility::Internal ? 'addInternalNote' : 'reply', $this->record);

        $attachments = $this->collectAttachments($state);

        try {
            app(TicketService::class)->addMessage($this->record, [
                'body' => Str::sanitizeHtml((string) ($state['body'] ?? '')),
                'visibility' => $visibility,
                'attachments' => $attachments,
            ], auth()->user());
        } catch (\Throwable $e) {
            // Transação revertida: não deixa arquivos órfãos no disco
            foreach ($attachments as $file) {
                Storage::disk($file['disk'])->delete($file['path']);
            }

            throw $e;
        }

        $this->resetComposer($visibility);
        unset($this->messages);

        // A página (SLA, status, datas) escuta para se atualizar
        $this->dispatch('ticket-conversation-updated');

        Notification::make()
            ->title($visibility === MessageVisibility::Internal ? 'Nota interna registrada' : 'Resposta enviada')
            ->success()
            ->send();
    }

    public function loadOlder(): void
    {
        $this->limit += self::PAGE_SIZE;
        unset($this->messages);
    }

    /**
     * @param  array<string, mixed>  $state
     * @return list<array{disk: string, path: string, original_name: string, mime_type: ?string, size: int}>
     */
    private function collectAttachments(array $state): array
    {
        $disk = config('helpdesk.attachments.disk');
        $names = $state['attachment_names'] ?? [];

        return collect($state['attachments'] ?? [])
            ->filter()
            ->map(fn (string $path) => [
                'disk' => $disk,
                'path' => $path,
                'original_name' => Str::limit($names[$path] ?? basename($path), 250, ''),
                'mime_type' => Storage::disk($disk)->mimeType($path) ?: null,
                'size' => (int) Storage::disk($disk)->size($path),
            ])
            ->values()
            ->all();
    }

    private function resetComposer(?MessageVisibility $keep = null): void
    {
        $options = array_keys($this->visibilityOptions());

        $this->form->fill([
            'visibility' => $keep?->value ?? ($options[0] ?? MessageVisibility::Public->value),
            'body' => null,
            'attachments' => [],
            'attachment_names' => [],
        ]);
    }

    // ================================================================== Dados

    /** @return Collection<int, TicketMessage> */
    #[Computed]
    public function messages(): Collection
    {
        return $this->messagesQuery()
            ->with(['author', 'attachments'])
            ->limit($this->limit)
            ->get();
    }

    #[Computed]
    public function total(): int
    {
        return $this->messagesQuery()->count();
    }

    private function messagesQuery(): HasMany
    {
        return $this->record->messages()
            ->reorder()                 // a relação ordena ASC por padrão
            ->latest('created_at')
            ->latest('id')              // desempate para mensagens no mesmo segundo
            ->when(
                Gate::denies('viewInternalNotes', $this->record),
                fn ($query) => $query->where('visibility', MessageVisibility::Public->value)
            );
    }

    public function isMine(TicketMessage $message): bool
    {
        return $message->author instanceof User && $message->author->is(auth()->user());
    }

    public function render(): View
    {
        return view('livewire.ticket-conversation');
    }
}
