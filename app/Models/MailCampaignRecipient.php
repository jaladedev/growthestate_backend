<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class MailCampaignRecipient extends Model
{
    use HasFactory;

    protected $fillable = [
        'mail_campaign_id',
        'user_id',
        'email',
        'status',
        'skip_reason',
        'error',
        'unsubscribe_token',
        'sent_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $recipient) {
            $recipient->unsubscribe_token ??= Str::random(48);
        });
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(MailCampaign::class, 'mail_campaign_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
