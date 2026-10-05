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

    /**
     * Deliberately protected, NOT public. Mailable::buildViewData() merges
     * every public property into the view data AFTER ->with(), so a public
     * $bodyHtml silently overwrites the personalized 'bodyHtml' passed in
     * build() and the raw {{first_name}} placeholder ships to the inbox.
     */
    public function __construct(
        protected string $subjectLine,
        protected string $bodyHtml,
        protected MailCampaignRecipient $recipient,
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
     *
     * Matched with a whitespace-tolerant, case-insensitive regex rather
     * than a literal str_replace — a rich-text editor can easily introduce
     * "{{ first_name }}" (extra spaces) or "{{First_Name}}" when an admin
     * free-types a placeholder into the body, and an exact-string match
     * would silently leave those completely unreplaced in a sent email
     * with no error anywhere in the pipeline to signal it.
     */
    private function personalize(string $html): string
    {
        $fullName  = trim((string) ($this->recipient->user->name ?? ''));
        $firstName = $fullName !== '' ? Str::of($fullName)->trim()->explode(' ')->first() : '';

        $html = preg_replace('/\{\{\s*first_name\s*\}\}/i', $firstName !== '' ? $firstName : 'there', $html);
        $html = preg_replace('/\{\{\s*name\s*\}\}/i', $fullName !== '' ? $fullName : 'Investor', $html);

        return $html;
    }
}
