# Partner + Reseller Foundation API Collection

Base URL: `{APP_URL}/api`  
Auth: `Authorization: Bearer {token}` and `Accept: application/json`  
Tenant: non-global users use tenant from login context; `X-Tenant-ID` must match authenticated user tenant.

All JSON responses follow:

- Success: `{ "success": true, "message": "Operation successful.", "data": {} }`
- Validation/Error: `{ "success": false, "message": "Validation failed.", "errors": {} }`

---

## Roles in this module

- Existing: `global_admin`, `company_admin`, `user`
- New: `partner_admin`, `partner_sales_manager`, `partner_sales_consultant`, `reseller_admin`, `reseller_sales_consultant`

Visibility:

- `global_admin` -> all tenants
- `company_admin` -> all organizations in own tenant
- `partner_admin` -> own partner org + child reseller orgs
- `reseller_*` -> own organization only

---

## Organization fields

- `tenant_id`
- `parent_organization_id`
- `type`: `company | partner | reseller`
- `legal_name`, `display_name`
- `registration_number`, `tax_number`
- `email`, `phone`, `website`
- `address_line_1`, `address_line_2`, `city`, `state`, `country`, `postal_code`
- `onboarding_status`: `draft | pending_review | approved | active | suspended | rejected`
- `status`: `active | inactive`
- `credit_limit`
- `metadata` (JSON)
- `created_by_user_id`, `updated_by_user_id`

Hierarchy rules:

- `company` -> no parent
- `partner` -> parent must be `company`
- `reseller` -> parent must be `partner`

---

## Endpoints

### 1) List organizations

`GET /organizations`

Query:

- `per_page` (1-100)
- `type`
- `status`
- `onboarding_status`
- `search`
- `tenant_id` (global admin only)

Use for grid/table pages.

---

### 1b) Parent options for create form (recommended)

`GET /organizations/parent-options`

Use this for the **Parent organization** dropdown instead of loading the full `/organizations` list and filtering in the browser. Results are **tenant-scoped** and **role-scoped** (no cross-tenant IDs; partner admins only see their own partner as parent for resellers).

**Query parameters**

| Parameter | Rules |
|-----------|--------|
| `child_type` | **Required.** `company` \| `partner` \| `reseller` — the type of record you are creating. |
| `tenant_id` | **Required for `global_admin`.** Target tenant id. |
| `include_inactive` | Optional boolean. Default `false`: only parents with `status=active` are returned. |

**What you get back**

`data.items` is an array of `{ id, tenant_id, type, display_name, legal_name, status }`.

**Behavior**

- `child_type=company` → `items` is `[]` (company orgs have no parent).
- `child_type=partner` → only **`company`** organizations in the tenant (company admin / global admin only; partner admin gets `422`).
- `child_type=reseller` → only **`partner`** organizations: all partners in tenant for company/global admin; **only the signed-in partner’s org** for `partner_admin`.

**Important:** This is separate from CRM **Companies** (`/companies`). Hierarchy parents are **`organizations` with `type=company`**, not CRM company rows, unless your product explicitly links them later.

---

### 2) Create organization

`POST /organizations`

**Implicit parent (Kiflo-style, no dropdown required)**

- **`type=partner`** (`company_admin` / `global_admin`): you may **omit** `parent_organization_id`. The API resolves the parent automatically:
  1. If the authenticated user has `organization_id` set to an **`organizations` row with `type=company`** in the **same tenant**, that id is used.
  2. Otherwise the **first company organization** for the tenant is used (lowest `id` — canonical root when multiple exist).
  3. If **no** `type=company` organization exists for the tenant, the request fails with `422` and `errors.parent_organization_id`: *No company organization exists for this tenant…*
- **`type=reseller`** (`partner_admin`): you may **omit** `parent_organization_id`. The parent is set to the signed-in user’s **`organization_id`** (their partner org).
- **`type=reseller`** (`company_admin` / `global_admin`): **parent is still required** (many partners per tenant — pick explicitly or add a dedicated simplified endpoint later).
- You may still send **`parent_organization_id`** explicitly; it overrides implicit resolution.

Body example (partner, **no parent field**):

```json
{
  "type": "partner",
  "legal_name": "Partner Legal Name (Pty) Ltd",
  "display_name": "Partner One",
  "email": "ops@partnerone.com",
  "onboarding_status": "pending_review"
}
```

Body example (partner with explicit parent — still supported):

