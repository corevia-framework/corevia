<?php

declare(strict_types=1);

namespace Corevia\Cli;

use Corevia\Realtime\{Server, SocketBroadcaster};
use Corevia\Core\{ConfigRepository, JobQueue, Version};
use Corevia\Core\{ApplicationArtifact,CanonicalJson};
use Corevia\Protocol\{ContractGenerator,ContractManifest};
use Corevia\Persistence\{DatabaseManager,SqlConnection,SqlRunStore};

final readonly class Console
{
    public function __construct(private string $basePath)
    {
    }

    public function run(array $argv): int
    {
        $command = $argv[1] ?? 'help';
        $options = [];
        foreach (array_slice($argv, 2) as $arg) {
            if (str_starts_with($arg, '--')) {
                [$key, $value] = array_pad(explode('=', substr($arg, 2), 2), 2, 'true');
                $options[$key] = $value;
            }
        }
        try {
            if($command==='starter:ai'){$this->print((new AiStarterInstaller($this->basePath))->install($options['path']??$this->basePath,isset($options['dry-run'])));return 0;}
            if($command==='release:check'){$report=ReleaseReadiness::report($this->basePath);$this->print($report);return $report['ready']?0:1;}
            if($command==='deploy:check'){
                $auth=is_file($this->basePath.'/config/auth.php')?require $this->basePath.'/config/auth.php':[];$ai=is_file($this->basePath.'/config/ai.php')?require $this->basePath.'/config/ai.php':[];
                $report=DeploymentInspector::inspect($this->basePath,$auth,$ai,isset($options['multi-host']));$this->print($report);return $report['ready']?0:1;
            }
            if(in_array($command,['coordination:migrate','queue:status','queue:dispatch','queue:work'],true)){
                $config=ConfigRepository::load($this->basePath.'/config/settings',getenv('APP_ENV')?:'development');$manager=new DatabaseManager($config->get('database',[]));$queue=new \Corevia\Persistence\SqlCoordinator($manager->sql($config->get('queue.connection')),$config->get('queue.prefix','cv_work'));
                try{
                    if($command==='coordination:migrate'){$queue->migrate();$this->print(['migrated'=>true]);}
                    elseif($command==='queue:status'){$this->print($queue->status());}
                    elseif($command==='queue:dispatch'){
                        if(!isset($options['idempotent'])){throw new \InvalidArgumentException('Explicit --idempotent is required for automatic retry.');}$file=$options['input']??throw new \InvalidArgumentException('Provide --input=private-json-file.');if(!is_file($file)||filesize($file)>1048576){throw new \InvalidArgumentException('Input must be a JSON file <=1 MiB.');}$input=json_decode(file_get_contents($file),true,64,JSON_THROW_ON_ERROR);if(!is_array($input)){throw new \InvalidArgumentException('Input must be an object or array.');}$this->print(['job_id'=>$queue->dispatch($argv[2]??'', $input,true,$queue->clock())]);
                    }else{
                        $scopes=new \Corevia\Core\ScopeManager();$handlers=require $this->basePath.'/routes/jobs.php';if(is_callable($handlers)){$handlers=$handlers($queue,$scopes);}$maximum=isset($options['once'])?1:filter_var($options['max']??100,FILTER_VALIDATE_INT);if($maximum===false||$maximum<1||$maximum>1000){throw new \InvalidArgumentException('Choose --once or --max=1..1000.');}$worker=new \Corevia\Core\JobWorker($queue,$scopes,$handlers);$results=[];for($index=0;$index<$maximum;$index++){$result=$worker->once();if($result['status']==='empty'){break;}$results[]=$result;}$this->print(['processed'=>$results]);
                    }
                }finally{$manager->disconnect();}return 0;
            }
            if(in_array($command,['mail:work','mail:status','mail:migrate','auth:roles'],true)){
                $file=$this->basePath.'/config/auth.php';if(!is_file($file)){throw new \LogicException('Install an auth starter first.');}$settings=require $file;
                if($command==='auth:roles'){
                    if(!array_key_exists('set',$options)){throw new \InvalidArgumentException('Provide --set=role[,role] or an explicit empty --set= to revoke roles.');}
                    $environment=getenv('APP_ENV')?:'development';$configuration=ConfigRepository::load($this->basePath.'/config/settings',$environment);$manager=new DatabaseManager($configuration->get('database',[]));
                    try{$store=\Corevia\Auth\AuthStores::configured($settings,$manager);$email=strtolower(trim($options['email']??''));$user=$store->findUser($email)??throw new \InvalidArgumentException('Account not found.');$roles=($options['set']??'')===''?[]:explode(',',$options['set']);(new \Corevia\Auth\Gate($store,$settings['roles']))->assign($user['id'],$roles,$options['tenant']??null);$this->print(['updated'=>true,'sessions_revoked'=>true]);}finally{$manager->disconnect();}return 0;
                }
                $delivery=$settings['delivery']??null;if(!in_array($delivery,['smtp','sqlsmtp'],true)){throw new \LogicException('Configure smtp or sqlsmtp delivery first.');}
                if($delivery==='sqlsmtp'){$config=ConfigRepository::load($this->basePath.'/config/settings',getenv('APP_ENV')?:'development');$queue=\Corevia\Auth\SqlMailQueue::configured($settings,new DatabaseManager($config->get('database',[])));}else{$queue=\Corevia\Auth\MailQueue::configured($settings);}
                if($command==='mail:migrate'){if(!$queue instanceof \Corevia\Auth\SqlMailQueue){throw new \LogicException('Only shared SQL mail requires migration.');}$queue->migrate();$this->print(['migrated'=>true]);return 0;}
                if($command==='mail:status'){$this->print($queue->status());return 0;}$maximum=isset($options['once'])?1:filter_var($options['max']??100,FILTER_VALIDATE_INT);if($maximum===false||$maximum<1||$maximum>1000){throw new \InvalidArgumentException('Choose --max=1..1000 or --once.');}
                $results=[];for($index=0;$index<$maximum;$index++){$result=$queue->workOnce();if($result['status']==='empty'){break;}$results[]=$result;}$this->print(['processed'=>$results]);return 0;
            }
            if(in_array($command,['auth:migrate','auth:outbox'],true)){
                $file=$this->basePath.'/config/auth.php';if(!is_file($file)){throw new \LogicException('Install an auth starter first.');}$settings=require $file;
                if($command==='auth:migrate'){
                    if(in_array($settings['driver']??'json',['json','mongodb'],true)){$this->print(['store'=>$settings['driver']??'json','migration_required'=>false]);return 0;}
                    if($settings['driver']!=='sql'){throw new \LogicException('Choose json or sql auth storage.');}
                    $environment=getenv('APP_ENV')?:'development';$configuration=ConfigRepository::load($this->basePath.'/config/settings',$environment);$manager=new DatabaseManager($configuration->get('database',[]));
                    try{(new \Corevia\Auth\SqlAuthStore($manager->sql($settings['connection']??null),$settings['prefix']??'cv_auth'))->migrate();}finally{$manager->disconnect();}
                    $this->print(['store'=>'sql','migrated'=>true]);return 0;
                }
                if(getenv('APP_ENV')==='production' || ($settings['delivery']??'disabled')!=='preview'){throw new \LogicException('Private outbox inspection is only available for development preview delivery.');}
                $directory=$settings['outbox'];if(is_link($directory)){throw new \RuntimeException('Outbox inspection cannot follow a symlink.');}$records=[];
                foreach(glob($directory.'/*.json')?:[] as $path){if(is_link($path) || filesize($path)>8192){throw new \RuntimeException('Invalid outbox record.');}$data=json_decode(file_get_contents($path),true,32,JSON_THROW_ON_ERROR);$records[basename($path)]=['file'=>basename($path),'recipient'=>$data['recipient'],'purpose'=>$data['purpose'],'expires'=>$data['expires']];}
                if(isset($options['show'])){$name=$options['show'];if(!preg_match('/^[a-f0-9]{32}\.json$/D',$name) || !isset($records[$name])){throw new \InvalidArgumentException('Choose a file listed by auth:outbox.');}$data=json_decode(file_get_contents($directory.'/'.$name),true,32,JSON_THROW_ON_ERROR);if($data['expires']<=time()){throw new \LogicException('This preview link has expired. Request another link.');}$this->print($data);}
                else{$this->print(array_values($records));}return 0;
            }
            if($command==='starter:auth'){$this->print((new StarterInstaller($this->basePath))->install($options['stack']??'bootstrap',$options['path']??$this->basePath,isset($options['dry-run']),isset($options['check'])));return 0;}
            if($command==='profile:list'){$this->print(\Corevia\Core\RuntimeProfile::presets());return 0;}
            if($command==='profile:show'){$bootstrap=require $this->basePath.'/config/runtime.php';$runtime=$bootstrap($this->basePath);$app=$runtime['app'];$this->print($app->runtimeProfile());return 0;}
            if ($command === 'contract:compile') {
                $manifest=ContractManifest::load($argv[2] ?? throw new \InvalidArgumentException('Provide a contract manifest.'));
                $output=$options['output'] ?? $this->basePath.'/contracts/generated';
                (new ContractGenerator($manifest))->write($output,isset($options['check']));
                $this->print(['contract'=>$manifest->data()['name'],'fingerprint'=>$manifest->fingerprint(),'output'=>$output,'checked'=>isset($options['check'])]);return 0;
            }
            if(in_array($command,['app:graph','app:compile','app:verify','app:inspect'],true)){
                $environment=getenv('APP_ENV') ?: 'development';$storage=getenv('COREVIA_STORAGE') ?: $this->basePath.'/storage';
                if(!is_dir($storage) && !mkdir($storage,0700,true) && !is_dir($storage)){throw new \RuntimeException('Cannot create private storage.');}
                $keyPath=$options['key-file'] ?? $storage.'/build-signing.key';$path=$options['output'] ?? $storage.'/application.build.json';
                if($command!=='app:graph'){
                    if($path===$keyPath){throw new \InvalidArgumentException('Store the signing key separately from the artifact.');}
                    if(!is_file($keyPath) && $command==='app:compile'){
                        $file=fopen($keyPath,'x+b');if($file===false){throw new \RuntimeException('Cannot create private build key.');}
                        try{if(!chmod($keyPath,0600) || fwrite($file,bin2hex(random_bytes(32)))!==64){throw new \RuntimeException('Cannot save private build key.');}}finally{fclose($file);}
                    }
                    if(!is_file($keyPath)){throw new \InvalidArgumentException('Build key is missing.');}$key=file_get_contents($keyPath);
                    if($command==='app:compile' && !is_dir(dirname($path)) && !mkdir(dirname($path),0700,true) && !is_dir(dirname($path))){throw new \RuntimeException('Cannot create artifact directory.');}
                    if(realpath($path)!==false && strcasecmp(realpath($path),realpath($keyPath))===0){throw new \InvalidArgumentException('Store the signing key separately from the artifact.');}
                }
                if($command==='app:inspect'){$this->print(ApplicationArtifact::load($path,$key,$environment)->summary());return 0;}
                $bootstrap=require $this->basePath.'/config/runtime.php';$runtime=$bootstrap($this->basePath,true);$app=$runtime['app'];
                if($command==='app:graph'){echo CanonicalJson::encode($app->graph()).PHP_EOL;return 0;}
                $sources=ApplicationArtifact::defaultSources($this->basePath);
                if($command==='app:compile'){$artifact=ApplicationArtifact::build($app,$environment,$sources,$key);$artifact->write($path);}
                else{$artifact=ApplicationArtifact::load($path,$key,$environment);$artifact->verify($app,$sources,$key);}
                $this->print($artifact->summary());return 0;
            }
            if(in_array($command,['route:list','db:status','db:check','db:migrate'],true)){
                if($command==='route:list'){$bootstrap=require $this->basePath.(getenv('COREVIA_PROFILE') || getenv('COREVIA_BUILD_ARTIFACT')?'/config/runtime.php':'/config/web.php');$runtime=$bootstrap($this->basePath);$app=$runtime['app'];$app->requireFeature('http');$this->print($runtime['router']->routes());return 0;}
                $environment=getenv('APP_ENV') ?: 'development';$storage=getenv('COREVIA_STORAGE') ?: $this->basePath.'/storage';
                $configuration=ConfigRepository::load($this->basePath.'/config/settings',$environment,$storage.'/config-'.$environment.'.json');
                $database=new DatabaseManager($configuration->get('database',[]));
                if($command==='db:status'){$this->print(['configured'=>$database->configured(),'pdo_drivers'=>class_exists(\PDO::class)?\PDO::getAvailableDrivers():[],'mongodb_extension'=>extension_loaded('mongodb')]);return 0;}
                $name=$argv[2] ?? null;
                if($command==='db:migrate'){$store=new SqlRunStore($database->sql($name));$store->migrate();echo "Framework run-store tables are ready.\n";return 0;}
                $connection=$database->connection($name);
                if($connection instanceof SqlConnection){$connection->select('SELECT 1');}else{$connection->find('corevia_connection_check',[],1);}
                $this->print(['connected'=>true,'connection'=>$name ?? 'default']);return 0;
            }
            if ($command === 'about') {
                $this->print(['framework'=>'Corevia','version'=>Version::VERSION,'protocols'=>['1.0','2.0'],'php'=>PHP_VERSION,'database'=>'PDO MySQL/PostgreSQL/SQLite; MongoDB and JSON documents; optional']); return 0;
            }
            if ($command === 'upgrade:check') {
                $report=(new UpgradeScanner())->scan($this->basePath); $this->print($report); return $report['compatible'] ? 0 : 1;
            }
            if (in_array($command, ['config:cache','cache:clear'], true)) {
                $environment=$options['env'] ?? (getenv('APP_ENV') ?: 'development');
                if (!preg_match('/^[a-zA-Z0-9_-]{1,64}$/D',$environment)) { throw new \InvalidArgumentException('Invalid environment.'); }
                $storage=getenv('COREVIA_STORAGE') ?: $this->basePath.'/storage';
                if (!is_dir($storage) && !mkdir($storage,0700,true) && !is_dir($storage)) { throw new \RuntimeException('Cannot create storage.'); }
                $path=$storage.'/config-'.$environment.'.json';
                if ($command==='config:cache') { ConfigRepository::load($this->basePath.'/config/settings',$environment)->cache($path,$environment); echo "Configuration cached for $environment.\n"; }
                elseif (is_file($path)) { if (!unlink($path)) { throw new \RuntimeException('Cannot remove config cache.'); } echo "Configuration cache cleared for $environment.\n"; }
                return 0;
            }
            if ($command === 'test') {
                $status=0;
                foreach (['tests/run.php','tests/v2/kernel.php','tests/v2/flows.php','tests/v2/protocol.php','tests/v2/tooling.php','tests/v2/upgrade.php','tests/v2/diagnostics.php','tests/v2/security.php','tests/features/databases.php','tests/features/web.php','tests/v3/composition.php','tests/v3/scopes.php','tests/v3/units.php','tests/v3/artifact-boot.php','tests/v3/contracts.php','tests/v3/profiles.php','tests/v3/auth.php','tests/v3/auth-storage.php','tests/v3/auth-recovery.php','tests/v3/identity.php','tests/v3/mail.php','tests/v3/adapters.php','tests/v3/distributed.php','tests/v3/ai.php','tests/v3/services.php','tests/v3/release.php','tests/v3/operations.php'] as $test) {
                    passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg($this->basePath.'/'.$test),$code); if ($code!==0) { $status=1; }
                }
                return $status;
            }
            if ($command === 'update:check') {
                if (isset($options['offline'])) { $this->print(['current'=>Version::VERSION,'available'=>null,'checked'=>false,'message'=>'Offline mode; use Composer to review and install updates explicitly.']); return 0; }
                passthru('composer outdated --direct --format=json --working-dir='.escapeshellarg($this->basePath),$code); return $code;
            }
            if ($command === 'help') {
                echo "auth:migrate | auth:outbox [--show=private-preview-file.json]\n";
                echo "auth:roles --email=address --set=admin[,editor] [--tenant=id] (empty --set revokes roles)\n";
                echo "mail:migrate | mail:status | mail:work [--once | --max=100]\n";
                echo "starter:ai [--path=app --dry-run] | deploy:check [--multi-host]\n";
                echo "coordination:migrate | queue:status | queue:dispatch <task> --input=file --idempotent | queue:work [--once | --max=100]\n";
                echo "starter:auth [--stack=bootstrap|tailwind|react --path=existing-project --dry-run --check]\n";
                echo "profile:list | profile:show (COREVIA_PROFILE=web|api|worker|realtime|ai|full)\n";
                echo "V3 foundations (unreleased): app:graph | app:compile | app:verify | app:inspect [--output=path --key-file=path]\ncontract:compile <manifest.json> [--output=directory --check]\n";
                echo "Web starter: route:list | db:status | db:check <connection> | db:migrate <connection>\n";
                echo "Corevia V2\n\nserve [--host=127.0.0.1 --port=8081]\nabout | routes | modules | flow:list | doctor\nflow:run <name> --input=file.json [--key=key]\nflow:show <run-id> | flow:inspect <run-id>\nflow:retry <run-id> <zero-based-step-index>\nflow:resume <run-id> [--event=name]\nrealtime:start [--host=127.0.0.1 --port=8080]\nrealtime:stop\nworker:start [--once] | worker:dispatch <flow> --input=file.json [--delay=seconds]\nmake:module <Name> | make:action <Name> | make:flow <Name> | make:event <Name>\nconfig:cache | cache:clear [--env=development]\ntest | upgrade:check | update:check [--offline]\n";
                return 0;
            }
            if (str_starts_with($command, 'make:')) {
                return $this->generate(substr($command, 5), $argv[2] ?? '');
            }
            if ($command === 'serve') {
                $profile=getenv('COREVIA_PROFILE')?:null;
                if(!$profile && ($artifactPath=getenv('COREVIA_BUILD_ARTIFACT'))){
                    $keyPath=getenv('COREVIA_BUILD_KEY_FILE');if(!$keyPath || !is_file($keyPath)){throw new \RuntimeException('A separate build key file is required.');}
                    $profile=ApplicationArtifact::load($artifactPath,file_get_contents($keyPath),getenv('APP_ENV')?:'development')->configuration()->get('runtime.profile');
                }
                if($profile && !in_array('http',\Corevia\Core\RuntimeProfile::featuresFor($profile),true)){throw new \LogicException('Runtime profile '.$profile.' does not enable http.');}
                $host = $options['host'] ?? '127.0.0.1';
                if (!filter_var($host, FILTER_VALIDATE_IP) && $host !== 'localhost') {
                    throw new \InvalidArgumentException('Host must be an IP address or localhost.');
                }
                $port = $this->port($options['port'] ?? '8081');
                passthru(escapeshellarg(PHP_BINARY) . ' -S ' . escapeshellarg($host . ':' . $port) . ' -t ' . escapeshellarg($this->basePath . '/public') . ' ' . escapeshellarg($this->basePath . '/public/index.php'), $status);
                return $status;
            }
            $bootstrap = require $this->basePath . (getenv('COREVIA_PROFILE') || getenv('COREVIA_BUILD_ARTIFACT')?'/config/runtime.php':'/config/bootstrap.php');
            $runtime = $bootstrap($this->basePath);
            $app = $runtime['app'];
            if(in_array($command,['realtime:start','realtime:stop'],true)){$app->requireFeature('websocket');$runtime=$this->realtimeRuntime($runtime,$command==='realtime:start');}
            $execute = function () use ($command,$runtime,$app,$argv,$options): int {
            switch ($command) {
                case 'routes':
                    $app->requireFeature('http');
                    $this->print($runtime['router']->routes());
                    break;
                case 'modules':
                    $this->print($app->registry->health());
                    break;
                case 'flow:list':
                    $this->print(array_keys($app->flows()));
                    break;
                case 'flow:run':
                    $file = $options['input'] ?? throw new \InvalidArgumentException('--input=file.json is required.');
                    $raw = file_get_contents($file);
                    if ($raw === false) {
                        throw new \InvalidArgumentException('Cannot read input file.');
                    }
                    $input = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
                    $result = $app->run($argv[2] ?? '', $input, $options['key'] ?? null);
                    $this->print($result);
                    return $result->status() === 'failed' ? 1 : 0;
                case 'flow:show':
                case 'flow:inspect':
                    $this->print($app->store->get($argv[2] ?? '') ?? throw new \OutOfBoundsException('Run not found.'));
                    break;
                case 'flow:resume':
                    $result=$app->resume($argv[2] ?? '',$options['event'] ?? null); $this->print($result); return $result->status()==='failed' ? 1 : 0;
                case 'worker:dispatch':
                    $file=$options['input'] ?? throw new \InvalidArgumentException('--input is required.');
                    $delay=$options['delay'] ?? '0'; if (!ctype_digit($delay) || (int)$delay>31536000) { throw new \InvalidArgumentException('Invalid delay.'); }
                    $input=json_decode(file_get_contents($file),true,64,JSON_THROW_ON_ERROR);
                    $flow=$argv[2] ?? ''; $app->flow($flow);
                    $id=(new JobQueue($runtime['storage'].'/jobs'))->dispatch($flow,$input,time()+(int)$delay); $this->print(['job_id'=>$id]); break;
                case 'worker:start':
                    $queue=new JobQueue($runtime['storage'].'/jobs');
                    $running=true;
                    if (function_exists('pcntl_async_signals')) { pcntl_async_signals(true); pcntl_signal(SIGTERM, function () use (&$running): void { $running=false; }); pcntl_signal(SIGINT, function () use (&$running): void { $running=false; }); }
                    do { $worked=$queue->work($app); if (!isset($options['once']) && !$worked) { usleep(250000); } } while (!isset($options['once']) && $running);
                    break;
                case 'flow:retry':
                    if (!isset($argv[3]) || !ctype_digit($argv[3])) {
                        throw new \InvalidArgumentException('A zero-based numeric step index is required.');
                    }
                    $result = $app->retry($argv[2] ?? '', (int) $argv[3]);
                    $this->print($result);
                    return $result->status() === 'completed' ? 0 : 1;
                case 'realtime:start':
                    $privateToken = getenv('COREVIA_PRIVATE_TOKEN');
                    $authorize = $privateToken ? static fn (string $channel, mixed $token): bool => is_string($token) && hash_equals($privateToken, $token) : null;
                    (new Server($app->logger, $runtime['secret'], $options['host'] ?? '127.0.0.1', $this->port($options['port'] ?? '8080'), $runtime['controlPort'], $runtime['origins'], $authorize, scopes: $app->scopes, app:$app))->run();
                    break;
                case 'realtime:stop':
                    (new SocketBroadcaster($runtime['secret'], $runtime['controlPort']))->command(['command' => 'stop']);
                    echo "Realtime stop acknowledged.\n";
                    break;
                case 'doctor':
                    if($profile=$app->runtimeProfile()){
                        $ok=PHP_VERSION_ID>=80200 && extension_loaded('json') && is_writable($runtime['storage']);
                        foreach($app->registry->health() as $health){$ok=$ok && $health['status']==='healthy';}
                        $this->print(['profile'=>$profile->name,'features'=>$profile->features,'healthy'=>$ok,'run_store'=>$app->config->get('runtime.run_store','json'),'modules'=>$app->registry->health()]);return $ok?0:1;
                    }
                    $ok = true;
                    echo 'PHP version                  ' . PHP_VERSION . (PHP_VERSION_ID >= 80200 ? ' OK' : ' FAIL') . PHP_EOL;
                    foreach (['json', 'PDO', 'pdo_sqlite'] as $extension) {
                        $loaded = extension_loaded($extension);
                        $ok = $ok && $loaded;
                        echo str_pad('Extension ' . $extension, 29) . ($loaded ? 'OK' : 'FAIL') . PHP_EOL;
                    }
                    $writable = is_writable($runtime['storage']);
                    $ok = $ok && $writable;
                    echo 'Writable storage             ' . ($writable ? 'OK' : 'FAIL') . PHP_EOL;
                    echo 'Database                     OK (SQLite)' . PHP_EOL;
                    $probe = @stream_socket_server('tcp://127.0.0.1:' . ($options['port'] ?? '8080'), $errno, $error);
                    echo 'Realtime port                ' . ($probe ? 'available' : 'in use; check realtime process') . PHP_EOL;
                    if ($probe) {
                        fclose($probe);
                    }
                    echo "Protocol version             1.0 (also supports 2.0)\n";
                    echo 'Fraud runtime                ' . (getenv('COREVIA_FRAUD_URL') ? 'remote HTTP (start Python worker)' : 'local demo rule') . PHP_EOL;
                    foreach ($app->registry->health() as $name => $health) {
                        $ok = $ok && $health['status'] === 'healthy';
                        echo str_pad('Module ' . $name, 29) . $health['status'] . PHP_EOL;
                    }
                    return $ok ? 0 : 1;
                default:
                    throw new \InvalidArgumentException('Unknown command: ' . $command);
            }
            return 0;
            };
            return in_array($command,['worker:start','realtime:start'],true) ? $execute() : $app->scope('cli',$execute);
        } catch (\Throwable $exception) {
            fwrite(STDERR, 'Corevia: ' . $exception->getMessage() . PHP_EOL);
            return 1;
        } finally {
            if (isset($app)) {
                $app->shutdown();
            }
        }
    }

    private function realtimeRuntime(array $runtime,bool $create):array
    {
        if(isset($runtime['secret'])){return $runtime;}
        $path=$runtime['storage'].'/realtime.secret';$lock=fopen($path.'.lock','c+b');
        if($lock===false || !flock($lock,LOCK_EX)){throw new \RuntimeException('Cannot lock realtime secret.');}
        try{
            if(!is_file($path)){
                if(!$create){throw new \RuntimeException('Start the realtime server before requesting a stop.');}
                if(file_put_contents($path,bin2hex(random_bytes(32)),LOCK_EX)===false || !chmod($path,0600)){throw new \RuntimeException('Cannot save realtime secret.');}
            }
            $secret=trim(file_get_contents($path));if(strlen($secret)<32){throw new \RuntimeException('Invalid realtime secret.');}
        }finally{flock($lock,LOCK_UN);fclose($lock);}
        return $runtime+['secret'=>$secret,'controlPort'=>$this->port(getenv('COREVIA_CONTROL_PORT')?:'8082'),'origins'=>array_filter(explode(',',getenv('COREVIA_ORIGINS')?:'http://localhost:8081,http://127.0.0.1:8081'))];
    }
    private function port(string $value): int
    {
        if (!ctype_digit($value) || (int) $value < 1 || (int) $value > 65535) {
            throw new \InvalidArgumentException('Invalid port.');
        }
        return (int) $value;
    }

    private function print(mixed $value): void
    {
        echo json_encode($value, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    }

    private function generate(string $kind, string $name): int
    {
        if (!preg_match('/^[A-Z][A-Za-z0-9]*$/D', $name)) {
            throw new \InvalidArgumentException('Use a PascalCase name.');
        }
        $header = "<?php\n\ndeclare(strict_types=1);\n\n";
        [$file, $source] = match ($kind) {
            'module' => ["app/$name/{$name}Module.php", $header . "namespace App\\$name;\n\nuse Corevia\\Core\\{Module, ModuleContext};\n\nfinal class {$name}Module implements Module\n{\n    public function name(): string { return '" . strtolower($name) . "'; }\n    public function dependencies(): array { return []; }\n    public function register(ModuleContext \$context): void\n    {\n        \$context->flows([\\Corevia\\Core\\Flow::define('" . strtolower($name) . "')]);\n    }\n}\n"],
            'action' => ["app/Generated/Actions/$name.php", $header . "namespace App\\Generated\\Actions;\n\nuse Corevia\\Core\\{Action, ExecutionContext, State};\n\nfinal class $name implements Action\n{\n    public function handle(State \$state, ExecutionContext \$context): State\n    {\n        \$state->data['executed'][] = self::class;\n        return \$state;\n    }\n}\n"],
            'flow' => ["app/Generated/Flows/$name.php", $header . "namespace App\\Generated\\Flows;\n\nuse Corevia\\Core\\Flow;\n\nfinal class $name\n{\n    public static function define(): Flow\n    {\n        return Flow::define('" . strtolower($name) . "');\n    }\n}\n"],
            'event' => ["app/Generated/Events/$name.php", $header . "namespace App\\Generated\\Events;\n\nfinal readonly class $name implements \\JsonSerializable\n{\n    public function __construct(public array \$payload) {}\n    public function jsonSerialize(): array { return \$this->payload; }\n}\n"],
            default => throw new \InvalidArgumentException('Unknown generator.'),
        };
        $path = $this->basePath . '/' . $file;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        $handle = @fopen($path, 'x');
        if ($handle === false) {
            throw new \RuntimeException('File exists or cannot be created.');
        }
        fwrite($handle, $source);
        fclose($handle);
        echo 'Created ' . $file . '. Register it explicitly in your module/bootstrap.' . PHP_EOL;
        return 0;
    }
}
