<?php

namespace App\Support;

class DomainConstants
{
    public const DATA_SCOPE_SELF = 1;

    public const DATA_SCOPE_TEAM = 2;

    public const MSG_CONTACT_CREATED = 'Contact created successfully.';

    public const MSG_CONTACT_UPDATED = 'Contact updated successfully.';

    public const MSG_CONTACT_DELETED = 'Contact deleted successfully.';

    public const MSG_CONTACT_FETCHED = 'Contacts fetched successfully.';

    public const MSG_CONTACT_NOT_FOUND = 'Contact not found.';

    public const MSG_CONTACT_COMPANY_ATTACHED = 'Company attached to contact successfully.';

    public const MSG_CONTACT_COMPANY_DETACHED = 'Company detached from contact successfully.';

    public const MSG_CONTACT_EMAIL_EXISTS = 'Contact email already exists.';

    public const MSG_COMPANY_CREATED = 'Company created successfully.';

    public const MSG_COMPANY_UPDATED = 'Company updated successfully.';

    public const MSG_COMPANY_DELETED = 'Company deleted successfully.';

    public const MSG_COMPANY_FETCHED = 'Companies fetched successfully.';

    public const MSG_COMPANY_NOT_FOUND = 'Company not found.';

    public const MSG_COMPANY_IMPORT_COMPLETED = 'Company import completed successfully.';

    public const MSG_COMPANY_EMAIL_EXISTS = 'Company email already exists.';

    public const MSG_ACTIVITY_ADDED = 'Contact activity added successfully.';

    public const MSG_IMPORT_COMPLETED = 'Contact import completed successfully.';

    public const MSG_UNAUTHORIZED_SCOPE = 'You are not allowed to access this resource.';

    public const MSG_INVALID_SCOPE = 'Invalid data scope selected.';

    public const MSG_INVALID_TEAM = 'Invalid team selected for tenant.';

    public const MSG_TEAM_CREATED = 'Team created successfully.';

    public const MSG_TEAM_UPDATED = 'Team updated successfully.';

    public const MSG_TEAM_DELETED = 'Team deleted successfully.';

    public const MSG_TEAM_FETCHED = 'Teams fetched successfully.';

    public const MSG_PIPELINE_CREATED = 'Pipeline created successfully.';

    public const MSG_PIPELINE_UPDATED = 'Pipeline updated successfully.';

    public const MSG_PIPELINE_DELETED = 'Pipeline deleted successfully.';

    public const MSG_PIPELINE_FETCHED = 'Pipelines fetched successfully.';

    public const MSG_PIPELINE_NOT_FOUND = 'Pipeline not found.';

    public const MSG_PIPELINE_STAGE_CREATED = 'Pipeline stage created successfully.';

    public const MSG_PIPELINE_STAGE_UPDATED = 'Pipeline stage updated successfully.';

    public const MSG_PIPELINE_STAGE_DELETED = 'Pipeline stage deleted successfully.';

    public const MSG_PIPELINE_STAGE_FETCHED = 'Pipeline stages fetched successfully.';

    public const MSG_PIPELINE_STAGE_NOT_FOUND = 'Pipeline stage not found.';

    public const MSG_DEAL_CREATED = 'Deal created successfully.';

    public const MSG_DEAL_UPDATED = 'Deal updated successfully.';

    public const MSG_DEAL_DELETED = 'Deal deleted successfully.';

    public const MSG_DEAL_FETCHED = 'Deals fetched successfully.';

    public const MSG_DEAL_NOT_FOUND = 'Deal not found.';

    public const MSG_DEAL_STAGE_MOVED = 'Deal stage moved successfully.';

    public const MSG_DEAL_STATUS_UPDATED = 'Deal status updated successfully.';

    public const MSG_DEAL_CONTACT_REQUIRED = 'A valid contact is required for the deal.';

    public const MSG_DEAL_STATUS_INVALID = 'Invalid deal status selected.';

    public const MSG_TENANT_REQUIRED = 'Tenant is required for this operation.';

    public const MSG_PRODUCT_CREATED = 'Product created successfully.';

    public const MSG_PRODUCT_UPDATED = 'Product updated successfully.';

