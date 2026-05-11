<?php

namespace App\Services\Quote;

use App\Mail\QuoteSharedMail;
use App\Mail\QuotePaymentLinkMail;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\Product;
use App\Models\Quote;
use App\Models\QuoteAttachment;
use App\Models\User;
use App\Repositories\AuditLogRepository;
use App\Repositories\QuoteRepository;
use App\Support\DomainConstants;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class QuoteService
{
    public function __construct(
        private readonly QuoteRepository $quoteRepository,
        private readonly AuditLogRepository $auditLogRepository,
    ) {
    }

    public function listQuotes(User $actor, array $filters, int $perPage): LengthAwarePaginator
    {
        $tenantId = $actor->isGlobalAdmin() ? ($filters['tenant_id'] ?? null) : $actor->tenant_id;
        $key = $this->buildCacheKey($tenantId, $filters, $perPage);

        return Cache::remember($key, now()->addMinutes(10), fn () => $this->quoteRepository->paginateFiltered($actor, $filters, $perPage));
    }

    public function createQuote(User $actor, array $payload, Request $request): Quote
    {
        return DB::transaction(function () use ($actor, $payload, $request): Quote {
            $tenantId = $this->resolveTenantId($actor, $payload);
            $contact = $this->mustGetTenantContact($tenantId, (int) $payload['contact_id']);
            $deal = $this->resolveOrCreateDeal($tenantId, $actor, $contact, $payload);
            $computed = $this->buildComputedItems($tenantId, $payload['products'], (float) ($payload['discount_total'] ?? 0));
            $quote = $this->quoteRepository->create([
                'tenant_id' => $tenantId,
                'deal_id' => $deal->id,
                'contact_id' => $contact->id,
                'created_by_user_id' => $actor->id,
                'updated_by_user_id' => $actor->id,
                'quote_number' => $this->generateQuoteNumber($tenantId),
                'public_uuid' => (string) Str::uuid(),
                'status' => Quote::STATUS_DRAFT,
                'quote_type' => (int) ($payload['quote_type'] ?? 0),
                'notes' => $payload['notes'] ?? null,
                'valid_until' => $payload['valid_until'] ?? null,
                'subtotal' => $computed['subtotal'],
                'tax_total' => $computed['tax_total'],
                'discount_total' => $computed['discount_total'],
                'total' => $computed['total'],
                'currency_code' => isset($payload['currency_code']) ? strtoupper((string) $payload['currency_code']) : null,
            ]);
            $this->quoteRepository->replaceItems($quote, $tenantId, $computed['items']);
            $this->syncDealFromQuote($deal, $quote, false);
            $this->recordAudit($actor, $request, 'created', $quote, null, $quote->toArray());
            Log::info(DomainConstants::LOG_QUOTE_CREATED, ['tenant_id' => $tenantId, 'quote_id' => $quote->id]);
            $this->bumpVersion($tenantId);

            return $this->mustGetQuote($quote->id);
        });
    }

    public function getQuote(User $actor, int $quoteId): Quote
    {
        $quote = $this->mustGetQuote($quoteId);
        if (! $this->hasVisibility($actor, $quote)) {
            throw new ModelNotFoundException(DomainConstants::MSG_QUOTE_NOT_FOUND);
        }

        return $quote;
    }

    public function updateQuote(User $actor, int $quoteId, array $payload, Request $request): Quote
    {
        return DB::transaction(function () use ($actor, $quoteId, $payload, $request): Quote {
            $quote = $this->getQuote($actor, $quoteId);
            $tenantId = (int) $quote->tenant_id;
            $contact = $this->mustGetTenantContact($tenantId, (int) $payload['contact_id']);
            $deal = $this->resolveDealForExistingQuote($tenantId, $quote, $contact, $payload);
            $computed = $this->buildComputedItems($tenantId, $payload['products'], (float) ($payload['discount_total'] ?? 0));
            $before = $quote->toArray();

            $updated = $this->quoteRepository->update($quote, [
                'deal_id' => $deal->id,
                'contact_id' => $contact->id,
                'updated_by_user_id' => $actor->id,
                'quote_type' => (int) ($payload['quote_type'] ?? $quote->quote_type),
                'notes' => $payload['notes'] ?? null,
                'valid_until' => $payload['valid_until'] ?? null,
                'subtotal' => $computed['subtotal'],
                'tax_total' => $computed['tax_total'],
                'discount_total' => $computed['discount_total'],
                'total' => $computed['total'],
                'currency_code' => isset($payload['currency_code']) ? strtoupper((string) $payload['currency_code']) : null,
            ]);
            $this->quoteRepository->replaceItems($updated, $tenantId, $computed['items']);
            $this->syncDealFromQuote($deal, $updated, false);
            $this->recordAudit($actor, $request, 'updated', $updated, $before, $updated->toArray());
            Log::info(DomainConstants::LOG_QUOTE_UPDATED, ['tenant_id' => $tenantId, 'quote_id' => $quoteId]);
            $this->bumpVersion($tenantId);

            return $this->mustGetQuote($updated->id);
        });
    }

    public function updateStatus(User $actor, int $quoteId, string $status, Request $request): Quote
    {
        $quote = $this->getQuote($actor, $quoteId);
        $next = Quote::statusCodeFromString($status);
        $before = $quote->toArray();
        $updated = $this->quoteRepository->update($quote, [
            'status' => $next,
            'updated_by_user_id' => $actor->id,
            'last_sent_at' => $next === Quote::STATUS_SENT ? now() : $quote->last_sent_at,
        ]);
        $deal = $updated->deal;
        $this->syncDealFromQuote($deal, $updated, $next === Quote::STATUS_ACCEPTED);
        $this->recordAudit($actor, $request, 'status_changed', $updated, $before, $updated->toArray());
        Log::info(DomainConstants::LOG_QUOTE_STATUS_CHANGED, ['tenant_id' => $quote->tenant_id, 'quote_id' => $quoteId, 'status' => $status]);
        $this->bumpVersion((int) $quote->tenant_id);

        return $this->mustGetQuote($updated->id);
    }

    public function deleteQuote(User $actor, int $quoteId, Request $request): void
    {
        $quote = $this->getQuote($actor, $quoteId);
        if (! $this->canDelete($actor, $quote)) {
            throw new ModelNotFoundException(DomainConstants::MSG_QUOTE_NOT_FOUND);
        }
        $before = $quote->toArray();
        $this->quoteRepository->delete($quote);
        $this->recordAudit($actor, $request, 'deleted', $quote, $before, null);
        Log::info(DomainConstants::LOG_QUOTE_DELETED, ['tenant_id' => $quote->tenant_id, 'quote_id' => $quoteId]);
        $this->bumpVersion((int) $quote->tenant_id);
    }

    public function uploadAttachment(User $actor, int $quoteId, string $name, UploadedFile $file, Request $request): QuoteAttachment
    {
        $quote = $this->getQuote($actor, $quoteId);
        $tenantId = (int) $quote->tenant_id;
        $fileName = Str::uuid().'.'.$file->getClientOriginalExtension();
        $fileKey = "tenant/{$tenantId}/quotes/{$quote->id}/attachments/{$fileName}";
        Storage::disk($this->storageDisk())->put($fileKey, $file->getContent(), ['visibility' => 'private']);
        $attachment = $this->quoteRepository->createAttachment([
            'tenant_id' => $tenantId,
            'quote_id' => $quote->id,
            'uploaded_by_user_id' => $actor->id,
            'name' => $name,
            'file_key' => $fileKey,
            'file_type' => (string) $file->getMimeType(),
            'file_size' => (int) $file->getSize(),
        ]);
        $attachment->setAttribute('signed_url', $this->signedUrl($fileKey));
        $this->recordAudit($actor, $request, 'attachment_uploaded', $quote, null, ['attachment_id' => $attachment->id]);
        Log::info(DomainConstants::LOG_QUOTE_ATTACHMENT_UPLOADED, ['tenant_id' => $tenantId, 'quote_id' => $quote->id]);

        return $attachment->load('uploadedByUser');
    }

    public function sendQuote(User $actor, int $quoteId, array $payload, Request $request): Quote
    {
        $quote = $this->getQuote($actor, $quoteId);
        $recipientEmail = $this->resolveQuoteRecipientEmail($quote, $payload);
        $publicBaseUrl = rtrim((string) config('app.url'), '/');
        $token = (string) $quote->public_uuid;
        $viewUrl = "{$publicBaseUrl}/quotes/public/{$token}";
        $acceptUrl = "{$publicBaseUrl}/quotes/public/{$token}/accept";
        $rejectUrl = "{$publicBaseUrl}/quotes/public/{$token}/reject";
        $layoutCode = $this->resolveLayoutCode($payload);
        $attachPdf = (bool) ($payload['attach_pdf'] ?? true);
        $pdfBinary = $attachPdf ? $this->buildQuotePdfBinary($quote, $layoutCode) : null;
        $pdfFileName = $attachPdf ? strtolower((string) $quote->quote_number).'.pdf' : null;

        Mail::to($recipientEmail)->send(new QuoteSharedMail(
            quoteNumber: (string) $quote->quote_number,
            customerName: trim((string) (($quote->contact?->first_name ?? '').' '.($quote->contact?->last_name ?? ''))),
            total: (string) $quote->total,
            currencyCode: $quote->currency_code,
            viewUrl: $viewUrl,
            acceptUrl: $acceptUrl,
            rejectUrl: $rejectUrl,
            messageText: $payload['message'] ?? null,
            pdfBinary: $pdfBinary,
            pdfFileName: $pdfFileName
        ));

        $before = $quote->toArray();
        $updated = $this->quoteRepository->update($quote, [
            'status' => Quote::STATUS_SENT,
            'updated_by_user_id' => $actor->id,
            'last_sent_at' => now(),
        ]);
        $this->recordAudit($actor, $request, 'sent', $updated, $before, array_merge($updated->toArray(), [
            'recipient_email' => $recipientEmail,
            'layout_code' => $layoutCode,
            'attach_pdf' => $attachPdf,
        ]));
        Log::info(DomainConstants::LOG_QUOTE_SENT, ['tenant_id' => $updated->tenant_id, 'quote_id' => $updated->id]);
        $this->bumpVersion((int) $updated->tenant_id);

        return $this->mustGetQuote($updated->id);
    }

    public function sendQuotePaymentLink(User $actor, int $quoteId, array $payload, Request $request): Quote
    {
        $quote = $this->getQuote($actor, $quoteId);
        $recipientEmail = $this->resolveQuoteRecipientEmail($quote, $payload);
        $publicBaseUrl = rtrim((string) config('app.url'), '/');
        $token = (string) $quote->public_uuid;
        $viewUrl = "{$publicBaseUrl}/quotes/public/{$token}";
        $paymentUrl = "{$publicBaseUrl}/quotes/public/{$token}?action=pay";

        Mail::to($recipientEmail)->send(new QuotePaymentLinkMail(
            quoteNumber: (string) $quote->quote_number,
            customerName: trim((string) (($quote->contact?->first_name ?? '').' '.($quote->contact?->last_name ?? ''))),
            total: (string) $quote->total,
            currencyCode: $quote->currency_code,
            paymentUrl: $paymentUrl,
            viewUrl: $viewUrl,
            messageText: $payload['message'] ?? null,
        ));

        $before = $quote->toArray();
        $updated = $this->quoteRepository->update($quote, [
            'status' => in_array((int) $quote->status, [Quote::STATUS_DRAFT, Quote::STATUS_SENT], true) ? Quote::STATUS_SENT : $quote->status,
            'updated_by_user_id' => $actor->id,
            'last_sent_at' => now(),
        ]);
        $this->recordAudit($actor, $request, 'payment_link_sent', $updated, $before, array_merge($updated->toArray(), [
            'recipient_email' => $recipientEmail,
            'payment_url' => $paymentUrl,
        ]));
        Log::info(DomainConstants::LOG_QUOTE_PAYMENT_LINK_SENT, ['tenant_id' => $updated->tenant_id, 'quote_id' => $updated->id]);
        $this->bumpVersion((int) $updated->tenant_id);

        return $this->mustGetQuote($updated->id);
    }

    public function getPublicQuote(string $token, Request $request): Quote
    {
        $quote = $this->mustGetQuoteByPublicToken($token);
        Log::info(DomainConstants::LOG_QUOTE_PUBLIC_VIEWED, ['tenant_id' => $quote->tenant_id, 'quote_id' => $quote->id]);
        $this->recordPublicAudit($request, 'public_viewed', $quote, null, ['token' => $token]);

        return $quote;
    }

    public function acceptPublicQuote(string $token, Request $request): Quote
    {
        return $this->updatePublicQuoteResponse($token, Quote::STATUS_ACCEPTED, $request);
    }

    public function rejectPublicQuote(string $token, Request $request): Quote
    {
        return $this->updatePublicQuoteResponse($token, Quote::STATUS_REJECTED, $request);
    }

    public function getQuoteByPublicTokenForPayment(string $token): Quote
    {
        return $this->mustGetQuoteByPublicToken($token);
    }

    public function applySuccessfulPayment(int $quoteId, ?Request $request = null): void
    {
        $quote = $this->mustGetQuote($quoteId);
        if ((int) $quote->payment_status === Quote::PAYMENT_STATUS_PAID) {
            return;
        }

        $this->quoteRepository->update($quote, ['payment_status' => Quote::PAYMENT_STATUS_PAID]);
        $fresh = $this->mustGetQuote($quoteId);
        $deal = $fresh->deal;
        if ($deal) {
            $this->syncDealFromQuote($deal, $fresh, true);
        }
        if ($request) {
            $this->recordPublicAudit($request, 'payment_succeeded', $fresh, null, ['gateway' => 'payfast']);
        }
        Log::info(DomainConstants::LOG_PAYFAST_PAYMENT_APPLIED, [
            'tenant_id' => $fresh->tenant_id,
            'quote_id' => $fresh->id,
        ]);
        $this->bumpVersion((int) $fresh->tenant_id);
    }

    public function listLayouts(): array
    {
        return array_values($this->quoteLayouts());
    }

    public function previewPrices(User $actor, array $payload): array
    {
        $tenantId = $this->resolveTenantId($actor, $payload);
        $dealId = isset($payload['deal_id']) && $payload['deal_id'] !== null ? (int) $payload['deal_id'] : null;
        if ($dealId !== null) {
            $this->mustGetTenantDeal($tenantId, $dealId);
        }

        $computed = $this->computePreviewTotals(
            $tenantId,
            $payload['products'],
            (float) ($payload['discount_total'] ?? 0)
        );
        $currencyCode = isset($payload['target_currency'])
            ? strtoupper((string) $payload['target_currency'])
            : null;
        Log::info(DomainConstants::LOG_QUOTE_PRICE_PREVIEWED, ['tenant_id' => $tenantId, 'deal_id' => $dealId]);

        return [
            'items' => $computed['items'],
            'subtotal' => $computed['subtotal'],
            'tax_total' => $computed['tax_total'],
            'discount_total' => $computed['discount_total'],
            'total' => $computed['total'],
            'currency_code' => $currencyCode,
        ];
    }

    private function resolveOrCreateDeal(int $tenantId, User $actor, Contact $contact, array $payload): Deal
    {
        if (isset($payload['deal_id']) && $payload['deal_id'] !== null) {
            return $this->mustGetTenantDeal($tenantId, (int) $payload['deal_id']);
        }

        $existing = $this->quoteRepository->findOpenDealForContact($tenantId, $contact->id);
        if ($existing) {
            return $existing;
        }

        $pipeline = Pipeline::query()->where('tenant_id', $tenantId)->where('status', Pipeline::STATUS_ACTIVE)->orderBy('id')->first();
        $stage = $pipeline
            ? PipelineStage::query()->where('tenant_id', $tenantId)->where('pipeline_id', $pipeline->id)->where('status', PipelineStage::STATUS_ACTIVE)->orderBy('stage_order')->first()
            : null;
        if (! $pipeline || ! $stage) {
            throw ValidationException::withMessages(['pipeline_id' => [DomainConstants::MSG_QUOTE_DEFAULT_PIPELINE_REQUIRED]]);
        }

        $deal = Deal::query()->create([
            'tenant_id' => $tenantId,
            'contact_id' => $contact->id,
            'company_id' => $contact->company_id,
            'owner_user_id' => $actor->id,
            'pipeline_id' => $pipeline->id,
            'pipeline_stage_id' => $stage->id,
            'created_by_user_id' => $actor->id,
            'updated_by_user_id' => $actor->id,
            'name' => 'Opportunity - '.$contact->first_name.' '.$contact->last_name,
            'status' => Deal::STATUS_OPEN,
            'meta' => ['source' => 'quote_auto_created'],
        ]);
        Log::info(DomainConstants::LOG_QUOTE_AUTO_DEAL_CREATED, ['tenant_id' => $tenantId, 'deal_id' => $deal->id]);

        return $deal;
    }

    private function resolveDealForExistingQuote(int $tenantId, Quote $quote, Contact $contact, array $payload): Deal
    {
        if (isset($payload['deal_id']) && $payload['deal_id'] !== null) {
            return $this->mustGetTenantDeal($tenantId, (int) $payload['deal_id']);
        }

        $deal = $this->mustGetTenantDeal($tenantId, (int) $quote->deal_id);
        if ((int) $deal->contact_id !== (int) $contact->id) {
            throw ValidationException::withMessages(['contact_id' => [DomainConstants::MSG_QUOTE_INVALID_CONTACT]]);
        }

        return $deal;
    }

    private function buildComputedItems(int $tenantId, array $products, float $discount = 0): array
    {
        if ($products === []) {
            throw ValidationException::withMessages(['products' => [DomainConstants::MSG_QUOTE_PRODUCTS_REQUIRED]]);
        }

        $rows = [];
        $subtotal = 0.0;
        $taxTotal = 0.0;
        foreach ($products as $index => $item) {
            $product = Product::query()->where('id', (int) $item['product_id'])->where('tenant_id', $tenantId)->first();
            if (! $product) {
                throw ValidationException::withMessages(['products' => [DomainConstants::MSG_QUOTE_INVALID_PRODUCT]]);
            }
            $quantity = max(1, (int) $item['quantity']);
            $unitPrice = isset($item['unit_price']) ? (float) $item['unit_price'] : (float) $product->unit_price;
            $taxRate = isset($item['tax_rate']) ? (float) $item['tax_rate'] : (float) ($product->tax_rate ?? 0);
            $lineSubtotal = $quantity * $unitPrice;
            $lineTax = $lineSubtotal * ($taxRate / 100);
            $lineTotal = $lineSubtotal + $lineTax;
            $subtotal += $lineSubtotal;
            $taxTotal += $lineTax;
            $rows[] = [
                'product_id' => $product->id,
                'product_name' => $product->name,
                'product_sku' => $product->sku,
                'quantity' => $quantity,
                'unit_price' => round($unitPrice, 2),
                'tax_rate' => round($taxRate, 2),
                'line_subtotal' => round($lineSubtotal, 2),
                'line_tax_total' => round($lineTax, 2),
                'line_total' => round($lineTotal, 2),
                'sort_order' => $index + 1,
            ];
        }

        $discountTotal = round($discount, 2);
        $total = max(0, round($subtotal + $taxTotal - $discountTotal, 2));

        return [
            'items' => $rows,
            'subtotal' => round($subtotal, 2),
            'tax_total' => round($taxTotal, 2),
            'discount_total' => $discountTotal,
            'total' => $total,
        ];
    }

    private function computePreviewTotals(int $tenantId, array $products, float $discountTotal = 0): array
    {
        if ($products === []) {
            throw ValidationException::withMessages(['products' => [DomainConstants::MSG_QUOTE_PRODUCTS_REQUIRED]]);
        }

        $rows = [];
        $subtotal = 0.0;
        $taxTotal = 0.0;
        $lineDiscountTotal = 0.0;
        foreach ($products as $item) {
            $product = Product::query()->where('id', (int) $item['product_id'])->where('tenant_id', $tenantId)->first();
            if (! $product) {
                throw ValidationException::withMessages(['products' => [DomainConstants::MSG_QUOTE_INVALID_PRODUCT]]);
            }

            $quantity = max(1, (int) $item['quantity']);
            $unitPrice = isset($item['unit_price']) ? (float) $item['unit_price'] : (float) $product->unit_price;
            $taxRate = isset($item['tax_rate']) ? (float) $item['tax_rate'] : (float) ($product->tax_rate ?? 0);
            $lineDiscount = round((float) ($item['discount'] ?? 0), 2);
            $lineSubtotal = $quantity * $unitPrice;
            $lineTax = $lineSubtotal * ($taxRate / 100);
            $lineTotal = max(0, $lineSubtotal + $lineTax - $lineDiscount);
            $subtotal += $lineSubtotal;
            $taxTotal += $lineTax;
            $lineDiscountTotal += $lineDiscount;
            $rows[] = [
                'product_id' => $product->id,
                'product_name' => $product->name,
                'quantity' => $quantity,
                'unit_price' => round($unitPrice, 2),
                'tax_rate' => round($taxRate, 2),
                'line_subtotal' => round($lineSubtotal, 2),
                'line_tax_total' => round($lineTax, 2),
                'line_discount_total' => $lineDiscount,
                'line_total' => round($lineTotal, 2),
            ];
        }

        $requestDiscountTotal = round($discountTotal, 2);
        $totalDiscount = round($lineDiscountTotal + $requestDiscountTotal, 2);
        $total = max(0, round($subtotal + $taxTotal - $totalDiscount, 2));

        return [
            'items' => $rows,
            'subtotal' => round($subtotal, 2),
            'tax_total' => round($taxTotal, 2),
            'discount_total' => $totalDiscount,
            'total' => $total,
        ];
    }

    private function syncDealFromQuote(Deal $deal, Quote $quote, bool $markWon): void
    {
        $payload = [
            'estimated_value' => $quote->total,
            'last_quote_id' => $quote->id,
            'updated_by_user_id' => $quote->updated_by_user_id,
        ];

        if ($markWon) {
            $finalStage = PipelineStage::query()
                ->where('tenant_id', $deal->tenant_id)
                ->where('pipeline_id', $deal->pipeline_id)
                ->orderByDesc('stage_order')
                ->first();
            if ($finalStage) {
                $payload['pipeline_stage_id'] = $finalStage->id;
            }
            $payload['status'] = Deal::STATUS_WON;
        }

        $deal->update($payload);
        Log::info(DomainConstants::LOG_QUOTE_DEAL_SYNCED, ['tenant_id' => $deal->tenant_id, 'deal_id' => $deal->id, 'quote_id' => $quote->id]);
    }

    private function hasVisibility(User $actor, Quote $quote): bool
    {
        if ($actor->isGlobalAdmin() || $actor->isCompanyAdmin()) {
            return true;
        }
        if ((int) $quote->tenant_id !== (int) $actor->tenant_id) {
            return false;
        }
        if ((int) $quote->created_by_user_id === (int) $actor->id) {
            return true;
        }
        if ((int) $actor->data_scope !== DomainConstants::DATA_SCOPE_TEAM || $actor->team_id === null) {
            return false;
        }

        return User::query()
            ->where('tenant_id', $actor->tenant_id)
            ->where('team_id', $actor->team_id)
            ->where('id', $quote->created_by_user_id)
            ->exists();
    }

    private function canDelete(User $actor, Quote $quote): bool
    {
        if ($actor->isGlobalAdmin() || $actor->isCompanyAdmin()) {
            return true;
        }

        return (int) $quote->created_by_user_id === (int) $actor->id;
    }

    private function resolveTenantId(User $actor, array $payload): int
    {
        if (! $actor->isGlobalAdmin()) {
            return (int) $actor->tenant_id;
        }
        if (! isset($payload['tenant_id'])) {
            throw ValidationException::withMessages(['tenant_id' => [DomainConstants::MSG_TENANT_REQUIRED]]);
        }

        return (int) $payload['tenant_id'];
    }

    private function mustGetTenantContact(int $tenantId, int $contactId): Contact
    {
        $contact = Contact::query()->where('tenant_id', $tenantId)->find($contactId);
        if (! $contact) {
            throw ValidationException::withMessages(['contact_id' => [DomainConstants::MSG_QUOTE_INVALID_CONTACT]]);
        }

        return $contact;
    }

    private function resolveQuoteRecipientEmail(Quote $quote, array $payload): string
    {
        if (isset($payload['email']) && trim((string) $payload['email']) !== '') {
            return (string) $payload['email'];
        }

        $email = $quote->contact?->email;
        if (! $email) {
            throw ValidationException::withMessages([
                'email' => [DomainConstants::MSG_QUOTE_CONTACT_EMAIL_REQUIRED],
            ]);
        }

        return (string) $email;
    }

    private function buildQuotePdfBinary(Quote $quote, string $layoutCode): string
    {
        $layoutView = $this->quoteLayouts()[$layoutCode]['pdf_view'];
        $pdf = Pdf::loadView($layoutView, [
            'quote' => $quote,
            'contactName' => trim((string) (($quote->contact?->first_name ?? '').' '.($quote->contact?->last_name ?? ''))),
            'companyName' => $quote->contact?->company?->name,
        ]);

        return $pdf->output();
    }

    private function resolveLayoutCode(array $payload): string
    {
        $code = (string) ($payload['layout_code'] ?? 'classic');
        if (! isset($this->quoteLayouts()[$code])) {
            return 'classic';
        }

        return $code;
    }

    private function quoteLayouts(): array
    {
        return [
            'classic' => [
                'code' => 'classic',
                'name' => 'Classic',
                'description' => 'Traditional quote format with clean table layout.',
                'pdf_view' => 'pdf.quote-classic',
            ],
            'modern' => [
                'code' => 'modern',
                'name' => 'Modern',
                'description' => 'Modern branding-focused quote layout.',
                'pdf_view' => 'pdf.quote-modern',
            ],
            'minimal' => [
                'code' => 'minimal',
                'name' => 'Minimal',
                'description' => 'Minimal quote format with compact visual style.',
                'pdf_view' => 'pdf.quote-minimal',
            ],
            'detailed' => [
                'code' => 'detailed',
                'name' => 'Detailed',
                'description' => 'Detailed quote including deal and contact context.',
                'pdf_view' => 'pdf.quote-detailed',
            ],
        ];
    }

    private function mustGetTenantDeal(int $tenantId, int $dealId): Deal
    {
        $deal = Deal::query()->where('tenant_id', $tenantId)->find($dealId);
        if (! $deal) {
            throw ValidationException::withMessages(['deal_id' => [DomainConstants::MSG_QUOTE_INVALID_DEAL]]);
        }

        return $deal;
    }

    private function mustGetQuote(int $quoteId): Quote
    {
        $quote = $this->quoteRepository->findById($quoteId);
        if (! $quote) {
            throw new ModelNotFoundException(DomainConstants::MSG_QUOTE_NOT_FOUND);
        }

        foreach ($quote->attachments as $attachment) {
            $attachment->setAttribute('signed_url', $this->signedUrl((string) $attachment->file_key));
        }

        return $quote;
    }

    private function mustGetQuoteByPublicToken(string $token): Quote
    {
        $quote = $this->quoteRepository->findByPublicToken($token);
        if (! $quote) {
            throw new ModelNotFoundException(DomainConstants::MSG_QUOTE_PUBLIC_TOKEN_INVALID);
        }

        foreach ($quote->attachments as $attachment) {
            $attachment->setAttribute('signed_url', $this->signedUrl((string) $attachment->file_key));
        }

        return $quote;
    }

    private function updatePublicQuoteResponse(string $token, int $targetStatus, Request $request): Quote
    {
        return DB::transaction(function () use ($token, $targetStatus, $request): Quote {
            $quote = $this->mustGetQuoteByPublicToken($token);
            if (! in_array((int) $quote->status, [Quote::STATUS_DRAFT, Quote::STATUS_SENT], true)) {
                throw ValidationException::withMessages([
                    'status' => [DomainConstants::MSG_QUOTE_INVALID_STATUS_TRANSITION],
                ]);
            }

            $before = $quote->toArray();
            $updated = $this->quoteRepository->update($quote, [
                'status' => $targetStatus,
                'updated_by_user_id' => $quote->updated_by_user_id ?? $quote->created_by_user_id,
            ]);
            $this->syncDealFromQuote($updated->deal, $updated, $targetStatus === Quote::STATUS_ACCEPTED);
            $this->recordPublicAudit(
                $request,
                $targetStatus === Quote::STATUS_ACCEPTED ? 'public_accepted' : 'public_rejected',
                $updated,
                $before,
                $updated->toArray()
            );
            Log::info(DomainConstants::LOG_QUOTE_PUBLIC_RESPONDED, [
                'tenant_id' => $updated->tenant_id,
                'quote_id' => $updated->id,
                'status' => $updated->statusLabel(),
            ]);
            $this->bumpVersion((int) $updated->tenant_id);

            return $this->mustGetQuote($updated->id);
        });
    }

    private function generateQuoteNumber(int $tenantId): string
    {
        $count = Quote::query()->where('tenant_id', $tenantId)->count() + 1;

        return sprintf('Q-%d-%06d', $tenantId, $count);
    }

    private function recordAudit(User $actor, Request $request, string $action, Quote $quote, ?array $before, ?array $after): void
    {
        $this->auditLogRepository->create([
            'tenant_id' => $quote->tenant_id,
            'user_id' => $actor->id,
            'module' => 'quote',
            'action' => $action,
            'entity_type' => Quote::class,
            'entity_id' => $quote->id,
            'before' => $before,
            'after' => $after,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }

    private function recordPublicAudit(Request $request, string $action, Quote $quote, ?array $before, ?array $after): void
    {
        $this->auditLogRepository->create([
            'tenant_id' => $quote->tenant_id,
            'user_id' => null,
            'module' => 'quote',
            'action' => $action,
            'entity_type' => Quote::class,
            'entity_id' => $quote->id,
            'before' => $before,
            'after' => $after,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }

    private function signedUrl(string $fileKey): string
    {
        /** @var \Illuminate\Filesystem\FilesystemAdapter $disk */
        $disk = Storage::disk($this->storageDisk());
        try {
            return $disk->temporaryUrl($fileKey, now()->addMinutes(10));
        } catch (\Throwable) {
            return $disk->url($fileKey);
        }
    }

    private function storageDisk(): string
    {
        return (string) env('QUOTE_STORAGE_DISK', env('COLLATERAL_STORAGE_DISK', 'local'));
    }

    private function buildCacheKey(?int $tenantId, array $filters, int $perPage): string
    {
        $version = Cache::get($this->versionKey($tenantId), 1);

        return "quotes:tenant:{$tenantId}:v:{$version}:p:{$perPage}:f:".md5(json_encode($filters));
    }

    private function bumpVersion(?int $tenantId): void
    {
        Cache::add($this->versionKey($tenantId), 1, now()->addDays(30));
        Cache::increment($this->versionKey($tenantId));
    }

    private function versionKey(?int $tenantId): string
    {
        return "quotes:tenant:{$tenantId}:version";
    }
}
