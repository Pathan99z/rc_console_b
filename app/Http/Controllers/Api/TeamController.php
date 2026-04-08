<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Team\CreateTeamRequest;
use App\Http\Requests\Team\ListTeamsRequest;
use App\Http\Requests\Team\UpdateTeamRequest;
use App\Http\Resources\TeamResource;
use App\Http\Responses\ApiResponse;
use App\Services\Team\TeamManagementService;
use App\Support\DomainConstants;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TeamController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly TeamManagementService $service)
    {
    }

    public function index(ListTeamsRequest $request): JsonResponse
    {
        $items = $this->service->listTeams(
            $request->user(),
            $request->validated(),
            (int) ($request->validated('per_page') ?? 15)
        );

        return $this->successResponse(DomainConstants::MSG_TEAM_FETCHED, [
            'items' => TeamResource::collection($items->items()),
            'pagination' => [
                'current_page' => $items->currentPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
                'last_page' => $items->lastPage(),
            ],
        ]);
    }

    public function store(CreateTeamRequest $request): JsonResponse
    {
        $team = $this->service->createTeam($request->user(), $request->validated());

        return $this->successResponse(DomainConstants::MSG_TEAM_CREATED, ['team' => new TeamResource($team)], 201);
    }

    public function update(UpdateTeamRequest $request, int $teamId): JsonResponse
    {
        $team = $this->service->updateTeam($request->user(), $teamId, $request->validated());

        return $this->successResponse(DomainConstants::MSG_TEAM_UPDATED, ['team' => new TeamResource($team)]);
    }

    public function destroy(Request $request, int $teamId): JsonResponse
    {
        $this->service->deleteTeam($request->user(), $teamId);

        return $this->successResponse(DomainConstants::MSG_TEAM_DELETED);
    }
}
