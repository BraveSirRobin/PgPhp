<?php

namespace pg;

use pg\wire;

require_once 'pg.wire.php';


/**
 * Wrapper for a Socket connection to postgres.
 */
class PgConnection
{

    public $debug = false;

    private $sock;
    private $host = 'localhost';
    private $port = 5432;

    private $database = 'test1';

    private $dbUser = 'php';
    private $dbPass = 'letmein';

    private $connected = false;

    /** Connection parameters, given by postgres during setup. */
    private $params = array();

    function __construct () {
        if (! ($this->sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP))) {
            throw new \Exception("Failed to create inet socket", 7895);
        } else if (! socket_connect($this->sock, $this->host, $this->port)) {
            throw new \Exception("Failed to connect inet socket ({$this->host}, {$this->port})", 7564);
        }
    }



    function connect () {
        $w = new wire\Writer();
        $r = new wire\Reader();

        $w->writeStartupMessage($this->dbUser, $this->database);
        $this->write($w->get());

        // Read Authentication message
        $resp = $this->read();
        $r->set($resp);
        $msgs = $r->chomp();
        if (count($msgs) != 1) {
            throw new \Exception("Connect Error (1) expected a single message response", 986);
        } else if ($msgs[0]->getType() != 'R') {
            throw new \Exception("Connect Error (2) unexpected message type", 987);
        }

        $auth = $this->getAuthResponse($msgs[0]);

        // Write Authentication response
        $w->clear();
        $w->writePasswordMessage($auth);
        $this->write($w->get());

        // expect BackendKeyData, ParameterStatus, ErrorResponse or NoticeResponse
        $resp = $this->read();
        $r->set($resp);
        $msgs = $r->chomp();

        if (! $msgs) {
            throw new \Exception("Connect Error (3) - no auth response", 8065);
        } else if ($msgs[0]->getName() !== 'AuthenticationOk') {
            throw new \Exception("Connect Error (4) - Auth failed.", 8065);
        }
        $c = count($msgs);

        for ($i = 1; $i < $c; $i++) {
            switch ($msgs[$i]->getName()) {
            case 'ParameterStatus':
                list($k, $v) = $msgs[$i]->getData();
                $this->params[$k] = $v;
                break;
            case 'ReadyForQuery':
                $this->connected = true;
                break;
            case 'ErrorResponse':
                throw new \Exception("Connect failed (5) - error response is post-auth", 8765);
            case 'NoticeResponse':
                throw new \Exception("Connect failed (6) - TODO: Test and implement", 8765);
            }
        }

    }

    private function getAuthResponse (wire\Message $authMsg) {
        list($authType, $salt) = $authMsg->getData();
        switch ($authType) {
        case 5:
            $cryptPwd2 = $this->pgMd5Encrypt($this->dbPass, $this->dbUser);
            $cryptPwd = $this->pgMd5Encrypt(substr($cryptPwd2, 3), $salt);
            return $cryptPwd;
        }
    }

    private function pgMd5Encrypt ($passwd, $salt) {
        $buff = $passwd . $salt;
        return "md5" . md5($buff);
    }




    function write ($buff) {
        $bw = 0;
        $contentLength = strlen($buff);
        if ($this->debug) {
            info("Write:\n%s", wire\hexdump($buff));
        }
        while (true) {
            if (($tmp = socket_write($this->sock, $buff)) === false) {
                throw new \Exception(sprintf("\nSocket write failed: %s\n",
                                             $this->strError()), 7854);
            }
            $bw += $tmp;
            if ($bw < $contentLength) {
                $buff = substr($buff, $bw);
            } else {
                break;
            }
        }
        return $bw;
    }


    function select ($tvSec = null, $tvUsec = 0) {
        $read = $write = $ex = null;
        $read = array($this->sock);

        $this->interrupt = false;
        $ret = socket_select($read, $write, $ex, $tvSec, $tvUsec);
        if ($ret === false && $this->lastError() == SOCKET_EINTR) {
            $this->interrupt = true;
        }
        return $ret;
    }


    function read () {
        $select = $this->select(5);
        if ($select === false) {
            return false;
        } else if ($select > 0) {
            $buff = $this->readAll();
        }
        if ($this->debug) {
            info("Read:\n%s", wire\hexdump($buff));
        }
        return $buff;
    }


    function lastError () {
        return socket_last_error();
    }

    function strError () {
        return socket_strerror($this->lastError());
    }

    function readAll ($readLen = 4096) {
        $buff = '';
        while (@socket_recv($this->sock, $tmp, $readLen, MSG_DONTWAIT)) {
            $buff .= $tmp;
        }
        return $buff;
    }


    function close () {
        $this->connected = false;
        socket_close($this->sock);
    }


    /**
     * Invoke the given query and store all result messages in $q
     */
    function runQuery (PgQuery $q) {
        if (! $this->connected) {
            throw new \Exception("Query run failed (0)", 735);
        }
        $w = new wire\Writer;
        $w->writeQuery($q->getQuery());
        if (! $this->write($w->get())) {
            throw new \Exception("Query run failed (1)", 736);
        }

        // Select calls system select and blocks
        //if (! $this->select()) {
        //    throw new \Exception("Query run failed (2)", 737);
        //}
        $complete = false;
        $r = new wire\Reader;
        $rSet = array();
        while (! $complete) {
            echo "\nCall Select\n";
            $this->select();
            if (! ($buff = $this->readAll())) {
                trigger_error("Query read failed", E_USER_WARNING);
                break;
            }

            if ($this->debug) {
                info("Read:\n%s", wire\hexdump($buff));
            }


            $r->set($buff);
            $msgs = $r->chomp();
            var_dump($msgs);
            foreach ($msgs as $m) {
                switch ($m->getName()) {
                case 'ErrorResponse':
                case 'ReadyForQuery':
                    $complete = true;
                }
            }
            $rSet = array_merge($rSet, $msgs);
        }
        $q->setResultSet($rSet);
    }

}


