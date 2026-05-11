# Payments & PayFast — API collection (RC Console)

Base URL: `{APP_URL}/api`  
Auth: `Authorization: Bearer {token}` and `Accept: application/json`  
Tenant: non–global-admin users send `X-Tenant-ID` when required by your client (same as existing CRM rules).

All JSON responses follow:

**Success:** `{ "success": true, "message": "...", "data": { ... } }`  
**Error:** `{ "success": false, "message": "...", "errors": { } }`

---

## 1. Payment settings (company / global admin only)

### GET `/settings/payment`

**Who:** `company_admin` or `global_admin` only.

**Query (global admin only):** `tenant_id` (required) — target tenant.

**Success `data`:** `tenant_id`, `payfast_mode` (`sandbox`|`live`), `merchant_id`, `merchant_key_masked`, `passphrase_configured` (boolean), `return_url`, `cancel_url`, `notify_url`.

**Errors:** `403` if not admin; `422` if global admin omits `tenant_id`.

---

### POST `/settings/payment`

**Who:** `company_admin` or `global_admin`.

**Body (JSON):**

| Field | Rules |
|--------|--------|
| `tenant_id` | Required for `global_admin`; omit for company admin (uses user tenant). |
| `payfast_mode` | `sandbox` or `live` |
| `merchant_id` | string, max 64 |
| `merchant_key` | string (stored encrypted) |
| `passphrase` | optional string (stored encrypted) |
| `return_url`, `cancel_url`, `notify_url` | optional valid URLs |

**Success:** `201`, message `Payment settings saved successfully.`, `data` same shape as GET (masked).

---

### PUT `/settings/payment`

Same body and success shape as **POST** (`200`). Upserts the row for the tenant.

---

## 2. PayFast payment session (hosted checkout)

### NEW Recommended Flow (create, then send)

1. `POST /quotes/{quoteId}/payment-links`  
   Create a persistent payment link token for the quote.
2. `POST /quotes/{quoteId}/payment-links/{linkId}/send`  
   Send email with the same stored link URL.
3. Customer opens `GET /payments/link/{token}`  
   Backend auto-generates PayFast fields and redirects/submits to PayFast.

---

### POST `/quotes/{quoteId}/payment-links`

**Auth:** required.

**Body (JSON):**

| Field | Rules |
|--------|--------|
| `expires_at` | optional datetime, must be in future |

**Success `201`:**

```json
{
  "success": true,
  "message": "Quote payment link created successfully.",
  "data": {
    "payment_link": {
      "id": 22,
      "token": "uuid-token",
      "status": "created",
      "expires_at": "2026-05-14T10:40:00.000000Z",
      "url": "https://your-domain.com/payments/link/uuid-token"
    }
  }
}
```

---

### POST `/quotes/{quoteId}/payment-links/{linkId}/send`

**Auth:** required.

**Body (JSON):**

| Field | Rules |
|--------|--------|
| `email` | optional recipient override |
| `message` | optional custom message |

**Success `200`:**

```json
{
  "success": true,
  "message": "Quote payment link sent successfully.",
  "data": {
    "payment_link": {
      "id": 22,
      "status": "sent",
      "recipient_email": "customer@example.com",
      "sent_at": "2026-05-07T08:10:11.000000Z",
      "expires_at": "2026-05-14T10:40:00.000000Z",
      "url": "https://your-domain.com/payments/link/uuid-token"
    }
  }
}
```

---

### POST `/quotes/{quoteId}/send-payment-link`

**Auth:** required.

**Who:** any authenticated user who can view that quote.

**Body (JSON):**

| Field | Rules |
|--------|--------|
| `email` | optional email override; if omitted, uses quote contact email |
| `message` | optional custom message |

**Success:** `200`, message `Quote payment link sent successfully.`

```json
{
  "success": true,
  "message": "Quote payment link sent successfully.",
  "data": {
    "quote": {
      "id": 101,
      "status": "sent",
      "payment_status": "unpaid"
    }
  }
}
```

**Legacy behavior:** sends customer an email with a `Pay Now` URL based on quote public token.
Prefer the new `payment-links` endpoints for deterministic create-then-send flow.

---

### POST `/quotes/{quoteId}/payment-link`

**Auth:** required.

**Who:** any user who can already view the quote (same visibility rules as `GET /quotes/{quoteId}`).

**Preconditions:** quote `status` is `sent` or `accepted`; `payment_status` is `unpaid`; contact has `email`; PayFast credentials configured (tenant settings or `.env` fallback).

**Success `data`:**

```json
{
  "action_url": "https://sandbox.payfast.co.za/eng/process",
  "method": "POST",
  "fields": {
    "merchant_id": "...",
    "merchant_key": "...",
    "return_url": "...",
    "cancel_url": "...",
    "notify_url": "...",
    "name_first": "...",
    "name_last": "...",
    "email_address": "...",
    "m_payment_id": "123",
    "amount": "100.00",
    "item_name": "...",
    "item_description": "...",
    "signature": "..."
  },
  "payment_record_id": 123
}
```

**Frontend:** build an HTML `<form method="POST" action="{action_url}">` with hidden inputs for each key in `fields`, then submit (or submit via JS). Do **not** treat return/cancel URLs as proof of payment; only the ITN webhook is authoritative.

**Errors:** `422` validation (e.g. not payable, missing email, incomplete PayFast config).

---

### POST `/quotes/public/{token}/payment-link`

**Auth:** none (public link).

**Throttle:** per IP + token.

Same success `data` shape as authenticated link. Use the quote’s `public_uuid` as `{token}`.

---

## 3. PayFast ITN (webhook)

### POST `/payments/webhook/payfast`

**Auth:** none.  
**Content-Type:** `application/x-www-form-urlencoded` (as sent by PayFast).

**Response:** `200` plain text `ITN received` on success; `400` plain text `Bad request` on validation/signature/amount mismatch; `500` on unexpected errors.

**Configure in PayFast dashboard:** ITN URL must be publicly reachable, e.g.  
`https://{your-domain}/api/payments/webhook/payfast`  
(or your `notify_url` override from tenant settings, which must point to this same handler unless you add another route).

**Server behaviour (summary):** verifies signature using tenant credentials (or env fallback); on `payment_status=COMPLETE` and matching `amount_gross`, marks `payment_records` success, sets quote `payment_status` to `paid`, syncs deal to **won** (same as accepted quote). Idempotent if already processed.

---

## 4. Quote resource field

`GET /quotes/...` responses now include:

- `payment_status`: `unpaid` | `paid`

Existing `status` (`draft`, `sent`, `accepted`, …) is unchanged.

---

## Postman / Insomnia quick import

Create a collection with:

1. **Login** — existing `POST /login` → save `token`.
2. **Settings** — `GET/POST/PUT /settings/payment` with Bearer auth.
3. **Send payment email** — `POST /quotes/{{quoteId}}/send-payment-link` with Bearer auth.
4. **Payment link** — `POST /quotes/{{quoteId}}/payment-link` with Bearer auth.
5. **Public payment link** — `POST /quotes/public/{{public_uuid}}/payment-link` without auth.
6. **ITN (manual test only)** — `POST /payments/webhook/payfast` as **x-www-form-urlencoded**; copy field names from a real PayFast ITN or from the `fields` object plus `pf_payment_id`, `payment_status`, `amount_gross`, etc., and recompute `signature` per PayFast rules (or use sandbox ITN).

For production ITN testing, use PayFast sandbox + ngrok (or similar) so PayFast can reach your dev URL.
