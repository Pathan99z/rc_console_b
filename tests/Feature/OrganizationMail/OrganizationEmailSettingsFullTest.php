<?php

namespace Tests\Feature\OrganizationMail;

use App\Models\Organization;
use App\Models\OrganizationEmailSetting;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserOrganizationAssignment;
use App\Services\OrganizationMail\OrganizationMailResolverService;
use App\Services\Payment\PaymentSecretEncrypter;
use App\Support\OrganizationMail\OrganizationMailContext;
use App\Notifications\Auth\VerifyEmailNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrganizationEmailSettingsFullTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_admin_can_save_and_read_masked_smtp_settings(): void
    {
        $ctx = $this->seedHierarchy();
        Sanctum::actingAs($ctx['companyAdmin']);

        $this->patchJson('/api/settings/email', [
            'organization_id' => $ctx['company']->id,
            'provider' => 'mailtrap',
            'host' => 'sandbox.smtp.mailtrap.io',
            'port' => 2525,
            'username' => 'u1',
            'password' => 'secret-smtp-pass',
            'from_address' => 'noreply@example.com',
            'from_name' => 'RC Console',
            'encryption' => null,
            'is_active' => true,
        ])->assertOk()
            ->assertJsonPath('data.settings.has_password', true)
            ->assertJsonMissingPath('data.settings.password');

        $stored = OrganizationEmailSetting::query()
            ->where('organization_id', $ctx['company']->id)
            ->first();
        $this->assertNotNull($stored);
        $this->assertNotEquals('secret-smtp-pass', $stored->encrypted_password);

        $this->getJson('/api/settings/email?organization_id='.$ctx['company']->id)->assertOk()
            ->assertJsonPath('data.email.configured', true)
            ->assertJsonPath('data.email.effective_mail.source_organization_id', $ctx['company']->id);
    }

    public function test_providers_api_includes_driver_defaults_and_manual_only_flags(): void
    {
        $ctx = $this->seedHierarchy();
        Sanctum::actingAs($ctx['companyAdmin']);

        $res = $this->getJson('/api/settings/email/providers')->assertOk();
        $providers = $res->json('data.providers');
        $this->assertNotNull($providers);

        $gmail = collect($providers)->firstWhere('code', 'gmail');
        $this->assertNotNull($gmail);
        $this->assertArrayHasKey('manual_only', $gmail);
        $this->assertFalse($gmail['manual_only']);
        $this->assertSame('smtp', $gmail['defaults']['driver']);
        $this->assertSame('smtp.gmail.com', $gmail['defaults']['host']);
        $this->assertSame(587, $gmail['defaults']['port']);
        $this->assertSame('tls', $gmail['defaults']['encryption']);

        $custom = collect($providers)->firstWhere('code', 'custom');
        $this->assertNotNull($custom);
        $this->assertTrue($custom['manual_only']);
        $this->assertNull($custom['defaults']['host']);
        $this->assertNull($custom['defaults']['port']);
        $this->assertNull($custom['defaults']['encryption']);
        $this->assertSame('smtp', $custom['defaults']['driver']);

        $yahoo = collect($providers)->firstWhere('code', 'yahoo');
        $this->assertSame(465, $yahoo['defaults']['port']);
        $this->assertSame('ssl', $yahoo['defaults']['encryption']);
        $this->assertSame('smtp.mail.yahoo.com', $yahoo['defaults']['host']);

        $smtp = collect($providers)->firstWhere('code', 'smtp');
        $this->assertFalse($smtp['manual_only']);
        $this->assertNull($smtp['defaults']['host']);
        $this->assertSame(587, $smtp['defaults']['port']);
        $this->assertSame('tls', $smtp['defaults']['encryption']);
    }

    public function test_patch_provider_only_applies_gmail_preset(): void
    {
        $ctx = $this->seedHierarchy();
        Sanctum::actingAs($ctx['companyAdmin']);

        $this->patchJson('/api/settings/email', [
            'organization_id' => $ctx['company']->id,
            'provider' => 'gmail',
            'is_active' => true,
        ])->assertOk();

        $row = OrganizationEmailSetting::query()
            ->where('organization_id', $ctx['company']->id)
            ->firstOrFail();
        $this->assertSame('gmail', $row->provider);
        $this->assertSame('smtp', $row->driver);
        $this->assertSame('smtp.gmail.com', $row->host);
        $this->assertSame(587, $row->port);
        $this->assertSame('tls', $row->encryption);
    }

    public function test_patch_switching_provider_auto_applies_outlook_preset(): void
    {
        $ctx = $this->seedHierarchy();
        Sanctum::actingAs($ctx['companyAdmin']);
        $enc = app(PaymentSecretEncrypter::class);

        OrganizationEmailSetting::query()->create([
            'tenant_id' => $ctx['tenant']->id,
            'organization_id' => $ctx['company']->id,
            'provider' => 'gmail',
            'driver' => 'smtp',
            'host' => 'smtp.gmail.com',
            'port' => 587,
            'username' => 'u',
            'encrypted_password' => $enc->encrypt('p'),
            'from_address' => 'a@example.com',
            'from_name' => 'A',
            'encryption' => 'tls',
            'is_active' => true,
            'is_verified' => true,
            'created_by_user_id' => $ctx['companyAdmin']->id,
        ]);

        $this->patchJson('/api/settings/email', [
            'organization_id' => $ctx['company']->id,
            'provider' => 'outlook',
        ])->assertOk();

        $row = OrganizationEmailSetting::query()
            ->where('organization_id', $ctx['company']->id)
            ->firstOrFail();
        $this->assertSame('outlook', $row->provider);
        $this->assertSame('smtp.office365.com', $row->host);
        $this->assertSame(587, $row->port);
        $this->assertSame('tls', $row->encryption);
    }

    public function test_patch_custom_does_not_overwrite_connection_with_presets(): void
    {
        $ctx = $this->seedHierarchy();
        Sanctum::actingAs($ctx['companyAdmin']);
        $enc = app(PaymentSecretEncrypter::class);

        OrganizationEmailSetting::query()->create([
            'tenant_id' => $ctx['tenant']->id,
            'organization_id' => $ctx['company']->id,
            'provider' => 'gmail',
            'driver' => 'smtp',
            'host' => 'smtp.gmail.com',
            'port' => 587,
            'username' => 'u',
            'encrypted_password' => $enc->encrypt('p'),
            'from_address' => 'a@example.com',
            'from_name' => 'A',
            'encryption' => 'tls',
            'is_active' => true,
            'is_verified' => false,
            'created_by_user_id' => $ctx['companyAdmin']->id,
        ]);

        $this->patchJson('/api/settings/email', [
            'organization_id' => $ctx['company']->id,
            'provider' => 'custom',
        ])->assertOk();

        $row = OrganizationEmailSetting::query()
            ->where('organization_id', $ctx['company']->id)
            ->firstOrFail();
        $this->assertSame('custom', $row->provider);
        $this->assertSame('smtp.gmail.com', $row->host);
        $this->assertSame(587, $row->port);
        $this->assertSame('tls', $row->encryption);
    }

    public function test_patch_explicit_host_preserved_when_switching_provider(): void
    {
        $ctx = $this->seedHierarchy();
        Sanctum::actingAs($ctx['companyAdmin']);
        $enc = app(PaymentSecretEncrypter::class);

        OrganizationEmailSetting::query()->create([
            'tenant_id' => $ctx['tenant']->id,
            'organization_id' => $ctx['company']->id,
            'provider' => 'gmail',
            'driver' => 'smtp',
            'host' => 'smtp.gmail.com',
            'port' => 587,
            'username' => 'u',
            'encrypted_password' => $enc->encrypt('p'),
            'from_address' => 'a@example.com',
            'from_name' => 'A',
            'encryption' => 'tls',
            'is_active' => true,
            'is_verified' => true,
            'created_by_user_id' => $ctx['companyAdmin']->id,
        ]);

        $this->patchJson('/api/settings/email', [
            'organization_id' => $ctx['company']->id,
            'provider' => 'outlook',
            'host' => 'relay.customer.example.net',
        ])->assertOk();

        $row = OrganizationEmailSetting::query()
            ->where('organization_id', $ctx['company']->id)
            ->firstOrFail();
        $this->assertSame('outlook', $row->provider);
        $this->assertSame('relay.customer.example.net', $row->host);
        $this->assertSame(587, $row->port);
        $this->assertSame('tls', $row->encryption);
    }

    public function test_yahoo_preset_applied_on_provider_only_patch(): void
    {
        $ctx = $this->seedHierarchy();
        Sanctum::actingAs($ctx['companyAdmin']);

        $this->patchJson('/api/settings/email', [
            'organization_id' => $ctx['company']->id,
            'provider' => 'yahoo',
        ])->assertOk();

        $row = OrganizationEmailSetting::query()
            ->where('organization_id', $ctx['company']->id)
            ->firstOrFail();
        $this->assertSame('yahoo', $row->provider);
        $this->assertSame('smtp.mail.yahoo.com', $row->host);
        $this->assertSame(465, $row->port);
        $this->assertSame('ssl', $row->encryption);
    }

    public function test_partner_fallback_to_company_resolution(): void
    {
        $ctx = $this->seedHierarchy();
        $resolver = app(OrganizationMailResolverService::class);

        OrganizationEmailSetting::query()->create([
            'tenant_id' => $ctx['tenant']->id,
            'organization_id' => $ctx['company']->id,
            'provider' => 'smtp',
            'driver' => 'smtp',
            'host' => 'mail.company.test',
            'port' => 587,
            'username' => 'c',
            'encrypted_password' => app(PaymentSecretEncrypter::class)->encrypt('cpass'),
            'from_address' => 'c@example.com',
            'from_name' => 'Co',
            'reply_to' => null,
            'encryption' => 'tls',
            'is_active' => true,
            'is_verified' => true,
            'created_by_user_id' => $ctx['companyAdmin']->id,
        ]);

        $resolved = $resolver->resolveForTenantOrganization((int) $ctx['tenant']->id, (int) $ctx['partner']->id);
        $this->assertNotNull($resolved);
        $this->assertSame('mail.company.test', $resolved->host);
        $this->assertSame((int) $ctx['company']->id, $resolved->sourceOrganizationId);
    }

    public function test_reseller_prefers_own_setting_over_partner_and_company(): void
    {
        $ctx = $this->seedHierarchy();
        $enc = app(PaymentSecretEncrypter::class);
        $resolver = app(OrganizationMailResolverService::class);

        foreach ([$ctx['company'], $ctx['partner']] as $org) {
            OrganizationEmailSetting::query()->create([
                'tenant_id' => $ctx['tenant']->id,
                'organization_id' => $org->id,
                'provider' => 'smtp',
                'driver' => 'smtp',
                'host' => 'mail.'.$org->id.'.test',
                'port' => 587,
                'username' => 'u',
                'encrypted_password' => $enc->encrypt('p'),
                'from_address' => 'x@example.com',
                'from_name' => 'X',
                'encryption' => 'tls',
                'is_active' => true,
                'is_verified' => true,
                'created_by_user_id' => $ctx['companyAdmin']->id,
            ]);
        }

        OrganizationEmailSetting::query()->create([
            'tenant_id' => $ctx['tenant']->id,
            'organization_id' => $ctx['reseller']->id,
            'provider' => 'smtp',
            'driver' => 'smtp',
            'host' => 'mail.reseller.test',
            'port' => 587,
            'username' => 'r',
            'encrypted_password' => $enc->encrypt('rpass'),
            'from_address' => 'r@example.com',
            'from_name' => 'R',
            'encryption' => 'tls',
            'is_active' => true,
            'is_verified' => true,
            'created_by_user_id' => $ctx['companyAdmin']->id,
        ]);

        $resolved = $resolver->resolveForTenantOrganization((int) $ctx['tenant']->id, (int) $ctx['reseller']->id);
        $this->assertSame('mail.reseller.test', $resolved->host);
        $this->assertSame((int) $ctx['reseller']->id, $resolved->sourceOrganizationId);
    }

    public function test_partner_admin_can_save_partner_smtp_settings(): void
    {
        $ctx = $this->seedHierarchy();
        Sanctum::actingAs($ctx['partnerAdmin']);

        $this->patchJson('/api/settings/email', [
            'organization_id' => $ctx['partner']->id,
            'provider' => 'smtp',
            'host' => 'mail.partner-admin.test',
            'port' => 587,
            'username' => 'pa',
            'password' => 'partner-pass',
            'from_address' => 'partner@example.com',
            'from_name' => 'Partner',
            'encryption' => 'tls',
            'is_active' => true,
        ])->assertOk()->assertJsonPath('data.settings.host', 'mail.partner-admin.test');

        $this->assertDatabaseHas('organization_email_settings', [
            'tenant_id' => $ctx['tenant']->id,
            'organization_id' => $ctx['partner']->id,
            'host' => 'mail.partner-admin.test',
        ]);
    }

    public function test_reseller_admin_can_save_reseller_smtp_settings(): void
    {
        $ctx = $this->seedHierarchy();
        Sanctum::actingAs($ctx['resellerAdmin']);

        $this->patchJson('/api/settings/email', [
            'organization_id' => $ctx['reseller']->id,
            'provider' => 'smtp',
            'host' => 'mail.reseller-admin.test',
            'port' => 587,
            'username' => 'ra',
            'password' => 'reseller-pass',
            'from_address' => 'reseller@example.com',
            'from_name' => 'Reseller',
            'encryption' => 'tls',
            'is_active' => true,
        ])->assertOk()->assertJsonPath('data.settings.host', 'mail.reseller-admin.test');
    }

    public function test_reseller_fallback_to_partner_before_company(): void
    {
        $ctx = $this->seedHierarchy();
        $enc = app(PaymentSecretEncrypter::class);
        $resolver = app(OrganizationMailResolverService::class);

        foreach ([$ctx['company'], $ctx['partner']] as $org) {
            $host = $org->id === $ctx['company']->id ? 'mail.company-wins.test' : 'mail.partner-wins.test';
            OrganizationEmailSetting::query()->create([
                'tenant_id' => $ctx['tenant']->id,
                'organization_id' => $org->id,
                'provider' => 'smtp',
                'driver' => 'smtp',
                'host' => $host,
                'port' => 587,
                'username' => 'u',
                'encrypted_password' => $enc->encrypt('p'),
                'from_address' => 'x@example.com',
                'from_name' => 'X',
                'encryption' => 'tls',
                'is_active' => true,
                'is_verified' => true,
                'created_by_user_id' => $ctx['companyAdmin']->id,
            ]);
        }

        $resolved = $resolver->resolveForTenantOrganization((int) $ctx['tenant']->id, (int) $ctx['reseller']->id);
        $this->assertNotNull($resolved);
        $this->assertSame('mail.partner-wins.test', $resolved->host);
        $this->assertSame((int) $ctx['partner']->id, $resolved->sourceOrganizationId);
    }

    public function test_reseller_fallback_to_company_when_partner_and_reseller_missing(): void
    {
        $ctx = $this->seedHierarchy();
        $enc = app(PaymentSecretEncrypter::class);
        $resolver = app(OrganizationMailResolverService::class);

        OrganizationEmailSetting::query()->create([
            'tenant_id' => $ctx['tenant']->id,
            'organization_id' => $ctx['company']->id,
            'provider' => 'smtp',
            'driver' => 'smtp',
            'host' => 'mail.root.test',
            'port' => 587,
            'username' => 'c',
            'encrypted_password' => $enc->encrypt('c'),
            'from_address' => 'c@example.com',
            'from_name' => 'C',
            'encryption' => 'tls',
            'is_active' => true,
            'is_verified' => true,
            'created_by_user_id' => $ctx['companyAdmin']->id,
        ]);

        $resolved = $resolver->resolveForTenantOrganization((int) $ctx['tenant']->id, (int) $ctx['reseller']->id);
        $this->assertNotNull($resolved);
        $this->assertSame('mail.root.test', $resolved->host);
    }

    public function test_env_fallback_when_no_context_or_no_org_settings(): void
    {
        $resolver = app(OrganizationMailResolverService::class);
        $this->assertNull($resolver->resolveFromContext());

        $ctx = $this->seedHierarchy();
        $this->assertNull($resolver->resolveForTenantOrganization((int) $ctx['tenant']->id, (int) $ctx['reseller']->id));
    }

    public function test_tenant_isolation_on_access(): void
    {
        $a = $this->seedHierarchy('Tenant A');
        $b = $this->seedHierarchy('Tenant B');
        Sanctum::actingAs($a['companyAdmin']);

        $this->patchJson('/api/settings/email', [
            'organization_id' => $b['company']->id,
            'provider' => 'smtp',
            'host' => 'evil.test',
            'port' => 587,
            'is_active' => true,
        ])->assertStatus(422);
    }

    public function test_sibling_partner_cannot_manage_other_partner_email_settings(): void
    {
        $ctx = $this->seedHierarchy();
        Sanctum::actingAs($ctx['partnerAdmin']);

        $this->patchJson('/api/settings/email', [
            'organization_id' => $ctx['siblingPartner']->id,
            'provider' => 'smtp',
            'host' => 'mail.sib.test',
            'port' => 587,
            'is_active' => true,
        ])->assertStatus(422);
    }

    public function test_consultant_cannot_access_email_settings(): void
    {
        $ctx = $this->seedHierarchy();
        $consultant = $this->makeUser(
            $ctx['tenant']->id,
            Role::CODE_PARTNER_SALES_CONSULTANT,
            'pc-'.uniqid('', true).'@example.com',
            $ctx['partner']->id
        );
        Sanctum::actingAs($consultant);

        $this->getJson('/api/settings/email?organization_id='.$ctx['partner']->id)->assertStatus(403);
    }

    public function test_test_email_returns_422_when_no_smtp_configured(): void
    {
        $ctx = $this->seedHierarchy();
        Sanctum::actingAs($ctx['companyAdmin']);

        $this->postJson('/api/settings/email/test', [
            'organization_id' => $ctx['company']->id,
            'recipient_email' => 'qa@example.com',
        ])->assertStatus(422)->assertJsonPath('success', false);
    }

    public function test_test_email_endpoint_uses_mail_facade(): void
    {
        Mail::fake();
        $ctx = $this->seedHierarchy();
        Sanctum::actingAs($ctx['companyAdmin']);

        $enc = app(PaymentSecretEncrypter::class);
        OrganizationEmailSetting::query()->create([
            'tenant_id' => $ctx['tenant']->id,
            'organization_id' => $ctx['company']->id,
            'provider' => 'smtp',
            'driver' => 'smtp',
            'host' => 'mail.test',
            'port' => 587,
            'username' => 'u',
            'encrypted_password' => $enc->encrypt('p'),
            'from_address' => 'a@example.com',
            'from_name' => 'A',
            'encryption' => 'tls',
            'is_active' => true,
            'is_verified' => false,
            'created_by_user_id' => $ctx['companyAdmin']->id,
        ]);

        $response = $this->postJson('/api/settings/email/test', [
            'organization_id' => $ctx['company']->id,
            'recipient_email' => 'qa@example.com',
        ]);
        $response->assertOk()->assertJsonPath('data.success', true);
    }

    public function test_registration_still_dispatches_verification_mail(): void
    {
        Notification::fake();

        $email = 'reg-'.uniqid('', true).'@example.com';
        $this->postJson('/api/register', [
            'first_name' => 'New',
            'last_name' => 'Registrant',
            'company_name' => 'Reg Co '.uniqid('', true),
            'email' => $email,
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertCreated();

        $user = User::query()->where('email', $email)->firstOrFail();
        Notification::assertSentTo($user, VerifyEmailNotification::class);
    }

    public function test_queued_notification_context_stack_for_user_notifiable(): void
    {
        $ctx = $this->seedHierarchy();
        $user = $ctx['resellerAdmin'];
        $resolver = app(OrganizationMailResolverService::class);

        $tenantId = (int) $user->tenant_id;
        $orgId = $resolver->resolveDefaultOrganizationIdForUser($user);
        $this->assertNotNull($orgId);

        OrganizationMailContext::run($tenantId, $orgId, function () use ($tenantId): void {
            $this->assertSame($tenantId, OrganizationMailContext::currentTenantId());
            $this->assertNotNull(OrganizationMailContext::currentOrganizationId());
        });
    }

    public function test_regression_smoke_modules(): void
    {
        $ctx = $this->seedHierarchy();
        Sanctum::actingAs($ctx['companyAdmin']);

        $this->getJson('/api/contacts')->assertOk();
        $this->getJson('/api/demo-links')->assertOk();
        $this->getJson('/api/tasks')->assertOk();
        $this->getJson('/api/prm/commission-accruals')->assertOk();
    }

    /**
     * @return array<string, mixed>
     */
    private function seedHierarchy(?string $tenantName = 'Mail Tenant'): array
    {
        $tenant = Tenant::query()->create(['name' => $tenantName, 'status' => Tenant::STATUS_ACTIVE]);
        $companyAdmin = User::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'CA',
            'email' => 'ca-'.uniqid('', true).'@example.com',
            'password' => 'secret123',
            'role' => Role::CODE_COMPANY_ADMIN,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);
        $company = Organization::query()->create([
            'tenant_id' => $tenant->id,
            'type' => Organization::TYPE_COMPANY,
            'legal_name' => 'Co',
            'display_name' => 'Co',
            'onboarding_status' => Organization::ONBOARDING_ACTIVE,
            'status' => Organization::STATUS_ACTIVE,
            'created_by_user_id' => $companyAdmin->id,
            'updated_by_user_id' => $companyAdmin->id,
        ]);
        $partner = Organization::query()->create([
            'tenant_id' => $tenant->id,
            'parent_organization_id' => $company->id,
            'type' => Organization::TYPE_PARTNER,
            'legal_name' => 'Partner',
            'display_name' => 'Partner',
            'onboarding_status' => Organization::ONBOARDING_ACTIVE,
            'status' => Organization::STATUS_ACTIVE,
            'created_by_user_id' => $companyAdmin->id,
            'updated_by_user_id' => $companyAdmin->id,
        ]);
        $siblingPartner = Organization::query()->create([
            'tenant_id' => $tenant->id,
            'parent_organization_id' => $company->id,
            'type' => Organization::TYPE_PARTNER,
            'legal_name' => 'Sibling Partner',
            'display_name' => 'Sibling Partner',
            'onboarding_status' => Organization::ONBOARDING_ACTIVE,
            'status' => Organization::STATUS_ACTIVE,
            'created_by_user_id' => $companyAdmin->id,
            'updated_by_user_id' => $companyAdmin->id,
        ]);
        $reseller = Organization::query()->create([
            'tenant_id' => $tenant->id,
            'parent_organization_id' => $partner->id,
            'type' => Organization::TYPE_RESELLER,
            'channel_mode' => Organization::CHANNEL_MODE_PARTNER_MANAGED,
            'legal_name' => 'Reseller',
            'display_name' => 'Reseller',
            'onboarding_status' => Organization::ONBOARDING_ACTIVE,
            'status' => Organization::STATUS_ACTIVE,
            'created_by_user_id' => $companyAdmin->id,
            'updated_by_user_id' => $companyAdmin->id,
        ]);

        $partnerAdmin = $this->makeUser($tenant->id, Role::CODE_PARTNER_ADMIN, 'pa-'.uniqid('', true).'@example.com', $partner->id);
        $resellerAdmin = $this->makeUser($tenant->id, Role::CODE_RESELLER_ADMIN, 'ra-'.uniqid('', true).'@example.com', $reseller->id);

        return compact(
            'tenant',
            'companyAdmin',
            'company',
            'partner',
            'siblingPartner',
            'reseller',
            'partnerAdmin',
            'resellerAdmin',
        );
    }

    private function makeUser(int $tenantId, string $role, string $email, ?int $orgId): User
    {
        $user = User::query()->create([
            'tenant_id' => $tenantId,
            'name' => 'U '.$email,
            'email' => $email,
            'password' => 'secret123',
            'role' => $role,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);
        if ($orgId !== null) {
            UserOrganizationAssignment::query()->create([
                'user_id' => $user->id,
                'organization_id' => $orgId,
            ]);
        }

        return $user->fresh(['organizationAssignment.organization']);
    }
}
