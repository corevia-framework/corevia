<?php
declare(strict_types=1);
namespace Corevia\Http;
/** Incremental SSE data parser; UTF-8 JSON is decoded only after a complete event. */
final class SseDecoder
{
    private string $buffer='';
    public function feed(string $chunk):array
    {
        $this->buffer.=$chunk;$events=[];
        while(preg_match('/\r?\n\r?\n/',$this->buffer,$match,PREG_OFFSET_CAPTURE)){$at=$match[0][1];if($at>65536){throw new \LengthException('SSE event exceeds 64 KiB.');}$frame=substr($this->buffer,0,$at);$this->buffer=substr($this->buffer,$at+strlen($match[0][0]));$data=[];foreach(explode("\n",str_replace("\r\n","\n",$frame)) as $line){if(str_starts_with($line,'data:')){$value=substr($line,5);$data[]=str_starts_with($value,' ')?substr($value,1):$value;}}if($data!==[]){$events[]=implode("\n",$data);}}
        if(strlen($this->buffer)>65536){throw new \LengthException('SSE buffer exceeds 64 KiB.');}return $events;
    }
    public function finish():void{if(trim($this->buffer)!==''){throw new \RuntimeException('Truncated SSE event.');}}
}
