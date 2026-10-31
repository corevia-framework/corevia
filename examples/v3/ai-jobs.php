<?php
declare(strict_types=1);
use Corevia\Ai\{AiClient,CompatibleProvider};
use Corevia\Core\{ScopeManager,WorkQueue};
use Corevia\Http\NativeHttpClient;
// Copy/merge this factory into routes/jobs.php after explicitly configuring a model.
// Automatic retries can repeat model calls and incur duplicate charges. Use only for repeatable tasks.
return static function(WorkQueue $queue,ScopeManager $scopes):array{
    $base=getenv('AI_BASE_URL')?:throw new LogicException('Configure AI_BASE_URL.');$model=getenv('AI_MODEL')?:throw new LogicException('Configure AI_MODEL.');
    $client=new AiClient(new CompatibleProvider(new NativeHttpClient(getenv('AI_LOCAL')==='true'),$base,$model,getenv('AI_API_KEY')?:''),$scopes);
    return ['ai.infer'=>static function(array $input)use($client):array{$message=$input['message']??null;if(!is_string($message)||strlen($message)>4096){throw new InvalidArgumentException('Use a bounded message.');}return ['text'=>$client->complete([['role'=>'user','content'=>$message]])];}];
};
