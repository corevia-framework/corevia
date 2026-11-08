<?php
declare(strict_types=1);
namespace Corevia\Http;
use Corevia\Core\{Deadline,EventOutbox,WorkContext};

/** Fixed operator-owned endpoint registry. Never accepts delivery URLs from events or subscribers. */
final readonly class WebhookDelivery
{
    public function __construct(private array $endpoints,private HttpClient $http=new NativeHttpClient(timeout:10),private bool $developmentLoopback=false)
    {
        if (count($endpoints)>128) { throw new \InvalidArgumentException('Too many webhook endpoints.'); }
        foreach ($endpoints as $topic=>$endpoint) {
            $url=is_array($endpoint)?parse_url($endpoint['url']??''):false;
            $local=$url&&$developmentLoopback&&in_array($url['host']??'',['127.0.0.1','[::1]'],true)&&($url['scheme']??'')==='http';
            if (!is_string($topic)||!preg_match('/^[a-z][a-z0-9_.:-]{0,127}$/D',$topic)||!$url||!isset($url['host'])
                ||(!$local&&($url['scheme']??'')!=='https')||isset($url['user'])||isset($url['pass'])||isset($url['fragment'])||isset($url['query'])
                ||preg_match('/[\\\\\x00-\x20]/',$endpoint['url'])||!(($endpoint['signature']??null) instanceof WebhookSignature)) {
                throw new \InvalidArgumentException('Webhooks require fixed HTTPS endpoints and independent signing keys.');
            }
        }
    }
    public function relay(EventOutbox $outbox,int $now,int $maxAttempts=6):bool
    {
        return $outbox->relay(function(array $event)use($now):void {
            $endpoint=$this->endpoints[$event['topic']]??throw new \LogicException('Webhook topic has no configured destination.');
            $body=json_encode(['id'=>$event['event_id'],'type'=>$event['topic'],'data'=>$event['data']],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
            $signature=$endpoint['signature']->sign($body,$event['event_id'],$now);
            WorkContext::run(new Deadline(10000),function()use($endpoint,$body,$signature,$event):void {
                $size=0;
                foreach ($this->http->stream('POST',$endpoint['url'],['Content-Type'=>'application/json','Corevia-Event-Id'=>$event['event_id'],
                    'Corevia-Signature'=>$signature,'Idempotency-Key'=>$event['event_id']],$body) as $chunk) {
                    $size+=strlen($chunk);if ($size>65536) { throw new \LengthException('Webhook response exceeds 64 KiB.'); }
                }
            });
        },$now,30,$maxAttempts);
    }
}
