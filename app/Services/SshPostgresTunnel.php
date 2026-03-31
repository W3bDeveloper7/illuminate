<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;
use Symfony\Component\Process\Process;

class SshPostgresTunnel
{
    /**
     * Pick a free TCP port on 127.0.0.1.
     */
    public function allocateLocalPort(): int
    {
        $socket = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($socket === false) {
            throw new RuntimeException("Could not bind ephemeral port: {$errstr} ({$errno})");
        }
        $name = stream_socket_get_name($socket, false);
        fclose($socket);
        if (! is_string($name) || ! str_contains($name, ':')) {
            throw new RuntimeException('Could not resolve ephemeral port.');
        }

        return (int) substr($name, strrpos($name, ':') + 1);
    }

    /**
     * Start `ssh -L local:remoteHost:remotePort … -N` and wait until the local port accepts connections.
     */
    public function start(
        string $identityPath,
        ChallengeSshCredentials $credentials,
        int $localPort,
    ): Process {
        $forward = sprintf(
            '%d:%s:%d',
            $localPort,
            $credentials->remotePgHost,
            $credentials->remotePgPort
        );

        $command = [
            'ssh',
            '-o', 'StrictHostKeyChecking=accept-new',
            '-o', 'ExitOnForwardFailure=yes',
            '-o', 'BatchMode=yes',
            '-o', 'ServerAliveInterval=30',
            '-o', 'ServerAliveCountMax=3',
            '-p', (string) $credentials->sshPort,
            '-i', $identityPath,
            '-L', $forward,
            sprintf('%s@%s', $credentials->sshUser, $credentials->sshHost),
            '-N',
        ];

        $process = new Process($command);
        $process->setTimeout(null);
        $process->start();

        for ($i = 0; $i < 100; $i++) {
            if ($this->canConnect('127.0.0.1', $localPort)) {
                return $process;
            }
            if (! $process->isRunning()) {
                $msg = trim($process->getErrorOutput().' '.$process->getOutput());
                throw new RuntimeException('SSH tunnel exited before the local port opened.'.($msg !== '' ? ' '.$msg : ''));
            }
            usleep(100_000);
        }

        $process->stop(0);

        throw new RuntimeException('Timed out waiting for SSH local port forward.');
    }

    private function canConnect(string $host, int $port): bool
    {
        $fp = @fsockopen($host, $port, $errno, $errstr, 0.25);

        if (is_resource($fp)) {
            fclose($fp);

            return true;
        }

        return false;
    }
}
