# Unified CRM + PRM Architecture

## Objective

Deliver a single multi-tenant CRM + PRM platform where partner/reseller users use the same identity system, same CRM APIs, and same application shell, with behavior differentiated by role, permission, organization assignment, and scoped visibility.

## Core Principles

- Single auth stack (Sanctum, `/api/login`, shared `users` model)
- Tenant-first isolation (`tenant.context`)
- RBAC + ABAC hybrid authorization
- Additive evolution, no destructive API removal
- Backward compatibility for existing PRM partner endpoints

## Target Capability Model

### Shared CRM foundation (all eligible users)

- Contacts
- Companies
- Deals
- Quotes
- Payments
- Invoices

### PRM extensions (role-gated)

- Partner dashboard
- Resource center
- Commissions
- License inventory
- Partner programs
- Reseller management

## Identity and Session Contract

Authentication stays unchanged, while `/api/user` is enriched (additive) to include:

- `role` + `roles[]`
- `permissions[]`
- `organization` (`id`, `type`, `parent_id`)
- `navigation_profile`
- `feature_flags`

## Authorization Architecture

### Layered model

1. Tenant boundary (`tenant.context`)
2. Central permission profile resolver (role -> permissions/profile)
3. Entity-level ABAC scope resolver (tenant + org hierarchy + ownership/team)
4. Feature-level permissions for PRM modules

### Migration posture

- Existing route/middleware contracts remain available.
- Legacy route bundles can remain while internal checks become permission-driven.
- No frontend-breaking removals until deprecation window closes.

## Deprecation Strategy

PRM APIs that duplicate CRM concepts remain active but are treated as compatibility facades:

- PRM lead registration -> CRM contact/company creation flow
- PRM opportunities -> CRM deal creation flow

## Rollout Phases

1. Gap analysis and architecture contract
2. Identity and invite verification configurability
3. Session metadata enrichment
4. Authorization centralization
5. Data scope hardening
6. Deprecated endpoint internal mapping
7. Full regression and security tests

