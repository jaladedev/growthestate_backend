<?php

namespace App\Jobs;

use App\Mail\MarketingMail;
use App\Models\MailCampaign;
use App\Models\MailCampaignRecipient;
use App\Services\MailService;
use App\Services\MarketingCampaignService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SendMarketingEmailJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;
    public int $timeout = 30;
    public array $backoff = [30];

    public function __construct(
        public MailCampaign $campaign,
        public MailCampaignRecipient $recipient,
    ) {}

    public function handle(MarketingCampaignService $campaigns): void
    {
        // Another run (or a retry) may already have resolved this recipient —
        // recipients are only ever claimed once.
        $this->recipient->refresh();
        if ($this->recipient->status !== 'pending') {
            return;
        }

        $user = $this->recipient->user;

        // Re-check eligibility at send time, not just at audience-snapshot
        // time — the user may have opted out, been suspended, or been
        // deleted in the time since the campaign was created.
        if ($user && ($user->marketing_opted_out_at || $user->is_suspended || ! $user->email_verified_at)) {
            $this->recipient->update([
                'status'      => 'skipped',
                'skip_reason' => $user->marketing_opted_out_at ? 'opted_out' : ($user->is_suspended ? 'suspended' : 'unverified'),
            ]);
            $this->campaign->increment('skipped_count');
            $campaigns->finalizeIfDone($this->campaign->fresh());
            return;
        }

        if (! $user) {
            $this->recipient->update(['status' => 'skipped', 'skip_reason' => 'user_deleted']);
            $this->campaign->increment('skipped_count');
            $campaigns->finalizeIfDone($this->campaign->fresh());
            return;
        }

        try {
            MailService::sendVia(
                new MarketingMail($this->campaign->subject, $this->campaign->body_html, $this->recipient),
                $this->recipient->email,
                'resend',
            );

            $this->recipient->update(['status' => 'sent', 'sent_at' => now()]);
            $this->campaign->increment('sent_count');
        } catch (\RuntimeException $e) {
            // Resend's daily cap was hit between dispatch and execution
            // (e.g. transactional mail used up the shared quota). Leave the
            // recipient 'pending' — the next campaigns:process tick will
            // pick it back up once quota frees up. Not a delivery failure,
            // but log it — otherwise a stuck campaign is invisible in every
            // metric (sent/failed counters, worker logs) at once.
            \Log::warning('Marketing send deferred: mailer capacity exhausted', [
                'campaign_id'  => $this->campaign->id,
                'recipient_id' => $this->recipient->id,
                'message'      => $e->getMessage(),
            ]);
            return;
        } catch (Throwable $e) {
            $this->recipient->update(['status' => 'failed', 'error' => $e->getMessage()]);
            $this->campaign->increment('failed_count');
        }

        $campaigns->finalizeIfDone($this->campaign->fresh());
    }
}
