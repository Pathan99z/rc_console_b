<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Document shared with you</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.5; color: #111827; padding: 20px;">
    <p>Hello,</p>
    <p>A document has been shared with you.</p>
    <p><strong>Product:</strong> {{ $productName }}</p>
    <p><strong>Collateral:</strong> {{ $collateralName }}</p>

    @if(!empty($messageText))
        <p><strong>Message:</strong> {{ $messageText }}</p>
    @endif

    <p>
        <a href="{{ $signedUrl }}" style="display:inline-block;padding:10px 16px;background:#1D4ED8;color:#fff;text-decoration:none;border-radius:4px;">
            View / Download Document
        </a>
    </p>
    <p>This link will expire shortly for security reasons.</p>
    <p>Thanks,<br>RC Console Team</p>
</body>
</html>
