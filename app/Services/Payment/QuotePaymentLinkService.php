<?php

namespace App\Services\Payment;

use App\Mail\QuotePaymentLinkMail;
use App\Models\QuotePaymentLink;
use App\Models\User;
use App\Repositories\QuotePaymentLinkRepository;
use App\Repositories\TenantPaymentSettingRepository;
use App\Services\Quote\QuoteService;
use App\Support\DomainConstants;
use Illuminate\Support\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class QuotePaymentLinkService
{
    public function __construct(
        private readonly QuoteService $quoteService,
        private readonly PayFastService $payFastService,
        private readonly QuotePaymentLinkRepository $quotePaymentLinkRepository,
        private readonly TenantPaymentSettingRepository $tenantPaymentSettingRepository,
    ) {
    }

    public function createStoredLink(User $user, int $quoteId, array $payload = []): QuotePaymentLink
    {
        $quote = $this->quoteService->getQuote($user, $quoteId);
        $quote->loadMissing('contact');

        $expiresAt = isset($payload['expires_at']) ? Carbon::parse((string) $payload['expires_at']) : now()->addDays(7);

        $link = $this->quotePaymentLinkRepository->create([
            'tenant_id' => (int) $quote->tenant_id,
            'quote_id' => (int) $quote->id,
            'token' => (string) Str::uuid(),
            'status' => QuotePaymentLink::STATUS_CREATED,
            'expires_at' => $expiresAt,
            'meta' => [
                'created_by_user_id' => $user->id,
            ],
        ]);

        Log::info(DomainConstants::LOG_QUOTE_PAYMENT_LINK_CREATED, [
            'tenant_id' => $quote->tenant_id,
            'quote_id' => $quote->id,
            'link_id' => $link->id,
        ]);

        return $link;
    }

    public function sendStoredLink(User $user, int $quoteId, int $linkId, array $payload, Request $request): QuotePaymentLink
    {
        $quote = $this->quoteService->getQuote($user, $quoteId);
        $quote->loadMissing('contact');
        $link = $this->mustGetLink((int) $quote->tenant_id, (int) $quote->id, $linkId);
        $this->assertNotExpired($link);

        $recipientEmail = $this->resolveRecipientEmail($quote->contact?->email, $payload);
        $publicBaseUrl = rtrim((string) config('app.url'), '/');
        $paymentUrl = "{$publicBaseUrl}/payments/link/{$link->token}";
        $viewUrl = "{$publicBaseUrl}/quotes/public/{$quote->public_uuid}";

        if ((int) $quote->status === \App\Models\Quote::STATUS_DRAFT) {
            $quote->update([
                'status' => \App\Models\Quote::STATUS_SENT,
                'last_sent_at' => now(),
                'updated_by_user_id' => $user->id,
            ]);
        }

        Mail::to($recipientEmail)->send(new QuotePaymentLinkMail(
            quoteNumber: (string) $quote->quote_number,
            customerName: trim((string) (($quote->contact?->first_name ?? '').' '.($quote->contact?->last_name ?? ''))),
            total: (string) $quote->total,
            currencyCode: $quote->currency_code,
            paymentUrl: $paymentUrl,
            viewUrl: $viewUrl,
            messageText: $payload['message'] ?? null,
        ));

        $updated = $this->quotePaymentLinkRepository->update($link, [
            'status' => QuotePaymentLink::STATUS_SENT,
            'recipient_email' => $recipientEmail,
            'sent_at' => now(),
            'meta' => array_merge($link->meta ?? [], [
                'sent_by_user_id' => $user->id,
                'sent_ip' => $request->ip(),
            ]),
        ]);

        Log::info(DomainConstants::LOG_QUOTE_PAYMENT_LINK_SENT, [
            'tenant_id' => $quote->tenant_id,
            'quote_id' => $quote->id,
            'link_id' => $updated->id,
            'recipient_email' => $recipientEmail,
        ]);

        return $updated;
    }

    /**
     * @return array{action_url: string, method: string, fields: array<string, string>, payment_record_id: int, link: QuotePaymentLink}
     */
    public function createForStoredToken(string $token): array
    {
        $link = $this->quotePaymentLinkRepository->findByToken($token);
        if (! $link || ! $link->quote) {
            throw ValidationException::withMessages(['token' => [DomainConstants::MSG_QUOTE_PAYMENT_LINK_INVALID]]);
        }
        $this->assertNotExpired($link);
        $quote = $link->quote;
        $quote->loadMissing('contact');

        $payfast = $this->payFastService->generatePaymentLink(
            $quote,
            $this->tenantPaymentSettingRepository->findByTenantId((int) $quote->tenant_id)
        );

        $updatedLink = $this->quotePaymentLinkRepository->update($link, [
            'status' => QuotePaymentLink::STATUS_OPENED,
            'last_opened_at' => now(),
            'last_payment_record_id' => $payfast['payment_record_id'],
        ]);

        return array_merge($payfast, ['link' => $updatedLink]);
    }

    /**
     * @return array{action_url: string, method: string, fields: array<string, string>, payment_record_id: int}
     */
    public function createForAuthenticatedUser(User $user, int $quoteId): array
    {
        $quote = $this->quoteService->getQuote($user, $quoteId);
        $quote->loadMissing('contact');

        return $this->payFastService->generatePaymentLink(
            $quote,
            $this->tenantPaymentSettingRepository->findByTenantId((int) $quote->tenant_id)
        );
    }

    /**
     * @return array{action_url: string, method: string, fields: array<string, string>, payment_record_id: int}
     */
    public function createForPublicToken(string $token): array
    {
        $quote = $this->quoteService->getQuoteByPublicTokenForPayment($token);
        $quote->loadMissing('contact');

        return $this->payFastService->generatePaymentLink(
            $quote,
            $this->tenantPaymentSettingRepository->findByTenantId((int) $quote->tenant_id)
        );
    }

    public function successMessage(): string
    {
        return DomainConstants::MSG_PAYFAST_LINK_CREATED;
    }

    public function publicPaymentUrl(string $token): string
    {
        return rtrim((string) config('app.url'), '/')."/payments/link/{$token}";
    }

    private function mustGetLink(int $tenantId, int $quoteId, int $linkId): QuotePaymentLink
    {
        $link = $this->quotePaymentLinkRepository->findByQuoteAndId($tenantId, $quoteId, $linkId);
        if (! $link) {
            throw ValidationException::withMessages(['link_id' => [DomainConstants::MSG_QUOTE_PAYMENT_LINK_INVALID]]);
        }

        return $link;
    }

    private function assertNotExpired(QuotePaymentLink $link): void
    {
        if ($link->expires_at !== null && $link->expires_at->isPast()) {
            $this->quotePaymentLinkRepository->update($link, ['status' => QuotePaymentLink::STATUS_EXPIRED]);
            throw ValidationException::withMessages(['link' => [DomainConstants::MSG_QUOTE_PAYMENT_LINK_EXPIRED]]);
        }
    }

    private function resolveRecipientEmail(?string $contactEmail, array $payload): string
    {
        $override = isset($payload['email']) ? trim((string) $payload['email']) : '';
        if ($override !== '') {
            return $override;
        }
        if (! $contactEmail) {
            throw ValidationException::withMessages(['email' => [DomainConstants::MSG_QUOTE_CONTACT_EMAIL_REQUIRED]]);
        }

        return $contactEmail;
    }
}
