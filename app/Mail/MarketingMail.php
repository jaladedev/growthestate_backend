<?php

namespace App\Mail;

use App\Models\MailCampaignRecipient;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class MarketingMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $subjectLine,
        public string $bodyHtml,
        public MailCampaignRecipient $recipient,
    ) {}

    public function build()
    {
        return $this->subject($this->subjectLine)
            ->view('emails.marketing')
            ->with([
                'bodyHtml'        => $this->bodyHtml,
                'logoUrl'         => asset('images/reu-logo.png'),
                'unsubscribeUrl'  => url("/api/marketing/unsubscribe/{$this->recipient->unsubscribe_token}"),
            ]);
    }
}
