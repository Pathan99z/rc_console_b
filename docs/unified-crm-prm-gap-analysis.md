# Unified CRM + PRM Backend Gap Analysis

Date: 2026-05-13  
Scope: Laravel multi-tenant CRM/PRM backend in `rc_console_b`  
Method: Code inspection only (no destructive or compatibility-breaking changes)

---

## 1) Executive Summary

The backend already has a strong unified foundation:

- single `users` model and table
- single `/api/login` endpoint with Sanctum
- common tenant enforcement (`tenant.context`)
- shared CRM APIs used by all authenticated users

The main architecture gaps are:

1. authorization is fragmented (service/repository checks, no centralized permission model)
2. inconsistent ABAC scope across CRM modules (notably companies/invoices)
3. PRM `partner` route namespace and middleware encourage portal-style separation
4. PRM lead/opportunity APIs duplicate CRM concepts
5. session/profile contract lacks `permissions`, navigation profile, and feature flags

This can be refactored safely with additive changes and compatibility layers.

---

## 2) Current Architecture Snapshot

### Identity and Auth

- Auth endpoints: `/api/login`, `/api/logout`, `/api/register`, `/api/user`
- Sanctum is shared for all roles.
- Invite acceptance (`/api/prm/invitations/accept`) creates a normal user and organization assignment.
- Current invite accept flow sets `email_verified_at` immediately at acceptance.

### Routing Model

- Shared authenticated CRM routes under `auth:sanctum + tenant.context`
- PRM administrative routes under `/api/prm/*`
- Partner bundle under `/api/prm/partner/*` with `partner.portal` middleware

### Role Model

Implemented role codes include:

- `global_admin`, `company_admin`, `user`
- `partner_admin`, `partner_sales_manager`, `partner_sales_consultant`
- `reseller_admin`, `reseller_sales_consultant`

### Scope Model

- Deal visibility includes partner-org scope (`partner_organization_id`) via `PartnerScopeResolver`
- Contacts/quotes rely mainly on owner/team style visibility
- Companies and invoices are effectively tenant-wide for many non-admin contexts

---

## 3) Gap Analysis by Module

## 3.1 Authentication / Identity

### Working

- Partners are not stored in a separate identity table.
- Same login endpoint and same token system are used.

### Gap

- Invite acceptance currently auto-verifies email. Target requires configurable behavior:
  - `PRM_AUTO_VERIFY_INVITED_USERS=false` by default
  - enforce verification-before-login unless explicitly enabled

### Risk

- Medium compatibility risk if changed directly without a transition toggle.

---

## 3.2 Authorization

### Working

- Role checks exist and are enforced in many service methods.
- Tenant middleware guards active user/tenant context.

### Gap

- Authorization is scattered across FormRequests, controllers, services, repositories.
- No centralized permission resolver/policy matrix.
- `partner.portal` couples access model to route namespace instead of permission capability.

### Risk

- High long-term maintenance and scope drift risk.

---

## 3.3 CRM Data Scope (ABAC/RBAC hybrid)

### Working

- Deal visibility includes partner hierarchy scope.

### Gaps

- **Companies:** tenant-wide exposure risk for partner/reseller roles.
- **Invoices:** tenant-wide exposure risk (insufficient partner org scoping).
- **Quotes/Contacts:** scope logic differs from deal/channel logic, creating inconsistency.

### Risk

- High security risk (cross-org visibility leakage within a tenant).

---

## 3.4 Route Architecture

### Working

- Existing APIs are stable and consumed by frontend.

### Gap

- `/api/prm/partner/*` creates a conceptual “separate portal backend” even though auth is unified.
- Capabilities should be permission-driven and profile-driven instead of route-bundle identity.

### Risk

- Medium migration risk if routes are removed abruptly.
- Must keep backward compatibility by preserving existing endpoints and internally mapping.

---

## 3.5 Domain Duplication (PRM vs CRM)

### Gap

- PRM lead registration and PRM opportunities duplicate existing CRM contact/company/deal concepts.
- This introduces parallel business paths and potential divergence.

### Target

- Keep old endpoints operational (deprecated).
- Internally map to canonical CRM domain flows.

### Risk

- Medium-to-high if not done with compatibility adapters.

---

## 3.6 Session/Profile Contract

### Current

- `GET /api/user` returns core user fields only.

### Gap

- Missing enterprise contract fields for unified UI composition:
  - `roles[]`
  - `permissions[]`
  - organization metadata (`id`, `type`, `parent_id`)
  - `navigation_profile`
  - `feature_flags`

---

## 4) Anti-Patterns Identified

1. role checks distributed in many layers (DRY/SOLID erosion)
2. route-namespace-driven authorization (`partner.portal`) instead of capability model
3. partial ABAC implementation (deals stronger than companies/invoices)
4. duplicate domain pathways for lead/opportunity
5. frontend navigation contract not formally backed by backend metadata

---

## 5) Compatibility and Breaking-Change Risks

| Area | Risk | Mitigation |
|------|------|------------|
| Tightening company/invoice visibility | High | feature-flagged rollout, audit logs, staged enforcement |
| Invite email verification behavior change | Medium | env toggle (`PRM_AUTO_VERIFY_INVITED_USERS`) + backward default transition |
| Reworking partner routes | Medium | keep endpoints, internally map to unified services, mark deprecated |
| Permission centralization | Medium | additive policies/resolver with fallback to existing checks in transition period |

---

## 6) Phased Remediation Plan (Dependency Ordered)

### Phase A — Contract and Guardrails

1. Define canonical role/permission matrix (RBAC).
2. Define org-scope ABAC rules per entity (contacts, companies, deals, quotes, invoices, payments).
3. Introduce feature flags to gate rollout behavior.

### Phase B — Identity and Session Unification

4. Add `PRM_AUTO_VERIFY_INVITED_USERS` config toggle.
5. Update invite acceptance flow to respect toggle (default false for enterprise target).
6. Extend session/profile payload with roles/permissions/navigation profile/feature flags (additive).

### Phase C — Authorization Centralization

7. Add centralized permission resolver + Laravel policies (non-breaking integration).
8. Gradually migrate existing service checks to permission layer.

### Phase D — Data Scope Hardening

9. Implement unified scope resolver used across repositories.
10. Apply to companies/invoices/quotes/contacts to align with deal partner scope behavior.
11. Keep current behavior behind temporary compatibility flags if needed.

### Phase E — De-duplicate PRM Domain Paths

12. Introduce internal mapping from deprecated PRM lead/opportunity endpoints to canonical CRM operations.
13. Keep endpoint signatures/responses backward compatible.
14. Mark deprecated in docs with migration guidance.

### Phase F — Route Architecture Cleanup

15. Keep old `/api/prm/partner/*` endpoints functional.
16. Move authorization semantics to permission checks; reduce hard dependency on `partner.portal`.

### Phase G — Verification and Hardening

17. Add end-to-end role scope tests for all CRM+PRM entities.
18. Add security tests for tenant leakage and cross-org access.
19. Add compatibility tests for deprecated endpoint mapping.

---

## 7) Required Deliverables for Implementation Phases

1. `docs/unified-crm-prm-architecture.md`
2. `docs/access-control-matrix.md`
3. `docs/api-deprecation-map.md`
4. Additive config and policy modules
5. Compatibility test suite for old/new paths

---

## 8) Immediate Recommendation

Proceed with implementation in strict order:

1. additive config + session contract
2. centralized authorization layer
3. data-scope hardening
4. deprecated API mapping
5. docs + tests

No existing API removals should occur until compatibility and migration windows are complete.

