<?php
declare(strict_types=1);
namespace Corevia\Testing;
final class Factory
{
    public static function records(int $count,callable $definition,?callable $persist=null):array
    {
        Guard::development();if($count<0||$count>1000){throw new \InvalidArgumentException('Use 0..1000 factory records.');}
        $result=[];for($index=0;$index<$count;$index++){$record=$definition($index);if(!is_array($record)){throw new \LogicException('Factory must return record data.');}if($persist){$persist($record);}$result[]=$record;}return $result;
    }
}
