<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\Contact;
use App\Models\Deal;
use App\Models\InAppNotification;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\Role;
use App\Models\User;
use App\Support\Notifications\InAppNotificationTemplateKeys;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\Concerns\BuildsTenantUsersForNotifications;
use Tests\TestCase;

class DealNotificationTest extends TestCase
{
    use BuildsTenantUsersForNotifications;
    use RefreshDatabase;

    /**
     * @return array{
     *     tenant: \App\Models\Tenant,
     *     creator: User,
     *     owner: User,
     *     pipeline: Pipeline,
     *     leadStage: PipelineStage,
     *     negotiationStage: PipelineStage
     * }
     */
    private function bootstrapDealActors(): array
    {
        $tenant = $this->makeActiveTenant();
        $creator = User::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Creator',
            'email' => 'deal-cr-'.uniqid('', true).'@example.com',
            'password' => 'secret123',
            'role' => Role::CODE_COMPANY_ADMIN,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);
        $owner = User::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Owner',
            'email' => 'deal-own-'.uniqid('', true).'@example.com',
            'password' => 'secret123',
            'role' => Role::CODE_COMPANY_ADMIN,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);

        $pipeline = Pipeline::query()->create([
            'tenant_id' => $tenant->id,
            'created_by_user_id' => $creator->id,
            'name' => 'Main',
            'status' => Pipeline::STATUS_ACTIVE,
        ]);
        $leadStage = PipelineStage::query()->create([
            'tenant_id' => $tenant->id,
            'pipeline_id' => $pipeline->id,
            'name' => 'Lead Phase',
            'stage_order' => 1,
            'status' => PipelineStage::STATUS_ACTIVE,
        ]);
        $negotiationStage = PipelineStage::query()->create([
            'tenant_id' => $tenant->id,
            'pipeline_id' => $pipeline->id,
            'name' => 'Negotiation',
            'stage_order' => 2,
            'status' => PipelineStage::STATUS_ACTIVE,
        ]);

        return compact('tenant', 'creator', 'owner', 'pipeline', 'leadStage', 'negotiationStage');
    }

    public function test_deal_created_for_other_owner_dispatches_deals_assigned(): void
    {
        extract($this->bootstrapDealActors());

        $contact = Contact::query()->create([
            'tenant_id' => $tenant->id,
            'created_by_user_id' => $creator->id,
            'updated_by_user_id' => $creator->id,
            'lifecycle_stage' => Contact::STAGE_LEAD,
            'first_name' => 'Zed',
            'email' => 'zed-'.uniqid('', true).'@example.com',
        ]);

        Sanctum::actingAs($creator);
        $this->postJson('/api/deals', [
            'name' => 'Big',
            'contact_id' => $contact->id,
            'owner_user_id' => $owner->id,
            'pipeline_id' => $pipeline->id,
            'pipeline_stage_id' => $leadStage->id,
        ])->assertCreated();

        $this->assertDatabaseHas('in_app_notifications', [
            'tenant_id' => $tenant->id,
            'recipient_user_id' => $owner->id,
            'notification_type' => InAppNotificationTemplateKeys::DEALS_ASSIGNED,
        ]);
    }

    public function test_deal_owner_change_dispatches_deals_owner_changed(): void
    {
        extract($this->bootstrapDealActors());

        $nextOwner = User::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Next Owner',
            'email' => 'deal-no-'.uniqid('', true).'@example.com',
            'password' => 'secret123',
            'role' => Role::CODE_COMPANY_ADMIN,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);

        $contact = Contact::query()->create([
            'tenant_id' => $tenant->id,
            'created_by_user_id' => $creator->id,
            'updated_by_user_id' => $creator->id,
            'lifecycle_stage' => Contact::STAGE_LEAD,
            'first_name' => 'Yve',
            'email' => 'yve-'.uniqid('', true).'@example.com',
        ]);

        Sanctum::actingAs($creator);
        $dealId = (int) $this->postJson('/api/deals', [
            'name' => 'Deal handoff',
            'contact_id' => $contact->id,
            'owner_user_id' => $owner->id,
            'pipeline_id' => $pipeline->id,
            'pipeline_stage_id' => $leadStage->id,
        ])->assertCreated()->json('data.deal.id');

        $this->putJson("/api/deals/{$dealId}", [
            'owner_user_id' => $nextOwner->id,
        ])->assertOk();

        $this->assertDatabaseHas('in_app_notifications', [
            'tenant_id' => $tenant->id,
            'recipient_user_id' => $nextOwner->id,
            'notification_type' => InAppNotificationTemplateKeys::DEALS_OWNER_CHANGED,
        ]);
    }

    public function test_negotiation_stage_move_dispatches_deals_stage_changed(): void
    {
        extract($this->bootstrapDealActors());

        $finance = User::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'FA',
            'email' => 'fa-'.uniqid('', true).'@example.com',
            'password' => 'secret123',
            'role' => Role::CODE_FINANCE_ADMIN,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);

        $contact = Contact::query()->create([
            'tenant_id' => $tenant->id,
            'created_by_user_id' => $creator->id,
            'updated_by_user_id' => $creator->id,
            'lifecycle_stage' => Contact::STAGE_LEAD,
            'first_name' => 'Xu',
            'email' => 'xu-'.uniqid('', true).'@example.com',
        ]);

        Sanctum::actingAs($creator);
        $dealId = (int) $this->postJson('/api/deals', [
            'name' => 'Stage radar',
            'contact_id' => $contact->id,
            'owner_user_id' => $owner->id,
            'pipeline_id' => $pipeline->id,
            'pipeline_stage_id' => $leadStage->id,
        ])->assertCreated()->json('data.deal.id');

        $this->postJson("/api/deals/{$dealId}/move-stage", [
            'pipeline_stage_id' => $negotiationStage->id,
            'notes' => null,
        ])->assertOk();

        $recipientIds = InAppNotification::query()
            ->where('tenant_id', $tenant->id)
            ->where('notification_type', InAppNotificationTemplateKeys::DEALS_STAGE_CHANGED)
            ->pluck('recipient_user_id')
            ->all();

        $this->assertContains($owner->id, $recipientIds);
        $this->assertContains($finance->id, $recipientIds);
        $this->assertContains($creator->id, $recipientIds);
    }

    public function test_marking_deal_won_dispatches_deals_won_fanout(): void
    {
        extract($this->bootstrapDealActors());

        $finance = User::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Fin Win',
            'email' => 'finw-'.uniqid('', true).'@example.com',
            'password' => 'secret123',
            'role' => Role::CODE_FINANCE_ADMIN,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);

        $contact = Contact::query()->create([
            'tenant_id' => $tenant->id,
            'created_by_user_id' => $creator->id,
            'updated_by_user_id' => $creator->id,
            'lifecycle_stage' => Contact::STAGE_LEAD,
            'first_name' => 'Win',
            'email' => 'win-'.uniqid('', true).'@example.com',
        ]);

        Sanctum::actingAs($creator);
        $dealId = (int) $this->postJson('/api/deals', [
            'name' => 'Closer',
            'contact_id' => $contact->id,
            'owner_user_id' => $owner->id,
            'pipeline_id' => $pipeline->id,
            'pipeline_stage_id' => $leadStage->id,
        ])->assertCreated()->json('data.deal.id');

        $this->patchJson("/api/deals/{$dealId}/status", [
            'status' => 'won',
        ])->assertOk();

        $this->assertSame(Deal::STATUS_WON, (int) Deal::query()->findOrFail($dealId)->status);

        $recipients = InAppNotification::query()
            ->where('tenant_id', $tenant->id)
            ->where('notification_type', InAppNotificationTemplateKeys::DEALS_WON)
            ->pluck('recipient_user_id')
            ->unique()
            ->all();

        foreach ([$owner->id, $finance->id, $creator->id] as $uid) {
            $this->assertContains($uid, $recipients);
        }
    }

    public function test_marking_deal_lost_dispatches_deals_lost(): void
    {
        extract($this->bootstrapDealActors());

        $finance = User::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Fin Lost',
            'email' => 'fin-l-'.uniqid('', true).'@example.com',
            'password' => 'secret123',
            'role' => Role::CODE_FINANCE_ADMIN,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);

        $contact = Contact::query()->create([
            'tenant_id' => $tenant->id,
            'created_by_user_id' => $creator->id,
            'updated_by_user_id' => $creator->id,
            'lifecycle_stage' => Contact::STAGE_LEAD,
            'first_name' => 'Lost',
            'email' => 'lost-'.uniqid('', true).'@example.com',
        ]);

        Sanctum::actingAs($creator);
        $dealId = (int) $this->postJson('/api/deals', [
            'name' => 'Goner',
            'contact_id' => $contact->id,
            'owner_user_id' => $owner->id,
            'pipeline_id' => $pipeline->id,
            'pipeline_stage_id' => $leadStage->id,
        ])->assertCreated()->json('data.deal.id');

        $this->patchJson("/api/deals/{$dealId}/status", [
            'status' => 'lost',
        ])->assertOk();

        foreach ([$owner->id, $finance->id, $creator->id] as $uid) {
            $this->assertDatabaseHas('in_app_notifications', [
                'tenant_id' => $tenant->id,
                'recipient_user_id' => $uid,
                'notification_type' => InAppNotificationTemplateKeys::DEALS_LOST,
            ]);
        }
    }
}
