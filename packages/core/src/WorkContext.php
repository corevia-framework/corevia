<?php
declare(strict_types=1);
namespace Corevia\Core;

/** Fiber-local work budget and trace; parent budgets remain authoritative inside nested work. */
final class WorkContext
{
    private static ?self $main=null;
    private static ?\WeakMap $fibers=null;
    public function __construct(public readonly Deadline $deadline,public readonly ?TraceContext $trace=null,private readonly ?self $parent=null) {}
    public static function current():?self
    {
        $fiber=\Fiber::getCurrent();return $fiber===null?self::$main:(self::$fibers[$fiber]??null);
    }
    public function check():void { $this->parent?->check();$this->deadline->check(); }
    public function remainingMilliseconds():int
    {
        $this->check();$remaining=$this->deadline->remainingMilliseconds();
        return $this->parent===null?$remaining:min($remaining,$this->parent->remainingMilliseconds());
    }
    public static function run(Deadline $deadline,callable $work,?TraceContext $trace=null):mixed
    {
        $old=self::current();return self::resume(new self($deadline,$trace??$old?->trace,$old),$work);
    }
    /** Resume a captured streaming context; retains cancellation/limits from its original parent chain. */
    public static function resume(self $context,callable $work):mixed
    {
        $old=self::current();$fiber=\Fiber::getCurrent();
        if ($fiber===null) { self::$main=$context; }
        else { self::$fibers??=new \WeakMap();self::$fibers[$fiber]=$context; }
        try { $context->check();$result=$work($context);$context->check();return $result; }
        finally {
            if ($fiber===null) { self::$main=$old; }
            elseif ($old===null) { unset(self::$fibers[$fiber]); }
            else { self::$fibers[$fiber]=$old; }
        }
    }
}
