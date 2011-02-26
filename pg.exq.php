<?php
namespace pg\exq;

class Portal
{
    const ST_PARSED = 1;
    const ST_DESCRIBED = 2;
    const ST_BOUND = 4;
    const ST_EXECD = 8;

    private $qry;
    private $prStatName = '';
    private $st = 0;

    function __construct ($prStatName = '', $qry = '') {
        $this->qry = $qry;
    }


    function getState () { return $this->st; }

    function parse () {
        // Close current portal, if required
        // Send parse command, handle errors
        // Flip state flags.

        $this->st = $this->st | self::ST_PARSED;
        $this->st = $this->st & ~self::ST_DESCRIBED;
    }

    function describe () {
        // ...
        $this->st = $this->st | self::ST_DESCRIBED;
    }

    function bind () {
    }

    function execute () {
    }

    function close () {
    }
}

