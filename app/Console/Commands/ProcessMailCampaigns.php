<?php

namespace App\Console\Commands;

use App\Models\MailCampaign;
use App\Services\MarketingCampaignService;
use Illuminate\Console\Command;

/**
 * Drives every active marketing campaign forward a little each tick,
 * rather than one command trying to blast a whole campaign in one go —
 * that's what lets MarketingCampaignService cap each run against
 * MailService::remainingCapacity() and never crowd out transactional mail.
 */
class ProcessMailCampaigns extends Command
{
    protected $signature   = 'campaigns:process';
    protected $description = 'Queue the next budget-limited batch of sends for due/in-progress marketing campaigns';

    public function handle(MarketingCampaignService $campaigns): int
    {
        $due = MailCampaign::query()
            ->where(function ($q) {
                $q->where('status', 'sending')
                  ->orWhere(function ($q2) {
                      $q2->where('status', 'scheduled')->where('scheduled_at', '<=', now());
                  });
            })
            ->get();

        foreach ($due as $campaign) {
            $queued = $campaigns->dispatchBatch($campaign);

            if ($queued > 0) {
                $this->info("Campaign #{$campaign->id} ({$campaign->name}): queued {$queued} sends.");
            }
        }

        return self::SUCCESS;
    }
}
