# API Compatibility Report (Unified CRM/PRM Refactor - Phase 1/2 Baseline)

## Compatibility Objective

Preserve all currently consumed API paths and envelopes while introducing additive enterprise metadata and configurable invite verification behavior.

## Endpoint Compatibility Status

### Unchanged Paths and Methods

- Auth endpoints (`/api/login`, `/api/logout`, `/api/user`, registration/verification endpoints)
- CRM endpoints (`/api/contacts`, `/api/companies`, `/api/deals`, `/api/quotes`, `/api/invoices`, `/api/settings/payment`, etc.)
- PRM endpoints (`/api/prm/*`, `/api/prm/partner/*`, invite endpoints)

No endpoint removals were introduced.

### Response Envelope

`{ success, message, data }` preserved across touched controllers/resources.

## Additive Response Changes

### `UserResource` (used by login, `/api/user`, invite accept)

Added fields:

- `roles`
- `permissions`
- `organization`
- `organization_role`
- `navigation_profile`
- `feature_flags`

Existing fields (`id`, `tenant_id`, `organization_id`, `team_id`, `data_scope`, `role`, `status`, `name`, `email`, etc.) remain unchanged.

## Invite Acceptance Compatibility

Endpoint unchanged: `POST /api/prm/invitations/accept`

Additive behavior:

- `requires_email_verification` now returned.
- `token` may be `null` when `PRM_AUTO_VERIFY_INVITED_USERS=false`.

Config toggle:

- `PRM_AUTO_VERIFY_INVITED_USERS=true`: legacy-style immediate verification + token issuance.
- `PRM_AUTO_VERIFY_INVITED_USERS=false`: invited user must verify email before normal login.

## Risk Notes

1. Frontend flows that assume invite acceptance always returns a usable token must now also handle `requires_email_verification=true`.
2. Existing API consumers remain compatible at path/method level.
3. Session payload expansion is additive and backward-compatible.

## Recommendation

For staged rollout, keep production on explicit `PRM_AUTO_VERIFY_INVITED_USERS=true` until frontend completes verification-first onboarding UX, then switch to `false`.

