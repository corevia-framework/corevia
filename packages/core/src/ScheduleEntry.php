<?php
declare(strict_types=1);
namespace Corevia\Core;

final readonly class ScheduleEntry
{
    private \DateTimeZone $zone;
    private array $payload;
    public string $fingerprint;

    /** Repeated DST minutes: once (local wall minute) or each (UTC minute). Missing minutes are skipped. */
    public function __construct(
        public string $id,
        public CronExpression $cron,
        public string $timezone,
        public string $task,
        array $input=[],
        public int $timeoutSeconds=300,
        public string $repeatedMinute='once',
    ) {
        if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.:-]{0,63}$/D',$id)
            ||!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.:-]{0,127}$/D',$task)
            ||$timeoutSeconds<1||$timeoutSeconds>3000||!in_array($repeatedMinute,['once','each'],true)
            ||!in_array($timezone,\DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC),true)) {
            throw new \InvalidArgumentException('Invalid schedule definition.');
        }
        $this->zone=new \DateTimeZone($timezone);
        $json=json_encode($input,JSON_THROW_ON_ERROR);
        if (strlen($json)>65536) { throw new \LengthException('Schedule input exceeds 64 KiB.'); }
        // Copy JSON data now; later mutation of caller-owned objects cannot alter a schedule.
        $this->payload=json_decode($json,true,64,JSON_THROW_ON_ERROR);
        $this->fingerprint=hash('sha256',json_encode([$id,$cron->expression,$timezone,$task,$this->payload,$timeoutSeconds,$repeatedMinute],JSON_THROW_ON_ERROR));
    }

    public function input():array { return $this->payload; }

    /** No hidden catch-up window: evaluate precisely this UTC minute. */
    public function occurrence(int $utc):?string
    {
        if ($utc<0) { throw new \InvalidArgumentException('Schedule timestamp must be nonnegative.'); }
        $local=(new \DateTimeImmutable('@'.$utc))->setTimezone($this->zone);
        if (!$this->cron->matches($local)) { return null; }
        return $this->repeatedMinute==='once' ? $local->format('Y-m-d\TH:i') : (string)intdiv($utc,60);
    }
}
