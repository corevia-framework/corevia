<?php
declare(strict_types=1);
/** Explicit end-to-end contracts; credentials come only from dedicated COREVIA_TEST_* variables. */
if (PHP_SAPI!=='cli'||getenv('COREVIA_DB_TESTS')!=='1') { throw new LogicException('Set COREVIA_DB_TESTS=1 for disposable service acceptance.'); }
$root=dirname(__DIR__,2);
foreach (['data','catalog','audit','service-accounts','account-lifecycle','organization-sso','passkey-storage','scheduler','resilience','reliability','webhooks','connected','sync','knowledge','tools'] as $name) {
    $process=proc_open([PHP_BINARY,$root.'/tests/v4/'.$name.'.php'],[0=>STDIN,1=>STDOUT,2=>STDERR],$pipes,$root,null,['bypass_shell'=>true,'create_no_window'=>true]);
    if (!is_resource($process)||proc_close($process)!==0) { throw new RuntimeException('Service contract failed: '.$name); }
}
echo "PASS V4 disposable SQL and MongoDB service suite\n";
