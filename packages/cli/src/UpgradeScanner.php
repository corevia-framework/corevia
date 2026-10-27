<?php
declare(strict_types=1);
namespace Corevia\Cli;

/** Read-only compatibility report. Behavioral checks still require an application's own tests. */
final class UpgradeScanner
{
    public function scan(string $basePath): array
    {
        $issues=[];
        if (PHP_VERSION_ID<80200) { $issues[]=['severity'=>'error','code'=>'PHP_VERSION','message'=>'PHP 8.2 or newer is required.']; }
        $directory=$basePath.'/app'; $files=0;
        if (is_dir($directory)) {
            $iterator=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory,\FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->getExtension()!=='php') { continue; } $files++;
                $source=file_get_contents($file->getPathname());
                if (preg_match('/->(?:registeredActions|registeredFlows|handlers)\s*(?:\[|=)/',$source)) { $issues[]=['severity'=>'warning','code'=>'DIRECT_REGISTRATION_MUTATION','file'=>substr($file->getPathname(),strlen($basePath)+1),'message'=>'Use ModuleContext registration methods inside register().']; }
            }
        }
        return ['target'=>'2.0.0','files_scanned'=>$files,'issues'=>$issues,'compatible'=>!array_filter($issues,fn(array $issue):bool=>$issue['severity']==='error'),'manual_review'=>['Register modules/hooks before boot; use a new app after shutdown.','Keep CVP/1 constructors or explicitly select CVP/2 in every peer.','Review paused workflows before deploying changed action or condition code.','Authorize resume/approval endpoints and verify external side-effect idempotency.'],'mutated_files'=>false];
    }
}
