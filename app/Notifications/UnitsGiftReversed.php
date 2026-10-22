<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class UnitsGiftReversed extends Notification implements ShouldQueue
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
        return ['database'];
    }

    private function message(): string
    {
        return "A gift of {$this->data['units']} unit(s) of "
            . ($this->data['land_title'] ?? 'a property') . ' was reversed by an administrator.';
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
}
