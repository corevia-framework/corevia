<?php
declare(strict_types=1);
namespace Corevia\Http;
use Corevia\Core\Telemetry;

/** Optional OTLP/HTTP JSON export to an operator-configured collector; never retries automatically. */
final readonly class OtlpTraceExporter
{
    public function __construct(private HttpClient $http,private string $endpoint,private array $headers=[],private ?string $metricsEndpoint=null)
    {
        foreach(array_filter([$endpoint,$metricsEndpoint],static fn($url)=>$url!==null) as $endpoint){
        $url=parse_url($endpoint);
        if (!$url||!in_array($url['scheme']??'',['http','https'],true)||!isset($url['host'])||isset($url['user'])||isset($url['pass'])||isset($url['fragment'])||preg_match('/[\x00-\x20]/',$endpoint)) { throw new \InvalidArgumentException('Use a fixed collector endpoint.'); }
        }
    }
    public function export(Telemetry $telemetry):array
    {
        $batch=$telemetry->drain();$spans=[];
        foreach ($batch['spans'] as $span) {
            $item=['traceId'=>$span['traceId'],'spanId'=>$span['spanId'],'name'=>$span['name'],
                'kind'=>array_search($span['kind'],['unspecified','internal','server','client','producer','consumer'],true),
                'startTimeUnixNano'=>$span['startTimeUnixNano'],'endTimeUnixNano'=>$span['endTimeUnixNano'],
                'status'=>['code'=>$span['errorType']===null?1:2]];
            if ($span['parentSpanId']!==null) { $item['parentSpanId']=$span['parentSpanId']; }
            if ($span['errorType']!==null) { $item['attributes']=[['key'=>'error.type','value'=>['stringValue'=>$span['errorType']]]]; }
            $spans[]=$item;
        }
        if($this->metricsEndpoint!==null&&$batch['metrics']!==[]){
            $counts=[];$durations=[];foreach($batch['metrics'] as $metric){
                $point=['attributes'=>[['key'=>'corevia.operation','value'=>['stringValue'=>$metric['operation']]],['key'=>'corevia.outcome','value'=>['stringValue'=>$metric['outcome']]]],
                    'startTimeUnixNano'=>$batch['start_unix_ns'],'timeUnixNano'=>$batch['end_unix_ns']];
                $counts[]=[...$point,'asInt'=>(string)$metric['count']];
                $durations[]=[...$point,'asDouble'=>$metric['duration_ns']/1e9];
            }
            $metrics=[['name'=>'corevia.operations','unit'=>'{operation}','sum'=>['aggregationTemporality'=>1,'isMonotonic'=>true,'dataPoints'=>$counts]],
                ['name'=>'corevia.operation.duration','unit'=>'s','sum'=>['aggregationTemporality'=>1,'isMonotonic'=>true,'dataPoints'=>$durations]]];
            $this->send($this->metricsEndpoint,['resourceMetrics'=>[['resource'=>['attributes'=>[['key'=>'service.name','value'=>['stringValue'=>$batch['service']]]]],'scopeMetrics'=>[['scope'=>['name'=>'corevia'],'metrics'=>$metrics]]]]]);
        }
        if ($spans===[]) { return ['exported'=>0,'dropped'=>$batch['dropped_spans']]; }
        $this->send($this->endpoint,['resourceSpans'=>[['resource'=>['attributes'=>[['key'=>'service.name','value'=>['stringValue'=>$batch['service']]]]],
            'scopeSpans'=>[['scope'=>['name'=>'corevia'],'spans'=>$spans]]]]]);
        return ['exported'=>count($spans),'dropped'=>$batch['dropped_spans']];
    }
    private function send(string $endpoint,array $packet):void
    {
        $body=json_encode($packet,JSON_THROW_ON_ERROR);$response='';
        foreach ($this->http->stream('POST',$endpoint,[...$this->headers,'Content-Type'=>'application/json'],$body) as $chunk) {
            $response.=$chunk;if (strlen($response)>65536) { throw new \LengthException('Collector response exceeds 64 KiB.'); }
        }
        $decoded=$response===''?new \stdClass():json_decode($response,false,16,JSON_THROW_ON_ERROR);
        if (!$decoded instanceof \stdClass) { throw new \RuntimeException('TRACE_EXPORT_NOT_FULLY_ACCEPTED'); }
        if(property_exists($decoded,'partialSuccess')){
            // Collector 0.165.0 emits an empty protobuf message on full acceptance.
            // Only its default-valued form is equivalent to an unset field. A warning,
            // rejected item, malformed value or unknown partial field stays observable.
            $partial=$decoded->partialSuccess;
            if(!$partial instanceof \stdClass){throw new \RuntimeException('TRACE_EXPORT_NOT_FULLY_ACCEPTED');}
            foreach(get_object_vars($partial)as $key=>$value){
                $empty=match($key){
                    'rejectedSpans','rejectedDataPoints'=>$value===0||$value==='0',
                    'errorMessage'=>$value==='',
                    default=>false,
                };
                if(!$empty){throw new \RuntimeException('TRACE_EXPORT_NOT_FULLY_ACCEPTED');}
            }
        }
    }
}