    public const MSG_PRODUCT_DELETED = 'Product deleted successfully.';

    public const MSG_PRODUCT_FETCHED = 'Products fetched successfully.';

    public const MSG_PRODUCT_NOT_FOUND = 'Product not found.';

    public const MSG_PRODUCT_STATUS_UPDATED = 'Product status updated successfully.';

    public const MSG_PRODUCT_SKU_EXISTS = 'Product SKU already exists.';

    public const MSG_PRODUCT_DELETE_FORBIDDEN = 'You are not allowed to delete this product.';

    public const MSG_PRODUCT_USED_IN_QUOTE = 'Product is already used in quote and cannot be deleted.';

    public const MSG_COLLATERAL_UPLOADED = 'Collateral uploaded successfully.';

    public const MSG_COLLATERAL_UPDATED = 'Collateral updated successfully.';

    public const MSG_COLLATERAL_FETCHED = 'Collaterals fetched successfully.';

    public const MSG_COLLATERAL_DELETED = 'Collateral deleted successfully.';

    public const MSG_COLLATERAL_SENT = 'Collateral shared successfully.';

    public const MSG_COLLATERAL_NOT_FOUND = 'Collateral not found.';

    public const MSG_COLLATERAL_DELETE_FORBIDDEN = 'You are not allowed to delete this collateral.';

    public const MSG_COLLATERAL_INVALID_PRODUCT = 'Invalid product selected for collateral.';

    public const MSG_COLLATERAL_CONTACT_EMAIL_REQUIRED = 'Contact does not have a valid email.';

    public const MSG_QUOTE_CREATED = 'Quote created successfully.';

    public const MSG_QUOTE_UPDATED = 'Quote updated successfully.';

    public const MSG_QUOTE_DELETED = 'Quote deleted successfully.';

    public const MSG_QUOTE_FETCHED = 'Quotes fetched successfully.';

    public const MSG_QUOTE_NOT_FOUND = 'Quote not found.';

    public const MSG_QUOTE_STATUS_UPDATED = 'Quote status updated successfully.';

    public const MSG_QUOTE_ATTACHMENT_UPLOADED = 'Quote attachment uploaded successfully.';

    public const MSG_QUOTE_PRODUCTS_REQUIRED = 'At least one product is required to create quote.';

    public const MSG_QUOTE_INVALID_STATUS_TRANSITION = 'Invalid quote status transition.';

    public const MSG_QUOTE_INVALID_PRODUCT = 'Invalid quote product selected.';

    public const MSG_QUOTE_INVALID_DEAL = 'Invalid deal selected for quote.';

    public const MSG_QUOTE_INVALID_CONTACT = 'Invalid contact selected for quote.';

    public const MSG_QUOTE_DEFAULT_PIPELINE_REQUIRED = 'Default pipeline and stage are required for auto deal creation.';

    public const MSG_QUOTE_PUBLIC_TOKEN_INVALID = 'Invalid or expired quote link.';

    public const MSG_QUOTE_PUBLIC_ACCEPTED = 'Quote accepted successfully.';

    public const MSG_QUOTE_PUBLIC_REJECTED = 'Quote rejected successfully.';

    public const MSG_QUOTE_SENT = 'Quote sent successfully.';

    public const MSG_QUOTE_PAYMENT_LINK_SENT = 'Quote payment link sent successfully.';

    public const MSG_QUOTE_PAYMENT_LINK_CREATED = 'Quote payment link created successfully.';

    public const MSG_QUOTE_PAYMENT_LINK_INVALID = 'Invalid or expired payment link.';

    public const MSG_QUOTE_PAYMENT_LINK_EXPIRED = 'This payment link has expired.';

    public const MSG_QUOTE_CONTACT_EMAIL_REQUIRED = 'Contact does not have a valid email for quote sending.';

    public const MSG_QUOTE_PRICE_PREVIEWED = 'Quote prices previewed successfully.';

    public const LOG_CONTACT_IMPORT_STARTED = 'contact.import.started';

    public const LOG_CONTACT_IMPORT_COMPLETED = 'contact.import.completed';

    public const LOG_CONTACT_EXPORT = 'contact.export.generated';

    public const LOG_COMPANY_IMPORT_STARTED = 'company.import.started';

