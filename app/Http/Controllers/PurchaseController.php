<?php

namespace App\Http\Controllers;

use App\Events\LandUnitsPurchased;
use App\Events\LandUnitsSold;
use App\Models\Land;
use App\Models\Purchase;
use App\Models\Referral;
use App\Models\ReferralReward;
use App\Models\Transaction;
use App\Models\UserLand;
use App\Services\LedgerService;
use App\Services\RewardsService;
use App\Services\CertificateService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PurchaseController extends Controller
{
    public function __construct(private CertificateService $certificateService) {}

    // ─────────────────────────────────────────────────────────────────────────
    // PURCHASE PREVIEW
    // GET /lands/{landId}/purchase/preview?units=N&use_rewards=1
    // ─────────────────────────────────────────────────────────────────────────
    public function preview(Request $request, $landId)
    {
        $user = $request->user();

        $request->validate([
            'units'       => ['required', 'integer', 'min:1'],
            'use_rewards' => ['sometimes', 'boolean'],
        ]);

        $useRewards = $request->boolean('use_rewards', true);
        $land       = Land::with('latestPrice')->findOrFail($landId);

        $pricePerUnit = $land->current_price_per_unit_kobo;

        if ($pricePerUnit <= 0) {
            return response()->json(['error' => 'This property has no price set.'], 422);
        }

        $units     = (int) $request->units;
        $totalCost = $pricePerUnit * $units;

        [
            $discountApplied,
            $discountLabel,
            $discountPercent,
            $firstPurchaseDiscount,
            $referralDiscount,
        ] = $this->calculateDiscount($user, $totalCost, $useRewards);

        $afterDiscount = $totalCost - $discountApplied;
        $rewardsUsed   = 0;

        if ($useRewards && $user->rewards_balance_kobo > 0) {
            $rewardsUsed = min($user->rewards_balance_kobo, $afterDiscount);
        }

        $mainUsed = $afterDiscount - $rewardsUsed;

        return response()->json([
            'data' => [
                'units'                         => $units,
                'price_per_unit_kobo'           => $pricePerUnit,
                'price_per_unit_naira'          => $pricePerUnit / 100,
                'original_cost_kobo'            => $totalCost,
                'original_cost_naira'           => $totalCost / 100,

                'first_purchase_discount_kobo'  => $firstPurchaseDiscount,
                'first_purchase_discount_naira' => $firstPurchaseDiscount / 100,
                'referral_discount_kobo'        => $referralDiscount,
                'referral_discount_naira'       => $referralDiscount / 100,
                'total_discount_kobo'           => $discountApplied,
                'total_discount_naira'          => $discountApplied / 100,
                'discount_percent'              => $discountPercent,
                'discount_label'                => $discountLabel,

                'rewards_balance_kobo'          => $user->rewards_balance_kobo,
                'rewards_balance_naira'         => $user->rewards_balance_kobo / 100,
                'paid_from_rewards_kobo'        => $rewardsUsed,
                'paid_from_rewards_naira'       => $rewardsUsed / 100,
                'paid_from_wallet_kobo'         => $mainUsed,
                'paid_from_wallet_naira'        => $mainUsed / 100,

                'total_due_kobo'                => $mainUsed,
                'total_due_naira'               => $mainUsed / 100,

                'sufficient_balance'            => $user->balance_kobo >= $mainUsed,
                'available_units'               => $land->available_units,
                'is_first_purchase'             => ! Purchase::where('user_id', $user->id)->exists(),
                'use_rewards'                   => $useRewards,
            ],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PURCHASE
    // POST /lands/{landId}/purchase
    // ─────────────────────────────────────────────────────────────────────────
    public function purchase(Request $request, $landId)
    {
        $user = $request->user();

        $request->validate([
            'units'       => ['required', 'integer', 'min:1'],
            'use_rewards' => ['sometimes', 'boolean'],
        ]);

        $useRewards      = $request->boolean('use_rewards', true);
        $eventPayload    = null;
        $isFirstPurchase = false;
        $responseData    = null;

        try {
            DB::transaction(function () use ($request, $landId, $user, $useRewards, &$eventPayload, &$isFirstPurchase, &$responseData) {

                $user = \App\Models\User::where('id', $user->id)->lockForUpdate()->firstOrFail();

                $land = Land::with('latestPrice')->lockForUpdate()->findOrFail($landId);

                if (! $land->is_available || $land->available_units < $request->units) {
                    throw ValidationException::withMessages([
                        'units' => 'Insufficient units available.',
                    ]);
                }

                $pricePerUnit = $land->current_price_per_unit_kobo;

                if ($pricePerUnit <= 0) {
                    throw ValidationException::withMessages([
                        'land' => 'This property has no price set. Please contact support.',
                    ]);
                }

                $totalCost = $pricePerUnit * $request->units;

                $isFirstPurchase = ! Purchase::where('user_id', $user->id)->exists();

                [
                    $discountApplied,
                    $discountLabel,
                    $discountPercent,
                    $firstPurchaseDiscount,
                    $referralDiscount,
                    $discountRewardUsed,
                ] = $this->calculateDiscount($user, $totalCost, $useRewards, lockReward: true);

                $afterDiscount = $totalCost - $discountApplied;

                $rewardsUsed = 0;
                $mainUsed    = $afterDiscount;

                if ($useRewards && $user->rewards_balance_kobo > 0) {
                    $rewardsUsed = min($user->rewards_balance_kobo, $afterDiscount);
                    $mainUsed    = $afterDiscount - $rewardsUsed;
                }

                if ($user->balance_kobo < $mainUsed) {
                    throw ValidationException::withMessages([
                        'wallet' => 'Insufficient wallet balance.',
                    ]);
                }

                $reference = 'PUR-' . Str::uuid();

                if ($discountRewardUsed) {
                    $discountRewardUsed->update(['claimed' => true, 'claimed_at' => now()]);
                    Log::info('Referral discount applied at checkout', [
                        'user_id'          => $user->id,
                        'reward_id'        => $discountRewardUsed->id,
                        'discount_kobo'    => $referralDiscount,
                        'discount_percent' => $discountRewardUsed->discount_percentage,
                        'reference'        => $reference,
                    ]);
                }

                if ($firstPurchaseDiscount > 0) {
                    Log::info('First-purchase discount applied', [
                        'user_id'       => $user->id,
                        'discount_kobo' => $firstPurchaseDiscount,
                        'reference'     => $reference,
                    ]);
                }

                if ($rewardsUsed > 0) {
                    RewardsService::spend($user, $rewardsUsed, $reference, "Purchase: {$land->title}");
                }

                if ($mainUsed > 0) {
                    $user->decrement('balance_kobo', $mainUsed);

                    LedgerService::postPurchase(
                        user:       $user->fresh(),
                        amountKobo: $mainUsed,
                        reference:  $reference,
                        note:       "Purchase: {$land->title}",
                    );
                }

                $land->decrement('available_units', $request->units);
                $land->is_available = $land->available_units > 0;
                $land->save();

                $actualAmountPaid = $rewardsUsed + $mainUsed;

                DB::statement("
                    INSERT INTO purchases
                        (user_id, land_id, units, units_sold,
                         total_amount_paid_kobo, total_amount_received_kobo,
                         status, purchase_date, reference, created_at, updated_at)
                    VALUES (?, ?, ?, 0, ?, 0, 'active', ?, ?, NOW(), NOW())
                    ON CONFLICT (user_id, land_id)
                    DO UPDATE SET
                        units                  = purchases.units + EXCLUDED.units,
                        total_amount_paid_kobo = purchases.total_amount_paid_kobo + EXCLUDED.total_amount_paid_kobo,
                        reference              = EXCLUDED.reference,
                        purchase_date          = EXCLUDED.purchase_date,
                        updated_at             = NOW()
                ", [
                    $user->id,
                    $land->id,
                    $request->units,
                    $actualAmountPaid,
                    now()->toDateTimeString(),
                    $reference,
                ]);

                DB::statement("
                    INSERT INTO user_land (user_id, land_id, units, created_at, updated_at)
                    VALUES (?, ?, ?, NOW(), NOW())
                    ON CONFLICT (user_id, land_id)
                    DO UPDATE SET
                        units      = user_land.units + EXCLUDED.units,
                        updated_at = NOW()
                ", [$user->id, $land->id, $request->units]);

                Transaction::create([
                    'user_id'          => $user->id,
                    'land_id'          => $land->id,
                    'type'             => 'purchase',
                    'units'            => $request->units,
                    'amount_kobo'      => $totalCost,
                    'status'           => 'completed',
                    'reference'        => $reference,
                    'transaction_date' => now(),
                ]);

                $eventPayload = [
                    'userId'       => $user->id,
                    'landId'       => $land->id,
                    'units'        => $request->units,
                    'pricePerUnit' => $pricePerUnit,
                    'totalCost'    => $totalCost,
                    'reference'    => $reference,
                ];

                $responseData = [
                    'message'                       => 'Purchase successful.',
                    'reference'                     => $reference,
                    'units'                         => $request->units,
                    'original_cost_kobo'            => $totalCost,
                    'original_cost_naira'           => $totalCost / 100,
                    'first_purchase_discount_kobo'  => $firstPurchaseDiscount,
                    'first_purchase_discount_naira' => $firstPurchaseDiscount / 100,
                    'referral_discount_kobo'        => $referralDiscount,
                    'referral_discount_naira'       => $referralDiscount / 100,
                    'total_discount_kobo'           => $discountApplied,
                    'total_discount_naira'          => $discountApplied / 100,
                    'discount_percent'              => $discountPercent,
                    'discount_label'                => $discountLabel,
                    'paid_from_rewards_kobo'        => $rewardsUsed,
                    'paid_from_rewards_naira'       => $rewardsUsed / 100,
                    'paid_from_wallet_kobo'         => $mainUsed,
                    'paid_from_wallet_naira'        => $mainUsed / 100,
                    'total_paid_kobo'               => $actualAmountPaid,
                    'total_paid_naira'              => $actualAmountPaid / 100,
                    'remaining_units'               => $land->fresh()->available_units,
                ];
            });

        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Purchase failed', [
                'error'   => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
                'user_id' => $user->id,
                'land_id' => $landId,
            ]);
            return response()->json(['error' => 'Purchase failed. Please try again.'], 500);
        }

        if ($eventPayload) {
            try {
                if ($isFirstPurchase) {
                    $this->completeReferral($user);
                }

                event(new LandUnitsPurchased(
                    $eventPayload['userId'],
                    $eventPayload['landId'],
                    $eventPayload['units'],
                    $eventPayload['pricePerUnit'],
                    $eventPayload['totalCost'],
                    $eventPayload['reference'],
                ));

                // Issue / refresh certificate with the latest purchase record
                $purchase = Purchase::where('user_id', $user->id)
                    ->where('land_id', $eventPayload['landId'])
                    ->latest('updated_at')
                    ->first();

                if ($purchase) {
                    $certificate = $this->certificateService->issue($purchase);

                    $responseData['certificate'] = [
                        'cert_number' => $certificate->cert_number,
                        'issued_at'   => $certificate->issued_at->toDateTimeString(),
                        'units'       => $certificate->units,
                        'view_url'    => "/portfolio/certificate/{$certificate->cert_number}",
                    ];
                }

            } catch (\Throwable $e) {
                Log::error('Post-purchase event/certificate failed', [
                    'reference' => $eventPayload['reference'],
                    'error'     => $e->getMessage(),
                ]);
                // Certificate failure must NOT block the purchase response
            }
        }

        return response()->json(array_merge(['success' => true], $responseData));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SELL
    // POST /lands/{landId}/sell
    // ─────────────────────────────────────────────────────────────────────────
    public function sellUnits(Request $request, $landId)
    {
        $user = $request->user();

        $request->validate([
            'units' => ['required', 'integer', 'min:1'],
        ]);

        $eventPayload = null;
        $responseData = null;

        try {
            DB::transaction(function () use ($request, $landId, $user, &$eventPayload, &$responseData) {

                $purchase = Purchase::where('user_id', $user->id)
                    ->where('land_id', $landId)
                    ->lockForUpdate()
                    ->first();

                if (! $purchase) {
                    throw ValidationException::withMessages([
                        'units' => 'You do not own any units in this land.',
                    ]);
                }

                if ($purchase->units < $request->units) {
                    throw ValidationException::withMessages([
                        'units' => 'Not enough units to sell.',
                    ]);
                }

                $land = Land::with('latestPrice')->lockForUpdate()->findOrFail($landId);

                $pricePerUnit = $land->current_price_per_unit_kobo;

                if ($pricePerUnit <= 0) {
                    throw ValidationException::withMessages([
                        'land' => 'This property has no price set. Please contact support.',
                    ]);
                }

                $totalReceived  = $pricePerUnit * $request->units;
                $reference      = 'SALE-' . Str::uuid();
                $remainingUnits = $purchase->units - $request->units;

                $purchase->update([
                    'units'                      => $remainingUnits,
                    'units_sold'                 => $purchase->units_sold + $request->units,
                    'total_amount_received_kobo' => $purchase->total_amount_received_kobo + $totalReceived,
                    'sell_date'                  => now(),
                    'status'                     => $remainingUnits === 0 ? 'sold_out' : 'partially_sold',
                    'reference'                  => $reference,
                ]);

                $land->increment('available_units', $request->units);
                $land->is_available = true;
                $land->save();

                $user->increment('balance_kobo', $totalReceived);

                LedgerService::postSale(
                    user:       $user->fresh(),
                    amountKobo: $totalReceived,
                    reference:  $reference,
                    note:       "Sale: {$land->title}",
                );

                UserLand::where('user_id', $user->id)
                    ->where('land_id', $land->id)
                    ->decrement('units', $request->units);

                Transaction::create([
                    'user_id'          => $user->id,
                    'land_id'          => $land->id,
                    'type'             => 'sale',
                    'units'            => $request->units,
                    'amount_kobo'      => $totalReceived,
                    'status'           => 'completed',
                    'reference'        => $reference,
                    'transaction_date' => now(),
                ]);

                $eventPayload = [
                    'userId'         => $user->id,
                    'landId'         => $land->id,
                    'units'          => $request->units,
                    'remainingUnits' => $remainingUnits,
                    'pricePerUnit'   => $pricePerUnit,
                    'totalReceived'  => $totalReceived,
                    'reference'      => $reference,
                    'purchaseId'     => $purchase->id,
                ];

                $responseData = [
                    'message'               => 'Units sold successfully.',
                    'reference'             => $reference,
                    'amount_received_kobo'  => $totalReceived,
                    'amount_received_naira' => $totalReceived / 100,
                    'available_units'       => $land->fresh()->available_units,
                ];
            });

        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Sale failed', [
                'error'   => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
                'user_id' => $user->id,
            ]);
            return response()->json(['error' => 'Sale failed. Please try again.'], 500);
        }

        if ($eventPayload) {
            try {
                event(new LandUnitsSold(
                    $eventPayload['userId'],
                    $eventPayload['landId'],
                    $eventPayload['units'],
                    $eventPayload['pricePerUnit'],
                    $eventPayload['totalReceived'],
                    $eventPayload['reference'],
                ));
            } catch (\Throwable $e) {
                Log::error('Post-sale event dispatch failed', [
                    'reference' => $eventPayload['reference'],
                    'error'     => $e->getMessage(),
                ]);
            }

            // ── Refresh / revoke certificate to reflect new unit count ────────
            try {
                $purchase = Purchase::find($eventPayload['purchaseId']);
                if ($purchase) {
                    $this->certificateService->issue($purchase);
                }
            } catch (\Throwable $e) {
                Log::error('Post-sale certificate refresh failed', [
                    'reference' => $eventPayload['reference'],
                    'error'     => $e->getMessage(),
                ]);
                // Non-blocking — sale already succeeded
            }
        }

        return response()->json(array_merge(['success' => true], $responseData));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DISCOUNT CALCULATOR
    // ─────────────────────────────────────────────────────────────────────────
    private function calculateDiscount(
        $user,
        int $totalCost,
        bool $useRewards,
        bool $lockReward = false
    ): array {
        if (! $useRewards) {
            return [0, null, 0, 0, 0, null];
        }

        $maxDiscountKobo = (int) config('rewards.max_discount_kobo', 500000);

        $applyCap = function (int $rawKobo) use ($maxDiscountKobo): array {
            if ($maxDiscountKobo > 0 && $rawKobo > $maxDiscountKobo) {
                return [$maxDiscountKobo, true];
            }
            return [$rawKobo, false];
        };

        $isFirst = ! Purchase::where('user_id', $user->id)->exists();

        if ($isFirst) {
            $percent = (int) config('rewards.first_purchase_discount_percent', 0);
            if ($percent > 0) {
                $rawKobo = (int) floor($totalCost * ($percent / 100));
                [$kobo, $wasCapped] = $applyCap($rawKobo);
                $effectivePercent = $totalCost > 0
                    ? round(($kobo / $totalCost) * 100, 2)
                    : $percent;
                $label = $wasCapped
                    ? "First purchase (capped at ₦" . number_format($maxDiscountKobo / 100) . " off)"
                    : "First purchase ({$percent}% off)";
                return [$kobo, $label, $effectivePercent, $kobo, 0, null];
            }
        }

        $query = ReferralReward::where('user_id', $user->id)
            ->where('reward_type', 'discount')
            ->where('claimed', false)
            ->whereNotNull('discount_percentage');

        $row = $lockReward ? $query->lockForUpdate()->first() : $query->first();

        if ($row) {
            $percent = $row->discount_percentage;
            $rawKobo = (int) floor($totalCost * ($percent / 100));
            [$kobo, $wasCapped] = $applyCap($rawKobo);
            $effectivePercent = $totalCost > 0
                ? round(($kobo / $totalCost) * 100, 2)
                : $percent;
            $label = $wasCapped
                ? "Referral discount (capped at ₦" . number_format($maxDiscountKobo / 100) . " off)"
                : "Referral discount ({$percent}% off)";
            return [$kobo, $label, $effectivePercent, 0, $kobo, $row];
        }

        return [0, null, 0, 0, 0, null];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // COMPLETE REFERRAL
    // ─────────────────────────────────────────────────────────────────────────
    private function completeReferral($referredUser): void
    {
        $referral = Referral::where('referred_user_id', $referredUser->id)
            ->where('status', 'pending')
            ->first();

        if (! $referral) return;

        try {
            DB::transaction(function () use ($referral) {
                $referral->markCompleted();

                ReferralReward::create([
                    'referral_id' => $referral->id,
                    'user_id'     => $referral->referrer_id,
                    'reward_type' => 'cashback',
                    'amount_kobo' => (int) config('rewards.referral_cashback_kobo', 5000),
                    'claimed'     => false,
                ]);

                ReferralReward::create([
                    'referral_id'         => $referral->id,
                    'user_id'             => $referral->referred_user_id,
                    'reward_type'         => 'discount',
                    'discount_percentage' => (int) config('rewards.referral_discount_percent', 10),
                    'claimed'             => false,
                ]);
            });
        } catch (\Exception $e) {
            Log::error('Failed to complete referral', [
                'referral_id' => $referral->id,
                'error'       => $e->getMessage(),
            ]);
        }
    }
}
