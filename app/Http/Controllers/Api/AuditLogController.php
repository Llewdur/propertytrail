<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\AuditLogRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AuditLogController extends Controller
{
    public function __construct(
        private readonly AuditLogRepository $auditLogs,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $limit = min(max((int) $request->input('limit', 100), 1), 500);

        return response()->json(['data' => $this->auditLogs->recent($limit)]);
    }
}
