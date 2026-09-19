<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown only by MailService::sendVia() when a mailer's shared daily send
 * cap has been reached. This is deliberately its own class rather than a
 * bare \RuntimeException — provider SDKs (Resend, Postmark, etc.) can and
 * do throw \RuntimeException for their own unrelated failures (invalid
 * domain, bad API key, rejected recipient...), and catching those broadly
 * as "just retry later, don't log it as a failure" hid a real domain
 * verification problem for hours. Callers should catch this specific type
 * to distinguish "temporary, safe to retry" from everything else.
 */
class MailCapacityExhaustedException extends RuntimeException
{
}
