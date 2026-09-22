<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Adds 'cancelled' as a distinct status from 'rejected': 'rejected' means
     * an admin rejected the request, 'cancelled' means the user withdrew the
     * request themselves while it was still pending. Follows the same
     * pattern as 2026_08_12_000002_add_rejected_to_withdrawals_status.php —
     * the check constraint must be widened before the new status can be
     * written, or WithdrawalController::cancelWithdrawal() 500s in production.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE withdrawals DROP CONSTRAINT IF EXISTS withdrawals_status_check');

        DB::statement("
            ALTER TABLE withdrawals
            ADD CONSTRAINT withdrawals_status_check
            CHECK (status IN ('pending', 'processing', 'completed', 'failed', 'rejected', 'cancelled'))
        ");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE withdrawals DROP CONSTRAINT IF EXISTS withdrawals_status_check');

        DB::statement("
            ALTER TABLE withdrawals
            ADD CONSTRAINT withdrawals_status_check
            CHECK (status IN ('pending', 'processing', 'completed', 'failed', 'rejected'))
        ");
    }
};
