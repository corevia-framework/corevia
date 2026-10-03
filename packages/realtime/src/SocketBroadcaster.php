<?php

declare(strict_types=1);

namespace Corevia\Realtime;

use Corevia\Protocol\Protocol;

/** Authenticated loopback IPC connects HTTP/FPM processes to the realtime process. */
final readonly class SocketBroadcaster implements Broadcaster
{
    public function __construct(private string $secret, private int $port = 8082, private float $timeout = 2.0)
    {
    }

    public function command(array $message): void
    {
        $socket = @stream_socket_client('tcp://127.0.0.1:' . $this->port, $errno, $error, $this->timeout);
        if ($socket === false) {
            throw new \RuntimeException('Realtime IPC unavailable.');
        }
        try {
            stream_set_timeout($socket, (int) $this->timeout, (int) (($this->timeout - (int) $this->timeout) * 1e6));
            $wire = json_encode(['secret' => $this->secret, ...$message], JSON_THROW_ON_ERROR) . "\n";
            while ($wire !== '') {
                $written = fwrite($socket, $wire);
                if ($written === false || $written === 0) {
                    throw new \RuntimeException('Realtime IPC write failed.');
                }
                $wire = substr($wire, $written);
            }
            $reply = fgets($socket, 1024);
            if ($reply === false || (json_decode($reply, true)['ok'] ?? false) !== true) {
                throw new \RuntimeException('Realtime IPC command failed.');
            }
        } finally {
            fclose($socket);
        }
    }

    public function broadcast(string $channel, string $event, array $payload, ?string $traceId = null): void
    {
        $message = Protocol::request('event', $channel, ['event' => $event, 'data' => $payload], traceId: $traceId);
        (new Protocol())->encode($message);
        $this->command(['command' => 'broadcast', 'message' => $message]);
    }
}
