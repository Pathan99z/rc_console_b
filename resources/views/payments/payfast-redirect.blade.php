<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Redirecting to payment...</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.5; color: #111827; padding: 20px;">
    <p>Redirecting you to secure payment...</p>

    <form id="payfast-form" method="POST" action="{{ $actionUrl }}">
        @foreach($fields as $fieldKey => $fieldValue)
            <input type="hidden" name="{{ $fieldKey }}" value="{{ $fieldValue }}">
        @endforeach
    </form>

    <script>
        document.getElementById('payfast-form').submit();
    </script>
</body>
</html>
