<?php
declare(strict_types=1);
namespace Corevia\Core;
/** Plain-text message catalogs; views remain responsible for context-appropriate escaping. */
final readonly class Translator
{
    public function __construct(private array $catalogs,public string $locale='en',private string $fallback='en')
    {
        foreach([$locale,$fallback,...array_keys($catalogs)] as $name){if(!is_string($name)||!preg_match('/^[a-z]{2,3}(?:-[A-Z]{2})?$/D',$name)){throw new \InvalidArgumentException('Invalid locale.');}}
        foreach($catalogs as $messages){if(!is_array($messages)){throw new \InvalidArgumentException('Invalid translation catalog.');}foreach($messages as $key=>$value){if(!is_string($key)||!is_string($value)){throw new \InvalidArgumentException('Translation messages must be plain strings.');}}}
    }
    public function text(string $key,array $parameters=[]):string
    {
        $message=$this->catalogs[$this->locale][$key]??$this->catalogs[$this->fallback][$key]??$key;$replace=[];
        foreach($parameters as $name=>$value){if(!is_string($name)||!is_scalar($value)){throw new \InvalidArgumentException('Invalid translation parameter.');}$replace[':'.$name]=(string)$value;}
        return strtr($message,$replace);
    }
    public function direction():string{return in_array(explode('-',$this->locale)[0],['ar','ur','fa','he'],true)?'rtl':'ltr';}
}
