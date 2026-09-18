<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>{{ config('app.name', 'REU.ng') }}</title>
</head>
<body style="margin:0;padding:0;background:#0a0f0c;font-family:'Helvetica Neue',Arial,sans-serif;">

    <table width="100%" cellpadding="0" cellspacing="0" style="background:#0a0f0c;padding:40px 16px;">
        <tr>
            <td align="center">
                <table width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;">

                    <!-- Logo -->
                    <tr>
                        <td align="center" style="padding-bottom:28px;">
                            <img
                                src="{{ $logoUrl ?? asset('images/reu-logo.png') }}"
                                alt="REU.ng"
                                width="84"
                                style="display:block;height:auto;max-width:84px;"
                            />
                        </td>
                    </tr>

                    <!-- Card: campaign-authored body renders as-is -->
                    <tr>
                        <td style="background:#0D1F1A;border-radius:16px;border:1px solid rgba(255,255,255,0.08);padding:40px 36px;color:rgba(255,255,255,0.85);font-size:14px;line-height:1.7;">
                            {!! $bodyHtml !!}
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td style="padding-top:24px;text-align:center;">
                            <p style="margin:0 0 6px;color:rgba(255,255,255,0.2);font-size:12px;">
                                Need help? Email us at
                                <a href="mailto:{{ config('mail.to.address', 'support@reu.ng') }}"
                                   style="color:rgba(200,135,58,0.7);text-decoration:none;">
                                    {{ config('mail.to.address', 'support@reu.ng') }}
                                </a>
                            </p>
                            <p style="margin:0 0 6px;color:rgba(255,255,255,0.2);font-size:12px;">
                                <a href="{{ $unsubscribeUrl }}" style="color:rgba(255,255,255,0.3);text-decoration:underline;">
                                    Unsubscribe from marketing emails
                                </a>
                            </p>
                            <p style="margin:0;color:rgba(255,255,255,0.12);font-size:11px;">
                                &copy; {{ date('Y') }} {{ config('app.name', 'REU.ng') }}. All rights reserved.
                            </p>
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>

</body>
</html>
