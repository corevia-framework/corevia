<?php
declare(strict_types=1);
namespace Corevia\Ai;
use Corevia\Core\WorkContext;
use Corevia\Http\{HttpClient,NativeHttpClient};

/** Optional dense cosine index. Tenant predicates and response ownership checks are mandatory. */
final readonly class QdrantVectorIndex implements VectorIndex,RevisionVectorIndex
{
    public function __construct(private string $origin,private string $collection,private int $dimensions,
        private ?string $apiKey=null,private HttpClient $http=new NativeHttpClient(timeout:10),private bool $developmentLoopback=false)
    {
        $url=parse_url($origin);$local=$url&&$developmentLoopback&&in_array($url['host']??'',['127.0.0.1','[::1]'],true)&&($url['scheme']??'')==='http';
        if (!$url||!isset($url['host'])||(!$local&&($url['scheme']??'')!=='https')||isset($url['user'])||isset($url['pass'])||isset($url['query'])||isset($url['fragment'])
            ||!in_array($url['path']??'',['','/'],true)||preg_match('/[\\\\\x00-\x20]/',$origin)||str_ends_with($origin,'/')
            ||!preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,63}$/D',$collection)||$dimensions<1||$dimensions>4096
            ||($apiKey!==null&&($apiKey===''||strlen($apiKey)>4096||preg_match('/[\x00-\x20]/',$apiKey)))) { throw new \InvalidArgumentException('Use a fixed Qdrant origin, collection, dimensions and private API key.'); }
    }
    private function path():string { return '/collections/'.$this->collection; }
    private function request(string $method,string $path,?array $data=null):mixed
    {
        WorkContext::current()?->check();$headers=['Content-Type'=>'application/json'];if ($this->apiKey!==null) { $headers['api-key']=$this->apiKey; }
        $body=$data===null?'':json_encode($data,JSON_THROW_ON_ERROR);$wire='';
        foreach ($this->http->stream($method,$this->origin.$path,$headers,$body) as $chunk) { $wire.=$chunk;if (strlen($wire)>1048576) { throw new \LengthException('Qdrant response exceeds 1 MiB.'); } }
        $reply=json_decode($wire,true,32,JSON_THROW_ON_ERROR);
        if (!is_array($reply)||($reply['status']??null)!=='ok'||!array_key_exists('result',$reply)) { throw new \RuntimeException('VECTOR_SERVICE_REJECTED'); }
        WorkContext::current()?->check();return $reply['result'];
    }
    /** Explicit operator provisioning; never reconfigures or deletes an existing collection. */
    public function createCollection():void
    {
        $this->request('PUT',$this->path(),['vectors'=>['size'=>$this->dimensions,'distance'=>'Cosine'],
            'on_disk_payload'=>true,'optimizers_config'=>['default_segment_number'=>1]]);
        $this->request('PUT',$this->path().'/index?wait=true',['field_name'=>'tenant','field_schema'=>['type'=>'keyword','is_tenant'=>true]]);
        $this->request('PUT',$this->path().'/index?wait=true',['field_name'=>'revision','field_schema'=>'keyword']);
    }
    public function assertCollection():void
    {
        $result=$this->request('GET',$this->path());$vectors=$result['config']['params']['vectors']??[];
        if (($vectors['size']??null)!==$this->dimensions||($vectors['distance']??null)!=='Cosine') { throw new \RuntimeException('VECTOR_COLLECTION_MISMATCH'); }
    }
    private function vector(array $vector):void { VectorRules::vector($vector);if (count($vector)!==$this->dimensions) { throw new \InvalidArgumentException('Vector dimension mismatch.'); } }
    private function point(string $tenant,string $id):string
    {
        $hash=hash('sha256',$tenant."\0".$id);return substr($hash,0,8).'-'.substr($hash,8,4).'-4'.substr($hash,13,3).'-8'.substr($hash,17,3).'-'.substr($hash,20,12);
    }
    public function put(string $tenant,string $id,string $text,array $vector):void { $this->putPoint($tenant,$id,$text,$vector); }
    private function putPoint(string $tenant,string $id,string $text,array $vector,?string $revision=null):void
    {
        VectorRules::identity($tenant,$id);$this->vector($vector);if (strlen($text)>16384||preg_match('//u',$text)!==1) { throw new \InvalidArgumentException('Use a bounded UTF-8 chunk.'); }
        $this->request('PUT',$this->path().'/points?wait=true',['points'=>[['id'=>$this->point($tenant,$id),'vector'=>$vector,'payload'=>['tenant'=>$tenant,'document_id'=>$id,'text'=>$text,...($revision===null?[]:['revision'=>$revision])]]]]);
    }
    public function search(string $tenant,array $vector,int $limit=5):array { return $this->searchFiltered($tenant,$vector,$limit); }
    private function searchFiltered(string $tenant,array $vector,int $limit,?array $revisions=null):array
    {
        VectorRules::identity($tenant,'query');$this->vector($vector);if ($limit<1||$limit>20) { throw new \InvalidArgumentException('Choose 1..20 vector results.'); }
        $result=$this->request('POST',$this->path().'/points/query',['query'=>$vector,'filter'=>['must'=>[['key'=>'tenant','match'=>['value'=>$tenant]],...($revisions===null?[]:[['key'=>'revision','match'=>['any'=>$revisions]]])]],
            'limit'=>$limit,'with_payload'=>['tenant','document_id','text','revision'],'with_vector'=>false]);
        $points=$result['points']??null;if (!is_array($points)||!array_is_list($points)||count($points)>$limit) { throw new \RuntimeException('INVALID_VECTOR_RESULTS'); }
        $hits=[];
        foreach ($points as $point) {
            $payload=$point['payload']??[];$id=$payload['document_id']??null;$text=$payload['text']??null;$score=$point['score']??null;
            if (($revisions!==null&&!in_array($payload['revision']??null,$revisions,true))||($payload['tenant']??null)!==$tenant||!is_string($id)||!is_string($text)||strlen($text)>16384||preg_match('//u',$text)!==1
                ||(!is_int($score)&&!is_float($score))||!is_finite((float)$score)||abs($score)>1.00001) { throw new \RuntimeException('VECTOR_RESULT_OWNERSHIP_OR_SHAPE'); }
            VectorRules::identity($tenant,$id);
            if (($point['id']??null)!==$this->point($tenant,$id)) { throw new \RuntimeException('VECTOR_RESULT_OWNERSHIP_OR_SHAPE'); }
            $hits[]=['id'=>$id,'text'=>$text,'score'=>(float)$score];
        }
        usort($hits,static fn($a,$b)=>($b['score']<=>$a['score'])?:strcmp($a['id'],$b['id']));return $hits;
    }
    public function delete(string $tenant,string $id):void
    {
        VectorRules::identity($tenant,$id);$this->request('POST',$this->path().'/points/delete?wait=true',['filter'=>['must'=>[
            ['key'=>'tenant','match'=>['value'=>$tenant]],['has_id'=>[$this->point($tenant,$id)]],
        ]]]);
    }
    private function revision(string $revision):void { if(!preg_match('/^[a-f0-9]{32}$/D',$revision)){throw new \InvalidArgumentException('Invalid vector revision.');} }
    public function putRevision(string $tenant,string $revision,string $id,string $text,array $vector):void
    {
        $this->revision($revision);$this->putPoint($tenant,$id,$text,$vector,$revision);
    }
    public function searchRevisions(string $tenant,array $revisions,array $vector,int $limit=5):array
    {
        if(!array_is_list($revisions)||count($revisions)>128){throw new \InvalidArgumentException('Use at most 128 explicit revisions.');}
        foreach($revisions as $revision){$this->revision($revision);}if($revisions===[]){VectorRules::identity($tenant,'query');$this->vector($vector);if($limit<1||$limit>20){throw new \InvalidArgumentException('Choose 1..20 results.');}return [];}
        return $this->searchFiltered($tenant,$vector,$limit,$revisions);
    }
    public function deleteRevision(string $tenant,string $revision):void
    {
        VectorRules::identity($tenant,'delete');$this->revision($revision);
        $this->request('POST',$this->path().'/points/delete?wait=true',['filter'=>['must'=>[['key'=>'tenant','match'=>['value'=>$tenant]],['key'=>'revision','match'=>['value'=>$revision]]]]]);
    }

}
