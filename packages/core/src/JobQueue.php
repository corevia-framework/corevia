<?php
declare(strict_types=1);
namespace Corevia\Core;

/** Single-node, scheduled flow jobs. Crashed claims require inspection, never silent replay. */
final class JobQueue
{
    public function __construct(private readonly string $directory)
    {
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) { throw new \RuntimeException('Cannot create private queue.'); }
    }
    public function dispatch(string $flow, array $input, ?int $notBefore = null): string
    {
        if ($flow === '') { throw new \InvalidArgumentException('Flow is required.'); }
        $id = bin2hex(random_bytes(16));
        $job = ['id'=>$id,'flow'=>$flow,'input'=>$input,'not_before'=>$notBefore ?? time(),'status'=>'pending'];
        if (strlen(json_encode($job,JSON_THROW_ON_ERROR))>1048576) { throw new \LengthException('Job exceeds 1MiB.'); }
        $path=$this->directory.'/'.$id.'.tmp';
        $file=fopen($path,'x+b');
        if ($file===false) { throw new \RuntimeException('Cannot create job.'); }
        try {
            try { chmod($path,0600); $this->write($file,$job); } finally { fclose($file); }
            if (!rename($path,$this->directory.'/'.$id.'.json')) { throw new \RuntimeException('Cannot publish job.'); }
        } finally { if (is_file($path)) { unlink($path); } }
        return $id;
    }
    private function write($file, array $job): void
    {
        $wire=json_encode($job,JSON_THROW_ON_ERROR); rewind($file);
        if (fwrite($file,$wire)!==strlen($wire) || !ftruncate($file,strlen($wire)) || !fflush($file)) { throw new \RuntimeException('Cannot save job.'); }
    }
    /** Returns true if one due job was claimed and processed. */
    public function work(Corevia $app): bool
    {
        $app->requireFeature('jobs');
        foreach (glob($this->directory.'/*.json') ?: [] as $path) {
            $file=fopen($path,'r+b');
            if ($file===false) { continue; }
            if (!flock($file,LOCK_EX|LOCK_NB)) { fclose($file); continue; }
            try {
                if (fstat($file)['size']>1048576) { throw new \LengthException('Job is too large.'); }
                $job=json_decode(stream_get_contents($file),true,64,JSON_THROW_ON_ERROR);
                if (($job['status']??null)!=='pending' || $job['not_before']>time()) { continue; }
                $job['status']='running'; $job['claimed_at']=gmdate('c'); $this->write($file,$job);
                try {
                    $result=$app->scope('job',fn():FlowResult=>$app->run($job['flow'],$job['input'],'job:'.$job['id']),['job_id'=>$job['id']]);
                    $job['run_id']=$result->run['run_id']; $job['status']=$result->status();
                } catch (\Throwable $error) { $job['status']='failed'; $job['error']=['code'=>'JOB_FAILED','exception'=>$error::class]; }
                $job['finished_at']=gmdate('c'); $this->write($file,$job); return true;
            } finally { flock($file,LOCK_UN); fclose($file); }
        }
        return false;
    }
}
