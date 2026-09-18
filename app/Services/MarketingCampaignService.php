<?php

namespace App\Services;

use App\Jobs\SendMarketingEmailJob;
use App\Models\MailCampaign;
use App\Models\MailCampaignRecipient;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MarketingCampaignService
{
    /**
     * Marketing sends go through Resend exclusively (see MailService::sendVia)
     * so the domain builds consistent reputation with one provider instead of
     * rotating IP pools/DKIM selectors across 5 — this reserve just leaves
     * Resend some headroom for transactional mail that lands on it via the
     * normal round-robin the same day.
     */
    private const RESERVE_FOR_TRANSACTIONAL = 15;

    /** Cap per scheduler tick even when capacity allows more, so a burst
     *  of sends doesn't hit provider APIs all at once. */
    private const MAX_PER_RUN = 80;

    /** Seconds between each queued send within a single run. */
    private const STAGGER_SECONDS = 3;

    public function resolveAudience(array $filter): Builder
    {
        $query = User::query()
            ->whereNotNull('email_verified_at')
            ->where('is_suspended', false)
            ->whereNull('marketing_opted_out_at');

        return match ($filter['segment'] ?? 'all') {
            'custom' => $query->whereIn('id', $filter['user_ids'] ?? []),

            // Users who verified but never bought a land unit — common
            // "come back and invest" campaign target.
            'verified_no_purchase' => $query->whereDoesntHave('purchases'),

            'all' => $query,

            default => $query->whereRaw('1 = 0'), // unknown segment: resolve to nobody, not everybody
        };
    }

    /**
     * Creates the campaign and snapshots its recipient list immediately, so
     * the audience is fixed at creation time and a slow rollout isn't
     * affected by users signing up, verifying, or opting out mid-send.
     */
    public function createCampaign(array $data, User $admin): MailCampaign
    {
        return DB::transaction(function () use ($data, $admin) {
            $campaign = MailCampaign::create([
                'name'             => $data['name'],
                'subject'          => $data['subject'],
                'body_html'        => $data['body_html'],
                'audience_filter'  => $data['audience_filter'],
                'scheduled_at'     => $data['scheduled_at'] ?? null,
                'status'           => isset($data['scheduled_at']) ? 'scheduled' : 'draft',
                'created_by'       => $admin->id,
            ]);

            $now = now();
            $rows = $this->resolveAudience($data['audience_filter'])
                ->select('id', 'email')
                ->get()
                ->map(fn (User $user) => [
                    'mail_campaign_id'   => $campaign->id,
                    'user_id'            => $user->id,
                    'email'              => $user->email,
                    'status'             => 'pending',
                    'unsubscribe_token'  => Str::random(48),
                    'created_at'         => $now,
                    'updated_at'         => $now,
                ]);

            foreach ($rows->chunk(500) as $chunk) {
                MailCampaignRecipient::insert($chunk->all());
            }

            $campaign->update(['total_recipients' => $rows->count()]);

            return $campaign->fresh();
        });
    }

    /**
     * Queues up to a budget-limited batch of pending recipients. Safe to
     * call repeatedly (e.g. every scheduler tick) — it only ever touches
     * recipients still 'pending' and stops early once capacity runs out.
     */
    public function dispatchBatch(MailCampaign $campaign): int
    {
        if (! in_array($campaign->status, ['scheduled', 'sending'], true)) {
            return 0;
        }

        $budget = min(
            self::MAX_PER_RUN,
            max(0, MailService::remainingCapacityFor('resend') - self::RESERVE_FOR_TRANSACTIONAL),
        );

        if ($budget <= 0) {
            return 0;
        }

        $recipients = $campaign->recipients()
            ->where('status', 'pending')
            ->limit($budget)
            ->get();

        if ($recipients->isEmpty()) {
            $this->finalizeIfDone($campaign);
            return 0;
        }

        if ($campaign->status === 'scheduled') {
            $campaign->update(['status' => 'sending', 'started_at' => now()]);
        }

        foreach ($recipients as $i => $recipient) {
            SendMarketingEmailJob::dispatch($campaign, $recipient)
                ->delay(now()->addSeconds($i * self::STAGGER_SECONDS));
        }

        return $recipients->count();
    }

    public function finalizeIfDone(MailCampaign $campaign): void
    {
        if ($campaign->status === 'sending' && $campaign->isDone()) {
            $campaign->update(['status' => 'completed', 'completed_at' => now()]);
        }
    }

    public function unsubscribe(string $token): bool
    {
        $recipient = MailCampaignRecipient::where('unsubscribe_token', $token)->first();

        if (! $recipient) {
            return false;
        }

        if ($recipient->user_id) {
            User::whereKey($recipient->user_id)
                ->whereNull('marketing_opted_out_at')
                ->update(['marketing_opted_out_at' => now()]);
        }

        return true;
    }
}
