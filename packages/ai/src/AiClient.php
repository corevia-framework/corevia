<?php
declare(strict_types=1);
namespace Corevia\Ai;
use Corevia\Core\ScopeManager;
final readonly class AiClient
{
    public function __construct(private AiProvider $provider,private ScopeManager $scopes){}
    public function stream(array $messages,callable $emit,int $maxTokens=256,?callable $cancelled=null):void
    {
        if(!array_is_list($messages)||count($messages)<1||count($messages)>64||$maxTokens<1||$maxTokens>8192){throw new \InvalidArgumentException('Invalid inference bounds.');}$size=0;foreach($messages as $message){if(!is_array($message)||!in_array($message['role']??null,['system','user','assistant'],true)||!is_string($message['content']??null)||preg_match('//u',$message['content'])!==1||array_diff(array_keys($message),['role','content'])!==[]){throw new \InvalidArgumentException('Use bounded text messages.');}$size+=strlen($message['content']);}if($size>65536){throw new \LengthException('Inference context exceeds 64 KiB.');}
        $this->scopes->run('ai.inference',function()use($messages,$emit,$maxTokens,$cancelled):void{if($cancelled!==null&&$cancelled()){throw new \RuntimeException('AI_CANCELLED');}$bytes=0;foreach($this->provider->stream($messages,$maxTokens) as $text){if(!is_string($text)||preg_match('//u',$text)!==1){throw new \RuntimeException('Invalid provider delta.');}$bytes+=strlen($text);if($bytes>1048576){throw new \LengthException('Inference output exceeds 1 MiB.');}preg_match_all('/.{1,4096}/us',$text,$parts);foreach($parts[0] as $part){if(($cancelled!==null&&$cancelled())||$emit($part)===false){throw new \RuntimeException('AI_CANCELLED');}}}},['max_tokens'=>$maxTokens]);
    }
    public function complete(array $messages,int $maxTokens=256):string{$text='';$this->stream($messages,static function(string $chunk)use(&$text):void{$text.=$chunk;},$maxTokens);return $text;}
}
