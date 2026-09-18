<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class MailService
{
    private const LIMITS = [
        'resend'      => 95,
        'mailtrap'    => 95,
        'mailersend'  => 95,
        'mailgun'     => 95,
        'postmark'    => 95,
    ];

    private const MAILERS = ['resend', 'mailtrap', 'mailersend', 'mailgun', 'postmark'];
    
    /**
     * Days before expiry to start warning.
     */
    private const EXPIRY_WARN_DAYS = 7;

    // ─────────────────────────────────────────────────────────────────────────
    // PUBLIC
    // ─────────────────────────────────────────────────────────────────────────

    public static function send($mailable, string $to): void
    {
        self::checkMailerSendTokenExpiry();

        $mailer = self::resolveMailer();

        try {
            Mail::mailer($mailer)->to($to)->send($mailable);
            self::increment($mailer);
        } catch (\Throwable $e) {
            Log::error("Mail failed on {$mailer}", ['error' => $e->getMessage()]);
            self::tryFallback($mailable, $to, [$mailer]);
        }
    }

    public static function queue($mailable, string $to): void
    {
        self::checkMailerSendTokenExpiry();

        $mailer = self::resolveMailer();
        Mail::mailer($mailer)->to($to)->queue($mailable);
        self::increment($mailer);
    }

    public static function counts(): array
    {
        $today  = now()->toDateString();
        $counts = [];

        foreach (self::MAILERS as $mailer) {
            $counts[$mailer] = [
                'sent'      => (int) Cache::get(self::key($mailer, $today), 0),
                'limit'     => self::LIMITS[$mailer],
                'remaining' => self::LIMITS[$mailer] - (int) Cache::get(self::key($mailer, $today), 0),
            ];
        }

        return $counts;
    }

    /**
     * Total sends still available today across every mailer, before any
     * provider is exhausted. Used by MarketingCampaignService to size
     * bulk-send batches so campaigns never crowd out transactional mail
     * (deposits, withdrawals, verification codes) sharing the same pool.
     */
    public static function remainingCapacity(): int
    {
        $today = now()->toDateString();

        return array_sum(array_map(
            fn ($mailer) => max(0, self::LIMITS[$mailer] - (int) Cache::get(self::key($mailer, $today), 0)),
            self::MAILERS,
        ));
    }

    public static function resetCounts(): void
    {
        $today = now()->toDateString();
        foreach (self::MAILERS as $mailer) {
            Cache::forget(self::key($mailer, $today));
        }
        Log::info('MailService: daily counts reset manually.');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PRIVATE
    // ─────────────────────────────────────────────────────────────────────────

    private static function checkMailerSendTokenExpiry(): void
    {
        $expiresAt = env('MAILERSEND_TOKEN_EXPIRES_AT');

        if (! $expiresAt) {
            return;
        }

        $cacheKey = 'mailersend_token_expiry_checked:' . now()->toDateString();
        if (Cache::has($cacheKey)) {
            return;
        }

        Cache::put($cacheKey, true, now()->endOfDay()->diffInSeconds(now()));

        $expiry   = \Carbon\Carbon::parse($expiresAt)->startOfDay();
        $daysLeft = (int) now()->startOfDay()->diffInDays($expiry, false);

        if ($daysLeft < 0) {
            Log::channel('mail_ops')->error('MailerSend API token has EXPIRED.', [
                'expired_at' => $expiresAt,
                'days_ago'   => abs($daysLeft),
                'action'     => 'Rotate token immediately in MailerSend dashboard and update MAILERSEND_API_KEY.',
            ]);
        } elseif ($daysLeft <= self::EXPIRY_WARN_DAYS) {
            Log::channel('mail_ops')->warning('MailerSend API token is expiring soon.', [
                'expires_at' => $expiresAt,
                'days_left'  => $daysLeft,
                'action'     => 'Rotate token in MailerSend dashboard and update MAILERSEND_API_KEY.',
            ]);
        }
    }

    private static function resolveMailer(): string
    {
        $today = now()->toDateString();

        foreach (self::MAILERS as $mailer) {
            $count = (int) Cache::get(self::key($mailer, $today), 0);
            if ($count < self::LIMITS[$mailer]) {
                return $mailer;
            }
        }

        Log::critical('MailService: ALL mail providers exhausted for today.', [
            'date'   => $today,
            'counts' => self::counts(),
        ]);

        Cache::forget(self::key('resend', $today));
        return 'resend';
    }

    /**
     * @param array<int,string> $tried  Mailers already attempted this send (accumulates
     *                                  across recursive calls) so a persistently-failing
     *                                  pair of providers can't bounce back and forth
     *                                  indefinitely instead of reaching the exhausted state.
     */
    private static function tryFallback($mailable, string $to, array $tried): void
    {
        $today     = now()->toDateString();
        $fallbacks = array_filter(
            self::MAILERS,
            fn($m) => ! in_array($m, $tried, true) &&
                      (int) Cache::get(self::key($m, $today), 0) < self::LIMITS[$m]
        );

        if (empty($fallbacks)) {
            Log::critical('MailService: no fallback available.', ['tried' => $tried, 'to' => $to]);
            throw new \RuntimeException('All mail providers failed or exhausted.');
        }

        $fallback = array_values($fallbacks)[0];

        try {
            Mail::mailer($fallback)->to($to)->send($mailable);
            self::increment($fallback);
            Log::info("MailService: fallback to {$fallback} succeeded.");
        } catch (\Throwable $e) {
            Log::error("MailService: fallback {$fallback} also failed.", ['error' => $e->getMessage()]);
            self::tryFallback($mailable, $to, [...$tried, $fallback]);
        }
    }

    private static function increment(string $mailer): void
    {
        $today = now()->toDateString();
        $key   = self::key($mailer, $today);

        Cache::increment($key);

        $ttl = now()->endOfDay()->diffInSeconds(now());
        Cache::put($key, Cache::get($key, 1), $ttl);
    }

    private static function key(string $mailer, string $date): string
    {
        return "mail_count:{$mailer}:{$date}";
    }
}