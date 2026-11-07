<?php
declare(strict_types=1);

// Trusted framework source only, isolated in its own PHP process; no application bootstrap.
$root=$argv[1]??throw new InvalidArgumentException('Provide a framework checkout.');
require $root.'/autoload.php';
$symbols=[];
$files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/packages',FilesystemIterator::SKIP_DOTS));
foreach($files as $file){
    if(!$file->isFile()||$file->getExtension()!=='php'||!str_contains(str_replace('\\','/',$file->getPathname()),'/src/')){continue;}
    $tokens=token_get_all(file_get_contents($file->getPathname()));$namespace='';
    for($index=0,$count=count($tokens);$index<$count;$index++){
        $token=$tokens[$index];if(!is_array($token)){continue;}
        if($token[0]===T_NAMESPACE){
            $namespace='';
            for($next=$index+1;$next<$count;$next++){
                if($tokens[$next]===';'||$tokens[$next]==='{'){break;}
                if(is_array($tokens[$next])&&in_array($tokens[$next][0],[T_STRING,T_NAME_QUALIFIED,T_NS_SEPARATOR],true)){$namespace.=$tokens[$next][1];}
            }
        }
        if(!in_array($token[0],[T_CLASS,T_INTERFACE,T_TRAIT,T_ENUM],true)){continue;}
        $next=$index+1;while(isset($tokens[$next])&&is_array($tokens[$next])&&in_array($tokens[$next][0],[T_WHITESPACE,T_COMMENT,T_DOC_COMMENT],true)){$next++;}
        if(isset($tokens[$next])&&is_array($tokens[$next])&&$tokens[$next][0]===T_STRING){$symbols[]=$namespace.'\\'.$tokens[$next][1];}
    }
}
sort($symbols,SORT_STRING);$api=[];
$constantValue=static function(mixed $value)use(&$constantValue):mixed{
    if($value instanceof BackedEnum){return ['enum'=>$value::class,'case'=>$value->name,'value'=>$value->value];}
    if($value instanceof UnitEnum){return ['enum'=>$value::class,'case'=>$value->name];}
    if(is_array($value)){foreach($value as $key=>$item){$value[$key]=$constantValue($item);}}
    return $value;
};
foreach(array_unique($symbols) as $symbol){
    if(!str_starts_with($symbol,'Corevia\\')){continue;}
    $class=new ReflectionClass($symbol);$methods=[];$properties=[];$constants=[];
    foreach($class->getMethods(ReflectionMethod::IS_PUBLIC|ReflectionMethod::IS_PROTECTED) as $method){
        if($method->getDeclaringClass()->getName()!==$symbol){continue;}
        $parameters=[];
        foreach($method->getParameters() as $parameter){
            $parameters[]=['name'=>$parameter->getName(),'type'=>(string)$parameter->getType(),
                'optional'=>$parameter->isOptional(),'variadic'=>$parameter->isVariadic(),
                'reference'=>$parameter->isPassedByReference(),
                'default_constant'=>$parameter->isDefaultValueAvailable()&&$parameter->isDefaultValueConstant()?$parameter->getDefaultValueConstantName():null];
        }
        $methods[$method->getName()]=['visibility'=>$method->isPublic()?'public':'protected',
            'static'=>$method->isStatic(),'abstract'=>$method->isAbstract(),'final'=>$method->isFinal(),
            'reference'=>$method->returnsReference(),'return'=>(string)$method->getReturnType(),'parameters'=>$parameters];
    }
    foreach($class->getProperties(ReflectionProperty::IS_PUBLIC|ReflectionProperty::IS_PROTECTED) as $property){
        if($property->getDeclaringClass()->getName()!==$symbol){continue;}
        $properties[$property->getName()]=['visibility'=>$property->isPublic()?'public':'protected',
            'static'=>$property->isStatic(),'readonly'=>$property->isReadOnly(),'type'=>(string)$property->getType()];
    }
    foreach($class->getReflectionConstants() as $constant){
        if($constant->isPrivate()||$constant->getDeclaringClass()->getName()!==$symbol){continue;}
        $constants[$constant->getName()]=['visibility'=>$constant->isPublic()?'public':'protected','final'=>$constant->isFinal(),
            'value'=>$constantValue($constant->getValue())];
    }
    ksort($methods,SORT_STRING);ksort($properties,SORT_STRING);ksort($constants,SORT_STRING);
    $interfaces=$class->getInterfaceNames();sort($interfaces,SORT_STRING);
    $traits=$class->getTraitNames();sort($traits,SORT_STRING);
    $api[$symbol]=['kind'=>$class->isInterface()?'interface':($class->isTrait()?'trait':($class->isEnum()?'enum':'class')),
        'abstract'=>$class->isAbstract(),'final'=>$class->isFinal(),'readonly'=>$class->isReadOnly(),
        'parent'=>($class->getParentClass()?:null)?->getName(),'interfaces'=>$interfaces,'traits'=>$traits,
        'methods'=>(object)$methods,'properties'=>(object)$properties,'constants'=>(object)$constants];
}
echo json_encode(['format'=>1,'symbols'=>(object)$api],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
