<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Billing\EntitlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

final class BillingController extends Controller
{
    public function show(EntitlementService $billing): JsonResponse
    {
        return response()->json(['data' => $billing->summary($billing->organisation())]);
    }

    public function webhook(Request $request, EntitlementService $billing): JsonResponse
    {
        $signature = $request->header('X-Fleet-Signature');
        $signature = is_string($signature) ? $signature : null;

        if (!$billing->verifyWebhookSignature($request->getContent(), $signature)) {
            return response()->json(['message' => 'Invalid webhook signature.'], HttpResponse::HTTP_FORBIDDEN);
        }

        $billing->applySubscriptionEvent($request->json()->all());

        return response()->json(['data' => ['received' => true]]);
    }
}
