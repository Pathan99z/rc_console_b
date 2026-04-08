<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Email Verification</title>
</head>
<body style="margin:0;min-height:100vh;background:#f3f4f6;font-family:Arial,Helvetica,sans-serif;display:flex;align-items:center;justify-content:center;padding:24px;">
    <div style="width:100%;max-width:560px;background:#ffffff;border-radius:14px;padding:28px;text-align:center;box-shadow:0 10px 28px rgba(0,0,0,0.06);">
        <img src="{{ $logoUrl }}" alt="{{ $appName }}" style="max-height:56px;margin-bottom:18px;">
        <h1 style="margin:0 0 12px;font-size:24px;color:#111827;">{{ $title }}</h1>
        <p style="margin:0 0 24px;font-size:15px;line-height:1.7;color:#4b5563;">{{ $message }}</p>
        @if (!empty($frontendUrl))
            <a href="{{ $frontendUrl }}" style="background:#2563eb;color:#ffffff;text-decoration:none;padding:11px 20px;border-radius:8px;font-weight:600;display:inline-block;">
                Go to Console
            </a>
        @endif
    </div>
</body>
</html>
