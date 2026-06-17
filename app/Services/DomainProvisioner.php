<?php

namespace App\Services;

use App\Exceptions\DomainProvisioningException;
use Symfony\Component\Process\Process;

class DomainProvisioner
{
    public function provision(string $domain): string
    {
        $process = new Process(['sudo', '/usr/local/bin/provision-domain', $domain]);
        $process->setTimeout(300);
        $process->run();

        $output = trim($process->getOutput()."\n".$process->getErrorOutput());

        if (! $process->isSuccessful()) {
            throw new DomainProvisioningException(
                'Domain provisioning failed.',
                $output
            );
        }

        return $output;
    }
}
