<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Null = subscribed. Set = opted out of marketing mail (unsubscribe
            // link or admin action). Transactional mail never checks this column.
            $table->timestamp('marketing_opted_out_at')->nullable()->after('email_verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('marketing_opted_out_at');
        });
    }
};
