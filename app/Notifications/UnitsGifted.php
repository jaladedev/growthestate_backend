<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

class UnitsGifted extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    protected array $data;

    public function __construct($transaction)
    {
        $this->data = [
            'id'         => $transaction->id,
            'units'      => $transaction->units,
            'reference'  => $transaction->reference,
            'land_title' => $transaction->land?->title,
        ];
    }

    public function via($notifiable): array
    {
        return ['database', 'broadcast'];
    }

    private function message(): string
    {
        return "You've been gifted {$this->data['units']} unit(s) of "
            . ($this->data['land_title'] ?? 'a property') . '.';
    }

    public function toDatabase($notifiable): array
    {
        return [
            'transaction_id' => $this->data['id'],
            'units'          => $this->data['units'],
            'land_title'     => $this->data['land_title'],
            'reference'      => $this->data['reference'],
            'message'        => $this->message(),
        ];
    }

    public function toBroadcast($notifiable): BroadcastMessage
    {
        return new BroadcastMessage([
            'transaction_id' => $this->data['id'],
            'units'          => $this->data['units'],
            'land_title'     => $this->data['land_title'],
            'reference'      => $this->data['reference'],
            'message'        => $this->message(),
            'timestamp'      => now(),
        ]);
    }
}
