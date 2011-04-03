<?php

/**
 * Simple benchmarking test  to see how much slower  PgPhp is than the
 * built-in libs.
 */

require (dirname(__FILE__) . '/../pg.php');

$host = 'locahost';

//$l = new ShaksLeach('/home/robin/Downloads/shaks/');

$b = new Bench(function () {
        echo "You ran a test!\n";
        usleep(5000);
    });

$b->run();

$r = $b->getResults();
echo $b->analyse($r[0]);






class Bench
{
    private $runFunc;
    private $runArgs;
    private $results = array();

    function __construct ($runFunc, $runArgs=array()) {
        if (! is_callable($runFunc)) {
            throw new Exception("Cannot benchmark nothing.", 4756);
        }
        $this->runFunc = $runFunc;
        $this->runArgs = $runArgs;
    }

    function getResults () {
        return $this->results;
    }

    function run ($n=1) {
        for ($i = 0; $i < $n; $i++) {
            $this->_run();
        }
    }

    private function _run () {
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
        return sprintf("memory delta: %d\nrealmem delta: %d\npeakmem delta: %d\nrun time: %f\n",
                       $memDelta, $realMemDelta, $peakMemDelta, $runTime);
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
            printf("Process file %s\n", $sFile);
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