/**
 * Wrapper for the Postgres Simple Query API
 */
class PgQuery
{
    private $q;
    private $r;

    function __construct ($q = '') {
        $this->setQuery($q);
    }

    function setQuery ($q) {
        $this->q = $q;
    }

    function getQuery () {
        return $this->q;
    }

    function setResultSet (array $r) {
        $this->r = $r;
    }

    function getResultSet () {
        return $this->r;
    }
}

// Unused!
class PgResultSet
{
    private $rDesc;
    private $rows = array();

    function __construct (wire\Message $rDesc) {
        if ($rDesc->getName() !== 'RowDescription') {
            throw new \Exception("Invalid result set row description message", 7548);
        }
        $this->rDesc = $rDesc;
    }

    function addRow (wire\Message $row) {
        if ($rDesc->getName() !== 'RowData') {
            throw new \Exception("Invalid result set row data message", 7549);
        }
        $this->rows[] = $row;
    }
}



/** See http://www.postgresql.org/docs/9.0/static/protocol-message-formats.html */
class PgTypedMessage
{

    //Severity: the field contents are ERROR, FATAL, or PANIC (in an error message), or WARNING, NOTICE, DEBUG, INFO, or LOG (in a notice message), or a localized translation of one of these. Always present.
    const SEVERITY = 'S';
    //Code: the SQLSTATE code for the error (see Appendix A). Not localizable. Always present.
    const CODE = 'C';
    //Message: the primary human-readable error message. This should be accurate but terse (typically one line). Always present.
    const MESSAGE = 'M';
    //Detail: an optional secondary error message carrying more detail about the problem. Might run to multiple lines.
    const DETAIL = 'D';
    //Hint: an optional suggestion what to do about the problem. This is intended to differ from Detail in that it offers advice (potentially inappropriate) rather than hard facts. Might run to multiple lines.
    const HINT = 'H';
    //Position: the field value is a decimal ASCII integer, indicating an error cursor position as an index into the original query string. The first character has index 1, and positions are measured in characters not bytes.
    const POSITION = 'P';
    //Internal position: this is defined the same as the P field, but it is used when the cursor position refers to an internally generated command rather than the one submitted by the client. The q field will always appear when this field appears.
    const INTERNAL = 'p';
    //Internal query: the text of a failed internally-generated command. This could be, for example, a SQL query issued by a PL/pgSQL function.
    const INTERNAL_QUERY = 'q';
    //Where: an indication of the context in which the error occurred. Presently this includes a call stack traceback of active procedural language functions and internally-generated queries. The trace is one entry per line, most recent first.
    const WHERE = 'W';
    //File: the file name of the source-code location where the error was reported.
    const FILE = 'F';
    //Line: the line number of the source-code location where the error was reported.
    const LINE = 'L';
    //Routine: the name of the source-code routine reporting the error.
    const ROUTINE = 'R';

    private $fields = array();
    function addField ($fieldType, $val) {
        $this->fields[$fieldType] = $val;
    }

    function getField ($fieldType) {
        return isset($this->fields[$fieldType]) ? $this->fields[$fieldType] : null;
    }

    function toString () {
        return print_r($this->fields, true);
    }
}
