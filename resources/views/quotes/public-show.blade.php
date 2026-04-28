<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quote {{ $quote->quote_number }}</title>
</head>
<body style="font-family: Arial, sans-serif; padding: 24px; color: #111827;">
    <div style="max-width: 900px; margin: 0 auto;">
        @php
            $status = $quote->statusLabel();
            $isFinal = in_array($status, ['accepted', 'rejected'], true);
            $canRespond = in_array($status, ['draft', 'sent'], true);
            $finalBannerBackground = $status === 'accepted' ? '#ECFDF5' : '#FEF2F2';
            $finalBannerColor = $status === 'accepted' ? '#065F46' : '#991B1B';
            $finalBannerStyle = "padding: 14px; border-radius: 10px; margin-bottom: 16px; background: {$finalBannerBackground}; color: {$finalBannerColor};";
        @endphp
        <h2 style="margin-bottom: 8px;">Quote {{ $quote->quote_number }}</h2>
        <p style="margin-top: 0;">
            Status: <strong>{{ ucfirst($quote->statusLabel()) }}</strong>
        </p>
        @if(session('status_message'))
            <div style="padding: 12px; border-radius: 8px; background: #EEF2FF; color: #1E3A8A; margin-bottom: 16px;">
                {{ session('status_message') }}
            </div>
        @endif
        @if($isFinal)
            <div style="{{ $finalBannerStyle }}">
                <p style="margin: 0 0 6px 0; font-size: 16px; font-weight: 700;">
                    {{ $status === 'accepted' ? 'Your quote has been accepted.' : 'Your quote has been rejected.' }}
                </p>
                <p style="margin: 0;">
                    {{ $status === 'accepted' ? 'Thank you for your confirmation. Our team will contact you with the next steps.' : 'Thank you for your response. If needed, our team can share a revised quote.' }}
                </p>
            </div>
        @endif
        <div style="margin-bottom: 16px;">
            <p><strong>Customer:</strong> {{ trim(($quote->contact?->first_name ?? '').' '.($quote->contact?->last_name ?? '')) }}</p>
            <p><strong>Valid Until:</strong> {{ optional($quote->valid_until)->toDateString() ?? '-' }}</p>
            <p><strong>Total:</strong> {{ $quote->currency_code ? $quote->currency_code.' ' : '' }}{{ $quote->total }}</p>
        </div>
        <table style="width:100%; border-collapse: collapse; margin-bottom: 20px;">
            <thead>
                <tr>
                    <th style="border: 1px solid #E5E7EB; padding: 8px; text-align: left;">Item</th>
                    <th style="border: 1px solid #E5E7EB; padding: 8px; text-align: right;">Qty</th>
                    <th style="border: 1px solid #E5E7EB; padding: 8px; text-align: right;">Unit Price</th>
                    <th style="border: 1px solid #E5E7EB; padding: 8px; text-align: right;">Tax %</th>
                    <th style="border: 1px solid #E5E7EB; padding: 8px; text-align: right;">Line Total</th>
                </tr>
            </thead>
            <tbody>
            @foreach($quote->items as $item)
                <tr>
                    <td style="border: 1px solid #E5E7EB; padding: 8px;">{{ $item->product_name }}</td>
                    <td style="border: 1px solid #E5E7EB; padding: 8px; text-align: right;">{{ $item->quantity }}</td>
                    <td style="border: 1px solid #E5E7EB; padding: 8px; text-align: right;">{{ $item->unit_price }}</td>
                    <td style="border: 1px solid #E5E7EB; padding: 8px; text-align: right;">{{ $item->tax_rate }}</td>
                    <td style="border: 1px solid #E5E7EB; padding: 8px; text-align: right;">{{ $item->line_total }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
        @if($canRespond)
            <div style="display: flex; gap: 10px;">
                <a href="{{ route('quotes.public.accept', ['token' => $token]) }}"
                   style="display:inline-block;padding:10px 16px;background:#059669;color:#fff;text-decoration:none;border-radius:6px;">
                    Accept Quote
                </a>
                <a href="{{ route('quotes.public.reject', ['token' => $token]) }}"
                   style="display:inline-block;padding:10px 16px;background:#DC2626;color:#fff;text-decoration:none;border-radius:6px;">
                    Reject Quote
                </a>
            </div>
        @endif
    </div>
</body>
</html>
