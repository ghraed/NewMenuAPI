<?php

namespace App\Jobs;

use App\Exceptions\DomainProvisioningException;
use App\Models\Restaurant;
use App\Services\DomainProvisioner;
use App\Services\RestaurantCustomDomainService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProvisionRestaurantDomainJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $restaurantId,
    ) {
    }

    public function handle(
        DomainProvisioner $domainProvisioner,
        RestaurantCustomDomainService $customDomainService,
    ): void {
        $restaurant = Restaurant::query()->find($this->restaurantId);

        if (! $restaurant) {
            Log::warning('Restaurant domain provisioning skipped because the restaurant was not found.', [
                'restaurant_id' => $this->restaurantId,
            ]);

            return;
        }

        $domain = $customDomainService->normalize($restaurant->custom_domain);

        if ($domain === null) {
            Log::info('Restaurant domain provisioning skipped because no custom domain is set.', [
                'restaurant_id' => $restaurant->id,
            ]);

            return;
        }

        $restaurant->forceFill([
            'custom_domain' => $domain,
            'custom_domain_status' => 'provisioning',
        ])->save();

        $customDomainService->syncCustomDomain($restaurant, $domain);

        try {
            $output = $domainProvisioner->provision($domain);

            $restaurant->forceFill([
                'custom_domain_status' => 'active',
                'custom_domain_error' => null,
                'ssl_issued_at' => now(),
            ])->save();

            $customDomainService->markProvisioned($restaurant);

            Log::info('Restaurant custom domain provisioned successfully.', [
                'restaurant_id' => $restaurant->id,
                'domain' => $domain,
                'output' => $output,
            ]);
        } catch (DomainProvisioningException $exception) {
            $errorOutput = trim($exception->processOutput());

            $restaurant->forceFill([
                'custom_domain_status' => 'failed',
                'custom_domain_error' => $errorOutput !== '' ? $errorOutput : $exception->getMessage(),
            ])->save();

            Log::error('Restaurant custom domain provisioning failed.', [
                'restaurant_id' => $restaurant->id,
                'domain' => $domain,
                'error' => $exception->getMessage(),
                'output' => $errorOutput,
            ]);
        } catch (\Throwable $exception) {
            $restaurant->forceFill([
                'custom_domain_status' => 'failed',
                'custom_domain_error' => $exception->getMessage(),
            ])->save();

            Log::error('Restaurant custom domain provisioning failed unexpectedly.', [
                'restaurant_id' => $restaurant->id,
                'domain' => $domain,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
