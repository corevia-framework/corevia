<?php
declare(strict_types=1);
namespace Corevia\Http;
use Corevia\Protocol\ContractManifest;
final class OpenApi
{
    public static function generate(Router $router,string $title,string $version):array
    {
        if($title===''||strlen($title)>200||!preg_match('/^\d+\.\d+\.\d+$/D',$version)){throw new \InvalidArgumentException('Invalid API identity.');}
        $paths=[];$operations=[];
        foreach($router->documentedRoutes() as $route){
            $doc=$route['documentation'];if($doc===[]){continue;}
            if(isset($operations[$doc['operation']])){throw new \LogicException('Duplicate API operation ID.');}$operations[$doc['operation']]=true;
            $input=$doc['input']?:['type'=>'object','additionalProperties'=>false];$output=$doc['output']?:['type'=>'object'];
            ContractManifest::fromArray(['format'=>1,'name'=>'ApiDocument','version'=>'1.0.0','actions'=>['operation'=>['input'=>$input,'output'=>$output]]]);
            $entry=['operationId'=>$doc['operation'],'responses'=>[(string)($doc['successStatus']??200)=>['description'=>'Successful response','content'=>['application/json'=>['schema'=>$output]]]]];
            preg_match_all('/\{([A-Za-z_][A-Za-z0-9_]*)\}/',$route['path'],$parameters);
            foreach($parameters[1] as $parameter){$entry['parameters'][]=['name'=>$parameter,'in'=>'path','required'=>true,'schema'=>['type'=>'string']];}
            if(in_array($route['method'],['POST','PUT','PATCH'],true)){$entry['requestBody']=['required'=>true,'content'=>['application/json'=>['schema'=>$input]]];}
            if($doc['scopes']!==[]){$entry['security']=[['apiToken'=>[]]];$entry['x-corevia-scopes']=$doc['scopes'];}
            $paths[$route['path']][strtolower($route['method'])]=$entry;
        }
        ksort($paths,SORT_STRING);
        return ['openapi'=>'3.1.0','info'=>['title'=>$title,'version'=>$version],'paths'=>(object)$paths,
            'components'=>['securitySchemes'=>['apiToken'=>['type'=>'http','scheme'=>'bearer','bearerFormat'=>'opaque']]]];
    }
}
