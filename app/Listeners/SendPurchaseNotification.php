<?php

namespace App\Listeners;

use App\Events\LandUnitsPurchased;
use App\Models\User;
use App\Models\Transaction;
use App\Notifications\PurchaseConfirmed;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SendPurchaseNotification implements ShouldQueue
{
    public int $tries = 3;

    public function backoff(): array
    {
        return [10, 60, 120];
    }

    public function handle(LandUnitsPurchased $event): void
    {
        $key = 'event:purchase:' . $event->reference;

        if (! Cache::add($key, true, 300)) {
            Log::info('Duplicate purchase event skipped', [
                'reference' => $event->reference,
            ]);
            return;
        }

        try {
            $user = User::find($event->userId);

            if (! $user) {
                Log::warning('User not found', [
                    'user_id' => $event->userId,
                ]);
                return;
            }

            $transaction = Transaction::where('reference', $event->reference)->first();

            if (! $transaction) {
                Log::warning('Transaction not found', [
                    'reference' => $event->reference,
                ]);
                return;
            }

            // Admin gifts reuse LandUnitsPurchased for cache/portfolio updates,
            // but the recipient gets the dedicated UnitsGifted notification instead.
            if ($transaction->type === 'gift') {
                return;
            }

            $user->notify(new PurchaseConfirmed($transaction));

        } catch (\Throwable $e) {
            Cache::forget($key);
            Log::error('SendPurchaseNotification failed', [
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}