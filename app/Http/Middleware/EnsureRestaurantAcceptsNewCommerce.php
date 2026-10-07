<?php

namespace App\Http\Middleware;

use App\Models\TableSession;
use App\Services\RestaurantCommercePolicy;
use App\Services\TenantRestaurantResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRestaurantAcceptsNewCommerce
{
    public function __construct(
        private readonly RestaurantCommercePolicy $policy,
        private readonly TenantRestaurantResolver $resolver,
    ) {}

    public function handle(Request $request, Closure $next, string $context = 'guest'): Response
    {
        // Match the destination controller's tenant source. In particular, a staff
        // bearer token must not replace the slug/host tenant on public guest routes.
        $restaurant = match ($context) {
            'staff' => $request->user()?->currentRestaurant(),
            'session' => $request->route('tableSession') instanceof TableSession
                ? $request->route('tableSession')->restaurant()->first()
                : null,
            'chat' => $request->user()?->currentRestaurant()
                ?? $this->resolver->resolveFromSlugOrHost(null, $request),
            default => $this->resolver->resolveFromSlugOrHost($request->route('restaurant_slug'), $request),
        };

        abort_unless($restaurant, 404);
        $this->policy->assertNewCommerce($restaurant);

        return $next($request);
    }
}
