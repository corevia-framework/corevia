<?php
declare(strict_types=1);
namespace CoreviaTests;

use Corevia\Auth\{SnapshotAuthStore,SqlAuthStore};
use Corevia\Persistence\{MongoSnapshotStore,SqlConnection};
require_once __DIR__.'/Services.php';

/** Explicit disposable databases, unique SQL prefixes and one owned Mongo snapshot per contract. */
final class IdentityServices
{
    public static function run(callable $contract):void
    {
        if (getenv('COREVIA_DB_TESTS')!=='1') { throw new \LogicException('Opt in to disposable service acceptance first.'); }
        foreach (Services::drivers() as $driver) {
            $db=SqlConnection::connect(Services::sql($driver));$prefix='cv_v4identity_'.bin2hex(random_bytes(6));
            try { $store=new SqlAuthStore($db,$prefix);$store->migrate();$contract($store,$driver); }
            finally { foreach (['state','tokens','sessions','limits','users','mutex'] as $table) { $db->execute('DROP TABLE IF EXISTS '.$db->quoteIdentifier($prefix.'_'.$table)); } }
        }
        if (!class_exists(\MongoDB\Driver\Manager::class)) { throw new \RuntimeException('Real identity service acceptance requires the MongoDB driver.'); }
        $uri=Services::mongoUri();$database=Services::database();$scope='v4-'.bin2hex(random_bytes(12));$collection='cv_v4_identity_tests';
        try { $contract(new SnapshotAuthStore(new MongoSnapshotStore($uri,$database,$collection,$scope)),'MongoDB'); }
        finally {
            $bulk=new \MongoDB\Driver\BulkWrite();$bulk->delete(['_id'=>$scope],['limit'=>1]);
            (new \MongoDB\Driver\Manager($uri))->executeBulkWrite($database.'.'.$collection,$bulk);
        }
    }
}
