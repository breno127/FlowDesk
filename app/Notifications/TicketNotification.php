<?php

namespace App\Notifications;

use App\Filament\Resources\TicketResource;
use App\Models\Ticket;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Throwable;

/**
 * Base das notificações de agentes: sino do Filament (database) + e-mail,
 * canais definidos em config('helpdesk.notifications.channels').
 */
abstract class TicketNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Ticket $ticket)
    {
        $this->onQueue(config('helpdesk.notifications.queue'));
        $this->afterCommit();
    }

    abstract protected function title(): string;

    abstract protected function body(): ?string;

    protected function icon(): string
    {
        return 'heroicon-o-ticket';
    }

    /** success | warning | danger | info */
    protected function status(): string
    {
        return 'info';
    }

    public function via(object $notifiable): array
    {
        return array_values(array_filter(
            config('helpdesk.notifications.channels', ['database']),
            fn (string $channel) => $channel !== 'mail' || filled($notifiable->email ?? null),
        ));
    }

    public function toDatabase(object $notifiable): array
    {
        return FilamentNotification::make()
            ->title($this->title())
            ->body($this->body())
            ->icon($this->icon())
            ->status($this->status())
            ->actions([
                Action::make('open')->label('Abrir ticket')->button()->url($this->ticketUrl())->markAsRead(),
            ])
            ->getDatabaseMessage();
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('['.$this->ticket->number.'] '.$this->title())
            ->line($this->body() ?? '')
            ->action('Abrir ticket', $this->ticketUrl());
    }

    protected function ticketUrl(): string
    {
        try {
            return TicketResource::getUrl('view', ['record' => $this->ticket], panel: 'admin');
        } catch (Throwable) {
            return url('/');
        }
    }
}
