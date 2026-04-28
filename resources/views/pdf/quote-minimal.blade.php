<!doctype html>
<html lang="en">
<head><meta charset="UTF-8"><title>Quote {{ $quote->quote_number }}</title></head>
<body style="font-family: DejaVu Sans, Arial, sans-serif; font-size: 11px;">
    <h3>Quote {{ $quote->quote_number }}</h3>
    <p>{{ $contactName }}</p>
    <table width="100%" cellspacing="0" cellpadding="4" border="1" style="border-collapse: collapse;">
        @foreach($quote->items as $item)
            <tr>
                <td>{{ $item->product_name }}</td>
                <td align="right">{{ $item->quantity }}</td>
                <td align="right">{{ $item->line_total }}</td>
            </tr>
        @endforeach
    </table>
    <p style="margin-top:10px;">Total: {{ $quote->currency_code ? $quote->currency_code.' ' : '' }}{{ $quote->total }}</p>
</body>
</html>
