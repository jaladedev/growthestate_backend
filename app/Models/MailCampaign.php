<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MailCampaign extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'subject',
        'body_html',
        'audience_filter',
        'status',
        'scheduled_at',
        'started_at',
        'completed_at',
        'total_recipients',
        'sent_count',
        'failed_count',
        'skipped_count',
        'created_by',
    ];

    protected $casts = [
        'audience_filter' => 'array',
        'scheduled_at'    => 'datetime',
        'started_at'      => 'datetime',
        'completed_at'    => 'datetime',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(MailCampaignRecipient::class);
    }

    public function isDone(): bool
    {
        return $this->recipients()->where('status', 'pending')->doesntExist();
    }
}
