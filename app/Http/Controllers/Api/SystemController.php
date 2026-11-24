<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class SystemController extends Controller
{
    public function health(): JsonResponse
    {
        $checks = [];

        try {
            DB::connection()->getPdo();
            $checks['database'] = 'ok';
        } catch (Throwable) {
            $checks['database'] = 'error';
        }

        try {
            Redis::ping();
            $checks['redis'] = 'ok';
        } catch (Throwable) {
            $checks['redis'] = 'error';
        }

        try {
            Storage::disk('local')->files();
            $checks['storage'] = 'ok';
        } catch (Throwable) {
            $checks['storage'] = 'error';
        }

        $healthy = !in_array('error', $checks, true);

        return response()->json(
            [
                'status' => $healthy ? 'ok' : 'degraded',
                'services' => $checks,
            ],
            $healthy ? 200 : 503,
        );
    }

    public function version(): JsonResponse
    {
        $version = config('app.version', '0.0.0');

        return response()->json([
            'version' => $version,
            'environment' => config('app.env'),
        ]);
    }
}
