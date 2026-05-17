<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\InAppNotification;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Notifications\InAppNotificationTemplateKeys;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\Concerns\BuildsTenantUsersForNotifications;
use Tests\TestCase;

/**
 * PHPUnit sets QUEUE_CONNECTION=sync — persists inbox rows in-process during HTTP tests.
 *
 * Production template keys align with constants (e.g. contacts.assigned, not "contact.assigned").
 */
class InAppNotificationsApiFullTest extends TestCase
{
    use BuildsTenantUsersForNotifications;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_notifications_index_authenticated_returns_pagination_structure(): void
    {
        $tenant = $this->makeActiveTenant();
        $user = $this->makeCompanyAdmin($tenant);
        Sanctum::actingAs($user);

        InAppNotification::query()->create([
            'tenant_id' => $tenant->id,
            'recipient_user_id' => $user->id,
            'actor_user_id' => null,
            'notification_type' => InAppNotificationTemplateKeys::TASKS_OVERDUE,
            'category' => 'tasks',
            'title' => 'One',
            'message' => 'Msg',
            'action_url' => null,
            'entity_type' => null,
            'entity_id' => null,
            'priority' => 'normal',
            'metadata' => null,
            'is_read' => false,
        ]);

        $this->getJson('/api/notifications?per_page=5')->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'items',
                    'pagination' => [
                        'current_page',
                        'per_page',
                        'total',
                        'last_page',
                    ],
                ],
            ])
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.pagination.per_page', 5);
        $payload = $this->getJson('/api/notifications?per_page=5')->decodeResponseJson()->json('data.items.0');
        $this->assertArrayHasKey('id', $payload);
        $this->assertArrayHasKey('notification_type', $payload);
    }

    public function test_filter_read_unread_category_and_notification_type(): void
    {
        Carbon::setTestNow('2026-06-01 14:30:00');
        $tenant = $this->makeActiveTenant();
        $admin = $this->makeCompanyAdmin($tenant);
        Sanctum::actingAs($admin);

        foreach ([false, false, true] as $read) {
            InAppNotification::query()->create([
                'tenant_id' => $tenant->id,
                'recipient_user_id' => $admin->id,
                'actor_user_id' => null,
                'notification_type' => InAppNotificationTemplateKeys::QUOTES_SENT,
                'category' => 'quotes',
                'title' => 'sent',
                'message' => 'm',
                'action_url' => null,
                'entity_type' => null,
                'entity_id' => null,
                'priority' => 'normal',
                'metadata' => null,
                'is_read' => $read,
                'read_at' => $read ? now() : null,
            ]);
        }
        InAppNotification::query()->create([
            'tenant_id' => $tenant->id,
            'recipient_user_id' => $admin->id,
            'notification_type' => InAppNotificationTemplateKeys::TASKS_ASSIGNED,
            'category' => 'tasks',
            'title' => 'task',
            'message' => 'm',
            'action_url' => null,
            'entity_type' => null,
            'entity_id' => null,
            'priority' => 'normal',
            'metadata' => null,
            'is_read' => false,
        ]);

        $this->getJson('/api/notifications?read=1')->assertOk()->assertJsonPath('data.pagination.total', 1);
        $this->getJson('/api/notifications?read=0')->assertOk()->assertJsonPath('data.pagination.total', 3);
        $this->getJson('/api/notifications?unread=1')->assertOk()->assertJsonPath('data.pagination.total', 3);

        $this->getJson('/api/notifications?category=tasks')->assertOk()->assertJsonPath('data.pagination.total', 1);
        $this->getJson('/api/notifications?notification_type='.urlencode(InAppNotificationTemplateKeys::QUOTES_SENT))
            ->assertOk()->assertJsonPath('data.pagination.total', 3);
        $this->getJson('/api/notifications?category=quotes&notification_type='.urlencode(InAppNotificationTemplateKeys::TASKS_ASSIGNED))
            ->assertOk()->assertJsonPath('data.pagination.total', 0);
    }

    public function test_list_returns_only_rows_for_authenticated_recipient_and_tenant_isolation(): void
    {
        $tenantA = $this->makeActiveTenant();
        $alice = User::query()->create([
            'tenant_id' => $tenantA->id,
            'name' => 'Alice',
            'email' => 'alice-'.uniqid('', true).'@example.com',
            'password' => 'secret123',
            'role' => Role::CODE_COMPANY_ADMIN,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);

        $bob = User::query()->create([
            'tenant_id' => $tenantA->id,
            'name' => 'Bob',
            'email' => 'bob-'.uniqid('', true).'@example.com',
            'password' => 'secret123',
            'role' => Role::CODE_COMPANY_ADMIN,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);

        InAppNotification::query()->create([
            'tenant_id' => $tenantA->id,
            'recipient_user_id' => $bob->id,
            'notification_type' => InAppNotificationTemplateKeys::DEALS_ASSIGNED,
            'category' => 'deals',
            'title' => 'for bob',
            'message' => 'm',
            'action_url' => null,
            'entity_type' => null,
            'entity_id' => null,
            'priority' => 'normal',
            'metadata' => null,
            'is_read' => false,
        ]);

        Sanctum::actingAs($alice);
        $this->getJson('/api/notifications')->assertOk()->assertJsonPath('data.pagination.total', 0);

        $tenantB = $this->makeActiveTenant('Other');
        User::query()->create([
            'tenant_id' => $tenantB->id,
            'name' => 'Foreign',
            'email' => 'for-tenant-b-'.uniqid('', true).'@example.com',
            'password' => 'secret123',
            'role' => Role::CODE_COMPANY_ADMIN,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);
        InAppNotification::query()->create([
            'tenant_id' => $tenantB->id,
            'recipient_user_id' => $alice->id,
            'notification_type' => InAppNotificationTemplateKeys::QUOTES_SENT,
            'category' => 'quotes',
            'title' => 'cross',
            'message' => 'm',
            'action_url' => null,
            'entity_type' => null,
            'entity_id' => null,
            'priority' => 'normal',
            'metadata' => null,
            'is_read' => false,
        ]);

        $this->getJson('/api/notifications')->assertOk()->assertJsonPath('data.pagination.total', 0);
    }

    public function test_unread_count_respects_reader_tenant_users_and_read_state(): void
    {
        $tenant = $this->makeActiveTenant();
        $owner = $this->makeCompanyAdmin($tenant);
        $other = User::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Other',
            'email' => 'other-'.uniqid('', true).'@example.com',
            'password' => 'secret123',
            'role' => Role::CODE_COMPANY_ADMIN,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);

        InAppNotification::query()->create([
            'tenant_id' => $tenant->id,
            'recipient_user_id' => $owner->id,
            'notification_type' => InAppNotificationTemplateKeys::USERS_INVITED,
            'category' => 'users',
            'title' => 'u1',
            'message' => 'm',
            'action_url' => null,
            'entity_type' => null,
            'entity_id' => null,
            'priority' => 'normal',
            'metadata' => null,
            'is_read' => false,
        ]);
        InAppNotification::query()->create([
            'tenant_id' => $tenant->id,
            'recipient_user_id' => $owner->id,
            'notification_type' => InAppNotificationTemplateKeys::USERS_INVITED,
            'category' => 'users',
            'title' => 'read',
            'message' => 'm',
            'action_url' => null,
            'entity_type' => null,
            'entity_id' => null,
            'priority' => 'normal',
            'metadata' => null,
            'is_read' => true,
            'read_at' => now(),
        ]);
        InAppNotification::query()->create([
            'tenant_id' => $tenant->id,
            'recipient_user_id' => $other->id,
            'notification_type' => InAppNotificationTemplateKeys::USERS_INVITED,
            'category' => 'users',
            'title' => 'noise',
            'message' => 'm',
            'action_url' => null,
            'entity_type' => null,
            'entity_id' => null,
            'priority' => 'normal',
            'metadata' => null,
            'is_read' => false,
        ]);

        Sanctum::actingAs($owner);
        $this->getJson('/api/notifications/unread-count')->assertOk()->assertJsonPath('data.count', 1);
    }

    public function test_patch_notification_read_updates_row_and_returns_serialized_notification(): void
    {
        $tenant = $this->makeActiveTenant();
        $user = $this->makeCompanyAdmin($tenant);
        Sanctum::actingAs($user);

        Carbon::setTestNow('2026-04-02 09:05:02');
        $row = InAppNotification::query()->create([
            'tenant_id' => $tenant->id,
            'recipient_user_id' => $user->id,
            'notification_type' => InAppNotificationTemplateKeys::TASKS_DUE_TODAY,
            'category' => 'tasks',
            'title' => 'Due',
            'message' => 'm',
            'action_url' => null,
            'entity_type' => null,
            'entity_id' => null,
            'priority' => 'normal',
            'metadata' => null,
            'is_read' => false,
        ]);

        $marked = $this->patchJson("/api/notifications/{$row->id}/read")->assertOk()
            ->assertJsonPath('data.notification.is_read', true);

        $this->assertNotNull($marked->json('data.notification.read_at'));

        $row->refresh();
        $this->assertTrue((bool) $row->is_read);
        $this->assertNotNull($row->read_at);
    }

    public function test_patch_read_foreign_recipient_returns_404_even_same_primary_key_collision(): void
    {
        $tenant = $this->makeActiveTenant();
        $alice = $this->makeCompanyAdmin($tenant);
        $bob = User::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Bob',
            'email' => 'bob-pr-'.uniqid('', true).'@example.com',
            'password' => 'secret123',
            'role' => Role::CODE_COMPANY_ADMIN,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);

        $row = InAppNotification::query()->create([
            'tenant_id' => $tenant->id,
            'recipient_user_id' => $bob->id,
            'notification_type' => InAppNotificationTemplateKeys::QUOTES_REJECTED,
            'category' => 'quotes',
            'title' => 'r',
            'message' => 'm',
            'action_url' => null,
            'entity_type' => null,
            'entity_id' => null,
            'priority' => 'normal',
            'metadata' => null,
            'is_read' => false,
        ]);

        Sanctum::actingAs($alice);
        $this->patchJson("/api/notifications/{$row->id}/read")->assertNotFound();
    }

    public function test_patch_read_foreign_tenant_returns_404(): void
    {
        $ta = $this->makeActiveTenant('A');
        $tb = Tenant::factory()->create(['name' => 'Tenant B']);

        $userA = $this->makeCompanyAdmin($ta);
        Sanctum::actingAs($userA);

        $userB = $this->makeCompanyAdmin($tb);
        $row = InAppNotification::query()->create([
            'tenant_id' => $tb->id,
            'recipient_user_id' => $userB->id,
            'notification_type' => InAppNotificationTemplateKeys::LICENSES_ACTIVATED,
            'category' => 'licenses',
            'title' => 'Lic',
            'message' => 'm',
            'action_url' => null,
            'entity_type' => null,
            'entity_id' => null,
            'priority' => 'normal',
            'metadata' => null,
            'is_read' => false,
        ]);

        $this->patchJson("/api/notifications/{$row->id}/read")->assertNotFound();
    }

    public function test_patch_read_all_marks_only_current_user_unreads_and_returns_updated_count(): void
    {
        Carbon::setTestNow('2026-05-20 08:00:00');
        $tenant = $this->makeActiveTenant();
        $actor = User::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Actor',
            'email' => 'actor-readall-'.uniqid('', true).'@example.com',
            'password' => 'secret123',
            'role' => Role::CODE_COMPANY_ADMIN,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);

        $other = User::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Other SA',
            'email' => 'other-readall-'.uniqid('', true).'@example.com',
            'password' => 'secret123',
            'role' => Role::CODE_COMPANY_ADMIN,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);

        foreach ([false, false, true] as $read) {
            InAppNotification::query()->create([
                'tenant_id' => $tenant->id,
                'recipient_user_id' => $actor->id,
                'notification_type' => InAppNotificationTemplateKeys::PARTNERS_SUBMITTED,
                'category' => 'orgs',
                'title' => 'p',
                'message' => 'm',
                'action_url' => null,
                'entity_type' => null,
                'entity_id' => null,
                'priority' => 'normal',
                'metadata' => null,
                'is_read' => $read,
                'read_at' => $read ? now() : null,
            ]);
        }
        InAppNotification::query()->create([
            'tenant_id' => $tenant->id,
            'recipient_user_id' => $other->id,
            'notification_type' => InAppNotificationTemplateKeys::PARTNERS_SUBMITTED,
            'category' => 'orgs',
            'title' => 'outside',
            'message' => 'm',
            'action_url' => null,
            'entity_type' => null,
            'entity_id' => null,
            'priority' => 'normal',
            'metadata' => null,
            'is_read' => false,
        ]);

        Sanctum::actingAs($actor);
        $this->patchJson('/api/notifications/read-all')->assertOk()->assertJsonPath('data.updated', 2);

        $this->assertSame(3, InAppNotification::query()->where('recipient_user_id', $actor->id)->count());
        $this->assertSame(0, InAppNotification::query()->where('recipient_user_id', $actor->id)->where('is_read', false)->count());
        $this->assertSame(1, InAppNotification::query()->where('recipient_user_id', $other->id)->where('is_read', false)->count());
    }

    public function test_plain_user_without_notifications_view_gets_403_on_inbox_and_count(): void
    {
        $tenant = $this->makeActiveTenant();
        $admin = $this->makeCompanyAdmin($tenant);
        $plain = $this->makeCrmPlainUser($tenant);

        Sanctum::actingAs($plain);
        $this->getJson('/api/notifications')->assertForbidden()
            ->assertJsonPath('message', 'You are not allowed to view notifications.');
        $this->getJson('/api/notifications/unread-count')->assertForbidden()
            ->assertJsonPath('success', false);
        $this->patchJson('/api/notifications/read-all')->assertForbidden();
        $this->patchJson('/api/notifications/999999/read')->assertForbidden();

        $row = InAppNotification::query()->create([
            'tenant_id' => $tenant->id,
            'recipient_user_id' => $admin->id,
            'notification_type' => InAppNotificationTemplateKeys::TASKS_ASSIGNED,
            'category' => 'tasks',
            'title' => 't',
            'message' => 'm',
            'action_url' => null,
            'entity_type' => null,
            'entity_id' => null,
            'priority' => 'normal',
            'metadata' => null,
            'is_read' => false,
        ]);

        Sanctum::actingAs($plain);
        $this->patchJson("/api/notifications/{$row->id}/read")->assertForbidden();
    }
}
