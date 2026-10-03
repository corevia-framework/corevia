<?php

declare(strict_types=1);

namespace Corevia\Realtime;

final class Frame
{
    public static function encode(string $payload, int $opcode = 1): string
    {
        $size = strlen($payload);
        return chr(0x80 | $opcode) . ($size < 126 ? chr($size) : ($size <= 65535 ? chr(126) . pack('n', $size) : chr(127) . pack('NN', 0, $size))) . $payload;
    }

    /** Consume one masked client frame, or null when incomplete. */
    public static function decode(string &$buffer, int $maxBytes): ?array
    {
        if (strlen($buffer) < 2) {
            return null;
        }
        $first = ord($buffer[0]);
        $second = ord($buffer[1]);
        $opcode = $first & 0x0f;
        if (($first & 0x70) !== 0 || ($second & 0x80) === 0 || !in_array($opcode, [0, 1, 8, 9, 10], true)) {
            throw new \InvalidArgumentException('Invalid WebSocket frame.');
        }
        $size = $second & 0x7f;
        $offset = 2;
        if ($size === 126) {
            if (strlen($buffer) < 4) {
                return null;
            }
            $size = unpack('n', substr($buffer, 2, 2))[1];
            if ($size < 126) {
                throw new \InvalidArgumentException('Non-canonical frame length.');
            }
            $offset = 4;
        } elseif ($size === 127) {
            if (strlen($buffer) < 10) {
                return null;
            }
            $length = unpack('Nhigh/Nlow', substr($buffer, 2, 8));
            if ($length['high'] !== 0 || $length['low'] < 65536) {
                throw new \LengthException('Invalid frame length.');
            }
            $size = $length['low'];
            $offset = 10;
        }
        if ($size > $maxBytes || ($opcode >= 8 && ($size > 125 || ($first & 0x80) === 0))) {
            throw new \LengthException('Invalid frame size.');
        }
        if (strlen($buffer) < $offset + 4 + $size) {
            return null;
        }
        $mask = substr($buffer, $offset, 4);
        $payload = substr($buffer, $offset + 4, $size);
        for ($i = 0; $i < $size; $i++) {
            $payload[$i] = $payload[$i] ^ $mask[$i % 4];
        }
        $buffer = substr($buffer, $offset + 4 + $size);
        return ['opcode' => $opcode, 'final' => (bool) ($first & 0x80), 'payload' => $payload];
    }
}
