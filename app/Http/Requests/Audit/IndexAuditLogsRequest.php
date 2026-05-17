<?php

declare(strict_types=1);

namespace App\Http\Requests\Audit;

use Illuminate\Foundation\Http\FormRequest;

final class IndexAuditLogsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'tenant_id' => ['nullable', 'integer', 'exists:tenants,id'],
            'date_from' => ['nullable', 'string'],
            'date_to' => ['nullable', 'string'],
            'module' => ['nullable', 'string', 'max:80'],
            'actor_user_id' => ['nullable', 'integer', 'min:1'],
            'event_key' => ['nullable', 'string', 'max:160'],
            'entity_type' => ['nullable', 'string', 'max:80'],
            'entity_id' => ['nullable', 'integer', 'min:0'],
            'organization_id' => ['nullable', 'integer', 'min:1'],
            'source' => ['nullable', 'string', 'max:40'],
            'include_archived' => ['nullable', 'boolean'],
            'include_deal_histories' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
