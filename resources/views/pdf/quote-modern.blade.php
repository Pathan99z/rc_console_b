<!doctype html>
<html lang="en">
<head><meta charset="UTF-8"><title>Quote {{ $quote->quote_number }}</title></head>
<body style="font-family: DejaVu Sans, Arial, sans-serif; color:#0F172A;">
    <div style="padding:14px;background:#EEF2FF;border-radius:8px;">
        <h2 style="margin:0;">Quote {{ $quote->quote_number }}</h2>
        <p style="margin:4px 0 0 0;">Customer: {{ $contactName }}</p>
    </div>
    <table width="100%" cellspacing="0" cellpadding="6" border="1" style="border-collapse: collapse; margin-top: 12px;">
        <thead><tr style="background:#F8FAFC;"><th align="left">Product</th><th align="right">Qty</th><th align="right">Unit</th><th align="right">Tax</th><th align="right">Amount</th></tr></thead>
        <tbody>
        @foreach($quote->items as $item)
            <tr><td>{{ $item->product_name }}</td><td align="right">{{ $item->quantity }}</td><td align="right">{{ $item->unit_price }}</td><td align="right">{{ $item->tax_rate }}%</td><td align="right">{{ $item->line_total }}</td></tr>
        @endforeach
        </tbody>
    </table>
    <p><strong>Total: {{ $quote->currency_code ? $quote->currency_code.' ' : '' }}{{ $quote->total }}</strong></p>
</body>
</html>
