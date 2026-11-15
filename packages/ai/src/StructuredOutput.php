<?php
declare(strict_types=1);
namespace Corevia\Ai;
use Corevia\Core\ActionContract;

/** Validate a complete buffered JSON answer. No fence stripping, repair prompts or automatic retries. */
final readonly class StructuredOutput
{
    public function __construct(private AiClient $client,private ActionContract $contract,private string $action)
    {
        $contract->outputSchema($action);
    }
    public function complete(array $messages,int $maxTokens=512):mixed
    {
        $schema=json_encode($this->contract->outputSchema($this->action),JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
        if (strlen($schema)>16384) { throw new \LengthException('Structured schema exceeds 16 KiB.'); }
        $messages=[['role'=>'system','content'=>'Return exactly one JSON value matching this schema. Do not use markdown or explanatory text. Schema: '.$schema],...$messages];
        $json=$this->client->complete($messages,$maxTokens);
        if (strlen($json)>65536) { throw new \LengthException('Structured output exceeds 64 KiB.'); }
        $value=json_decode($json,false,40,JSON_THROW_ON_ERROR);$this->contract->assertOutput($this->action,$value);return $value;
    }
}
