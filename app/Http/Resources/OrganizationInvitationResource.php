<?php

namespace App\Http\Resources;

use App\Models\OrganizationInvitation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin OrganizationInvitation */
class OrganizationInvitationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'email' => $this->email,
            'role_code' => $this->role_code,
            'status' => $this->status,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'last_sent_at' => $this->last_sent_at?->toIso8601String(),
            'send_count' => $this->send_count,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
