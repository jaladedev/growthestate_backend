<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Routes:
 *   GET /transactions/user
 */
class TransactionController extends Controller
{
    private const PER_PAGE = 20;

    // GET /transactions/user
    public function userTransactions(Request $request)
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        $userId  = $user->id;
        $page    = max(1, (int) $request->query('page', 1));
        $perPage = self::PER_PAGE;

        // Paginated at the DB level via UNION ALL rather than fetching every
        // land transaction, deposit, and withdrawal the user has ever made
        // and paginating in PHP — the previous approach re-fetched and
        // re-sorted the user's ENTIRE history on every single page request,
        // including page 50, which only ever grows worse over the account's
        // lifetime.
        $total = DB::query()
            ->fromSub($this->historyUnion($userId), 'combined')
            ->count();

        $rows = DB::query()
            ->fromSub($this->historyUnion($userId), 'combined')
            // Postgres defaults to NULLS FIRST on DESC order, which would
            // float transactions with a null transaction_date to the very
            // top as if they were the most recent. Force NULLS LAST to
            // match the intended "most recent first" ordering.
            ->orderByRaw('date DESC NULLS LAST')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        $data = $rows->map(fn ($r) => [
            'type'      => ucfirst($r->type),
            'land'      => $r->land,
            'units'     => $r->units,
            'amount'    => ($r->amount_kobo ?? 0) / 100,
            'date'      => $r->date ? Carbon::parse($r->date)->toISOString() : null,
            'status'    => ucfirst($r->status),
            'reference' => $r->reference,
        ]);

        return response()->json([
            'success' => true,
            'data'    => $data,
            'meta'    => [
                'page'       => $page,
                'per_page'   => $perPage,
                'total'      => $total,
                'last_page'  => (int) max(1, ceil($total / $perPage)),
            ],
        ]);
    }

    /**
     * Builds a fresh UNION ALL query builder combining land transactions,
     * deposits, and withdrawals into one shape (id, type, land, units,
     * amount_kobo, date, status). Returns a new builder instance each call
     * — reusing the same builder object across the count() and the page
     * get() risks stale/duplicated bindings on Laravel's query builder.
     */
    private function historyUnion(int $userId)
    {
        $transactions = DB::table('transactions as t')
            ->leftJoin('lands as l', 'l.id', '=', 't.land_id')
            ->where('t.user_id', $userId)
            ->select([
                't.id',
                't.type',
                'l.title as land',
                't.units',
                't.amount_kobo',
                't.transaction_date as date',
                DB::raw("COALESCE(t.status, 'completed') as status"),
                DB::raw('NULL as reference'),
            ]);

        $deposits = DB::table('deposits as d')
            ->where('d.user_id', $userId)
            ->select([
                'd.id',
                DB::raw("'deposit' as type"),
                DB::raw('NULL as land'),
                DB::raw('NULL as units'),
                'd.amount_kobo',
                'd.created_at as date',
                DB::raw("COALESCE(d.status, 'pending') as status"),
                'd.reference',
            ]);

        $withdrawals = DB::table('withdrawals as w')
            ->where('w.user_id', $userId)
            ->select([
                'w.id',
                DB::raw("'withdrawal' as type"),
                DB::raw('NULL as land'),
                DB::raw('NULL as units'),
                'w.amount_kobo',
                'w.created_at as date',
                DB::raw("COALESCE(w.status, 'pending') as status"),
                'w.reference',
            ]);

        return $transactions->unionAll($deposits)->unionAll($withdrawals);
    }
}
