<?php
declare(strict_types=1);
namespace Corevia\Ai;
use Corevia\Core\ActionTransport;
/** Fixed registered target; remote authorization and OS isolation remain operator responsibilities. */
final readonly class TransportTool implements ToolHandler
{
    public function __construct(private ActionTransport $transport,private string $target)
    {
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_.:-]{0,127}$/D',$target)) { throw new \InvalidArgumentException('Register a static tool target.'); }
    }
    public function execute(mixed $input,ToolExecution $execution):mixed
    {
        $execution->check();if (!is_array($input)&&!$input instanceof \stdClass) { throw new \InvalidArgumentException('Transport tools require object/array input.'); }
        $reply=$this->transport->call($this->target,['input'=>(array)$input,'effect_key'=>$execution->effectKey]);
        if (!$reply->ok) { throw new \RuntimeException('TOOL_REMOTE_FAILED'); }$execution->check();return $reply->data;
    }
}
