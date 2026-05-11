<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quote payment link</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.5; color: #111827; padding: 20px;">
    <p>Hello {{ $customerName }},</p>
    <p>A payment link has been generated for your quote.</p>
    <p><strong>Quote #:</strong> {{ $quoteNumber }}</p>
    <p><strong>Total:</strong> {{ $currencyCode ? $currencyCode.' ' : '' }}{{ $total }}</p>

    @if(!empty($messageText))
        <p><strong>Message:</strong> {{ $messageText }}</p>
    @endif

    <p>
        <a href="{{ $paymentUrl }}" style="display:inline-block;padding:10px 16px;background:#059669;color:#fff;text-decoration:none;border-radius:4px;">
            Pay Now
        </a>
    </p>

    <p>
        <a href="{{ $viewUrl }}" style="display:inline-block;padding:10px 16px;background:#1D4ED8;color:#fff;text-decoration:none;border-radius:4px;">
            View Quote
        </a>
    </p>
    <p>Thanks,<br>RC Console Team</p>
</body>
</html>
