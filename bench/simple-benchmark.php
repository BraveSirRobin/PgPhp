<?php

/**
 * Simple benchmarking test  to see how much slower  PgPhp is than the
 * built-in libs.
 */

require (dirname(__FILE__) . '/../pg.php');

$host = 'locahost';



/**
 * Hard-coded test table!
 */
$createQ = 'CREATE TABLE bench_test (' .
    'fld1  character varying (512), ' .
    'fld2  character varying (512), ' .
    'fld3  text, ' .
    'fld4  text)';

$insertQ = 'INSERT INTO bench_test (fld1, fld2, fld3, fld4) VALUES ($1, $2, $3, $4)';
$insertQ2 = 'INSERT INTO bench_test (fld1, fld2, fld3, fld4) VALUES (?, ?, ?, ?)';
$selectQ = 'SELECT * FROM bench_test';
$deleteQ = 'DELETE FROM bench_test';
$dropQ = 'DROP TABLE bench_test';
$vacuumQ = 'VACUUM FULL ANALYSE';

/**
 * Hard-coded test data (kinda)
 */
$DATA = array();
$N = 1000;
$nRows = 10000;
$witti = new ShaksLeach('/home/robin/Downloads/shaks/');
for ($i = 0; $i < $nRows; $i++) {
    $row = array(
        substr(implode(' ', $witti->getLines(8)), 0, 512),
        substr(implode(' ', $witti->getLines(8)), 0, 512),
        implode(' ', $witti->getLines(16)),
        implode(' ', $witti->getLines(24)));
    $DATA[] = $row;
}


printf("Start test\n");


// Either
//$testee = new NativeTester;
//$insertQ = $insertQ2;

// OR
$testee = new MystuffTester;



$bench = new Bench;
$testee->connect();
$testee->setup($createQ);


$outF = fopen(dirname(__FILE__) . '/bench-out/data.R', 'w') or die("Couldn't open stats file");
$header = array("N", "w-mem-delta", "w-real-mem-delta", "w-peak-mem-delta", "w-run-time",
    "r-mem-delta", "r-real-mem-delta", "r-peak-mem-delta", "r-run-time");
fputcsv($outF, $header);
for ($N = 300; $N < 1000; $N+=3) {
    printf(".");
    $statRow = array($N);

    $bench->setRunFunc(array($testee, 'write'), array($insertQ, $N));
    $bench->run();
    $res = $bench->getResults();
    //printf("Results for write:\n%s\n", $bench->analyse($res[0]));
    foreach ($bench->analyse($res[0]) as $k => $v) {
        $statRow['w-' . $k] = $v;
    }
    $bench->clearResults();


    $bench->setRunFunc(array($testee, 'select'), array($selectQ));
    $bench->run();
    $res = $bench->getResults();
    //printf("Results for select:\n%s\n", $bench->analyse($res[0]));
    foreach ($bench->analyse($res[0]) as $k => $v) {
        $statRow['r-' . $k] = $v;
    }
    $bench->clearResults();
    fputcsv($outF, $statRow);

    $testee->deleteAll($deleteQ);
    //$testee->vacuum($vacuumQ);

}
$testee->teardown($dropQ);
fclose($outF);
printf("Complete!");

/*
$bench->setRunFunc(array($testee, 'connect'));
$bench->run();
$res = $bench->getResults();
printf("Results for connect:\n%s\n", $bench->analyse($res[0]));
$bench->clearResults();

$bench->setRunFunc(array($testee, 'setup'), array($createQ));
$bench->run();
$res = $bench->getResults();
printf("Results for setup:\n%s\n", $bench->analyse($res[0]));
$bench->clearResults();


$bench->setRunFunc(array($testee, 'write'), array($insertQ, $N));
$bench->run();
$res = $bench->getResults();
printf("Results for write:\n%s\n", $bench->analyse($res[0]));
$bench->clearResults();


$bench->setRunFunc(array($testee, 'select'), array($selectQ));
$bench->run();
$res = $bench->getResults();
printf("Results for select:\n%s\n", $bench->analyse($res[0]));
$bench->clearResults();


$bench->setRunFunc(array($testee, 'teardown'), array($dropQ));
$bench->run();
$res = $bench->getResults();
printf("Results for teardown:\n%s\n", $bench->analyse($res[0]));
$bench->clearResults();
*/








































class MystuffTester
{
    private $dbh;
    private $stW; // writes
    private $stR; // reads
    private $stD; // deletes
    private $stV; // vacuums
    function connect () {
        $this->dbh = new pg\Connection;
        $this->dbh->connect();
    }
    function setup ($create) {
        $q = new pg\Query($create);
        $this->dbh->runQuery($q);
    }

    function write ($q, $n) {
        global $DATA;
        if (is_null($this->stW)) {
            $this->stW = new pg\Statement($this->dbh);
            $this->stW->setSql($q);
            $this->stW->setName('st1');
            $this->stW->parse();
        }
        for ($i = 0; $i < $n; $i++) {
            $this->stW->execute($DATA[$i]);
        }
    }

