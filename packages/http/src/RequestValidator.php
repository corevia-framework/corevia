<?php
declare(strict_types=1);
namespace Corevia\Http;
use Corevia\Core\Validator;

/** Opt-in nested validation; existing flat Validator semantics remain unchanged. */
final class RequestValidator
{
    public function validate(array $input,array $rules):array
    {
        if(count($rules)>256){throw new \LengthException('Too many request fields.');}
        $errors=[];$validator=new Validator();
        foreach($rules as $pattern=>$checks){
            if(is_string($pattern)&&substr_count($pattern,'.')>15){throw new \LengthException('Nested validation depth exceeded.');}
            if(!is_string($pattern)||!preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\.(?:[A-Za-z_][A-Za-z0-9_]*|\*))*(?:\.[0-9]+)?$/D',$pattern)||!is_array($checks)){throw new \InvalidArgumentException('Invalid nested validation rule.');}
            $paths=[['value'=>$input,'path'=>'','present'=>true]];
            foreach(explode('.',$pattern) as $part){
                $next=[];
                foreach($paths as $item){
                    if($part==='*'){
                        if(!is_array($item['value'])||!array_is_list($item['value'])){$errors[rtrim($item['path'],'.')?:$pattern][]='array';continue;}
                        if(count($item['value'])>1000){throw new \LengthException('Nested input exceeds item bound.');}
                        foreach($item['value'] as $index=>$value){$next[]=['value'=>$value,'path'=>$item['path'].$index.'.','present'=>true];}
                    }else{$present=$item['present']&&is_array($item['value'])&&array_key_exists($part,$item['value']);$next[]=['value'=>$present?$item['value'][$part]:null,'path'=>$item['path'].$part.'.','present'=>$present];}
                    if(count($next)>2000){throw new \LengthException('Nested validation expansion exceeded.');}
                }$paths=$next;
            }
            foreach($paths as $item){$field=rtrim($item['path'],'.');$invalid=$validator->validate($item['present']?['value'=>$item['value']]:[],['value'=>$checks]);if(isset($invalid['value'])){$errors[$field]=array_values(array_unique([...($errors[$field]??[]),...$invalid['value']]));}}
        }ksort($errors,SORT_STRING);return $errors;
    }
}
