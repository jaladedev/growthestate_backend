<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\AuthController;
use App\Http\Controllers\PinController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\LandController;
use App\Http\Controllers\DepositController;
use App\Http\Controllers\WithdrawalController;
use App\Http\Controllers\PortfolioController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\KycController;
use App\Http\Controllers\KycImageController;
use App\Http\Controllers\ReferralController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\SupportController;
use App\Http\Controllers\AdminUserController;
use App\Http\Controllers\AdminRoleController;
use App\Http\Controllers\AdminSupportController;
use App\Http\Controllers\MarketplaceController;
use App\Http\Controllers\CertificateController;
use App\Http\Controllers\WaitlistController;
use App\Http\Controllers\BlogController;
use App\Http\Controllers\LiveChatController;
use App\Http\Controllers\PurchaseController;
use App\Http\Controllers\ComplianceController;
use App\Http\Controllers\PaystackWebhookController;
use App\Http\Controllers\MonnifyWebhookController;
use App\Http\Controllers\OpayWebhookController;
use App\Http\Controllers\MailCampaignController;
use App\Http\Controllers\MarketingUnsubscribeController;
use Illuminate\Support\Facades\Queue;

// =============================================================================
// PUBLIC — no authentication required
// =============================================================================

Route::get('/health', function () {
    $redis = false;
    $queue = null;

    $db = false;

    try {
        \Illuminate\Support\Facades\DB::select('select 1');
        $db = true;
    } catch (\Exception $e) {
        \Illuminate\Support\Facades\Log::error('Health check: DB unreachable', ['error' => $e->getMessage()]);
    }

    try {
        \Illuminate\Support\Facades\Redis::ping();
        $redis = true;
        $queue = Queue::size('default');
    } catch (\Exception $e) {
        \Illuminate\Support\Facades\Log::error('Health check: Redis unreachable', ['error' => $e->getMessage()]);
    }

    return response()->json([
        'status'     => ($db && $redis) ? 'ok' : 'degraded',
        'db'         => $db,
        'redis'      => $redis,
        'queue_size' => $queue,
        'timestamp'  => now(),
    ], ($db && $redis) ? 200 : 503);
});

Route::get('/up', function () {
    return response()->json(['status' => 'ok'], 200);
});
Route::post('/register', [AuthController::class, 'register'])
    ->middleware('throttle:5,60');

Route::post('/login', [AuthController::class, 'login'])
    ->middleware('throttle:10,5');

Route::post('/email/verify/code', [AuthController::class, 'verifyEmailCode'])
    ->middleware('throttle:3,15');

Route::post('/email/resend-verification', [AuthController::class, 'resendVerification'])
    ->middleware('throttle:3,15');

Route::post('/password/reset/code',   [AuthController::class, 'sendPasswordResetCode'])
    ->middleware('throttle:3,15');
Route::post('/password/reset/verify', [AuthController::class, 'verifyPasswordResetCode'])
    ->middleware('throttle:3,15');
Route::post('/password/reset',        [AuthController::class, 'resetPassword'])
    ->middleware('throttle:3,15');

Route::get('/land', [LandController::class, 'index']);

Route::post('/referrals/validate', [ReferralController::class, 'validateCode'])
    ->middleware('throttle:20,1');

Route::get('/support/faqs', [SupportController::class, 'faqs']);

Route::post('/support/tickets/guest', [SupportController::class, 'storeGuestTicket'])
    ->middleware('throttle:5,10');

Route::prefix('blog')->group(function () {
    Route::get('/',            [BlogController::class, 'index']);
    Route::get('/categories',  [BlogController::class, 'categories']);
    Route::get('/tags',        [BlogController::class, 'tags']);
    Route::get('/{slug}',      [BlogController::class, 'show']);
});

Route::post('/waitlist',       [WaitlistController::class, 'store'])->middleware('throttle:5,10');
Route::post('/waitlist/check', [WaitlistController::class, 'check'])->middleware('throttle:10,1');

