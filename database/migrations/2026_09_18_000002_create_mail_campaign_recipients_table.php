<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_campaign_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mail_campaign_id')->constrained()->cascadeOnDelete();

            // Nullable + email snapshot kept independently of users.email so a
            // later email change or user deletion doesn't corrupt campaign history.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('email');

            $table->enum('status', ['pending', 'sent', 'failed', 'skipped'])->default('pending');
            $table->string('skip_reason')->nullable(); // e.g. "opted_out", "unverified", "suspended"
            $table->text('error')->nullable();

            // Random, unguessable — used in the one-click unsubscribe link instead
            // of the user id, so the link works even for guests/without auth and
            // can't be used to enumerate or unsubscribe someone else.
            $table->string('unsubscribe_token', 64)->unique();

            $table->timestamp('sent_at')->nullable();

            $table->timestamps();

            $table->unique(['mail_campaign_id', 'user_id']);
            $table->index(['mail_campaign_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_campaign_recipients');
    }
};
