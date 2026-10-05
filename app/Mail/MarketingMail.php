<?php

namespace App\Mail;

use App\Models\MailCampaignRecipient;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

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
            ->replyTo(config('mail.reply_to.address'), config('mail.reply_to.name'))
            ->view('emails.marketing')
            ->with([
                'bodyHtml'        => $this->personalize($this->bodyHtml),
                'logoUrl'         => asset('images/reu-logo.png'),
                'unsubscribeUrl'  => url("/api/marketing/unsubscribe/{$this->recipient->unsubscribe_token}"),
            ]);
    }

    /**
     * Fills in {{name}} / {{first_name}} placeholders using the recipient's
     * account name. Manually-typed recipients (see
     * MarketingCampaignService::createCampaign's extra 'emails' entries)
     * have no user_id and so no name on file — falls back to "Investor"
     * rather than leaving the raw placeholder or an empty string in a sent
     * email.
     */
    private function personalize(string $html): string
    {
        $fullName  = trim((string) ($this->recipient->user->name ?? ''));
        $firstName = $fullName !== '' ? Str::of($fullName)->trim()->explode(' ')->first() : '';

        return str_replace(
            ['{{name}}', '{{first_name}}'],
            [$fullName !== '' ? $fullName : 'Investor', $firstName !== '' ? $firstName : 'there'],
            $html,
        );
    }
}
