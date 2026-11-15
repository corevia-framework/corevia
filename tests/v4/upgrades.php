<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/autoload.php';
require dirname(__DIR__).'/support/Fixtures.php';
use Corevia\Cli\{StarterInstaller,StarterUpgradePlanner};
function check(bool $ok):void{if(!$ok){throw new RuntimeException('Starter upgrade assertion failed.');}}
function rejects(callable $work):void{try{$work();}catch(InvalidArgumentException|LogicException){return;}throw new RuntimeException('Unsafe starter plan accepted.');}
$directory=sys_get_temp_dir().'/corevia-v4-plan-'.bin2hex(random_bytes(8));mkdir($directory);
try{
    mkdir($directory.'/packages/auth/src',0755,true);file_put_contents($directory.'/corevia','');file_put_contents($directory.'/autoload.php','');file_put_contents($directory.'/packages/auth/src/AuthService.php','');
    (new StarterInstaller(dirname(__DIR__,2)))->install('bootstrap',$directory);
    $marker=$directory.'/.corevia-auth-starter.json';$wire=file_get_contents($marker);$original=json_decode($wire,true,32,JSON_THROW_ON_ERROR);
    $plan=StarterUpgradePlanner::plan($directory);check(!$plan['requires_review']&&!$plan['files_written']&&!$plan['can_apply']);
    $path='config/auth.php';$file=$directory.'/'.$path;$current=file_get_contents($file);
    $inventory=$original;$inventory['files'][$path]=hash('sha256','previous template');
    file_put_contents($marker,json_encode($inventory,JSON_THROW_ON_ERROR));file_put_contents($file,'previous template');
    $status=static function(array $plan,string $path):string{foreach($plan['files'] as $row){if($row['path']===$path){return $row['status'];}}throw new RuntimeException('Missing plan path.');};
    check($status(StarterUpgradePlanner::plan($directory),$path)==='update_available');
    file_put_contents($file,'application customization');$before=hash_file('sha256',$file);
    check($status(StarterUpgradePlanner::plan($directory),$path)==='conflict');check(hash_file('sha256',$file)===$before);
    unlink($file);check($status(StarterUpgradePlanner::plan($directory),$path)==='deleted_by_application');
    unset($inventory['files'][$path]);file_put_contents($marker,json_encode($inventory,JSON_THROW_ON_ERROR));
    check($status(StarterUpgradePlanner::plan($directory),$path)==='new_file');
    file_put_contents($file,'unrelated existing file');check($status(StarterUpgradePlanner::plan($directory),$path)==='conflict');
    $inventory['files']['old-page.txt']=hash('sha256','old page');file_put_contents($directory.'/old-page.txt','old page');file_put_contents($marker,json_encode($inventory,JSON_THROW_ON_ERROR));
    check($status(StarterUpgradePlanner::plan($directory),'old-page.txt')==='obsolete');
    check(is_file($directory.'/old-page.txt'));
    $inventory['files']['../escape.txt']=str_repeat('0',64);file_put_contents($marker,json_encode($inventory,JSON_THROW_ON_ERROR));rejects(fn()=>StarterUpgradePlanner::plan($directory));
    file_put_contents($marker,$wire);file_put_contents($file,$current);check(!StarterUpgradePlanner::plan($directory)['requires_review']);
    check(file_get_contents($marker)===$wire);
    echo "PASS read-only starter upgrade preview: clean, changed templates, edited/deleted/new/obsolete files and traversal rejection\n";
}finally{\CoreviaTests\Fixtures::remove($directory,'corevia-v4-plan-');}
