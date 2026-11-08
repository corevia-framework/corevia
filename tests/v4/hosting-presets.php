<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/autoload.php';
use Corevia\Cli\HostingPreset;
function check(bool $ok):void{if(!$ok){throw new RuntimeException('Hosting recipe contract failed.');}}
function rejects(callable $work):void{try{$work();}catch(InvalidArgumentException){return;}throw new RuntimeException('Unsafe output accepted.');}
$root=dirname(__DIR__,2);$fixture=$root.'/storage/hosting-fixture-'.bin2hex(random_bytes(8));
mkdir($fixture.'/storage',0700,true);
function clean(string $path):void{if(is_link($path)||is_file($path)){unlink($path);return;}foreach(new FilesystemIterator($path)as $entry){clean($entry->getPathname());}rmdir($path);}
try{
    foreach(['shared','vps','aws','azure']as $target){
        $preview=HostingPreset::prepare($fixture,$target,$target,true);
        check(!$preview['written']&&!$preview['target_validated']&&!$preview['production_ready']&&!file_exists($fixture.'/storage/'.$target));
        $report=HostingPreset::prepare($fixture,$target,$target);
        check($report['written']&&$report['files']===$preview['files']);
        foreach($report['files']as $name=>$hash){check(hash_file('sha256',$report['output'].'/'.$name)===$hash);}
        rejects(fn()=>HostingPreset::prepare($fixture,$target,$target));
    }
    foreach(['../public','/tmp/corevia','a/../b','a\\b','a//b','azure/extra','C:/temp','x\nfile','']as $bad){rejects(fn()=>HostingPreset::prepare($fixture,'shared',$bad,true));}
    rejects(fn()=>HostingPreset::prepare($fixture,'unknown','valid',true));
    if(PHP_OS_FAMILY!=='Windows'){
        symlink($fixture.'/storage/azure',$fixture.'/storage/link');
        rejects(fn()=>HostingPreset::prepare($fixture,'shared','link/child',true));
    }
    echo "PASS hosting recipe hashes, write-free previews, private output boundaries and overwrite refusal\n";
}finally{clean($fixture);}
