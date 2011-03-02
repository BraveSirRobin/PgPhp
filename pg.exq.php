<?php
namespace pg\exq;

use pg\wire as wire;


class Statement
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

    // Sends protocol messages: parse, sync, blocks for response
    function parse () {
        $w = new wire\Writer;
        printf("Write parse for %s\n", $this->name);
        $w->writeParse($this->name, $this->sql, $this->ppTypes);
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

    // Sends protocol messages: bind, execute, sync, blocks for response
    function execute (array $params=array(), $rowLimit=0) {
        $w = new wire\Writer;
        $w->writeBind($this->name, $this->name, $params);
        printf("Write bind for %s\n", $this->name);
        $w->writeExecute($this->name, $rowLimit);
        $w->writeSync();
        printf("Write execute for %s\n", $this->name);
        $this->conn->write($w->get());
        $this->readAndDump(); // return
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

