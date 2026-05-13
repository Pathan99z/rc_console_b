# API Deprecation Map (CRM + PRM Unification)

## Policy

- No immediate endpoint removals.
- Existing frontend-facing APIs remain functional.
- Deprecation is logical first (documentation + compatibility mapping), then physical removal in a later major version.

## Deprecated-by-Design Endpoints (Compatibility Mode)

| Existing Endpoint | Current Purpose | Canonical Target Domain | Compatibility Strategy |
|---|---|---|---|
| `GET /api/prm/partner/leads` | Partner lead listing | CRM contacts/companies (scoped) | Keep endpoint; map internally to unified lead/contact query layer |
| `POST /api/prm/partner/leads` | Lead registration | CRM contact/company creation | Keep endpoint; map payload to CRM contact/company service |
| `PUT /api/prm/partner/leads/{leadId}` | Lead update | CRM lead/contact lifecycle | Keep endpoint; map to canonical contact lead-state update logic |
| `POST /api/prm/partner/opportunities` | Opportunity registration | CRM deals | Keep endpoint; map to deal creation flow and dedupe checks |

## Non-Deprecated PRM Endpoints (Remain Domain-Specific)

| Endpoint Group | Reason |
|---|---|
| `/api/prm/commission-accruals*` | PRM-specific finance domain |
| `/api/prm/license-entitlements*` | PRM-specific licensing domain |
| `/api/prm/programs*` | PRM program administration |
| `/api/prm/partner/resources/*` | PRM content distribution |
| `/api/prm/partner/dashboard` | Partner KPI shell |
| `/api/organizations/*/invitations*` + public invite accept/preview | Partner/reseller onboarding domain |

## API Compatibility Guarantees

1. HTTP method and path unchanged during compatibility period.
2. Existing response envelopes remain (`success`, `message`, `data`).
3. New response fields can be additive.
4. Behavior changes gated by config/feature flags where needed.

## Proposed Sunset Process

1. Mark deprecated in docs and release notes.
2. Add response header in deprecated endpoints (future step): `X-API-Deprecated: true`.
3. Provide canonical replacement endpoint references.
4. Track usage for at least one release cycle.
5. Remove only in major API version with migration notice.