    public const LOG_COMPANY_IMPORT_COMPLETED = 'company.import.completed';

    public const LOG_COMPANY_EXPORT = 'company.export.generated';

    public const LOG_DEAL_CREATED = 'deal.created';

    public const LOG_DEAL_UPDATED = 'deal.updated';

    public const LOG_DEAL_STAGE_MOVED = 'deal.stage.moved';

    public const LOG_DEAL_STATUS_CHANGED = 'deal.status.changed';

    public const LOG_PRODUCT_CREATED = 'product.created';

    public const LOG_PRODUCT_UPDATED = 'product.updated';

    public const LOG_PRODUCT_DELETED = 'product.deleted';

    public const LOG_PRODUCT_STATUS_CHANGED = 'product.status.changed';

    public const LOG_COLLATERAL_UPLOADED = 'collateral.uploaded';

    public const LOG_COLLATERAL_UPDATED = 'collateral.updated';

    public const LOG_COLLATERAL_DELETED = 'collateral.deleted';

    public const LOG_COLLATERAL_SENT = 'collateral.sent';

    public const LOG_QUOTE_CREATED = 'quote.created';

    public const LOG_QUOTE_UPDATED = 'quote.updated';

    public const LOG_QUOTE_DELETED = 'quote.deleted';

    public const LOG_QUOTE_STATUS_CHANGED = 'quote.status.changed';

    public const LOG_QUOTE_ATTACHMENT_UPLOADED = 'quote.attachment.uploaded';

    public const LOG_QUOTE_AUTO_DEAL_CREATED = 'quote.auto.deal.created';

    public const LOG_QUOTE_DEAL_SYNCED = 'quote.deal.synced';

    public const LOG_QUOTE_PUBLIC_VIEWED = 'quote.public.viewed';

    public const LOG_QUOTE_PUBLIC_RESPONDED = 'quote.public.responded';

    public const LOG_QUOTE_SENT = 'quote.sent';

    public const LOG_QUOTE_PAYMENT_LINK_CREATED = 'quote.payment_link.created';

    public const LOG_QUOTE_PAYMENT_LINK_SENT = 'quote.payment_link.sent';

    public const LOG_QUOTE_PRICE_PREVIEWED = 'quote.price.previewed';

    public const MSG_PAYMENT_SETTINGS_SAVED = 'Payment settings saved successfully.';

    public const MSG_PAYMENT_SETTINGS_FETCHED = 'Payment settings fetched successfully.';

    public const MSG_PAYMENT_SETTINGS_FORBIDDEN = 'You are not allowed to manage payment settings.';

    public const MSG_INVOICE_FETCHED = 'Invoices fetched successfully.';

    public const MSG_PAYFAST_CREDENTIALS_INCOMPLETE = 'PayFast is not configured for this tenant.';

    public const MSG_PAYFAST_CONTACT_EMAIL_REQUIRED = 'Contact email is required to start payment.';

    public const MSG_PAYFAST_QUOTE_ALREADY_PAID = 'This quote is already marked as paid.';

    public const MSG_PAYFAST_QUOTE_NOT_PAYABLE = 'Quote must be sent or accepted before payment.';

    public const MSG_PAYFAST_QUOTE_AMOUNT_INVALID = 'Quote total must be greater than zero for payment.';

    public const MSG_PAYFAST_LINK_CREATED = 'PayFast payment session created successfully.';

    public const MSG_ORGANIZATION_CREATED = 'Organization created successfully.';

    public const MSG_ORGANIZATION_UPDATED = 'Organization updated successfully.';

    public const MSG_ORGANIZATION_FETCHED = 'Organizations fetched successfully.';

    public const MSG_ORGANIZATION_STATUS_UPDATED = 'Organization status updated successfully.';

    public const MSG_ORGANIZATION_APPROVED = 'Organization approved successfully.';

    public const MSG_ORGANIZATION_REJECTED = 'Organization rejected successfully.';

    public const MSG_ORGANIZATION_SUSPENDED = 'Organization suspended successfully.';

    public const MSG_ORGANIZATION_NOT_FOUND = 'Organization not found.';

