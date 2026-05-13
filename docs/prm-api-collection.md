# PRM (Partner Relationship Management) API — Request / Response Reference

Base URL: `{APP_URL}/api`  
Auth: `Authorization: Bearer {token}` for protected routes (Sanctum).  
Tenant: authenticated tenant users must use existing `tenant.context` behaviour (same as CRM).

Standard success envelope:

```json
{
  "success": true,
  "message": "…",
  "data": { }
}
```

Errors: `422` validation (`errors` object), `403` / `401` / `404` as per global API exception handler.

---

## Phase 1 — Organization invitations (partner & reseller onboarding)

### Public — Preview invitation

`GET /api/prm/invitations/preview?token={plain_token}`  
Throttle: `partner-invite-preview`

**Response 200**

```json
{
  "success": true,
  "message": "Invitation preview loaded.",
  "data": {
    "organization_display_name": "Acme Partner",
    "email_masked": "jo******e@example.com",
    "expires_at": "2026-05-18T12:00:00+00:00",
    "role_code": "partner_admin"
  }
}
```

### Public — Accept invitation (password setup + activation)

`POST /api/prm/invitations/accept`  
Throttle: `partner-invite-accept`

**Request**

```json
{
  "token": "plain-token-from-email",
  "name": "Jane Partner",
  "password": "Secretpass1",
  "password_confirmation": "Secretpass1",
  "terms_accepted": true
}
```

**Response 200**

```json
{
  "success": true,
  "message": "Account activated successfully.",
  "data": {
    "requires_email_verification": false,
    "token": "1|sanctum-plain-token…",
    "user": {
      "id": 12,
      "tenant_id": 1,
      "organization_id": 5,
      "team_id": null,
      "data_scope": "self",
      "role": "partner_admin",
      "status": "active",
      "name": "Jane Partner",
      "email": "jane@example.com",
      "email_verified_at": "2026-05-11T16:00:00.000000Z",
      "created_at": "…"
    }
  }
}
```

Verification behavior is configurable via `PRM_AUTO_VERIFY_INVITED_USERS`:

- `true`: invited users are verified at acceptance and receive `token` immediately.
- `false` (default): invited users are created unverified, verification email is sent, `requires_email_verification=true`, and `token=null` until they verify and log in via normal `/api/login`.

### Authenticated — List invitations

`GET /api/organizations/{organizationId}/invitations?per_page=15`

**Roles:** `company_admin` (same tenant) or `partner_admin` (only reseller orgs under their tree for reseller invites).  
**Response 200** — `data.items[]` uses `OrganizationInvitationResource` (no raw token).

### Authenticated — Create invitation

`POST /api/organizations/{organizationId}/invitations`

**Request**

```json
{
  "email": "newadmin@example.com",
  "role_code": "partner_admin",
  "expires_in_days": 14
}
```

`role_code`: `partner_admin` when `organization.type = partner`; `reseller_admin` when `organization.type = reseller`.

**Response 201** — `message` only (email contains link; token is never returned in JSON).

### Authenticated — Resend invitation

`POST /api/organizations/{organizationId}/invitations/{invitationId}/resend`

**Response 200**

### Authenticated — Revoke invitation

`DELETE /api/organizations/{organizationId}/invitations/{invitationId}`

**Response 200**

**Behaviour:** Secure random token (hashed at rest), email with `config('prm.invite_accept_url')` + `?token=…`, audit module `prm`, resend rotates token, revoke sets `status=revoked`.

---

## Phase 2 — Partner portal shell

**Middleware:** `partner.portal` — requires partner/reseller channel role + `organization_id` assignment.

### Navigation model (for SPA routing)

`GET /api/prm/partner/navigation`

**Response 200**

```json
{
  "success": true,
  "message": "Partner navigation loaded.",
  "data": {
    "items": [
      { "key": "dashboard", "label": "Dashboard", "route": "/partner/dashboard" },
      { "key": "leads", "label": "Lead registration", "route": "/partner/leads" }
    ]
  }
}
```

### Dashboard KPI shell

`GET /api/prm/partner/dashboard`

**Response 200**

```json
{
  "success": true,
  "message": "Partner dashboard loaded.",
  "data": {
    "summary": {
      "partner_organization_id": 5,
      "counts": { "leads": 3, "deals": 8, "quotes": 2 },
      "commission_pending_total": 120.5,
      "license_units_available": 40,
      "pipeline_value": 250000.0
    }
  }
}
```

---

## Phase 3 — Partner CRM operations

### Partner leads

| Method | Path |
|--------|------|
| GET | `/api/prm/partner/leads` |
| POST | `/api/prm/partner/leads` |
| PUT | `/api/prm/partner/leads/{leadId}` |

**POST body (example)**

```json
{
  "title": "Retail expansion",
  "contact_email": "buyer@corp.com",
  "company_name": "Corp Ltd",
  "status": "new",
  "approval_status": "pending",
  "metadata": { "source": "event" }
}
```