Route::get('/verify/{verifyToken}', [CertificateController::class, 'verify'])
    ->middleware('throttle:30,1');

Route::get('/marketing/unsubscribe/{token}', MarketingUnsubscribeController::class)
    ->middleware('throttle:20,1');

// ── Payment webhooks & OPay redirects ─────────────────────────────────────
// Deliberately NOT included in this file — they're registered once,
// unprefixed, from routes/api.php directly. They are called by external
// payment providers using a fixed URL configured on the provider's
// dashboard (Paystack/Monnify/Opay), so duplicating them under /v1 serves
// no purpose, and their named routes (opay.webhook, opay.return,
// opay.cancel) would collide with themselves if registered twice — the
// route() helper would then non-deterministically resolve to whichever
// copy loaded last.

// =============================================================================
// AUTHENTICATED — requires valid JWT
// =============================================================================

Route::middleware(['jwt.custom'])->group(function () {

    Route::post('/logout',  [AuthController::class, 'logout']);
    Route::post('/refresh', [AuthController::class, 'refresh'])->middleware('throttle:10,1');

    Route::post('/user/change-password', [AuthController::class, 'changePassword'])
        ->middleware('throttle:5,15');

    Route::middleware(['verified'])->group(function () {

        // ── Profile ───────────────────────────────────────────────────────────
        Route::get('/me',                  [ProfileController::class, 'me']);
        Route::get('/user/account-status', [ProfileController::class, 'accountStatus']);
        Route::get('/user/stats',          [ProfileController::class, 'stats']);
        Route::get('/user/lands',          [ProfileController::class, 'lands']);
        Route::put('/user/bank-details',   [ProfileController::class, 'updateBankDetails'])
            ->middleware(['check.pin', 'audit.log']);

        // ── Transaction PIN ───────────────────────────────────────────────────
        Route::post('/pin/set',    [PinController::class, 'set'])->middleware('throttle:5,15');
        Route::post('/pin/update', [PinController::class, 'update'])->middleware('throttle:5,15');

        Route::post('/pin/forgot',      [PinController::class, 'forgot'])->middleware('throttle:5,15');
        Route::post('/pin/verify-code', [PinController::class, 'verifyCode'])->middleware('throttle:5,15');
        Route::post('/pin/reset',       [PinController::class, 'reset'])->middleware('throttle:5,15');

        // ── Lands ─────────────────────────────────────────────────────────────
        Route::get('/lands',              [LandController::class, 'indexAuth']);
        Route::get('/lands/map',          [LandController::class, 'mapIndex']);
        Route::get('/lands/{land}',       [LandController::class, 'show']);
        Route::get('/lands/{land}/units', [LandController::class, 'units']);

        // ── Deposits ──────────────────────────────────────────────────────────
        Route::post('/deposit', [DepositController::class, 'initiateDeposit'])
            ->middleware(['idempotent', 'throttle:10,60', 'screening.transact', 'suspended', 'audit.log']);
        Route::get('/deposit/verify/{reference}', [DepositController::class, 'verifyDeposit']);
        Route::get('/paystack/banks',             [DepositController::class, 'banks']);
        Route::post('/paystack/resolve-account',  [DepositController::class, 'resolveAccount'])
            ->middleware('throttle:20,1');

        // ── Withdrawals ───────────────────────────────────────────────────────
        Route::post('/withdraw', [WithdrawalController::class, 'requestWithdrawal'])
            ->middleware(['idempotent', 'throttle:5,60', 'screening.transact', 'suspended', 'check.pin', 'audit.log']);
        Route::get('/withdrawals/{reference}', [WithdrawalController::class, 'getWithdrawalStatus']);

        // ── Transactions ──────────────────────────────────────────────────────
        Route::get('/transactions/user',      [TransactionController::class, 'userTransactions']);
        Route::get('/lands/{land}/purchase/preview', [PurchaseController::class, 'preview']);
        Route::post('/lands/{land}/purchase', [PurchaseController::class, 'purchase'])
            ->middleware(['idempotent', 'screening.transact', 'suspended', 'check.pin', 'audit.log']);
        Route::post('/lands/{land}/sell',     [PurchaseController::class, 'sellUnits'])
            ->middleware(['idempotent', 'screening.transact', 'suspended', 'check.pin', 'audit.log']);

        // ── Portfolio ─────────────────────────────────────────────────────────
        Route::get('/portfolio/summary',     [PortfolioController::class, 'summary']);
        Route::get('/portfolio/chart',       [PortfolioController::class, 'chart']);
        Route::get('/portfolio/performance', [PortfolioController::class, 'performance']);
        Route::get('/portfolio/allocation',  [PortfolioController::class, 'allocation']);
        Route::get('/portfolio/asset/{land}',[PortfolioController::class, 'asset']);

        // ── KYC ───────────────────────────────────────────────────────────────
        Route::get('/kyc/status',          [KycController::class, 'status']);
        Route::post('/kyc/submit',         [KycController::class, 'submit'])->middleware('throttle:3,60');
        Route::get('/kyc/{id}/image/{type}',[KycImageController::class, 'show']);

        // ── Referrals ─────────────────────────────────────────────────────────
        Route::get('/referrals/dashboard',           [ReferralController::class, 'dashboard']);
        Route::get('/referrals/rewards',             [ReferralController::class, 'availableRewards']);
        Route::post('/referrals/rewards/{id}/claim', [ReferralController::class, 'claimReward'])
            ->middleware('throttle:10,1');

        // ── Notifications ─────────────────────────────────────────────────────
        Route::get('/notifications',             [NotificationController::class, 'index']);
        Route::get('/notifications/unread',      [NotificationController::class, 'unread']);
        Route::post('/notifications/read',       [NotificationController::class, 'markAllRead']);
        Route::post('/notifications/{id}/read',  [NotificationController::class, 'markRead']);

        // ── Support ───────────────────────────────────────────────────────────
        Route::post('/support/chat', [SupportController::class, 'chat'])
            ->middleware('throttle:20,10');
        Route::get('/support/tickets',                        [SupportController::class, 'indexTickets']);
        Route::post('/support/tickets',                       [SupportController::class, 'storeTicket'])
            ->middleware('throttle:5,60');
        Route::get('/support/tickets/{ticket}',               [SupportController::class, 'showTicket']);
        Route::post('/support/tickets/{ticket}/reply',        [SupportController::class, 'replyTicket'])
            ->middleware('throttle:20,10');
        Route::patch('/support/tickets/{ticket}/close',       [SupportController::class, 'closeTicket']);

        Route::prefix('support/live-chat')->group(function () {
            Route::post('/request',              [LiveChatController::class, 'request']);
            Route::post('/{ticket}/message',     [LiveChatController::class, 'sendMessage']);
            Route::post('/{ticket}/typing',      [LiveChatController::class, 'typing']);
        });

        // ── Marketplace ───────────────────────────────────────────────────────
        Route::get('/marketplace',               [MarketplaceController::class, 'index']);
        Route::get('/marketplace/my-listings',   [MarketplaceController::class, 'myListings']);
        Route::get('/marketplace/my-offers',     [MarketplaceController::class, 'myOffers']);
        Route::get('/marketplace/my-transactions',[MarketplaceController::class, 'myTransactions']);
        Route::get('/marketplace/{listing}',     [MarketplaceController::class, 'show']);
        Route::post('/marketplace',              [MarketplaceController::class, 'store'])
            ->middleware('throttle:10,60');
        Route::patch('/marketplace/{listing}',   [MarketplaceController::class, 'update']);
        Route::delete('/marketplace/{listing}',  [MarketplaceController::class, 'destroy']);
        Route::post('/marketplace/{listing}/offers', [MarketplaceController::class, 'makeOffer'])
            ->middleware(['throttle:10,60', 'screening.transact', 'suspended']);
        Route::patch('/marketplace/{listing}/offers/{offer}/accept',   [MarketplaceController::class, 'acceptOffer'])
            ->middleware(['idempotent', 'throttle:5,1', 'screening.transact', 'suspended', 'check.pin', 'audit.log']);
        Route::patch('/marketplace/{listing}/offers/{offer}/reject',   [MarketplaceController::class, 'rejectOffer']);
        Route::patch('/marketplace/{listing}/offers/{offer}/withdraw', [MarketplaceController::class, 'withdrawOffer']);
        Route::get('/marketplace/{listing}/messages',  [MarketplaceController::class, 'messages']);
        Route::post('/marketplace/{listing}/messages', [MarketplaceController::class, 'sendMessage'])
            ->middleware('throttle:30,1');

        // ── Certificates ──────────────────────────────────────────────────────
        Route::get('/certificates',                       [CertificateController::class, 'index']);
        Route::get('/certificates/{certNumber}',          [CertificateController::class, 'show']);
        Route::get('/certificates/{certNumber}/download', [CertificateController::class, 'download'])
            ->middleware('throttle:10,1');
    });
});

