<?php
namespace pg\exq;

use pg\wire as wire;

// $p = new Portal($conn);
// $p->setQuery('...');
// $p->setParseParamTypes(array(...));
// $p->parse();

class Portal
{
    const ST_PARSED = 1;
    const ST_DESCRIBED = 2;
    const ST_BOUND = 4;
    const ST_EXECD = 8;

    private $conn;
    private $sql;
    private $name = false;
    private $ppTypes = array();
    private $st = 0;

    function __construct (\pg\Connection $conn) {
        $this->conn = $conn;
    }

    function getState () { return $this->st; }


    function setSql ($q) {
        $this->sql = $q;
    }

    function setParseParamTypes (array $oids) {
        $this->ppTypes;
    }

    function setName ($name) {
        $this->name = $name;
    }

    function parse () {
        $w = new wire\Writer;
        $w->writeParse($this->name, $this->sql, $this->ppTypes);
        printf("Write parse for %s\n", $this->name);
        $this->conn->write($w->get());
        $w->clear();
        $w->writeSync();
        $this->conn->write($w->get());
        $this->readAndDump();
        $this->st = $this->st | self::ST_PARSED;
        $this->st = $this->st & ~self::ST_DESCRIBED;
    }

    function describe () {
        // ...
        $this->st = $this->st | self::ST_DESCRIBED;
    }

    function bind (array $params) {
        $w = new wire\Writer;
        $w->writeBind($this->name, $this->name, $params);
        printf("Write bind for %s\n", $this->name);
        $this->conn->write($w->get());
        /*
        $w->clear();
        $w->writeSync();
        $this->conn->write($w->get());
        $this->readAndDump();
        */
    }

    function execute ($rowLimit=0) {
        $w = new wire\Writer;
        $w->writeExecute($this->name, $rowLimit);
        $w->writeSync();
        printf("Write execute for %s\n", $this->name);
        $this->conn->write($w->get());

        $w->clear();
        $w->writeSync();
        $this->conn->write($w->get());
        $this->readAndDump();
    }

    function sync () {
        $w = new wire\Writer;
        $w->writeSync();
        printf("Write sync for %s\n", $this->name);
        $this->conn->write($w->get());
        $this->readAndDump();
    }

    function close () {
    }


    private function readAndDump () {
        $r = new wire\Reader($this->conn->read());
        foreach($r->chomp() as $m) {
            printf("Read %s\n", $m->getName());
        }
    }
}

