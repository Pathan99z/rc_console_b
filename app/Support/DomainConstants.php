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
    public const MSG_COMPANY_CREATED = 'Company created successfully.';
    public const MSG_COMPANY_UPDATED = 'Company updated successfully.';
    public const MSG_COMPANY_DELETED = 'Company deleted successfully.';
    public const MSG_COMPANY_FETCHED = 'Companies fetched successfully.';
    public const MSG_COMPANY_NOT_FOUND = 'Company not found.';
    public const MSG_ACTIVITY_ADDED = 'Contact activity added successfully.';
    public const MSG_IMPORT_COMPLETED = 'Contact import completed successfully.';
    public const MSG_UNAUTHORIZED_SCOPE = 'You are not allowed to access this resource.';
    public const MSG_INVALID_SCOPE = 'Invalid data scope selected.';
    public const MSG_INVALID_TEAM = 'Invalid team selected for tenant.';
    public const MSG_TEAM_CREATED = 'Team created successfully.';
    public const MSG_TEAM_UPDATED = 'Team updated successfully.';
    public const MSG_TEAM_DELETED = 'Team deleted successfully.';
    public const MSG_TEAM_FETCHED = 'Teams fetched successfully.';

    public const LOG_CONTACT_IMPORT_STARTED = 'contact.import.started';
    public const LOG_CONTACT_IMPORT_COMPLETED = 'contact.import.completed';
    public const LOG_CONTACT_EXPORT = 'contact.export.generated';
}
