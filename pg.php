<?php

namespace pg;

use pg\wire;

require_once 'pg.wire.php';


/**
 * Wrapper for a Socket connection to postgres.
 */
class Connection
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
        $w = new wire\Writer;
        $w->writeTerminate();
        $this->write($w->get());
        $this->connected = false;
        socket_close($this->sock);
    }


    /**
     * Invoke the given query and store all result messages in $q.
     */
    function runQuery (Query $q) {
        if (! $this->connected) {
            throw new \Exception("Query run failed (0)", 735);
        }
        $w = new wire\Writer;
        $w->writeQuery($q->getQuery());
        if (! $this->write($w->get())) {
            throw new \Exception("Query run failed (1)", 736);
        }

        $complete = false;
        $r = new wire\Reader;
        $rSet = null;
        while (! $complete) {
            $this->select();
            if (! ($buff = $this->readAll())) {
                trigger_error("Query read failed", E_USER_WARNING);
                break;
            }

            if ($this->debug) {
                info("Read:\n%s", wire\hexdump($buff));
            }


            $r->append($buff);
            $msgs = $r->chomp();
            foreach ($msgs as $m) {
                switch ($m->getName()) {
                case 'RowDescription':
                    $rSet = new ResultSet($m);
                    break;
                case 'RowData':
                    if (! $rSet) {
                        throw new \Exception("Illegal state - no current row container", 1749);
                    }
                    $rSet->addRow($m);
                    break;
                case 'CommandComplete':
                    if ($rSet) {
                        //$ret[] = $rSet;
                        $q->addResult($rSet);
                        $rSet = null;
                    } else {
                        //$ret[] = $m;
                        $q->addResult(new Result($m));
                    }
                case 'ErrorResponse':
                    //$ret[] = $m;
                    $q->addResult(new Result($m));
                case 'ReadyForQuery':
                    $complete = true;
                break;
                case 'CopyInResponse':
                    if ($cir = $q->popCopyData()) {
                        $w->clear();
                        $w->writeCopyData($cir);
                        $w->writeCopyDone();
                        info("Write Copy Data:\n%s", wire\hexdump($w->get()));
                        $this->write($w->get());
                    } else {
                        $w->clear();
                        $w->writeCopyFail('No input data provided');
                        $this->write($w->get());
                    }
                    break;
                }
            }
            //$ret = array_merge($ret, $msgs);
        }
        //$q->setResultSet($ret);
    }

}


/**
 * Wrapper for the Postgres Simple Query API
 */
class Query
{
    private $q;
    private $r;

    private $copyData;

    function __construct ($q = '') {
        $this->setQuery($q);
    }

    function setQuery ($q) {
        $this->q = $q;
    }

    function getQuery () {
        return $this->q;
    }

    /*function setResultSet (array $r) {
        $this->r = $r;
        }*/

    function addResult (Result $res) {
        $this->r[] = $res;
    }

    function getResults () {
        return $this->r;
    }

    function pushCopyData ($dt) {
        $this->copyData[] = $dt;
    }

    function popCopyData () {
        return array_pop($this->copyData);
    }

}

// These are the fields that are returned as part of a ErrorResponse response.
// See http://www.postgresql.org/docs/9.0/static/protocol-message-formats.html

//Severity: the field contents are ERROR, FATAL, or PANIC (in an error message), or WARNING, NOTICE, DEBUG, INFO, or LOG (in a notice message), or a localized translation of one of these. Always present.
const ERR_SEVERITY = 'S';
//Code: the SQLSTATE code for the error (see Appendix A). Not localizable. Always present.
const ERR_CODE = 'C';
//Message: the primary human-readable error message. This should be accurate but terse (typically one line). Always present.
const ERR_MESSAGE = 'M';
//Detail: an optional secondary error message carrying more detail about the problem. Might run to multiple lines.
const ERR_DETAIL = 'D';
//Hint: an optional suggestion what to do about the problem. This is intended to differ from Detail in that it offers advice (potentially inappropriate) rather than hard facts. Might run to multiple lines.
const ERR_HINT = 'H';
//Position: the field value is a decimal ASCII integer, indicating an error cursor position as an index into the original query string. The first character has index 1, and positions are measured in characters not bytes.
const ERR_POSITION = 'P';
//Internal position: this is defined the same as the P field, but it is used when the cursor position refers to an internally generated command rather than the one submitted by the client. The q field will always appear when this field appears.
const ERR_INTERNAL = 'p';
//Internal query: the text of a failed internally-generated command. This could be, for example, a SQL query issued by a PL/pgSQL function.
const ERR_INTERNAL_QUERY = 'q';
//Where: an indication of the context in which the error occurred. Presently this includes a call stack traceback of active procedural language functions and internally-generated queries. The trace is one entry per line, most recent first.
const ERR_WHERE = 'W';
//File: the file name of the source-code location where the error was reported.
const ERR_FILE = 'F';
//Line: the line number of the source-code location where the error was reported.
const ERR_LINE = 'L';
//Routine: the name of the source-code routine reporting the error.
const ERR_ROUTINE = 'R';