// =============================================================================
// ADMIN — requires JWT + admin flag
// =============================================================================

Route::middleware(['jwt.custom', 'admin', 'throttle:60,1', 'audit.log'])->prefix('admin')->group(function () {

    // ── Users ─────────────────────────────────────────────────────────────────
    Route::get('/users',                       [AdminUserController::class, 'index'])->middleware('permission:users.view');
    Route::get('/users/{user}',                [AdminUserController::class, 'show'])->middleware('permission:users.view');
    Route::patch('/users/{user}/suspend',      [AdminUserController::class, 'suspend'])->middleware('permission:users.suspend');
    Route::patch('/users/{user}/unsuspend',    [AdminUserController::class, 'unsuspend'])->middleware('permission:users.unsuspend');
    Route::patch('/users/{user}/make-admin',   [AdminUserController::class, 'makeAdmin'])->middleware('permission:roles.manage');
    Route::patch('/users/{user}/remove-admin', [AdminUserController::class, 'removeAdmin'])->middleware('permission:roles.manage');
    Route::delete('/users/{user}',             [AdminUserController::class, 'destroy'])->middleware('permission:users.delete');

    // ── Roles ─────────────────────────────────────────────────────────────────
    // ── Marketing / bulk mail campaigns ─────────────────────────────────────
    Route::get('/mail-campaigns',                       [MailCampaignController::class, 'index'])->middleware('permission:marketing.view');
    Route::get('/mail-campaigns/{mailCampaign}',         [MailCampaignController::class, 'show'])->middleware('permission:marketing.view');
    Route::post('/mail-campaigns',                       [MailCampaignController::class, 'store'])->middleware('permission:marketing.manage');
    Route::patch('/mail-campaigns/{mailCampaign}/schedule', [MailCampaignController::class, 'schedule'])->middleware('permission:marketing.manage');
    Route::patch('/mail-campaigns/{mailCampaign}/cancel',   [MailCampaignController::class, 'cancel'])->middleware('permission:marketing.manage');
    // TEMPORARY — remove once the stuck-campaign investigation is closed.
    // Read-only: surfaces MailService::counts() and per-recipient status so
    // a stuck campaign can be diagnosed without Render shell access.
    Route::get('/mail-campaigns/{mailCampaign}/diagnostics', [MailCampaignController::class, 'diagnostics'])->middleware('permission:marketing.view');

    Route::get('/roles',                       [AdminRoleController::class, 'index'])->middleware('permission:roles.manage');
    Route::get('/users/{user}/roles',          [AdminRoleController::class, 'userRoles'])->middleware('permission:roles.manage');
    Route::post('/users/{user}/roles',         [AdminRoleController::class, 'assignRole'])->middleware('permission:roles.manage');
    Route::delete('/users/{user}/roles/{role}',[AdminRoleController::class, 'revokeRole'])->middleware('permission:roles.manage');

    // ── Lands ─────────────────────────────────────────────────────────────────
    Route::get('/lands',                       [LandController::class, 'adminIndex'])->middleware('permission:lands.manage');
    Route::get('/lands/{land}',                [LandController::class, 'adminShow'])->middleware('permission:lands.manage');
    Route::post('/lands',                      [LandController::class, 'store'])->middleware('permission:lands.manage');
    Route::post('/lands/{land}',               [LandController::class, 'update'])->middleware('permission:lands.manage');
    Route::patch('/lands/{land}/price',        [LandController::class, 'updatePrice'])->middleware('permission:lands.manage');
    Route::patch('/lands/{land}/availability', [LandController::class, 'toggleAvailability'])->middleware('permission:lands.manage');

    Route::get('/lands/{land}/valuation',                   [LandController::class, 'getValuations'])->middleware('permission:lands.manage');
    Route::post('/lands/{land}/valuation',                  [LandController::class, 'addValuationEntry'])->middleware('permission:lands.manage');
    Route::patch('/lands/{land}/valuation/{year}/{month}',  [LandController::class, 'updateValuationEntry'])->middleware('permission:lands.manage');
    Route::delete('/lands/{land}/valuation/{year}/{month}', [LandController::class, 'deleteValuationEntry'])->middleware('permission:lands.manage');

    // ── KYC ───────────────────────────────────────────────────────────────────
    Route::get('/kyc',                   [KycController::class, 'adminIndex'])->middleware('permission:kyc.view');
    Route::get('/kyc/{id}',              [KycController::class, 'adminShow'])->middleware('permission:kyc.view');
    Route::post('/kyc/{id}/approve',     [KycController::class, 'adminApprove'])->middleware(['throttle:30,1', 'permission:kyc.approve']);
    Route::post('/kyc/{id}/reject',      [KycController::class, 'adminReject'])->middleware(['throttle:30,1', 'permission:kyc.reject']);
    Route::post('/kyc/{id}/resubmit',    [KycController::class, 'adminRequestResubmit'])->middleware(['throttle:30,1', 'permission:kyc.reject']);

    // ── Compliance (Sanctions & PEP) ──────────────────────────────────────────
    Route::prefix('compliance')->group(function () {
        Route::get('/stats',                          [ComplianceController::class, 'stats'])->middleware('permission:compliance.view');
        Route::get('/screenings',                     [ComplianceController::class, 'index'])->middleware('permission:compliance.view');
        Route::get('/screenings/{screening}',         [ComplianceController::class, 'show'])->middleware('permission:compliance.view');
        Route::post('/screenings/{screening}/clear',  [ComplianceController::class, 'clear'])->middleware(['throttle:30,1', 'permission:compliance.clear']);
        Route::post('/screenings/{screening}/block',  [ComplianceController::class, 'block'])->middleware(['throttle:30,1', 'permission:compliance.block']);
        Route::post('/users/{user}/rescreen',         [ComplianceController::class, 'rescreen'])->middleware(['throttle:10,1', 'permission:compliance.rescreen']);
    });

    // ── Support ───────────────────────────────────────────────────────────────
    Route::get('/support/tickets',                      [AdminSupportController::class, 'index'])->middleware('permission:support.tickets.view');
    Route::get('/support/tickets/{ticket}',             [AdminSupportController::class, 'show'])->middleware('permission:support.tickets.view');
    Route::post('/support/tickets/{ticket}/reply',      [AdminSupportController::class, 'reply'])->middleware('permission:support.tickets.manage');
    Route::patch('/support/tickets/{ticket}/status',    [AdminSupportController::class, 'updateStatus'])->middleware('permission:support.tickets.manage');
    Route::delete('/support/tickets/{ticket}',          [AdminSupportController::class, 'destroy'])->middleware('permission:support.tickets.manage');
    Route::get('/support/stats',                        [SupportController::class, 'adminStats'])->middleware('permission:support.tickets.view');

    // ── Live chat (agent) ─────────────────────────────────────────────────────
    Route::prefix('live-chat')->middleware('permission:live_chat.manage')->group(function () {
        Route::get('/queue',             [LiveChatController::class, 'agentQueue']);
        Route::post('/{ticket}/claim',   [LiveChatController::class, 'agentClaim']);
        Route::post('/{ticket}/message', [LiveChatController::class, 'agentMessage']);
        Route::post('/{ticket}/typing',  [LiveChatController::class, 'agentTyping']);
        Route::post('/{ticket}/end',     [LiveChatController::class, 'agentEnd']);
    });

    // ── Blog ──────────────────────────────────────────────────────────────────
    Route::prefix('blog')->middleware('permission:blog.manage')->group(function () {
        Route::get('/categories', [BlogController::class, 'adminCategories']);
        Route::get('/tags',       [BlogController::class, 'adminTags']);

        Route::prefix('categories')->group(function () {
            Route::post('/',                   [BlogController::class, 'storeCategory']);
            Route::patch('/{blogCategory}',    [BlogController::class, 'updateCategory']);
            Route::delete('/{blogCategory}',   [BlogController::class, 'destroyCategory']);
        });

        Route::prefix('tags')->group(function () {
            Route::post('/',          [BlogController::class, 'storeTag']);
            Route::delete('/{blogTag}',[BlogController::class, 'destroyTag']);
        });

        Route::get('/',  [BlogController::class, 'adminIndex']);
        Route::post('/', [BlogController::class, 'store']);

        Route::get('/{blogPost}',    [BlogController::class, 'adminShow'])->whereNumber('blogPost');
        Route::put('/{blogPost}',    [BlogController::class, 'update'])->whereNumber('blogPost');
        Route::delete('/{blogPost}', [BlogController::class, 'destroy'])->whereNumber('blogPost');
    });

    // ── Withdrawals ───────────────────────────────────────────────────────────
    Route::prefix('withdrawals')->group(function () {
        Route::get('/', [WithdrawalController::class, 'adminIndex'])->middleware('permission:withdrawals.view');

        Route::post('/approve-all',    [WithdrawalController::class, 'adminApproveAll'])->middleware(['throttle:5,1', 'permission:withdrawals.approve']);
        Route::post('/{id}/approve',   [WithdrawalController::class, 'adminApprove'])->middleware(['throttle:30,1', 'permission:withdrawals.approve']);
        Route::post('/{id}/reject',    [WithdrawalController::class, 'adminReject'])->middleware(['throttle:30,1', 'permission:withdrawals.reject']);
    });

    // ── Referrals ─────────────────────────────────────────────────────────────
    Route::get('/referrals',       [ReferralController::class, 'adminIndex'])->middleware('permission:referrals.view');
    Route::get('/referrals/stats', [ReferralController::class, 'adminStats'])->middleware('permission:referrals.view');

    // ── Certificates ──────────────────────────────────────────────────────────
    Route::get('/certificates',                          [CertificateController::class, 'adminIndex'])->middleware('permission:certificates.view');
    Route::patch('/certificates/{certificate}/revoke',   [CertificateController::class, 'revoke'])->middleware('permission:certificates.manage');
    Route::post('/certificates/{certificate}/regenerate',[CertificateController::class, 'regenerate'])->middleware('permission:certificates.manage');

    // ── Waitlist ──────────────────────────────────────────────────────────────
    Route::get('/waitlist',                    [WaitlistController::class, 'index'])->middleware('permission:waitlist.view');
    Route::get('/waitlist/stats',              [WaitlistController::class, 'stats'])->middleware('permission:waitlist.view');
    Route::post('/waitlist/{waitlist}/invite', [WaitlistController::class, 'invite'])->middleware('permission:waitlist.manage');
    Route::delete('/waitlist/{waitlist}',      [WaitlistController::class, 'destroy'])->middleware('permission:waitlist.manage');
});
