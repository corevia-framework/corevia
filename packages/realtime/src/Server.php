<?php

declare(strict_types=1);

namespace Corevia\Realtime;

use Corevia\Core\ActionResult;
use Corevia\Core\Logger;
use Corevia\Protocol\Protocol;

final class Server implements Broadcaster
{
    private array $clients = [];
    private array $connections = [];
    private bool $running = true;
    private readonly Protocol $protocol;

    public function __construct(
        private readonly Logger $logger,
        private readonly string $secret,
        private readonly string $host = '127.0.0.1',
        private readonly int $port = 8080,
        private readonly int $controlPort = 8082,
        private readonly array $origins = ['http://localhost:8081', 'http://127.0.0.1:8081'],
        private readonly ?\Closure $authorize = null,
        private readonly int $maxBytes = 65536,
        private readonly int $heartbeatSeconds = 20,
    ) {
        if (strlen($secret) < 32) {
            throw new \InvalidArgumentException('Realtime secret must have at least 32 characters.');
        }
        $this->protocol = new Protocol($maxBytes);
    }

    public function stop(): void
    {
        $this->running = false;
    }

    public function run(): void
    {
        $listener = stream_socket_server('tcp://' . $this->host . ':' . $this->port, $errno, $error);
        if ($listener === false) {
            throw new \RuntimeException('Unable to bind realtime port: ' . $error);
        }
        $control = stream_socket_server('tcp://127.0.0.1:' . $this->controlPort, $errno, $error);
        if ($control === false) {
            fclose($listener);
            throw new \RuntimeException('Unable to bind IPC port: ' . $error);
        }
        stream_set_blocking($listener, false);
        stream_set_blocking($control, false);
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, fn () => $this->stop());
            pcntl_signal(SIGINT, fn () => $this->stop());
        }
        $this->logger->log('info', 'Realtime started', ['host' => $this->host, 'port' => $this->port]);
        try {
            while ($this->running) {
                $read = [$listener, $control];
                $write = [];
                foreach ($this->clients as $client) {
                    $read[] = $client['socket'];
                    if ($client['out'] !== '') {
                        $write[] = $client['socket'];
                    }
                }
                $except = [];
                if (@stream_select($read, $write, $except, 0, 200000) === false) {
                    continue;
                }
                foreach ($read as $socket) {
                    if ($socket === $listener || $socket === $control) {
                        $this->accept($socket, $socket === $control);
                    } else {
                        $this->read((int) $socket);
                    }
                }
                foreach ($write as $socket) {
                    $id = (int) $socket;
                    if (!isset($this->clients[$id])) {
                        continue;
                    }
                    $written = @fwrite($socket, $this->clients[$id]['out']);
                    if ($written === false) {
                        $this->drop($id);
                    } else {
                        $this->clients[$id]['out'] = substr($this->clients[$id]['out'], $written);
                        if ($this->clients[$id]['closing'] && $this->clients[$id]['out'] === '') {
                            $this->drop($id);
                        }
                    }
                }
                $this->heartbeat();
            }
        } finally {
            foreach (array_keys($this->clients) as $id) {
                if ($this->clients[$id]['ready']) {
                    @fwrite($this->clients[$id]['socket'], Frame::encode(pack('n', 1001), 8));
                }
                $this->drop($id);
            }
            fclose($listener);
            fclose($control);
            $this->logger->log('info', 'Realtime stopped');
        }
    }

    private function accept($listener, bool $ipc): void
    {
        $socket = @stream_socket_accept($listener, 0, $peer);
        if ($socket === false) {
            return;
        }
        $ip = substr($peer, 0, (int) strrpos($peer, ':'));
        $now = time();
        $bucket = $this->connections[$ip] ?? ['start' => $now, 'count' => 0];
        if ($now - $bucket['start'] >= 60) {
            $bucket = ['start' => $now, 'count' => 0];
        }
        if (!$ipc) {
            $bucket['count']++;
            $this->connections[$ip] = $bucket;
        }
        if (count($this->clients) >= 512 || (!$ipc && $bucket['count'] > 30)) {
            fclose($socket);
            return;
        }
        stream_set_blocking($socket, false);
        $this->clients[(int) $socket] = ['socket' => $socket, 'ipc' => $ipc, 'in' => '', 'out' => '', 'ready' => false, 'closing' => false, 'topics' => [], 'opened' => $now, 'seen' => $now, 'ping' => $now, 'awaiting' => false, 'window' => $now, 'count' => 0, 'fragment' => null];
    }

    private function read(int $id): void
    {
        if (!isset($this->clients[$id]) || $this->clients[$id]['closing']) {
            return;
        }
        $chunk = @fread($this->clients[$id]['socket'], 8192);
        if ($chunk === false || ($chunk === '' && feof($this->clients[$id]['socket']))) {
            $this->drop($id);
            return;
        }
        $this->clients[$id]['in'] .= $chunk;
        if (strlen($this->clients[$id]['in']) > $this->maxBytes + 4096) {
            $this->drop($id);
            return;
        }
        try {
            if ($this->clients[$id]['ipc']) {
                $this->control($id);
                return;
            }
            if (!$this->clients[$id]['ready'] && !$this->handshake($id)) {
                return;
            }
            while (isset($this->clients[$id]) && ($frame = Frame::decode($this->clients[$id]['in'], $this->maxBytes)) !== null) {
                if (time() - $this->clients[$id]['window'] >= 60) {
                    $this->clients[$id]['window'] = time();
                    $this->clients[$id]['count'] = 0;
                }
                if (++$this->clients[$id]['count'] > 120) {
                    throw new \RuntimeException('RATE_LIMITED');
                }
                $this->clients[$id]['seen'] = time();
                if ($frame['opcode'] === 8) {
                    $this->clients[$id]['out'] .= Frame::encode(pack('n', 1000), 8);
                    $this->clients[$id]['closing'] = true;
                    return;
                }
                if ($frame['opcode'] === 9) {
                    $this->clients[$id]['out'] .= Frame::encode($frame['payload'], 10);
                    continue;
                }
                if ($frame['opcode'] === 10) {
                    $this->clients[$id]['awaiting'] = false;
                    continue;
                }
                if ($frame['opcode'] === 0) {
                    if ($this->clients[$id]['fragment'] === null) {
                        throw new \RuntimeException('Invalid continuation.');
                    }
                    $this->clients[$id]['fragment'] .= $frame['payload'];
                } elseif ($this->clients[$id]['fragment'] !== null) {
                    throw new \RuntimeException('Interleaved fragments.');
                } elseif (!$frame['final']) {
                    $this->clients[$id]['fragment'] = $frame['payload'];
                }
                if (strlen($this->clients[$id]['fragment'] ?? '') > $this->maxBytes) {
                    throw new \LengthException('Fragmented payload too large.');
                }
                if ($frame['final']) {
                    $payload = $this->clients[$id]['fragment'] ?? $frame['payload'];
                    $this->clients[$id]['fragment'] = null;
                    if (!preg_match('//u', $payload)) {
                        throw new \RuntimeException('Invalid UTF-8.');
                    }
                    $this->message($id, $this->protocol->decode($payload));
                }
            }
        } catch (\Throwable $exception) {
            $this->logger->log('warning', 'Realtime client rejected', ['exception' => $exception::class]);
            if (isset($this->clients[$id]) && $this->clients[$id]['ready']) {
                $this->clients[$id]['out'] .= Frame::encode(pack('n', 1008), 8);
                $this->clients[$id]['closing'] = true;
            } else {
                $this->drop($id);
            }
        }
    }

    private function handshake(int $id): bool
    {
        $buffer = $this->clients[$id]['in'];
        $end = strpos($buffer, "\r\n\r\n");
        if ($end === false) {
            if (strlen($buffer) > 8192) {
                throw new \LengthException('Handshake too large.');
            }
            return false;
        }
        if ($end > 8192) {
            throw new \LengthException('Handshake too large.');
        }
        $lines = explode("\r\n", substr($buffer, 0, $end));
        if (!preg_match('#^GET /(?:realtime)? HTTP/1\.1$#D', array_shift($lines))) {
            throw new \RuntimeException('Invalid handshake path.');
        }
        $headers = [];
        foreach ($lines as $line) {
            $parts = explode(':', $line, 2);
            if (count($parts) !== 2) {
                throw new \RuntimeException('Invalid handshake header.');
            }
            $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
        }
        $key = $headers['sec-websocket-key'] ?? '';
        if (strtolower($headers['upgrade'] ?? '') !== 'websocket' || !preg_match('/(?:^|,)\s*upgrade\s*(?:,|$)/i', $headers['connection'] ?? '') || ($headers['sec-websocket-version'] ?? '') !== '13' || strlen(base64_decode($key, true) ?: '') !== 16 || (isset($headers['origin']) && !in_array($headers['origin'], $this->origins, true))) {
            throw new \RuntimeException('Handshake denied.');
        }
        $accept = base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
        $this->clients[$id]['out'] .= "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: " . $accept . "\r\n\r\n";
        $this->clients[$id]['in'] = substr($buffer, $end + 4);
        $this->clients[$id]['ready'] = true;
        return true;
    }

    private function message(int $id, array $message): void
    {
        $type = $message['type'] ?? '';
        $topic = $message['target'] ?? '';
        if ($type === 'ping') {
            $this->queue($id, Protocol::request('pong', payload: [], id: $message['id'], traceId: $message['meta']['trace_id'] ?? null));
            return;
        }
        $allowed = in_array($type, ['subscribe', 'unsubscribe'], true) && preg_match('/^[a-zA-Z0-9_.:-]{1,128}$/D', $topic);
        if ($allowed && $type === 'subscribe' && str_starts_with($topic, 'private-')) {
            $allowed = $this->authorize !== null && ($this->authorize)($topic, $message['payload']['token'] ?? null);
        }
        if (!$allowed || ($type === 'subscribe' && !isset($this->clients[$id]['topics'][$topic]) && count($this->clients[$id]['topics']) >= 64)) {
            $this->queue($id, Protocol::response($message['id'], ActionResult::failure('FORBIDDEN', 'Subscription denied.')));
            return;
        }
        if ($type === 'subscribe') {
            $this->clients[$id]['topics'][$topic] = true;
        } else {
            unset($this->clients[$id]['topics'][$topic]);
        }
        $this->queue($id, Protocol::response($message['id'], ActionResult::success(['channel' => $topic, 'subscribed' => $type === 'subscribe']), $message['meta']['trace_id'] ?? null));
    }

    private function queue(int $id, array $message): void
    {
        $this->clients[$id]['out'] .= Frame::encode($this->protocol->encode($message));
        if (strlen($this->clients[$id]['out']) > $this->maxBytes * 4) {
            $this->drop($id);
        }
    }

    private function control(int $id): void
    {
        $end = strpos($this->clients[$id]['in'], "\n");
        if ($end === false) {
            return;
        }
        $command = json_decode(substr($this->clients[$id]['in'], 0, $end), true, 64, JSON_THROW_ON_ERROR);
        if (!is_string($command['secret'] ?? null) || !hash_equals($this->secret, $command['secret'])) {
            throw new \RuntimeException('IPC authentication failed.');
        }
        if (($command['command'] ?? null) === 'broadcast') {
            $message = $command['message'];
            $this->protocol->encode($message);
            if ($message['type'] !== 'event') {
                throw new \RuntimeException('Invalid IPC message.');
            }
            $this->broadcast($message['target'], $message['payload']['event'], $message['payload']['data'], $message['meta']['trace_id'] ?? null);
        } elseif (($command['command'] ?? null) === 'stop') {
            $this->stop();
        } else {
            throw new \RuntimeException('Unknown IPC command.');
        }
        // Tiny acknowledgement is written immediately; clients use a bounded blocking read.
        @fwrite($this->clients[$id]['socket'], "{\"ok\":true}\n");
        $this->drop($id);
    }

    public function broadcast(string $channel, string $event, array $payload, ?string $traceId = null): void
    {
        $message = Protocol::request('event', $channel, ['event' => $event, 'data' => $payload], traceId: $traceId);
        $this->protocol->encode($message);
        foreach (array_keys($this->clients) as $id) {
            if ($this->clients[$id]['ready'] && isset($this->clients[$id]['topics'][$channel])) {
                $this->queue($id, $message);
            }
        }
    }

    private function heartbeat(): void
    {
        $now = time();
        foreach (array_keys($this->clients) as $id) {
            $client = $this->clients[$id];
            if (($client['closing'] && $now - $client['seen'] > 5) || (!$client['ready'] && $now - $client['opened'] > 5) || ($client['awaiting'] && $now - $client['ping'] > $this->heartbeatSeconds)) {
                $this->drop($id);
            } elseif ($client['ready'] && !$client['awaiting'] && $now - $client['ping'] >= $this->heartbeatSeconds) {
                $this->clients[$id]['out'] .= Frame::encode('cvp', 9);
                $this->clients[$id]['ping'] = $now;
                $this->clients[$id]['awaiting'] = true;
            }
        }
        foreach ($this->connections as $ip => $bucket) {
            if ($now - $bucket['start'] >= 60) {
                unset($this->connections[$ip]);
            }
        }
    }

    private function drop(int $id): void
    {
        if (isset($this->clients[$id])) {
            fclose($this->clients[$id]['socket']);
            unset($this->clients[$id]);
        }
    }
}
