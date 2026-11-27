<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;
use Zekini\PulseSdk\Audit\RouteSegmentClassifier;

/**
 * What the Pulse SDK may read of the current request. It sends the path with each server error, and our report,
 * signing and invite paths carry a secret token, so it gets the route template instead.
 */
final class PulseRequestView extends Request
{
    public function getPathInfo(): string
    {
        $request = request();
        $route = $request->route();

        if ($route !== null) {
            return '/' . ltrim($route->uri(), '/');
        }

        $classifier = new RouteSegmentClassifier();

        return implode('/', array_map(
            static fn(string $segment): string => $classifier->isIdentifier($segment) ? '{param}' : $segment,
            explode('/', $request->getPathInfo()),
        ));
    }

    public function header($key = null, $default = null)
    {
        return request()->header($key, $default);
    }
}
