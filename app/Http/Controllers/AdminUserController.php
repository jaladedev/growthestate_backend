<?php

namespace App\Http\Controllers;

use App\Models\AdminActionLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Admin user management.
 *
 * Routes (under /admin prefix + admin middleware):
 *   GET    /admin/users
 *   GET    /admin/users/{user}
 *   PATCH  /admin/users/{user}/suspend
 *   PATCH  /admin/users/{user}/unsuspend
 *   PATCH  /admin/users/{user}/make-admin
 *   PATCH  /admin/users/{user}/remove-admin
 *   DELETE /admin/users/{user}
 */
class AdminUserController extends Controller
{
    // ─────────────────────────────────────────────────────────────────────────
    // LIST  GET /admin/users
    // ─────────────────────────────────────────────────────────────────────────
    public function index(Request $request)
    {
        $request->validate([
            'search'       => 'sometimes|string|max:100',
            'suspended'    => 'sometimes|in:0,1,true,false',
            'is_admin'     => 'sometimes|in:0,1,true,false',
            'kyc_status'   => 'sometimes|in:not_submitted,pending,approved,rejected,resubmit',
            'per_page'     => 'sometimes|integer|min:1|max:100',
            'sort'         => 'sometimes|in:created_at,name,email,balance_kobo',
            'direction'    => 'sometimes|in:asc,desc',
        ]);

        $perPage   = (int) $request->input('per_page', 20);
        $sort      = $request->input('sort', 'created_at');
        $direction = $request->input('direction', 'desc');

        $query = User::with('kycVerification:id,user_id,status')
            ->when($request->filled('search'), function ($q) use ($request) {
                $search = $request->search;
                $q->where(function ($q2) use ($search) {
                    $q2->where('name',  'ilike', "%{$search}%")
                       ->orWhere('email', 'ilike', "%{$search}%")
                       ->orWhere('uid',   'ilike', "%{$search}%");
                });
            })
            ->when($request->filled('suspended'), fn ($q) =>
                $q->where('is_suspended', filter_var($request->suspended, FILTER_VALIDATE_BOOLEAN))
            )
            ->when($request->filled('is_admin'), fn ($q) =>
                $q->where('is_admin', filter_var($request->is_admin, FILTER_VALIDATE_BOOLEAN))
            )
            ->when($request->filled('kyc_status'), function ($q) use ($request) {
                if ($request->kyc_status === 'not_submitted') {
                    $q->doesntHave('kycVerification');
                } else {
                    $q->whereHas('kycVerification', fn ($q2) =>
                        $q2->where('status', $request->kyc_status)
                    );
                }
            })
            ->orderBy($sort, $direction);

        $users = $query->paginate($perPage);

        return response()->json(['success' => true, 'data' => $users]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SHOW  GET /admin/users/{user}
    // ─────────────────────────────────────────────────────────────────────────
    public function show(Request $request, User $user)
    {
        $user->load([
            'kycVerification',
            'userLands.land:id,title,price_per_unit',
            'recentTransactions', 
        ]);

        // GET request — outside LogSensitiveRequests's mutating-verbs-only
        // coverage. This endpoint returns a user's full profile (PII, KYC
        // metadata, transaction history), so who looked at whose record
        // needs its own trail. See KycImageController::show() for the
        // precedent this follows.
        Log::channel('audit')->info('user_profile_accessed', [
            'viewer_id'  => $request->user()->id,
            'subject_id' => $user->id,
            'ip'         => $request->ip(),
            'timestamp'  => now()->toIso8601String(),
        ]);

        return response()->json([
            'success' => true,
            'data'    => array_merge($user->toArray(), [
                'total_lands'       => $user->userLands->count(),
                'total_units_owned' => $user->userLands->sum('units'),
                'kyc_status'        => $user->kycVerification?->status ?? 'not_submitted',
            ]),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SUSPEND  PATCH /admin/users/{user}/suspend
    // ─────────────────────────────────────────────────────────────────────────
    public function suspend(Request $request, User $user)
    {
        if ($user->is_admin) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot suspend an admin account.',
            ], 403);
        }

        if ($user->is_suspended) {
            return response()->json([
                'success' => true,
                'message' => "{$user->name} is already suspended.",
                'changed' => false,
            ]);
        }

        $user->update(['is_suspended' => true]);

        Log::info('User suspended', [
            'target_user_id' => $user->id,
            'target_email'   => $user->email,
            'by_admin_id'    => $request->user()->id,
            'ip'             => $request->ip(),
        ]);

        AdminActionLog::record($request->user(), 'users.suspend', 'User', $user->id, [], $request->ip());

        return response()->json([
            'success' => true,
            'message' => "{$user->name} has been suspended.",
            'changed' => true,
            'data'    => $user->only('id', 'name', 'email', 'is_suspended', 'is_admin'),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // UNSUSPEND  PATCH /admin/users/{user}/unsuspend
    // ─────────────────────────────────────────────────────────────────────────
    public function unsuspend(Request $request, User $user)
    {
        if (! $user->is_suspended) {
            return response()->json([
                'success' => true,
                'message' => "{$user->name} is not suspended.",
                'changed' => false,
            ]);
        }

        $user->update(['is_suspended' => false, 'suspended_by_compliance' => false]);

        Log::info('User unsuspended', [
            'target_user_id' => $user->id,
            'target_email'   => $user->email,
            'by_admin_id'    => $request->user()->id,
            'ip'             => $request->ip(),
        ]);

        AdminActionLog::record($request->user(), 'users.unsuspend', 'User', $user->id, [], $request->ip());

        return response()->json([
            'success' => true,
            'message' => "{$user->name} has been unsuspended.",
            'changed' => true,
            'data'    => $user->only('id', 'name', 'email', 'is_suspended', 'is_admin'),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // MAKE ADMIN  PATCH /admin/users/{user}/make-admin
    // ─────────────────────────────────────────────────────────────────────────
    public function makeAdmin(Request $request, User $user)
    {
        if ($user->is_admin) {
            return response()->json([
                'success' => true,
                'message' => "{$user->name} is already an admin.",
                'changed' => false,
            ]);
        }

        $user->update(['is_admin' => true]);

        Log::warning('User promoted to admin', [
            'target_user_id' => $user->id,
            'target_email'   => $user->email,
            'by_admin_id'    => $request->user()->id,
            'ip'             => $request->ip(),
        ]);

        AdminActionLog::record($request->user(), 'users.make_admin', 'User', $user->id, [], $request->ip());

        return response()->json([
            'success' => true,
            'message' => "{$user->name} is now an admin.",
            'changed' => true,
            'data'    => $user->only('id', 'name', 'email', 'is_suspended', 'is_admin'),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // REMOVE ADMIN  PATCH /admin/users/{user}/remove-admin
    // ─────────────────────────────────────────────────────────────────────────
    public function removeAdmin(Request $request, User $user)
    {
        // Prevent self-demotion
        if ($user->id === $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot remove your own admin privileges.',
            ], 403);
        }

        if (! $user->is_admin) {
            return response()->json([
                'success' => true,
                'message' => "{$user->name} is not an admin.",
                'changed' => false,
            ]);
        }

        $user->update(['is_admin' => false]);

        Log::warning('Admin privileges removed', [
            'target_user_id' => $user->id,
            'target_email'   => $user->email,
            'by_admin_id'    => $request->user()->id,
            'ip'             => $request->ip(),
        ]);

        AdminActionLog::record($request->user(), 'users.remove_admin', 'User', $user->id, [], $request->ip());

        return response()->json([
            'success' => true,
            'message' => "{$user->name}'s admin privileges have been removed.",
            'changed' => true,
            'data'    => $user->only('id', 'name', 'email', 'is_suspended', 'is_admin'),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DELETE  DELETE /admin/users/{user}
    // ─────────────────────────────────────────────────────────────────────────
    public function destroy(Request $request, User $user)
    {
        // Never delete an admin
        if ($user->is_admin) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete an admin account. Remove admin privileges first.',
            ], 403);
        }

        // Never delete self
        if ($user->id === $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot delete your own account.',
            ], 403);
        }

        $name  = $user->name;
        $email = $user->email;
        $userId = $user->id;

        $user->delete();

        Log::warning('User account deleted', [
            'deleted_name'  => $name,
            'deleted_email' => $email,
            'by_admin_id'   => $request->user()->id,
            'ip'            => $request->ip(),
        ]);

        AdminActionLog::record($request->user(), 'users.delete', 'User', $userId, ['name' => $name, 'email' => $email], $request->ip());

        return response()->json([
            'success' => true,
            'message' => "User {$name} ({$email}) has been deleted.",
        ]);
    }
}