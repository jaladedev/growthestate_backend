<?php

namespace App\Http\Controllers;

use App\Models\MailCampaign;
use App\Services\MarketingCampaignService;
use Illuminate\Http\Request;

/**
 * Admin marketing/bulk mail campaigns.
 *
 * Routes (under /admin prefix + admin middleware):
 *   GET    /admin/mail-campaigns
 *   GET    /admin/mail-campaigns/{mailCampaign}
 *   POST   /admin/mail-campaigns
 *   PATCH  /admin/mail-campaigns/{mailCampaign}/schedule
 *   PATCH  /admin/mail-campaigns/{mailCampaign}/cancel
 *
 * Sending itself is not triggered here — creating/scheduling only seeds
 * the recipient list and marks the campaign due. The `campaigns:process`
 * scheduled command (routes/console.php) is what actually queues sends,
 * a few at a time, respecting MailService's shared provider budget.
 */
class MailCampaignController extends Controller
{
    public function __construct(private MarketingCampaignService $campaigns) {}

    // ─────────────────────────────────────────────────────────────────────────
    // LIST  GET /admin/mail-campaigns
    // ─────────────────────────────────────────────────────────────────────────
    public function index(Request $request)
    {
        $request->validate([
            'status'   => 'sometimes|in:draft,scheduled,sending,completed,cancelled',
            'per_page' => 'sometimes|integer|min:1|max:100',
        ]);

        $campaigns = MailCampaign::query()
            ->with('creator:id,name,email')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->latest()
            ->paginate((int) $request->input('per_page', 20));

        return response()->json(['success' => true, 'data' => $campaigns]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SHOW  GET /admin/mail-campaigns/{mailCampaign}
    // ─────────────────────────────────────────────────────────────────────────
    public function show(MailCampaign $mailCampaign)
    {
        return response()->json([
            'success' => true,
            'data'    => array_merge($mailCampaign->load('creator:id,name,email')->toArray(), [
                'pending_count' => $mailCampaign->recipients()->where('status', 'pending')->count(),
            ]),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CREATE  POST /admin/mail-campaigns
    // Creates the campaign and snapshots its recipient list immediately.
    // Leave scheduled_at out to save as a draft you can schedule later.
    // ─────────────────────────────────────────────────────────────────────────
    public function store(Request $request)
    {
        $data = $request->validate([
            'name'                    => 'required|string|max:150',
            'subject'                 => 'required|string|max:200',
            'body_html'               => 'required|string',
            'audience_filter'         => 'required|array',
            'audience_filter.segment' => 'required|in:all,verified_no_purchase,custom',
            'audience_filter.user_ids'   => 'required_if:audience_filter.segment,custom|array',
            'audience_filter.user_ids.*' => 'integer|exists:users,id',
            'scheduled_at'            => 'sometimes|nullable|date|after:now',
        ]);

        $campaign = $this->campaigns->createCampaign($data, $request->user());

        return response()->json(['success' => true, 'data' => $campaign], 201);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SCHEDULE  PATCH /admin/mail-campaigns/{mailCampaign}/schedule
    // Moves a draft (or re-schedules a cancelled campaign) to 'scheduled'.
    // Pass no scheduled_at to send as soon as the next scheduler tick runs.
    // ─────────────────────────────────────────────────────────────────────────
    public function schedule(Request $request, MailCampaign $mailCampaign)
    {
        if (! in_array($mailCampaign->status, ['draft', 'cancelled'], true)) {
            return response()->json([
                'success' => false,
                'message' => "Campaign is '{$mailCampaign->status}' and can't be (re)scheduled.",
            ], 422);
        }

        $data = $request->validate([
            'scheduled_at' => 'sometimes|nullable|date',
        ]);

        $mailCampaign->update([
            'status'       => 'scheduled',
            'scheduled_at' => $data['scheduled_at'] ?? now(),
        ]);

        return response()->json(['success' => true, 'data' => $mailCampaign->fresh()]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CANCEL  PATCH /admin/mail-campaigns/{mailCampaign}/cancel
    // Stops future batches from being queued. Sends already queued/in-flight
    // for the current batch are not recalled.
    // ─────────────────────────────────────────────────────────────────────────
    public function cancel(MailCampaign $mailCampaign)
    {
        if (! in_array($mailCampaign->status, ['draft', 'scheduled', 'sending'], true)) {
            return response()->json([
                'success' => false,
                'message' => "Campaign is already '{$mailCampaign->status}'.",
            ], 422);
        }

        $mailCampaign->update(['status' => 'cancelled']);

        return response()->json(['success' => true, 'data' => $mailCampaign->fresh()]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DIAGNOSTICS  GET /admin/mail-campaigns/{mailCampaign}/diagnostics
    // TEMPORARY — for investigating a stuck campaign without shell access.
    // Read-only. Safe to remove once the investigation is closed.
    // ─────────────────────────────────────────────────────────────────────────
    public function diagnostics(MailCampaign $mailCampaign)
    {
        $recipients = $mailCampaign->recipients()
            ->select('id', 'email', 'status', 'sent_at', 'error', 'updated_at')
            ->orderByDesc('updated_at')
            ->limit(50)
            ->get();

        $logTail = [];
        $logPath = storage_path('logs/laravel.log');
        if (is_file($logPath)) {
            // Cheap tail: read the last ~64KB rather than the whole file,
            // which can be large in production.
            $handle = fopen($logPath, 'r');
            $size   = filesize($logPath);
            fseek($handle, max(0, $size - 65536));
            $chunk = fread($handle, 65536);
            fclose($handle);

            $logTail = array_slice(
                array_filter(explode("\n", $chunk), fn ($l) => trim($l) !== ''),
                -200,
            );
        }

        return response()->json([
            'success' => true,
            'data'    => [
                'campaign'          => $mailCampaign->only([
                    'id', 'status', 'scheduled_at', 'started_at', 'completed_at',
                    'sent_count', 'failed_count', 'skipped_count',
                ]),
                'mail_provider_counts' => \App\Services\MailService::counts(),
                'recipients'           => $recipients,
                'recent_log_lines'     => $logTail,
            ],
        ]);
    }
}
