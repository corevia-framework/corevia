<?php
declare(strict_types=1);
namespace Corevia\Core;

/** Numeric five-field cron. Day-of-month and day-of-week restrictions use OR. */
final readonly class CronExpression
{
    private array $fields;

    public function __construct(public string $expression)
    {
        if (strlen($expression)>256) { throw new \InvalidArgumentException('Cron expression is too long.'); }
        $parts=preg_split('/\s+/',trim($expression));
        if (count($parts)!==5) { throw new \InvalidArgumentException('Cron requires five numeric fields.'); }
        $fields=[];
        foreach ([[0,59],[0,23],[1,31],[1,12],[0,6]] as $i=>[$min,$max]) {
            $values=[];
            foreach (explode(',',$parts[$i]) as $piece) {
                if (!preg_match('/^(\*|\d{1,2}(?:-\d{1,2})?)(?:\/(\d{1,2}))?$/D',$piece,$match)) { throw new \InvalidArgumentException('Unsupported cron field.'); }
                $step=(int)($match[2]??1);
                if ($step<1||$step>$max-$min+1) { throw new \InvalidArgumentException('Invalid cron step.'); }
                if ($match[1]==='*') { $start=$min;$end=$max; }
                else { $range=explode('-',$match[1]);$start=(int)$range[0];$end=(int)($range[1]??$range[0]);
                    if (isset($match[2])&&count($range)===1) { throw new \InvalidArgumentException('Steps require a range or wildcard.'); }
                }
                if ($start<$min||$end>$max||$start>$end) { throw new \InvalidArgumentException('Cron field out of range.'); }
                for ($value=$start;$value<=$end;$value+=$step) { $values[$value]=true; }
            }
            $fields[]=['values'=>$values,'wildcard'=>str_starts_with($parts[$i],'*')];
        }
        $this->fields=$fields;
    }

    public function matches(\DateTimeImmutable $local):bool
    {
        $v=array_map('intval',explode(' ',$local->format('i G j n w')));
        foreach ([0,1,3] as $i) { if (!isset($this->fields[$i]['values'][$v[$i]])) { return false; } }
        $day=isset($this->fields[2]['values'][$v[2]]);$week=isset($this->fields[4]['values'][$v[4]]);
        return $this->fields[2]['wildcard']||$this->fields[4]['wildcard'] ? $day&&$week : $day||$week;
    }
}
