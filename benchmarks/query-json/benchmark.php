<?php

declare(strict_types=1);

final class CountedPDO extends PDO
{
    public int $calls = 0;
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        ++$this->calls; return parent::prepare($query,$options);
    }
    public function query(string $query, ?int $fetchMode = null, mixed ...$args): PDOStatement|false
    {
        ++$this->calls; return $fetchMode === null ? parent::query($query) : parent::query($query,$fetchMode,...$args);
    }
}

function connection(): CountedPDO
{
    return new CountedPDO('mysql:host=127.0.0.1;port='.(getenv('LIGHT_DB_QUERY_BENCH_PORT') ?: '33391').';dbname=light_db_query_json_bench;charset=utf8mb4','root','query_json_bench',[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,
    ]);
}

$count=(int)(getenv('BENCH_ROWS') ?: 10000);
if ($count<1 || $count>1000000) { throw new InvalidArgumentException('BENCH_ROWS must be 1..1000000.'); }
$action=$argv[1] ?? '';
if ($action==='seed') {
    $pdo=connection();
    foreach (['plain','json'] as $workload) {
        $table='bench_'.$workload.'_users';
        $pdo->exec('CREATE TABLE '.$table.'(user_id INT PRIMARY KEY,name VARCHAR(100),email VARCHAR(150),age INT,balance DECIMAL(12,2),created_at DATETIME,payload VARCHAR(255)'.($workload==='json'?',settings JSON':'').')');
        $insert=$pdo->prepare('INSERT INTO '.$table.' VALUES('.implode(',',array_fill(0,$workload==='json'?8:7,'?')).')');
        $pdo->beginTransaction();
        for ($i=1;$i<=$count;++$i) {
            $values=[$i,'User '.$i,'user'.$i.'@example.test',18+$i%63,sprintf('%d.%02d',$i%10000,$i%100),'2026-01-01 12:00:00',str_repeat('x',128)];
            if ($workload==='json') { $values[]=json_encode(['theme'=>'dark','notifications'=>$i%2===0,'tags'=>['a','b']],JSON_THROW_ON_ERROR); }
            $insert->execute($values);
        }
        $pdo->commit();
    }
    echo json_encode(['rows'=>$count,'mysql'=>$pdo->query('SELECT VERSION()')->fetchColumn()]),"\n"; exit;
}
if ($action!=='worker') { throw new InvalidArgumentException('Use seed or worker.'); }
[$variant,$mode,$workload]=[$argv[2],$argv[3],$argv[4]];
if (!in_array($variant,['old','baseline','new','entity','entity-no-tracking','profile'],true) || !in_array($mode,['cold','warm'],true) || !in_array($workload,['plain','json'],true)) {
    throw new InvalidArgumentException('Invalid worker arguments.');
}
$newRoot=dirname(__DIR__,2);
$oldRoot=getenv('LIGHT_DB_OLD_ROOT') ?: dirname($newRoot).'/light-db';
require ($variant==='old'?$oldRoot:$newRoot).'/vendor/autoload.php';
if ($variant==='old' || $variant==='baseline') {
    $source=getenv($variant==='old' ? 'LIGHT_DB_OLD_SOURCE' : 'LIGHT_DB_BASELINE_SOURCE');
    if (!$source) { throw new RuntimeException('The runner must provide the archived old source.'); }
    spl_autoload_register(static function (string $class) use ($source): void {
        $prefix='Light\\Db\\';
        if (str_starts_with($class,$prefix)) {
            $file=$source.'/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php';
            if (is_file($file)) { require $file; }
        }
    },true,true);
}

class User extends \Light\Db\Model
{
    protected static $_table='bench_plain_users';
    public static function useTable(string $table): void { static::$_table=$table; }
}

$pdo=connection();
if ($variant==='old') {
    User::SetAdapter(new Light\Db\Adapter(new Laminas\Db\Adapter\Driver\Pdo\Pdo($pdo)));
} else { Light\Db\Database::setDefault(new Light\Db\Database($pdo)); }
User::useTable('bench_'.$workload.'_users');
$context = null;
$profile = [];
$fetch = static function () use ($variant, $pdo, &$context, &$profile): array {
    if ($variant === 'profile') {
        $start = hrtime(true);
        $database = \Light\Db\Database::getDefault();
        $mapping = $database->metadata(User::class);
        $set = $database->set(User::class);
        $prepared = hrtime(true);
        $models = $set->toArray();
        $hydrated = hrtime(true);
        foreach ($models as $model) { $model->attachRecord($database, $mapping); }
        $attached = hrtime(true);
        $profile = ['setup_ms'=>($prepared-$start)/1e6, 'materialize_ms'=>($hydrated-$prepared)/1e6, 'attach_snapshot_ms'=>($attached-$hydrated)/1e6];
        return $models;
    }
    if (!str_starts_with($variant, 'entity')) { return User::Query()->toArray(); }
    $context ??= new \Light\Entity\DbContext($pdo, mappings: [\Light\Db\Database::getDefault()->metadata(User::class)]);
    $set = $context->set(User::class);
    return ($variant === 'entity-no-tracking' ? $set->asNoTracking() : $set)->toArray();
};
if ($mode==='warm') {
    $warm=$fetch(); $warmJson=json_encode($warm,JSON_THROW_ON_ERROR);
    unset($warmJson,$warm); if ($context !== null) { foreach ($context->changeTracker->entries() as $entry) { $entry->state = \Light\Entity\EntityState::Detached; } unset($entry); } gc_collect_cycles();
}
$pdo->calls=0;
memory_reset_peak_usage();
$before=memory_get_usage(false);
$start=hrtime(true);
$rows=$fetch();
$fetched=hrtime(true);
$json=json_encode($rows,JSON_THROW_ON_ERROR);
$done=hrtime(true);
$memory=memory_get_usage(false)-$before;
$peak=memory_get_peak_usage(false)-$before;
$sqlCalls=$pdo->calls;
// Validate every value outside the timed region, and compare complete JSON bytes in the runner.
$data=json_decode($json,true,512,JSON_THROW_ON_ERROR);
if (count($data)!==$count) { throw new RuntimeException('Incorrect row count.'); }
foreach ($data as $i=>$row) {
    $id=$i+1;
    if ($row['user_id']!==$id || $row['name']!=='User '.$id || $row['email']!=='user'.$id.'@example.test'
        || $row['age']!==18+$id%63 || $row['balance']!==sprintf('%d.%02d',$id%10000,$id%100)
        || $row['created_at']!=='2026-01-01 12:00:00' || $row['payload']!==str_repeat('x',128)) {
        throw new RuntimeException('Row validation failed at '.$id);
    }
    if ($workload==='json' && ($row['settings']['theme']!=='dark' || $row['settings']['notifications']!==($id%2===0) || $row['settings']['tags']!==['a','b'])) {
        throw new RuntimeException('JSON column validation failed at '.$id);
    }
}
echo json_encode(['variant'=>$variant,'mode'=>$mode,'workload'=>$workload,'rows'=>count($rows),
    'total_ms'=>($done-$start)/1e6,'fetch_ms'=>($fetched-$start)/1e6,'json_ms'=>($done-$fetched)/1e6,
    'retained_bytes'=>$memory,'peak_bytes'=>$peak,'sql_calls'=>$sqlCalls,
    'profile'=>$profile,'json_bytes'=>strlen($json),'checksum'=>hash('sha256',$json)],JSON_THROW_ON_ERROR),"\n";
