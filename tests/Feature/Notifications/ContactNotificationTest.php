<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\Role;
use App\Models\User;
use App\Support\Notifications\InAppNotificationTemplateKeys;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\Concerns\BuildsTenantUsersForNotifications;
use Tests\TestCase;

/**
 * Covers {@see InAppNotificationTemplateKeys::CONTACTS_ASSIGNED} and CONTACTS_REASSIGNED.
 */
class ContactNotificationTest extends TestCase
{
    use BuildsTenantUsersForNotifications;
    use RefreshDatabase;

    public function test_contact_assigned_persists_contacts_assigned_row(): void
    {
        $tenant = $this->makeActiveTenant();
        $actor = $this->makeCompanyAdmin($tenant);
        $assignee = User::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Assignee',
            'email' => 'cnt-asg-'.uniqid('', true).'@example.com',
            'password' => 'secret123',
            'role' => Role::CODE_COMPANY_ADMIN,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);

        Sanctum::actingAs($actor);
        $this->postJson('/api/contacts', [
            'first_name' => 'Ann',
            'last_name' => 'Lee',
            'email' => 'ann-'.uniqid('', true).'@example.com',
            'assigned_user_id' => $assignee->id,
        ])->assertCreated();

        $this->assertDatabaseHas('in_app_notifications', [
            'tenant_id' => $tenant->id,
            'recipient_user_id' => $assignee->id,
            'notification_type' => InAppNotificationTemplateKeys::CONTACTS_ASSIGNED,
        ]);
    }

    public function test_contact_reassigned_persists_contacts_reassigned_row(): void
    {
        $tenant = $this->makeActiveTenant();
        $actor = $this->makeCompanyAdmin($tenant);

        $userA = User::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'UA',
            'email' => 'ua-'.uniqid('', true).'@example.com',
            'password' => 'secret123',
            'role' => Role::CODE_COMPANY_ADMIN,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);

        $userB = User::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'UB',
            'email' => 'ub-'.uniqid('', true).'@example.com',
            'password' => 'secret123',
            'role' => Role::CODE_COMPANY_ADMIN,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);

        Sanctum::actingAs($actor);
        $cid = (int) $this->postJson('/api/contacts', [
            'first_name' => 'Rob',
            'email' => 'rob-'.uniqid('', true).'@example.com',
            'assigned_user_id' => $userA->id,
        ])->assertCreated()->json('data.contact.id');

        $this->putJson("/api/contacts/{$cid}", [
            'assigned_user_id' => $userB->id,
        ])->assertOk();

        $this->assertDatabaseHas('in_app_notifications', [
            'tenant_id' => $tenant->id,
            'recipient_user_id' => $userB->id,
            'notification_type' => InAppNotificationTemplateKeys::CONTACTS_REASSIGNED,
        ]);
    }
}
