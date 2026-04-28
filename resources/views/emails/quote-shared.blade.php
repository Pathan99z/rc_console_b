<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quote shared with you</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.5; color: #111827; padding: 20px;">
    <p>Hello {{ $customerName }},</p>
    <p>A quote has been shared with you.</p>
    <p><strong>Quote #:</strong> {{ $quoteNumber }}</p>
    <p><strong>Total:</strong> {{ $currencyCode ? $currencyCode.' ' : '' }}{{ $total }}</p>

    @if(!empty($messageText))
        <p><strong>Message:</strong> {{ $messageText }}</p>
    @endif

    <p>
        <a href="{{ $viewUrl }}" style="display:inline-block;padding:10px 16px;background:#1D4ED8;color:#fff;text-decoration:none;border-radius:4px;">
            View Quote
        </a>
    </p>
    <p>
        <a href="{{ $acceptUrl }}" style="display:inline-block;padding:10px 16px;background:#059669;color:#fff;text-decoration:none;border-radius:4px;margin-right:10px;">
            Accept Quote
        </a>
        <a href="{{ $rejectUrl }}" style="display:inline-block;padding:10px 16px;background:#DC2626;color:#fff;text-decoration:none;border-radius:4px;">
            Reject Quote
        </a>
    </p>
    <p>Thanks,<br>RC Console Team</p>
</body>
</html>
