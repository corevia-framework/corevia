<?php
declare(strict_types=1);
namespace Corevia\Protocol;
use Corevia\Core\ActionResult;

/** One trusted local executable per call; JSON on stdin/stdout, no shell interpolation. */
final readonly class ProcessTransport
{
    public function __construct(private array $command, private int $timeoutMs = 5000, private Protocol $protocol = new Protocol())
    {
        if ($command === [] || $timeoutMs < 1 || $timeoutMs > 300000) { throw new \InvalidArgumentException('Command and bounded deadline required.'); }
        foreach ($command as $arg) { if (!is_string($arg) || str_contains($arg, "\0")) { throw new \InvalidArgumentException('Invalid process argument.'); } }
    }
    public function call(string $target, array $payload): ActionResult
    {
        $request = Protocol::request('action.call', $target, $payload, version: Protocol::V2, timeoutMs: $this->timeoutMs);
        $directory = sys_get_temp_dir() . '/corevia-process-' . bin2hex(random_bytes(12));
        if (!mkdir($directory, 0700)) { throw new \RuntimeException('Cannot create process directory.'); }
        $process = null;
        try {
            file_put_contents($directory . '/input', $this->protocol->encode($request) . "\n");
            $process = proc_open($this->command, [['file', $directory . '/input', 'r'], ['file', $directory . '/output', 'w'], ['file', $directory . '/error', 'w']], $pipes, null, null, ['bypass_shell' => true, 'create_no_window' => true]);
            if (!is_resource($process)) { return ActionResult::failure('PROCESS_UNAVAILABLE', 'Worker could not start.', true); }
            $start = hrtime(true);
            do {
                $status = proc_get_status($process); clearstatcache();
                if ((hrtime(true) - $start) / 1e6 > $this->timeoutMs) { proc_terminate($process); return ActionResult::failure('PROCESS_TIMEOUT', 'Worker deadline exceeded.', true); }
                foreach (['output', 'error'] as $file) { if (is_file($directory . '/' . $file) && filesize($directory . '/' . $file) > $this->protocol->maxBytes) { proc_terminate($process); return ActionResult::failure('PAYLOAD_TOO_LARGE', 'Worker output exceeded limit.'); } }
                if ($status['running']) { usleep(1000); }
            } while ($status['running']);
            if ($status['exitcode'] !== 0) { return ActionResult::failure('PROCESS_CRASH', 'Worker exited unsuccessfully.', true); }
            $response = $this->protocol->decode(trim(file_get_contents($directory . '/output')));
            if ($response['cvp'] !== Protocol::V2 || $response['id'] !== $request['id'] || !array_key_exists('ok', $response)) { throw new \RuntimeException('Invalid process response.'); }
            return $response['ok'] ? ActionResult::success($response['result']) : ActionResult::failure($response['error']['code'], $response['error']['message'], $response['error']['retryable']);
        } catch (\Throwable) { return ActionResult::failure('PROCESS_PROTOCOL_ERROR', 'Invalid worker response.'); }
        finally {
            if (is_resource($process)) { proc_close($process); }
            foreach (['input', 'output', 'error'] as $file) { if (is_file($directory . '/' . $file)) { unlink($directory . '/' . $file); } }
            rmdir($directory);
        }
    }
}