    public const MSG_ORGANIZATION_PARENT_OPTIONS_FETCHED = 'Organization parent options fetched successfully.';

    public const LOG_PAYFAST_LINK_GENERATED = 'payfast.link.generated';

    public const LOG_PAYFAST_ITN_RECEIVED = 'payfast.itn.received';

    public const LOG_PAYFAST_ITN_REJECTED = 'payfast.itn.rejected';

    public const LOG_PAYFAST_PAYMENT_APPLIED = 'payfast.payment.applied';

    public const LOG_INVOICE_CREATED = 'invoice.created';

    public const LOG_PAYMENT_SETTINGS_SAVED = 'payment.settings.saved';

    public const LOG_ORGANIZATION_CREATED = 'organization.created';

    public const LOG_ORGANIZATION_UPDATED = 'organization.updated';

    public const LOG_ORGANIZATION_STATUS_CHANGED = 'organization.status.changed';

    public const LOG_ORGANIZATION_APPROVED = 'organization.approved';

    public const LOG_ORGANIZATION_REJECTED = 'organization.rejected';

    public const LOG_ORGANIZATION_SUSPENDED = 'organization.suspended';

    public const LOG_USER_ROLE_CHANGED = 'user.role.changed';

    public const MSG_PRM_INVITATION_NOT_FOUND = 'Invitation not found.';

    public const MSG_PRM_INVITATION_CREATED = 'Invitation created and email sent.';

    public const MSG_PRM_INVITATION_LISTED = 'Invitations fetched successfully.';

    public const MSG_PRM_INVITATION_REVOKED = 'Invitation revoked successfully.';

    public const MSG_PRM_INVITATION_PREVIEW = 'Invitation preview loaded.';

    public const MSG_PRM_INVITATION_ACCEPTED = 'Account activated successfully.';

    public const MSG_PRM_PARTNER_NAV = 'Partner navigation loaded.';

    public const MSG_PRM_PARTNER_DASHBOARD = 'Partner dashboard loaded.';

    public const MSG_ORGANIZATION_DASHBOARD_FETCHED = 'Organization dashboard fetched successfully.';

    public const MSG_PRM_RESELLER_DASHBOARD = 'Reseller dashboard loaded.';

    public const MSG_PRM_LEAD_CREATED = 'Partner lead created successfully.';

    public const MSG_PRM_LEAD_UPDATED = 'Partner lead updated successfully.';

    public const MSG_PRM_LEAD_FETCHED = 'Partner leads fetched successfully.';

    public const MSG_PRM_OPPORTUNITY_REGISTERED = 'Opportunity registered successfully.';

    public const MSG_PRM_DUPLICATE_OPPORTUNITY = 'This opportunity is already registered for your channel.';

    public const MSG_PRM_RESOURCE_FETCHED = 'Resources fetched successfully.';

    public const MSG_PRM_DOWNLOAD_RECORDED = 'Download recorded.';

    public const MSG_PRM_PROGRAMS_FETCHED = 'Partner programs fetched successfully.';

    public const MSG_PRM_PROGRAM_FETCHED = 'Partner program fetched successfully.';

    public const MSG_PRM_PROGRAM_CREATED = 'Partner program created successfully.';

    public const MSG_PRM_PROGRAM_UPDATED = 'Partner program updated successfully.';

    public const MSG_PRM_PROGRAM_STATUS_UPDATED = 'Partner program status updated successfully.';

    public const MSG_PRM_ENROLLMENT_SAVED = 'Program enrollment saved successfully.';

    public const MSG_PRM_PARTNER_ENROLLMENTS_FETCHED = 'Partner program enrollments fetched successfully.';

    public const MSG_PRM_COMMISSION_FETCHED = 'Commission accruals fetched successfully.';

    public const MSG_PRM_COMMISSION_UPDATED = 'Commission accrual updated successfully.';

    public const MSG_PRM_LICENSE_FETCHED = 'License entitlements fetched successfully.';

    public const MSG_PRM_LICENSE_ALLOCATED = 'License entitlement created successfully.';

    public const MSG_PRM_LICENSE_CONSUMED = 'License consumption updated successfully.';

    public const MSG_PRM_RESOURCE_ADMIN_LISTED = 'PRM resources listed successfully.';

