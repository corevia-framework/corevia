<?php
declare(strict_types=1);
namespace Corevia\Http;

/** Trusted PHP templates with explicit escaping. Template names are resolved within the view root. */
final readonly class View
{
    public function __construct(private string $directory) {}
    public static function escape(mixed $value): string { return htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
    public function render(string $name,array $data=[]): string
    {
        if(!preg_match('/^[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+)*$/D',$name)){throw new \InvalidArgumentException('Invalid view name.');}
        $root=realpath($this->directory);$file=realpath($this->directory.'/'.str_replace('.','/',$name).'.php');
        if($root===false || $file===false || !str_starts_with($file,$root.DIRECTORY_SEPARATOR)){throw new \OutOfBoundsException('View not found.');}
        $level=ob_get_level();ob_start();
        try{(static function(string $__file,array $__data):void{$e=View::escape(...);extract($__data,EXTR_SKIP);require $__file;})($file,$data);return ob_get_clean();}
        finally{while(ob_get_level()>$level){ob_end_clean();}}
    }
}
