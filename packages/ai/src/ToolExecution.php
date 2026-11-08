<?php
declare(strict_types=1);
namespace Corevia\Ai;
use Corevia\Core\{TenantScope,WorkContext};
final readonly class ToolExecution
{
    public function __construct(public TenantScope $scope,public string $effectKey,private WorkContext $context) {}
    public function check():void { $this->scope->check();$this->context->check(); }
    public function remainingMilliseconds():int { $this->check();return $this->context->remainingMilliseconds(); }
    public function __serialize():array { throw new \LogicException('Tool authority cannot be serialized.'); }
}
