<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Verify your email</title>
</head>
<body style="margin:0;padding:24px;background:#f3f4f6;font-family:Arial,Helvetica,sans-serif;color:#111827;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
        <tr>
            <td align="center">
                <table role="presentation" width="600" cellspacing="0" cellpadding="0" style="max-width:600px;background:#ffffff;border-radius:12px;overflow:hidden;">
                    <tr>
                        <td style="padding:24px;text-align:center;background:#f9fafb;border-bottom:1px solid #e5e7eb;">
                            <img src="{{ $logoUrl }}" alt="{{ $appName }}" style="max-height:56px;">
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:28px;">
                            <h2 style="margin:0 0 12px;font-size:22px;color:#111827;">Welcome to {{ $appName }}</h2>
                            <p style="margin:0 0 10px;font-size:15px;line-height:1.6;">Hi {{ $name }},</p>
                            <p style="margin:0 0 22px;font-size:15px;line-height:1.6;">
                                Thank you for creating your account. Please confirm your email address to activate your access securely.
                            </p>
                            <p style="margin:0 0 26px;">
                                <a href="{{ $verificationUrl }}" style="background:#2563EB;color:#ffffff;text-decoration:none;padding:12px 22px;border-radius:8px;display:inline-block;font-weight:600;">
                                    Verify Email Address
                                </a>
                            </p>
                            <p style="margin:0 0 10px;font-size:13px;color:#6b7280;">
                                If the button does not work, copy and paste this link into your browser:
                            </p>
                            <p style="margin:0;font-size:12px;word-break:break-all;color:#2563eb;">{{ $verificationUrl }}</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:18px 28px;background:#f9fafb;border-top:1px solid #e5e7eb;">
                            <p style="margin:0;font-size:12px;color:#6b7280;">If you did not create this account, you can safely ignore this email.</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
