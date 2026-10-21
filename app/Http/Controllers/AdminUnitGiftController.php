<?php

namespace App\Http\Controllers;

use App\Events\LandUnitsPurchased;
use App\Models\AdminActionLog;
use App\Models\Land;
use App\Models\Purchase;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\UnitsGifted;
use App\Services\CertificateService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Admin gifting of land units.
 *
 * Route (under /admin prefix + admin middleware):
 *   POST /admin/users/{user}/gift-units
 *
 * Units come out of the platform's unsold inventory (lands.available_units)
 * and land in the recipient's holding. No wallet or rewards balance moves,
 * so no ledger entry is posted — the double-entry ledger tracks money only.
 * The gift is traceable through: a `gift` Transaction row, the Purchase
 * row (cost basis added: 0), an AdminActionLog entry, and the audit log.
 */
class AdminUnitGiftController extends Controller
{
    public function __construct(private CertificateService $certificateService) {}

    public function store(Request $request, User $user)
    {
        $data = $request->validate([
            'land_id' => ['required', 'integer', 'exists:lands,id'],
            'units'   => ['required', 'integer', 'min:1', 'max:100000'],
            // A reason is mandatory: gifts are free value and must be explainable in an audit.
            'reason'  => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $admin     = $request->user();
        $units     = (int) $data['units'];
        $reference = 'GFT-' . Str::uuid();

        try {
            $result = DB::transaction(function () use ($data, $units, $user, $admin, $reference) {

                // Lock recipient first, then land (consistent order).
                $recipient = User::where('id', $user->id)->lockForUpdate()->firstOrFail();
                $land      = Land::where('id', $data['land_id'])->lockForUpdate()->firstOrFail();

                $this->assertRecipientEligible($recipient);

                if ($land->available_units < $units) {
                    throw ValidationException::withMessages([
                        'units' => "Only {$land->available_units} unit(s) of this property are available to gift.",
                    ]);
                }

                // 1. Take units out of platform inventory.
                $land->decrement('available_units', $units);
                $land->is_available = $land->available_units > 0;
                $land->save();

                // 2. Credit the recipient's holding.
                DB::statement("
                    INSERT INTO user_land (user_id, land_id, units, created_at, updated_at)
                    VALUES (?, ?, ?, NOW(), NOW())
                    ON CONFLICT (user_id, land_id)
                    DO UPDATE SET
                        units      = user_land.units + EXCLUDED.units,
                        updated_at = NOW()
                ", [$recipient->id, $land->id, $units]);

                // 3. Purchase record — cost basis increases by 0 (it was free).
                DB::statement("
                    INSERT INTO purchases
                        (user_id, land_id, units, units_sold,
                         total_amount_paid_kobo, total_amount_received_kobo,
                         status, purchase_date, reference, created_at, updated_at)
                    VALUES (?, ?, ?, 0, 0, 0, 'active', ?, ?, NOW(), NOW())
                    ON CONFLICT (user_id, land_id)
                    DO UPDATE SET
                        units         = purchases.units + EXCLUDED.units,
                        status        = 'active',
                        reference     = EXCLUDED.reference,
                        purchase_date = EXCLUDED.purchase_date,
                        updated_at    = NOW()
                ", [
                    $recipient->id,
                    $land->id,
                    $units,
                    now()->toDateTimeString(),
                    $reference,
                ]);

                // 4. Transaction history row for the recipient.
                $transaction = Transaction::create([
                    'user_id'          => $recipient->id,
                    'land_id'          => $land->id,
                    'type'             => 'gift',
                    'units'            => $units,
                    'amount_kobo'      => 0,
                    'status'           => 'completed',
                    'reference'        => $reference,
                    'message'          => $data['reason'],
                    'transaction_date' => now(),
                ]);

                // 5. Admin audit trail (in-transaction so it can't be skipped).
                AdminActionLog::record(
                    $admin,
                    'units.gift',
                    'User',
                    $recipient->id,
                    [
                        'land_id'   => $land->id,
                        'units'     => $units,
                        'reason'    => $data['reason'],
                        'reference' => $reference,
                    ],
                    request()->ip(),
                );

                return [
                    'recipient'   => $recipient,
                    'land'        => $land->fresh(),
                    'transaction' => $transaction,
                ];
            });
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Admin unit gift failed', [
                'error'     => $e->getMessage(),
                'file'      => $e->getFile(),
                'line'      => $e->getLine(),
                'admin_id'  => $admin->id,
                'target_id' => $user->id,
                'land_id'   => $data['land_id'],
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gift failed. No units were transferred.',
            ], 500);
        }

        // ── Post-commit side effects: failures here must not undo the gift ──
        $recipient = $result['recipient'];
        $land      = $result['land'];

        Log::channel('audit')->info('units_gifted', [
            'admin_id'     => $admin->id,
            'recipient_id' => $recipient->id,
            'land_id'      => $land->id,
            'units'        => $units,
            'reference'    => $reference,
            'ip'           => $request->ip(),
            'timestamp'    => now()->toIso8601String(),
        ]);

        $certificate = null;

        try {
            // Clears land caches + refreshes portfolio snapshots (invested = 0,
            // so the gifted units show as pure gain). The purchase-confirmation
            // notification skips `gift` transactions; UnitsGifted is sent below.
            event(new LandUnitsPurchased(
                $recipient->id,
                $land->id,
                $units,
                (int) ($land->current_price_per_unit_kobo ?? 0),
                0,
                $reference,
            ));

            $purchase = Purchase::where('user_id', $recipient->id)
                ->where('land_id', $land->id)
                ->first();

            if ($purchase) {
                $certificate = $this->certificateService->issue($purchase);
            }

            $recipient->notify(new UnitsGifted($result['transaction']));
        } catch (\Throwable $e) {
            Log::error('Unit gift post-commit step failed', [
                'reference' => $reference,
                'error'     => $e->getMessage(),
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => "Gifted {$units} unit(s) of {$land->title} to {$recipient->name}.",
            'data'    => [
                'reference'       => $reference,
                'recipient'       => $recipient->only('id', 'name', 'email'),
                'land'            => ['id' => $land->id, 'title' => $land->title],
                'units'           => $units,
                'remaining_units' => $land->available_units,
                'certificate'     => $certificate ? [
                    'cert_number' => $certificate->cert_number,
                    'units'       => $certificate->units,
                ] : null,
            ],
        ], 201);
    }

    /**
     * Same compliance rules the marketplace applies to a trade counterparty:
     * a restricted, flagged, pending-KYC or suspended account can't receive value.
     */
    private function assertRecipientEligible(User $recipient): void
    {
        if (in_array($recipient->screening_status, ['blocked', 'flagged', 'pending'], true)) {
            throw ValidationException::withMessages([
                'user' => match ($recipient->screening_status) {
                    'blocked' => "This user's account has been restricted.",
                    'flagged' => "This user's account is under compliance review.",
                    default   => "This user's account verification is pending.",
                },
            ]);
        }

        if ($recipient->is_suspended) {
            throw ValidationException::withMessages([
                'user' => "This user's account is suspended.",
            ]);
        }

        if ($recipient->is_admin) {
            throw ValidationException::withMessages([
                'user' => 'Units cannot be gifted to an admin account.',
            ]);
        }
    }
}
