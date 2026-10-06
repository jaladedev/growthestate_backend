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
        $personalizedHtml = $this->personalize($this->bodyHtml);
        $unsubscribeUrl   = url("/api/marketing/unsubscribe/{$this->recipient->unsubscribe_token}");
        $supportEmail     = config('mail.to.address', 'support@reu.ng');

        return $this->subject($this->subjectLine)
            ->replyTo(config('mail.reply_to.address'), config('mail.reply_to.name'))
            ->view('emails.marketing')
            ->text('emails.marketing-text')
            ->with([
                'bodyHtml'        => $personalizedHtml,
                'bodyText'        => $this->htmlToPlainText($personalizedHtml),
                'logoUrl'         => asset('images/reu-logo.png'),
                'unsubscribeUrl'  => $unsubscribeUrl,
                'supportEmail'    => $supportEmail,
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

    /**
     * Derives the text/plain alternative part from the (already
     * personalized) HTML body. A multipart message with a real text/plain
     * part, not just HTML, is one of the signals Gmail's classifier weighs
     * toward Primary instead of Promotions — see emails/marketing-text
     * and the ->text() call in build() above.
     *
     * Links are special-cased to "label (url)" before tags are stripped,
     * since plain strip_tags() would silently drop the href and leave only
     * the anchor text — losing the WhatsApp channel link entirely, for
     * example.
     */
    private function htmlToPlainText(string $html): string
    {
        $text = preg_replace_callback(
            '/<a\s+[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is',
            fn ($m) => trim(strip_tags($m[2])) . ' (' . $m[1] . ')',
            $html
        );

        $text = preg_replace('/<br\s*\/?>/i', "\n", $text);
        $text = preg_replace('/<\/p>/i', "\n\n", $text);
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }
}
