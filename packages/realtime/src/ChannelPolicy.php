<?php
declare(strict_types=1);
namespace Corevia\Realtime;

final class ChannelPolicy
{
    public static function valid(string $topic): bool
    {
        if (!preg_match('/^[a-zA-Z0-9_.:*-]{1,128}$/D', $topic)) { return false; }
        if (!str_contains($topic, '*')) { return true; }
        return str_ends_with($topic, '.*') && !str_contains(substr($topic, 0, -1), '*') && !self::restricted($topic);
    }
    public static function restricted(string $topic): bool { return str_starts_with($topic, 'private-') || str_starts_with($topic, 'presence-'); }
    public static function matches(string $subscription, string $topic): bool
    {
        return $subscription === $topic || (!self::restricted($topic) && str_ends_with($subscription, '.*') && str_starts_with($topic, substr($subscription, 0, -1)));
    }
}