**Optional approval workflow:** use `approval_status` (`pending` / `approved` / `rejected`) — transitions are app-driven.

### Partner opportunity registration (deal + dedupe)

`POST /api/prm/partner/opportunities`

Same required deal fields as `POST /api/deals`, plus optional dedupe inputs:

```json
{
  "opportunity_key": "acme-corp-erp-2026",
  "contact_email": "buyer@corp.com",
  "company_id": 9,
  "name": "ERP rollout",
  "contact_id": 4,
  "owner_user_id": 3,
  "pipeline_id": 1,
  "pipeline_stage_id": 1,
  "estimated_value": 50000,
  "currency_code": "ZAR"
}
```

**Dedupe:** Server builds `partner_opportunity_fingerprint` (SHA-256 over tenant + partner org + `opportunity_key` or `email|company_id`). Unique per tenant + partner org + fingerprint — duplicate returns `422` with `MSG_PRM_DUPLICATE_OPPORTUNITY`.

**Existing CRM:** `POST /api/deals` unchanged; optional `partner_organization_id` / `partner_opportunity_fingerprint` may be sent for company users.

---

## Phase 4 — Resource center

### List partner-visible collaterals

`GET /api/prm/partner/resources/collaterals?per_page=15&resource_category=training`

Filters: `collaterals.partner_visible = true`, tenant match.  
`resource_category` on collaterals: e.g. `training`, `nda`, `battle_card`, `brochure` (free-form string).

### Record download

`POST /api/prm/partner/resources/collaterals/{collateralId}/downloads`

Creates `collateral_downloads` audit row (IP, user-agent, optional partner org).

---

## Phase 5 — Reseller invitations

Same invitation endpoints as Phase 1; use `organizationId` of a **reseller** org and `role_code: "reseller_admin"`. **Partner admin** may invite for reseller orgs in their subtree; **company admin** may invite for any org in tenant.

---

## Phase 6 — Partner programs & enrollment

### List programs (templates seeded per tenant)

`GET /api/prm/programs`

Returns `silver`, `gold`, `platinum`, `custom` rows created in migration `2026_05_12_180400…`.

### Enroll organization in program

`POST /api/prm/programs/enroll`

**Request**

```json
{
  "organization_id": 5,
  "partner_program_id": 2,
  "tier_code": "gold",
  "commission_percent": 12.5
}
```

**Roles:** `company_admin` or `global_admin`.

### List enrollments for an organization

`GET /api/prm/organizations/{organizationId}/program-enrollments`

---

## Phase 7 — Commission engine (payment-linked)

### List accruals

`GET /api/prm/commission-accruals?per_page=15`

- **Company admin:** all accruals in tenant.  
- **Partner portal user:** accruals for their primary org.  
- **Global admin:** not supported without tenant filter (returns validation-style error from service).

### Update accrual status (approval / payout)

`PATCH /api/prm/commission-accruals/{accrualId}/status`

**Request**

```json
{ "status": "approved" }
```

Statuses: `pending`, `approved`, `paid`, `void`.

**Accrual creation:** On successful PayFast ITN, if the quote’s deal has `partner_organization_id` and an active program enrollment with commission %, a **pending** `commission_accruals` row is inserted (linked to `payment_record_id` and `quote_id`).

---

## Phase 8 — License allocation

### List entitlements

`GET /api/prm/license-entitlements?per_page=15`

### Allocate (company → partner or partner → reseller)

`POST /api/prm/license-entitlements`

```json
{
  "holder_organization_id": 5,
  "product_id": 1,
  "units_total": 100,
  "parent_entitlement_id": null,
  "notes": "FY allocation"
}
```

### Consume units

`POST /api/prm/license-entitlements/{entitlementId}/consume`

```json
{ "units": 3 }
```

**Roles:** allocate = company admin; consume = partner portal user (holder must match) or company admin.

---

## Phase 9 — Dashboards

Partner dashboard summary is returned by `GET /api/prm/partner/dashboard` (counts, pipeline value, pending commission total, license stock). Extend the SPA with charts calling the same CRM list endpoints (`/api/deals`, `/api/quotes`, …) scoped by new deal visibility rules.

---

## Configuration

`.env` / `config/prm.php`:

- `PRM_INVITE_EXPIRY_DAYS` (default 7)  
- `PRM_INVITE_ACCEPT_URL` — frontend base URL for the accept screen (query `token` appended)

---

## Audit & security

- PRM actions write `audit_logs` with `module = prm` and entity types such as `organization_invitation`, `partner_lead`, `commission_accrual`, `license_entitlement`.  
- Tenant isolation preserved; partner portal middleware enforces channel assignment.  
- No breaking changes to existing CRM routes; additive columns on `deals` and `collaterals` only.
