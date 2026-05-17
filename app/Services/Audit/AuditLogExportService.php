<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Models\User;
use Illuminate\Support\Facades\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AuditLogExportService
{
    public function __construct(private readonly AuditLogQueryService $queryService) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function streamCsv(User $viewer, array $filters): StreamedResponse
    {
        /** @var int $max */
        $max = (int) config('audit.csv_max_rows_inline', 10000);
        $filters = array_merge($filters, ['include_deal_histories' => $filters['include_deal_histories'] ?? true]);
        $rows = $this->queryService->collectForCsv($viewer, $filters, $max);

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="audit-export-'.now()->format('Y-m-d-His').'.csv"',
        ];

        return Response::streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }

            fputcsv($out, [
                'public_id',
                'stream',
                'occurred_at',
                'tenant_id',
                'organization_id',
                'event_key',
                'module',
                'action',
                'entity_type',
                'entity_id',
                'actor_user_id',
                'actor_email',
                'source',
                'correlation_id',
            ]);

            foreach ($rows as $row) {
                /** @var array<string,mixed> $row */
                $actor = $row['actor_user'] ?? null;
                fputcsv($out, [
                    $row['public_id'] ?? '',
                    $row['stream'] ?? '',
                    $row['occurred_at'] ?? '',
                    $row['tenant_id'] ?? '',
                    $row['organization_id'] ?? '',
                    $row['event_key'] ?? '',
                    $row['module'] ?? '',
                    $row['action'] ?? '',
                    $row['entity_type'] ?? '',
                    $row['entity_id'] ?? '',
                    is_array($actor) ? ($actor['id'] ?? '') : '',
                    is_array($actor) ? ($actor['email'] ?? '') : '',
                    $row['source'] ?? '',
                    $row['correlation_id'] ?? '',
                ]);
            }

            fclose($out);
        }, 'audit-export.csv', $headers);
    }
}
