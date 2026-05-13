# Access Control Matrix (RBAC + ABAC)

## Role Profiles

- `global_admin`
- `company_admin`
- `user` (internal sales/general CRM)
- `partner_admin`
- `partner_sales_manager`
- `partner_sales_consultant`
- `reseller_admin`
- `reseller_sales_consultant`

## Scope Rules

### Tenant boundary (all non-global)

- Access limited to actor `tenant_id`.

### Organization hierarchy scope

- `company_admin`: full tenant scope
- `partner_admin` and partner sales roles: assigned partner org + child reseller orgs
- reseller roles: assigned reseller org only

### Team/ownership scope

- Applies to internal CRM modules where ownership/team semantics are enforced.

## Module Capability Matrix

| Module | global_admin | company_admin | user | partner_admin | partner_sales_* | reseller_admin | reseller_sales_consultant |
|---|---|---|---|---|---|---|---|
| Users/Teams management | Yes | Yes | No | No | No | No | No |
| Tenant settings | Yes | Limited | No | No | No | No | No |
| Products | Yes | Yes | By assignment | View/limited by scope | View/limited by scope | View/limited by scope | View/limited by scope |
| Contacts | Yes | Yes | Scoped | Scoped | Scoped | Scoped | Scoped |
| Companies | Yes | Yes | Scoped | Scoped | Scoped | Scoped | Scoped |
| Deals | Yes | Yes | Scoped | Scoped + partner hierarchy | Scoped + partner hierarchy | Scoped own org | Scoped own org |
| Quotes | Yes | Yes | Scoped | Scoped | Scoped | Scoped | Scoped |
| Payments/Invoices | Yes | Yes | Scoped | Scoped | Scoped | Scoped | Scoped |
| PRM programs | Yes | Yes | No | No | No | No | No |
| Commissions update status | Yes | Yes | No | No | No | No | No |
| Commissions view | Yes | Yes | No | Scoped | Scoped | Scoped | Limited |
| Licenses allocate | Yes | Yes | No | No | No | No | No |
| Licenses consume/view | Yes | Yes | No | Scoped | Scoped | Scoped | Limited |
| Resource center | Yes | Yes | Optional | Yes | Yes | Yes | Yes |
| Reseller management | Yes | Yes | No | Scoped | No | Scoped | No |

## Session Contract Fields Required by Frontend

- `role`
- `roles[]`
- `permissions[]`
- `organization` (`id`, `type`, `parent_id`)
- `navigation_profile`
- `feature_flags`

## Enforcement Strategy

1. Tenant middleware first (`tenant.context`)
2. Permission profile resolution
3. Entity ABAC scope resolver
4. Controller/service permission checks
5. Repository-level query scoping (defense in depth)

