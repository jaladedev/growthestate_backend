<?php

namespace App\Http\Controllers;

use App\Models\KycVerification;
use App\Models\UserScreening;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class KycController extends Controller
{
    public function status()
    {
        $kyc = auth()->user()->kycVerification;

        if (! $kyc) {
            return response()->json([
                'success' => true,
                'data'    => [
                    'status'      => 'not_submitted',
                    'is_verified' => false,
                ],
            ]);
        }

        return response()->json([
            'success' => true,
            'data'    => [
                'status'           => $kyc->status,
                'is_verified'      => $kyc->status === 'approved',
                'submission_date'  => $kyc->created_at,
                'verified_at'      => $kyc->verified_at,
                'rejection_reason' => $kyc->rejection_reason,
            ],
        ]);
    }

    public function submit(Request $request)
    {
        $user = auth()->user();

        if ($user->is_kyc_verified) {
            return response()->json([
                'success' => false,
                'message' => 'Your KYC is already verified',
            ], 400);
        }

        $existingKyc = $user->kycVerification;
        if ($existingKyc && $existingKyc->status === 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'You have a pending KYC verification',
            ], 400);
        }

        $isBvn = $request->input('id_type') === 'bvn';

        $request->merge([
            'is_pep' => filter_var($request->input('is_pep'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
        ]);

        $data = $request->validate([
            'full_name'        => 'required|string|max:255',
            'date_of_birth'    => 'required|date|before:today',
            'phone_number'     => 'required|string|max:20',
            'address'          => 'required|string',
            'city'             => 'required|string|max:100',
            'state'            => 'required|string|max:100',
            'country'          => 'sometimes|string|max:100',
            'id_type'          => 'required|in:nin,drivers_license,voters_card,passport,bvn',
            'id_number'        => 'required|string|max:50',
            'id_front'         => [Rule::requiredIf(! $isBvn), 'nullable', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'id_back'          => 'nullable|mimes:jpg,jpeg,png,webp|max:5120',
            'selfie'           => 'required|mimes:jpg,jpeg,png,webp|max:5120',
            'is_pep'           => 'required|boolean',
            'pep_relationship' => 'required_if:is_pep,true|in:self,family,associate',
            'pep_role'         => 'required_if:is_pep,true|nullable|string|max:100',
            'pep_country'      => 'required_if:is_pep,true|nullable|string|size:2',
            'pep_details'      => 'required_if:is_pep,true|nullable|string|max:500',
        ]);

        $idFrontPath   = null;
        $idBackPath    = null;
        $selfiePath    = null;
        $uploadedPaths = [];

        try {
            if ($request->hasFile('id_front')) {
                $idFrontPath     = $request->file('id_front')->store('kyc/ids', 'r2');
                $uploadedPaths[] = $idFrontPath;
            }

            if ($request->hasFile('id_back')) {
                $idBackPath      = $request->file('id_back')->store('kyc/ids', 'r2');
                $uploadedPaths[] = $idBackPath;
            }

            if ($request->hasFile('selfie')) {
                $selfiePath      = $request->file('selfie')->store('kyc/selfies', 'r2');
                $uploadedPaths[] = $selfiePath;
            }

            $kyc = DB::transaction(function () use ($user, $data, $idFrontPath, $idBackPath, $selfiePath) {
                return $user->kycVerification()->updateOrCreate(
                    ['user_id' => $user->id],
                    [
                        'full_name'        => $data['full_name'],
                        'date_of_birth'    => $data['date_of_birth'],
                        'phone_number'     => $data['phone_number'],
                        'address'          => $data['address'],
                        'city'             => $data['city'],
                        'state'            => $data['state'],
                        'country'          => $data['country'] ?? 'Nigeria',
                        'id_type'          => $data['id_type'],
                        'id_number'        => $data['id_number'],
                        'id_front_path'    => $idFrontPath,
                        'id_back_path'     => $idBackPath,
                        'selfie_path'      => $selfiePath,
                        'status'           => 'pending',
                        'rejection_reason' => null,
                        'is_pep'           => $data['is_pep'],
                        'pep_relationship' => $data['pep_relationship'] ?? null,
                        'pep_role'         => $data['pep_role'] ?? null,
                        'pep_country'      => $data['pep_country'] ?? null,
                        'pep_details'      => $data['pep_details'] ?? null,
                    ]
                );
            });

            Log::channel('telegram')->warning(
                '📝 KYC Submitted',
                [
                    'user_id'  => $user->id,
                    'name'     => $user->name,
                    'id_type'  => $data['id_type'],
                    'country'  => $data['country'] ?? 'Nigeria',
                    'is_pep'   => $data['is_pep'] ? 'yes' : 'no',
                ]
            );

            if ($data['is_pep']) {
                $user->update(['screening_status' => 'flagged']);

                UserScreening::create([
                    'user_id' => $user->id,
                    'status'  => 'flagged',
                    'trigger' => 'pep_self_declaration',
                    'matches' => [[
                        'source'       => 'self_declared',
                        'matched_name' => $user->name,
                        'queried_name' => $user->name,
                        'score'        => 100,
                        'is_pep'       => true,
                        'program'      => $data['pep_role'] ?? null,
                        'entry_type'   => 'individual',
                    ]],
                ]);

                Log::channel('telegram')->warning(
                    '⚠️ PEP Self-Declaration — Manual review required',
                    [
                        'user_id'          => $user->id,
                        'name'             => $user->name,
                        'pep_relationship' => $data['pep_relationship'] ?? null,
                        'pep_role'         => $data['pep_role'] ?? null,
                        'pep_country'      => $data['pep_country'] ?? null,
                    ]
                );
            }

            \App\Jobs\ScreenUserJob::dispatch($user, 'kyc')->onQueue('default');

        } catch (\Throwable $e) {
            foreach ($uploadedPaths as $path) {
                Storage::disk('r2')->delete($path);
            }

            return response()->json([
                'success' => false,
                'message' => 'KYC submission failed. Please try again.',
            ], 500);
        }

        return response()->json([
            'success' => true,
            'message' => 'KYC submitted successfully. We will review it shortly.',
            'data'    => [
                'status'       => $kyc->status,
                'submitted_at' => $kyc->created_at,
            ],
        ]);
    }

    public function getImageUrl(Request $request, $id, string $imageType)
    {
        $kyc  = KycVerification::findOrFail($id);
        $user = auth()->user();

        if ($user->id !== $kyc->user_id && ! $user->hasPermission('kyc.view')) {
            abort(403);
        }

        $allowed = ['id_front', 'id_back', 'selfie'];
        if (! in_array($imageType, $allowed, true)) {
            return response()->json(['error' => 'Invalid image type.'], 422);
        }

        $pathColumn = $imageType . '_path';
        $filePath   = $kyc->getRawOriginal($pathColumn);

        if (! $filePath) {
            return response()->json(['error' => 'Image not found.'], 404);
        }

        $expiresAt = now()->addMinutes(15);
        $signedUrl = Storage::disk('r2')->temporaryUrl($filePath, $expiresAt);

        return response()->json([
            'url'        => $signedUrl,
            'expires_at' => $expiresAt->toIso8601String(),
        ]);
    }

    public function adminIndex(Request $request)
    {
        $status = $request->query('status');
        $query  = KycVerification::with('user:id,name,email');

        if ($status && in_array($status, ['pending', 'approved', 'rejected', 'resubmit'])) {
            $query->where('status', $status);
        }

        $kycs = $query->latest()->paginate(20);

        return response()->json(['success' => true, 'data' => $kycs]);
    }

    public function adminShow(Request $request, $id)
    {
        $kyc  = KycVerification::with('user:id,name,email')->findOrFail($id);

        // GET request — outside LogSensitiveRequests's mutating-verbs-only
        // coverage. This is the metadata sibling of KycImageController's
        // image bytes (ID numbers, DOB, address, PEP status) — same
        // 'kyc_image_accessed'-style trail, distinct event name since no
        // image_type applies here.
        Log::channel('audit')->info('kyc_record_accessed', [
            'viewer_id'  => $request->user()->id,
            'subject_id' => $kyc->user_id,
            'kyc_id'     => $kyc->id,
            'ip'         => $request->ip(),
            'timestamp'  => now()->toIso8601String(),
        ]);

        $data = $kyc->toArray();

        $base = url("/api/kyc/{$kyc->id}/image");

        $data['id_front_url'] = $kyc->getRawOriginal('id_front_path') ? "{$base}/id_front" : null;
        $data['id_back_url']  = $kyc->getRawOriginal('id_back_path')  ? "{$base}/id_back"  : null;
        $data['selfie_url']   = $kyc->getRawOriginal('selfie_path')   ? "{$base}/selfie"   : null;

        unset($data['id_front_path'], $data['id_back_path'], $data['selfie_path']);

        return response()->json(['success' => true, 'data' => $data]);
    }

    public function adminApprove($id)
    {
        $kyc = KycVerification::with('user')->findOrFail($id);

        if ($kyc->status === 'approved') {
            return response()->json([
                'success' => false,
                'message' => 'KYC is already approved',
            ], 400);
        }

         DB::transaction(function () use ($kyc) {
            $kyc->update([
                'status'           => 'approved',
                'verified_at'      => now(),
                'verified_by'      => auth()->id(),
                'rejection_reason' => null,
            ]);
            
            // Dispatches only after commit, so the worker always sees approved KYC
            DB::afterCommit(function () use ($kyc) {
                \App\Jobs\ScreenUserJob::dispatch($kyc->user, 'kyc_approved')->onQueue('default');
            });
        });

        \App\Models\AdminActionLog::record(auth()->user(), 'kyc.approve', 'KycVerification', $kyc->id, ['user_id' => $kyc->user_id], request()->ip());

        return response()->json([
            'success' => true,
            'message' => 'KYC approved successfully',
            'data'    => $kyc->fresh(),
        ]);
    }

    public function adminReject(Request $request, $id)
    {
        $data = $request->validate(['reason' => 'required|string']);

        $kyc = KycVerification::findOrFail($id);
        $kyc->update([
            'status'           => 'rejected',
            'rejection_reason' => $data['reason'],
            'verified_at'      => null,
            'verified_by'      => auth()->id(),
        ]);

        \App\Models\AdminActionLog::record($request->user(), 'kyc.reject', 'KycVerification', $kyc->id, ['user_id' => $kyc->user_id, 'reason' => $data['reason']], $request->ip());

        return response()->json([
            'success' => true,
            'message' => 'KYC rejected',
            'data'    => $kyc,
        ]);
    }

    public function adminRequestResubmit(Request $request, $id)
    {
        $data = $request->validate(['reason' => 'required|string']);

        $kyc = KycVerification::findOrFail($id);
        $kyc->update([
            'status'           => 'resubmit',
            'rejection_reason' => $data['reason'],
            'verified_at'      => null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Resubmission requested',
            'data'    => $kyc,
        ]);
    }

}