    public const MSG_PRM_RESOURCE_CREATED = 'PRM resource created successfully.';

    public const MSG_PRM_RESOURCE_SHOWN = 'PRM resource loaded successfully.';

    public const MSG_PRM_RESOURCE_UPDATED = 'PRM resource updated successfully.';

    public const MSG_PRM_RESOURCE_STATUS_UPDATED = 'PRM resource status updated successfully.';

    public const MSG_PRM_RESOURCE_DELETED = 'PRM resource deleted successfully.';

    public const MSG_PRM_RESOURCE_ANALYTICS = 'PRM resource analytics loaded successfully.';

    public const MSG_PRM_PAYOUT_FETCHED = 'Payouts fetched successfully.';

    public const MSG_PRM_PAYOUT_GENERATED = 'Payout generated successfully.';

    public const MSG_PRM_PAYOUT_UPDATED = 'Payout updated successfully.';

    public const MSG_PRM_PAYOUT_PAID = 'Payout marked as paid successfully.';

    public const MSG_PRM_PAYOUT_REVERSED = 'Payout reversed successfully.';

    public const MSG_PRM_PAYOUT_STATEMENT = 'Payout statement loaded successfully.';

    public const MSG_PRM_PAYOUT_RECONCILIATION = 'Payout reconciliation loaded successfully.';

    public const MSG_PRM_PAYOUT_BATCH_CREATED = 'Payout batch created successfully.';

    public const MSG_PRM_PAYOUT_BATCH_FETCHED = 'Payout batch fetched successfully.';

    public const MSG_PRM_PAYOUT_BATCH_UPDATED = 'Payout batch updated successfully.';

    public const MSG_PRM_PAYOUT_ADJUSTMENT_FETCHED = 'Payout adjustments fetched successfully.';

    public const MSG_PRM_PAYOUT_ADJUSTMENT_CREATED = 'Payout adjustment created successfully.';

    public const MSG_PRM_PAYOUT_DISPUTE_FETCHED = 'Payout disputes fetched successfully.';

    public const MSG_PRM_PAYOUT_DISPUTE_CREATED = 'Payout dispute created successfully.';

    public const MSG_PRM_PAYOUT_DISPUTE_UPDATED = 'Payout dispute updated successfully.';

    public const MSG_PRM_PAYOUT_ACCOUNT_FETCHED = 'Payout accounts fetched successfully.';

    public const MSG_PRM_PAYOUT_ACCOUNT_CREATED = 'Payout account created successfully.';

    public const MSG_PRM_PAYOUT_ACCOUNT_UPDATED = 'Payout account updated successfully.';

    public const MSG_PRM_PAYOUT_ACCOUNT_VERIFIED = 'Payout account verified successfully.';

    public const MSG_TASK_FETCHED = 'Tasks fetched successfully.';

    public const MSG_TASK_CREATED = 'Task created successfully.';

    public const MSG_TASK_UPDATED = 'Task updated successfully.';

    public const MSG_TASK_DELETED = 'Task deleted successfully.';

    public const MSG_TASK_ASSIGNED = 'Task assigned successfully.';

    public const MSG_TASK_ASSIGNABLE_USERS_FETCHED = 'Assignable users fetched successfully.';

    public const MSG_DEMO_LINK_FETCHED = 'Demo links fetched successfully.';

    public const MSG_DEMO_LINK_CREATED = 'Demo link created successfully.';

    public const MSG_DEMO_LINK_UPDATED = 'Demo link updated successfully.';

    public const MSG_DEMO_LINK_DELETED = 'Demo link deleted successfully.';

    public const MSG_DEMO_LINK_STATUS_CHECKED = 'Demo link status checked successfully.';

    public const MSG_DEMO_LINK_SHAREABLE_ORGS_FETCHED = 'Shareable organizations fetched successfully.';

    public const MSG_EMAIL_SETTINGS_FETCHED = 'Organization email settings fetched successfully.';

    public const MSG_EMAIL_SETTINGS_UPDATED = 'Organization email settings updated successfully.';

    public const MSG_EMAIL_SETTINGS_PROVIDERS_FETCHED = 'Mail provider presets fetched successfully.';
}
