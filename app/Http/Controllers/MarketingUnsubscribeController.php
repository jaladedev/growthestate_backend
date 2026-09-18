<?php

namespace App\Http\Controllers;

use App\Services\MarketingCampaignService;

/**
 * Public one-click unsubscribe link, mailed in every marketing email's
 * footer (see resources/views/emails/marketing.blade.php). Intentionally
 * unauthenticated — the unsubscribe_token itself is the credential, so
 * this works without asking the recipient to log in.
 */
class MarketingUnsubscribeController extends Controller
{
    public function __invoke(string $token, MarketingCampaignService $campaigns)
    {
        $ok = $campaigns->unsubscribe($token);

        if (! $ok) {
            return response()->json(['success' => false, 'message' => 'Invalid or expired unsubscribe link.'], 404);
        }

        return response()->json(['success' => true, 'message' => 'You have been unsubscribed from marketing emails.']);
    }
}
