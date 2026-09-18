<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('subject');

            // Rendered/stored HTML body. Kept as raw HTML (not a Blade view)
            // so campaigns can be authored/edited without a deploy.
            $table->longText('body_html');

            // Audience filter, e.g. {"segment":"all"} or
            // {"segment":"verified_no_purchase"} or {"segment":"custom","user_ids":[1,2,3]}.
            // Interpreted by MarketingAudienceService — keeping it structured
            // rather than a raw SQL fragment avoids ever running admin-supplied SQL.
            $table->json('audience_filter');

            $table->enum('status', [
                'draft',      // being authored, not yet queued
                'scheduled',  // has scheduled_at, waiting for the scheduler
                'sending',    // recipients are being sent in batches
                'completed',  // every recipient resolved (sent/failed/skipped)
                'cancelled',
            ])->default('draft');

            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->unsignedInteger('total_recipients')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0); // opted-out / unverified at send time

            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();

            $table->timestamps();

            $table->index('status');
            $table->index('scheduled_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_campaigns');
    }
};
