<?php

namespace App\Services;

use App\Exceptions\DomainProvisioningException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class DomainProvisioner
{
    private const TIMEOUT_SECONDS = 300;
    private const POLL_INTERVAL_MICROSECONDS = 500000;

    public function provision(string $domain): string
    {
        $bridgeDir = $this->bridgeDirectory();
        $requestDir = $bridgeDir.'/requests';
        $resultDir = $bridgeDir.'/results';

        File::ensureDirectoryExists($requestDir);
        File::ensureDirectoryExists($resultDir);

        $requestId = (string) Str::uuid();
        $requestPath = $requestDir.'/'.$requestId.'.json';
        $resultPath = $resultDir.'/'.$requestId.'.json';

        $payload = json_encode([
            'id' => $requestId,
            'domain' => $domain,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($payload === false || file_put_contents($requestPath, $payload) === false) {
            throw new DomainProvisioningException('Domain provisioning request could not be written.');
        }

        $startedAt = microtime(true);

        try {
            while ((microtime(true) - $startedAt) < self::TIMEOUT_SECONDS) {
                clearstatcache(true, $resultPath);

                if (is_file($resultPath)) {
                    $result = json_decode((string) file_get_contents($resultPath), true);
                    @unlink($resultPath);

                    if (! is_array($result)) {
                        throw new DomainProvisioningException('Domain provisioning returned an invalid response.');
                    }

                    $output = trim((string) ($result['output'] ?? ''));
                    $error = trim((string) ($result['error'] ?? ''));

                    if (($result['success'] ?? false) === true) {
                        return $output;
                    }

                    throw new DomainProvisioningException(
                        'Domain provisioning failed.',
                        $error !== '' ? $error : $output
                    );
                }

                usleep(self::POLL_INTERVAL_MICROSECONDS);
            }

            throw new DomainProvisioningException('Domain provisioning timed out after 300 seconds.');
        } finally {
            @unlink($requestPath);
        }
    }

    private function bridgeDirectory(): string
    {
        $configuredPath = env('DOMAIN_PROVISIONING_BRIDGE_DIR');

        if (is_string($configuredPath) && trim($configuredPath) !== '') {
            return rtrim($configuredPath, '/');
        }

        return base_path('bridge/domain-provisioner');
    }
}
