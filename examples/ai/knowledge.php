<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404);exit; }
require dirname(__DIR__,2).'/autoload.php';
use Corevia\Ai\{EmbeddingProvider,KnowledgeBase,SqlRevisionVectors};
use Corevia\Core\TenantScope;
use Corevia\Persistence\{SqlConnection,SqlRevisionCatalog};

$directory=dirname(__DIR__,2).'/storage/ai-knowledge-example';
if (!is_dir($directory)&&!mkdir($directory,0700,true)&&!is_dir($directory)) { throw new RuntimeException('Cannot create private example storage.'); }
$db=SqlConnection::connect(['driver'=>'sqlite','database'=>$directory.'/knowledge.sqlite']);$catalog=new SqlRevisionCatalog($db);$vectors=new SqlRevisionVectors($db);
// Deliberately simple teaching fixture, not a semantic model or provider-quality benchmark.
$embeddings=new class implements EmbeddingProvider {
    public function embed(array $texts):array { return array_map(static function($text):array {
        $v=[substr_count(strtolower($text),'php'),substr_count(strtolower($text),'python'),substr_count(strtolower($text),'dart')];return array_sum($v)===0?[0,0,1]:$v;
    },$texts); }
};
$scope=new TenantScope('example','local-learner',fn()=>true,['ai.documents.read','ai.documents.write']);$kb=new KnowledgeBase($embeddings,$vectors,$catalog,'teaching-word-count-v1');
$action=$argv[1]??'help';
$result=match($action) {
    'migrate'=>(static function()use($catalog,$vectors):array { $catalog->migrate();$vectors->migrate();return ['status'=>'migrated']; })(),
    'seed'=>(static function()use($kb,$catalog,$scope):array {
        if ($catalog->active($scope)!==[]) { return ['status'=>'already_seeded']; }
        return [$kb->replace($scope,'php-guide',0,'PHP runs Corevia web routes, controllers and application workflows.'),
            $kb->replace($scope,'python-guide',0,'Python can run optional Corevia model and action workers.')];
    })(),
    'ask'=>$kb->search($scope,$argv[2]??'PHP'),
    'collect'=>['collected'=>$kb->collect($scope)],
    default=>['usage'=>'php examples/ai/knowledge.php migrate|seed|ask "PHP"|collect'],
};
echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
