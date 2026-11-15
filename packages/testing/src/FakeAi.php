<?php
declare(strict_types=1);
namespace Corevia\Testing;
use Corevia\Ai\AiProvider;
final class FakeAi implements AiProvider
{
    private array $calls=[];
    public function __construct(private readonly array $deltas=['fixture output']){Guard::development();}
    public function stream(array $messages,int $maxTokens):iterable
    {
        $this->calls[]=['messages'=>$messages,'max_tokens'=>$maxTokens];foreach($this->deltas as $delta){yield $delta;}
    }
    public function calls():array{return $this->calls;}
}