    function select ($q) {
        if (is_null($this->stR)) {
            $this->stR = new pg\Statement($this->dbh);
            $this->stR->setSql($q);
            $this->stR->setName('st2');
            $this->stR->parse();
        }
        $this->stR->execute();
    }

    function deleteAll ($q) {
        if (is_null($this->stD)) {
            $this->stD = new pg\Statement($this->dbh);
            $this->stD->setSql($q);
            $this->stD->setName('st3');
            $this->stD->parse();
        }
        $this->stD->execute();
    }

    function vacuum ($q) {
        if (is_null($this->stV)) {
            $this->stV = new pg\Statement($this->dbh);
            $this->stV->setSql($q);
            $this->stV->setName('st4');
            $this->stV->parse();
        }
        $this->stV->execute();
    }


    function teardown ($q) {
        $p = new pg\Statement($this->dbh);
        $p->setSql($q);
        $p->setName('st5');
        $p->parse();
        $p->execute();
    }
}


class NativeTester
{
    function connect () {
        $this->dbh = new PDO('pgsql:host=localhost;dbname=test1;user=php;password=letmein');
        $this->dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    function setup ($create) {
        $this->dbh->exec($create);
    }

    function write ($q, $n) {
        global $DATA;
        $st = $this->dbh->prepare($q);
        //foreach ($data as $row) {
        for ($i = 0; $i < $n; $i++) {
            $st->execute($DATA[$i]);
        }
    }

    function select ($q) {
        $st = $this->dbh->prepare($q);
        $st->execute();
        $somData = $st->fetchAll(PDO::FETCH_ASSOC);
    }

    function deleteAll ($q) {
        $st = $this->dbh->prepare($q);
        $st->execute();
    }

    function vacuum ($q) {
        $st = $this->dbh->prepare($q);
        $st->execute();
    }


    function teardown ($q) {
        $st = $this->dbh->prepare($q);
        $st->execute();
    }
}




class Bench
{
    private $runFunc;
    private $runArgs;
    private $results = array();


    function getResults () {
        return $this->results;
    }

    function clearResults () {
        $this->results = array();
    }

    function setRunFunc ($runFunc, $runArgs=array()) {
        if (! is_callable($runFunc)) {
            throw new Exception("Cannot benchmark nothing.", 4756);
        }
        $this->runFunc = $runFunc;
        $this->runArgs = $runArgs;
    }

    function run ($n=1) {
        for ($i = 0; $i < $n; $i++) {
            $this->_run();
        }
    }

    private function _run () {
        gc_collect_cycles();
        $stMem = memory_get_usage();
        $stRealMem = memory_get_usage(true);
        $stPeakMem = memory_get_peak_usage(true);

        $stMicro = microtime();
        call_user_func_array($this->runFunc, $this->runArgs);
        $enMicro = microtime();

        $enPeakMem = memory_get_peak_usage(true);
        $enRealMem = memory_get_usage(true);
        $enMem = memory_get_usage();
        $this->results[] = compact('stMem', 'stRealMem', 'stPeakMem', 'stMicro', 'enMicro', 'enPeakMem', 'enRealMem', 'enMem');
    }

    function analyse ($result) {
        $memDelta = bcsub($result['enMem'], $result['stMem']);
        $realMemDelta = bcsub($result['enRealMem'], $result['stRealMem']);
        $peakMemDelta = bcsub($result['enPeakMem'], $result['stPeakMem']);
        $stMicro = explode(' ', $result['stMicro']);
        $enMicro = explode(' ', $result['enMicro']);
        $runTime = bcsub(bcadd($enMicro[0], $enMicro[1], 10), bcadd($stMicro[0], $stMicro[1], 10), 10);
        return array(
            "mem-delta" => $memDelta,
            "real-mem-delta" => $realMemDelta,
            "peak-mem-delta" => $peakMemDelta,
            "run-time" => $runTime);
/*
        return sprintf("memory delta: %d\nrealmem delta: %d\npeakmem delta: %d\nrun time: %f\n",
        $memDelta, $realMemDelta, $peakMemDelta, $runTime);*/
    }
}





/**
 * Spits back randomisations of Shakespear, used to construct test data sets.
 */
class ShaksLeach
{
    private $lines;
    private $nLines;

    private $shaksPath;
    function __construct ($path) {
        if (! is_dir($path)) {
            throw new Exception("Bad Shakespeare path $path", 8924);
        }
        $this->shaksPath = $path;
    }

    function getLines ($n) {
        $this->cacheLines();
        $ret = array();
        for ($i = 0; $i < $n; $i++) {
            $ret[] = $this->lines[rand(0, $this->nLines-1)];
        }
        return $ret;
    }

    private function cacheLines () {
        if ($this->lines) {
            return;
        }
        foreach (glob("{$this->shaksPath}/*.xml") as $sFile) {
            $r = new XMLReader;
            $r->open($sFile);
            //printf("Process file %s\n", $sFile);
            while ($r->read()) {
                if ($r->nodeType == XMLReader::ELEMENT && strtolower($r->name) == 'line') {
                    $r->read();
                    $this->lines[] = $r->value;
                }
            }
        }
        $this->nLines = count($this->lines);
        printf("Cached %d lines of Shakespeare\n", $this->nLines);
    }
}