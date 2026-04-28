<!doctype html>
<html lang="en">
<head><meta charset="UTF-8"><title>Quote {{ $quote->quote_number }}</title></head>
<body style="font-family: DejaVu Sans, Arial, sans-serif; font-size: 12px;">
    <h2>Quote {{ $quote->quote_number }}</h2>
    <p><strong>Customer:</strong> {{ $contactName }}</p>
    <p><strong>Company:</strong> {{ $companyName ?? '-' }}</p>
    <p><strong>Deal:</strong> {{ $quote->deal?->name ?? '-' }}</p>
    <p><strong>Status:</strong> {{ ucfirst($quote->statusLabel()) }}</p>
    <p><strong>Valid Until:</strong> {{ optional($quote->valid_until)->toDateString() ?? '-' }}</p>
    <table width="100%" cellspacing="0" cellpadding="6" border="1" style="border-collapse: collapse; margin-top: 8px;">
        <thead><tr><th align="left">Item</th><th align="right">Qty</th><th align="right">Unit Price</th><th align="right">Line Subtotal</th><th align="right">Tax</th><th align="right">Line Total</th></tr></thead>
        <tbody>
        @foreach($quote->items as $item)
            <tr>
                <td>{{ $item->product_name }}</td>
                <td align="right">{{ $item->quantity }}</td>
                <td align="right">{{ $item->unit_price }}</td>
                <td align="right">{{ $item->line_subtotal }}</td>
                <td align="right">{{ $item->line_tax_total }}</td>
                <td align="right">{{ $item->line_total }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
    <p style="margin-top: 10px;"><strong>Subtotal:</strong> {{ $quote->subtotal }}</p>
    <p><strong>Tax:</strong> {{ $quote->tax_total }}</p>
    <p><strong>Discount:</strong> {{ $quote->discount_total }}</p>
    <p><strong>Total:</strong> {{ $quote->currency_code ? $quote->currency_code.' ' : '' }}{{ $quote->total }}</p>
</body>
</html>
