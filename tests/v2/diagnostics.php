<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/autoload.php';
use Corevia\Core\Logger;
use Corevia\Persistence\{PdoRunStore,QueryDiagnostics};
$logger=new class implements Logger {
    public array $records=[];
    public function log(string $level,string $message,array $context=[]): void { $this->records[]=[$level,$message,$context]; }
};
$diagnostics=new QueryDiagnostics($logger,'trace-query',3); $store=new PdoRunStore(new PDO('sqlite::memory:'),$diagnostics);
for ($i=0;$i<4;$i++) { $store->get('sensitive-user-input'); }
if (count($logger->records)!==1 || str_contains(json_encode($logger->records),'sensitive-user-input') || array_values($diagnostics->snapshot())[0]['count']!==4) { throw new RuntimeException('Unsafe or missing query diagnostics'); }
echo "PASS opt-in PDO query timing, repeated-query detection and parameter privacy\n";
