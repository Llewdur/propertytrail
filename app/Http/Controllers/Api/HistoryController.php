<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Repositories\AuditLogRepository;
use App\Repositories\LeaseRepository;
use App\Support\AuditHistory;
use App\Support\Tenancy\CurrentOrganisation;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** History per person: one tenancy's timeline, or one staff member's actions. */
final class HistoryController extends Controller
{
    public function __construct(
        private readonly AuditLogRepository $auditLogs,
        private readonly LeaseRepository $leases,
        private readonly CurrentOrganisation $currentOrganisation,
    ) {}

    /** The tenant on this lease, across every link and phone, plus staff activity on their material. */
    public function lease(Request $request, int $lease): JsonResponse
    {
        $model = $this->leases->find($lease);

        if ($model === null) {
            return response()->json(['message' => 'Lease not found.'], Response::HTTP_NOT_FOUND);
        }

        return $this->answer($this->auditLogs->forLease((int) $model->getKey(), $this->limit($request)));
    }

    /** What one staff member did in this organisation. An admin sees anyone's; others only their own. */
    public function user(Request $request, int $user): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $request->user();
        $organisationId = $this->currentOrganisation->id() ?? 0;

        $isMember = User::query()
            ->whereKey($user)
            ->whereHas('organisations', static fn($query) => $query->whereKey($organisationId))
            ->exists();

        if (!$isMember) {
            return response()->json(['message' => 'User not found.'], Response::HTTP_NOT_FOUND);
        }

        if ($viewer->getKey() !== $user && !$viewer->isAdminIn($organisationId)) {
            return response()->json([
                'message' => 'Only an admin can see another person\'s history.',
            ], Response::HTTP_FORBIDDEN);
        }

        return $this->answer($this->auditLogs->forActor($user, $this->limit($request)));
    }

    private function limit(Request $request): int
    {
        return min(max((int) $request->input('limit', 100), 1), 500);
    }

    /** @param Collection<int, AuditLog> $logs */
    private function answer(Collection $logs): JsonResponse
    {
        return response()->json(['data' => $logs->map(AuditHistory::entry(...))->values()->all()], Response::HTTP_OK, [
            'Cache-Control' => 'no-store, private',
        ]);
    }
}
