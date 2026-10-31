<?php
declare(strict_types=1);
namespace Corevia\Ai;
use Corevia\Http\{HttpClient,HttpJson,SseDecoder};
/** Chat-completions/SSE protocol, compatible with local or hosted model servers. */
final readonly class CompatibleProvider implements AiProvider,EmbeddingProvider
{
    public function __construct(private HttpClient $http,private string $base,private string $model,private string $key='',private string $embeddingModel='',private string $tokenField='max_tokens')
    {if($model===''||!in_array($tokenField,['max_tokens','max_completion_tokens'],true)){throw new \InvalidArgumentException('Configure an AI model and supported token field.');}$parts=parse_url($base);if(isset($parts['query'])||isset($parts['fragment'])){throw new \InvalidArgumentException('AI base URL cannot contain query/fragment.');}}
    private function headers():array{return ['Content-Type'=>'application/json',...($this->key!==''?['Authorization'=>'Bearer '.$this->key]:[])];}
    public function stream(array $messages,int $maxTokens):iterable
    {
        $decoder=new SseDecoder();$finished=false;$body=json_encode(['model'=>$this->model,'messages'=>$messages,'stream'=>true,$this->tokenField=>$maxTokens],JSON_THROW_ON_ERROR);
        foreach($this->http->stream('POST',rtrim($this->base,'/').'/chat/completions',$this->headers(),$body) as $chunk){foreach($decoder->feed($chunk) as $data){if($data==='[DONE]'){return;}$event=json_decode($data,true,32,JSON_THROW_ON_ERROR);if(isset($event['error'])){throw new \RuntimeException('AI provider rejected the inference.');}$choice=$event['choices'][0]??null;if($choice!==null){$text=$choice['delta']['content']??'';if(!is_string($text)){throw new \RuntimeException('Invalid model text delta.');}if($text!==''){yield $text;}if(($choice['finish_reason']??null)!==null){$finished=true;}}}}
        $decoder->finish();if(!$finished){throw new \RuntimeException('AI stream ended before completion.');}
    }
    public function embed(array $texts):array
    {
        if($this->embeddingModel===''||!array_is_list($texts)||count($texts)<1||count($texts)>16){throw new \InvalidArgumentException('Configure an embedding model and 1..16 texts.');}foreach($texts as $text){if(!is_string($text)||strlen($text)>4096){throw new \InvalidArgumentException('Embedding text exceeds 4096 bytes.');}}
        $data=HttpJson::request($this->http,'POST',rtrim($this->base,'/').'/embeddings',$this->headers(),json_encode(['model'=>$this->embeddingModel,'input'=>$texts],JSON_THROW_ON_ERROR),maxBytes:4194304);$result=[];foreach($data['data']??[] as $row){$index=$row['index']??null;if(!is_int($index)||$index<0||$index>=count($texts)||isset($result[$index])){throw new \RuntimeException('Invalid embedding indices.');}$vector=$row['embedding']??[];VectorRules::vector($vector);$result[$index]=$vector;}ksort($result);if(count($result)!==count($texts)){throw new \RuntimeException('Embedding response count mismatch.');}return array_values($result);
    }
}