/** Wrapper for non-data result types, at the moment either
    CommandComplete or ErrorResponse */
class Result
{
    private $raw;
    private $resultType; // CommandComplete or ErrorResponse
    private $commandType; // update, insert, etc.
    private $commandOid; // oid portion of CommandComplete response (optional)
    private $affectedRows = 0; // # of rows affected by CommandComplete
    private $errData; // Assoc array of error data

    function __construct (wire\Message $m) {
        $this->raw = $m;
        switch ($this->resultType = $m->getName()) {
        case 'ErrorResponse':
            $this->errData = array();
            foreach ($m->getData() as $row) {
                $this->errData[$row[0]] = $row[1];
            }
            break;
        case 'CommandComplete':
            $msg = $m->getData();
            $bits = explode(' ', $msg[0]);
            $this->commandType = array_shift($bits);
            if (count($bits) > 1) {
                list($this->commandOid, $this->affectedRows) = $bits;
            } else {
                $this->affectedRows = $bits;
            }
            break;
        }
    }

    function getResultType () {
        return $this->raw->getName();
    }

    function getCommand () {
        return $this->commandType;
    }

    function getRowsAffected () {
        return $this->affectedRows;
    }

    function getErrDetail () {
        return $this->errData;
    }
}


class ResultSet extends Result implements \Iterator, \ArrayAccess
{
    const ASSOC = 1;
    const NUMERIC = 2;

    public $resultStyle = self::ASSOC;
    private $colNames = array();
    private $colTypes = array();
    private $rows = array();
    private $i = 0;

    function __construct (wire\Message $rDesc) {
        $this->initCols($rDesc);
    }

    function offsetExists ($n) {
        return array_key_exists($n, $this->rows);
    }

    function offsetGet ($n) {
        return $this->rows[$n];
    }

    function offsetSet ($ofs, $val) {
        throw new \Exception("ResultSet is a read-only data handler", 9865);
    }

    function offsetUnset ($ofs) {
        throw new \Exception("ResultSet is a read-only data handler [2]", 9866);
    }

    function addRow (wire\Message $msg) {
        $aRow = array();
        foreach ($msg->getData() as $i => $dt) {
            if ($i == 0) {
                continue;
            }
            $aRow[] = $dt[1];
        }
        $this->rows[] = $aRow;
    }

    /** Munge the column meta data in to something that's easier to work with */
    private function initCols (wire\Message $msg) {
        foreach ($msg->getData() as $i => $row) {
            if ($i == 0) {
                continue;
            }
            $this->colNames[] = $row[0];
            $this->colTypes[] = $row[3];
        }
    }

    function rewind () {
        $this->i = 0;
    }

    function current () {
        return ($this->resultStyle == self::ASSOC) ?
            array_combine($this->colNames, $this->rows[$this->i])
            : $this->rows[$this->i];
    }

    function key () {
        return $this->i;
    }

    function next () {
        $this->i++;
    }

    function valid () {
        return $this->i < count($this->rows);
    }
}


// Use low level constructs to a database's type meta data
// See: http://www.postgresql.org/docs/9.0/interactive/catalog-pg-type.html#CATALOG-TYPCATEGORY-TABLE
class TypeDict
{
    // Format: array(<oid> => <typcategory code>)
    private $types = array();
    function __construct (Connection $conn) {
        $this->initCache($conn);
    }

    /** Load type data for the given Connection */
    private function initCache ($conn) {
        $q = new Query('SELECT oid, typname, typtype, typcategory, typarray FROM pg_type ORDER BY typcategory;');
        $conn->runQuery($q);
        $complete = false;
        // TODO: Rewrite - broken now!
        foreach ($q->getResultSet() as $r) {
            $d = $r->getData();
            switch ($mType = $r->getName()) {
            case 'RowDescription':
                break; // Chicken, egg?
            case 'RowData':
                $this->types[(int) $d[1][1]] = array($d[1][1], $d[2][1], $d[3][1], $d[4][1], $d[5][1]);
                break;
            case 'ReadyForQuery':
                $complete = true;
                break;
            }
        }
        if (! $complete) {
            throw new \Exception("Failed to collect type data", 8746);
        }
        array_map(function ($row) {
                return vprintf("oid: %s; typename: %s; typtype: %s; cat: %s; array: %d\n", $row);
            },
            $this->types);
        printf("\nCached %d types\n", count($this->types));
    }

    function getTypes () {
        return $this->types;
    }

    function getType ($oid) {
        return isset($this->types[$oid]) ? $this->types[$oid] : null;
    }
}