```json
{
  "type": "partner",
  "parent_organization_id": 1,
  "legal_name": "Partner Legal Name (Pty) Ltd",
  "display_name": "Partner One",
  "registration_number": "2019/123456/07",
  "tax_number": "9876543210",
  "email": "ops@partnerone.com",
  "phone": "+27110000000",
  "website": "https://partnerone.example",
  "city": "Johannesburg",
  "country": "South Africa",
  "onboarding_status": "pending_review",
  "status": "active",
  "credit_limit": 10000,
  "metadata": {
    "tier": "gold"
  }
}
```

Rules:

- `company_admin` can create `partner` and `reseller` in tenant; **partner** parent is auto-resolved when omitted (see above).
- `partner_admin` can create only `reseller` under own partner; **reseller** parent defaults to own partner when omitted.
- `reseller_*` cannot create organizations

---

### 3) Get organization details

`GET /organizations/{organizationId}`

Returns one organization with parent/children context fields.

---

### 4) Update organization

`PUT /organizations/{organizationId}`

Body: any editable profile fields (`display_name`, contacts, address, metadata, credit_limit, etc.).

---

### 5) Update organization active status

`PATCH /organizations/{organizationId}/status`

Body:

```json
{
  "status": "inactive"
}
```

---

### 6) Approve onboarding

`POST /organizations/{organizationId}/approve`

Behavior:

- allowed from `draft` or `pending_review`
- sets onboarding lifecycle to active state for operational usage

---

### 7) Reject onboarding

`POST /organizations/{organizationId}/reject`

Body:

```json
{
  "reason": "Compliance documents missing"
}
```

Behavior:

- sets onboarding to `rejected`
- stores reason in metadata for audit visibility

---

### 8) Suspend organization

`POST /organizations/{organizationId}/suspend`

Behavior:

- sets onboarding to `suspended`
- marks operational status inactive

---

## User organization mapping

`users.organization_id` is now supported for role alignment:

- company admin user -> company organization
- partner users -> partner organization
- reseller users -> reseller organization

User creation payload supports:

```json
{
  "name": "Partner Sales",
  "email": "sales@partner.com",
  "password": "secret123",
  "role": "partner_sales_consultant",
  "organization_id": 10
}
```

Role update endpoint (`PATCH /users/{userId}/role`) supports new role codes and writes audit log entry `user.role.changed`.

---

## Audit events tracked

- `organization.created`
- `organization.updated`
- `organization.status.changed`
- `organization.approved`
- `organization.rejected`
- `organization.suspended`
- `user.role.changed`

---

## Frontend integration sequence

1. Load `/organizations` with filters for the **list/table** page.
2. **Create partner (simple / Kiflo-style):** for `company_admin` / `global_admin`, call `POST /organizations` with **`type=partner`** and **omit** `parent_organization_id`. The API attaches the partner under the tenant’s company org automatically (see implicit rules in section 2). **No parent dropdown required** for this flow.
3. **Create reseller as `partner_admin`:** omit `parent_organization_id`; the API uses the signed-in user’s partner `organization_id` as parent.
4. **Optional advanced UI:** if you still want a parent picker (e.g. `company_admin` creating a **reseller** under a chosen partner), use `GET /organizations/parent-options?child_type=reseller` and send `parent_organization_id` on submit.
5. Run onboarding actions with `approve` / `reject` / `suspend` as needed.
6. Map users to org via `organization_id` in user create/update flows (set `company_admin`’s `organization_id` to the tenant **company** org so implicit partner parent prefers that row when multiple company roots exist).
7. For `global_admin`, pass `tenant_id` on create when required by existing API rules.

---

## Frontend changes checklist (Vue)

1. **Partner create:** hide **Parent organization** for `company_admin` / `global_admin`; do **not** send `parent_organization_id` (or send `null`). Rely on backend implicit resolution.
2. **Partner create as `partner_admin`:** not allowed (unchanged); keep disabled or hidden.
3. **Reseller create as `partner_admin`:** hide parent field; omit `parent_organization_id`.
4. **Reseller create as `company_admin`:** keep parent selection (or `parent-options?child_type=reseller`) — parent is **not** auto-resolved for this role.
5. **Company org prerequisite:** ensure one `type=company` organization exists per tenant (seed on tenant registration or first-run wizard) so implicit partner create never hits `422`.
6. **Global admin** create: continue sending `tenant_id` in the body when acting for a tenant.
7. **Errors:** show `422` `errors`; message *No company organization exists for this tenant…* means seed the company org first.

Email-confirmation style “partner accepts invite” is **not** part of this module unless you add it separately.
