<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/autoload.php';
use Corevia\Cli\FeatureResolver;
function check(bool $ok):void{if(!$ok){throw new RuntimeException('Feature contract failed.');}}
function rejects(callable $work):void{try{$work();}catch(InvalidArgumentException|LogicException){return;}throw new RuntimeException('Invalid feature graph accepted.');}
$catalog=FeatureResolver::catalog();$plan=FeatureResolver::resolve(['ai'],$catalog);
check($plan['order']===['web','auth','ai']);
check($plan['manifest_fingerprint']===FeatureResolver::resolve(['auth','web','ai'],$catalog)['manifest_fingerprint']);
check(!$plan['hooks_executed']);
rejects(fn()=>FeatureResolver::resolve(['api','auth'],$catalog));
rejects(fn()=>FeatureResolver::resolve(['missing'],$catalog));
rejects(fn()=>FeatureResolver::resolve(['web'],$catalog,'2.0.0'));
rejects(fn()=>FeatureResolver::resolve(['web'],$catalog,'4.0.0','8.1.0'));
$cycle=$catalog;$cycle['web']['requires']=['ai'];rejects(fn()=>FeatureResolver::resolve(['web'],$cycle));
$hooks=$catalog;$hooks['web']['hooks']=['execute'];rejects(fn()=>FeatureResolver::resolve(['web'],$hooks));
$ambiguous=$catalog;$ambiguous['ai']['provides']=['auth.session'];rejects(fn()=>FeatureResolver::resolve(['ai'],$ambiguous));
$invalid=$catalog;$invalid['web']['framework']['before']='2.0.0';rejects(fn()=>FeatureResolver::resolve(['web'],$invalid));
$backwards=$catalog;$backwards['web']['framework']=['before'=>'5.0.0','min'=>'3.0.0'];check(FeatureResolver::resolve(['web'],$backwards)['order']===['web']);
$permuted=$catalog;$permuted['web']['provides']=['z.web','http.web'];$first=FeatureResolver::resolve(['ai'],$permuted);
$permuted['web']['provides']=array_reverse($permuted['web']['provides']);$permuted=array_reverse($permuted,true);
check($first['manifest_fingerprint']===FeatureResolver::resolve(['ai'],$permuted)['manifest_fingerprint']);
echo "PASS deterministic dependency closure, conflicts, runtime bounds, cycles, capability ambiguity and executable-hook rejection\n";
