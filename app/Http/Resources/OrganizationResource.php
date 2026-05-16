<?php

namespace App\Http\Resources;

use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Organization */
class OrganizationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'parent_organization_id' => $this->parent_organization_id,
            'type' => $this->type,
            'channel_mode' => $this->channel_mode,
            'legal_name' => $this->legal_name,
            'display_name' => $this->display_name,
            'registration_number' => $this->registration_number,
            'tax_number' => $this->tax_number,
            'email' => $this->email,
            'phone' => $this->phone,
            'website' => $this->website,
            'address_line_1' => $this->address_line_1,
            'address_line_2' => $this->address_line_2,
            'city' => $this->city,
            'state' => $this->state,
            'country' => $this->country,
            'postal_code' => $this->postal_code,
            'onboarding_status' => $this->onboarding_status,
            'status' => $this->status,
            'credit_limit' => $this->credit_limit,
            'metadata' => $this->metadata ?? (object) [],
            'parent' => $this->whenLoaded('parentOrganization', fn (): ?array => $this->parentOrganization ? [
                'id' => $this->parentOrganization->id,
                'type' => $this->parentOrganization->type,
                'display_name' => $this->parentOrganization->display_name,
            ] : null),
            'children_count' => $this->whenLoaded('childOrganizations', fn (): int => $this->childOrganizations->count()),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
