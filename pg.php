<?php

namespace pg;

use pg\wire;

require_once 'pg.wire.php';



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


// Exception wrapper for a Pg ErrorResponse message
class PgException extends \Exception
{
    private $eData;
    function __construct (wire\Message $em = null, $errNum = 0) {
        if (!$em) {
            parent::__construct("Invalid error condition - no message supplied", 7643);
        } else if ($em->getName() != 'ErrorResponse') {
            parent::__construct("Unexpected input message for PgException: " . $em->getName(), 3297);
        } else {
            $errCode = -1;
            $errMsg = '(no pg error message found)';
            foreach ($em->getData() as $eField) {
                $this->eData[$eField[0]] = $eField[1];
                switch ($eField[0]) {
                case ERR_CODE:
                    $errCode = $eField[1];
                    break;
                case ERR_MESSAGE:
                    $errMsg = $eField[1];
                    break;
                }
            }
            parent::__construct($errMsg, $errNum);
        }
    }

    function getErrorFields () {
        return $this->eData;
    }

    function getSqlState () {
        return (isset($this->eData[ERR_CODE])) ?
            $this->eData[ERR_CODE]
            : false;
    }
}




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

    private $meta;

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
                throw new PgException($m, 7585);
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
            printf("Write:\n%s\n", wire\hexdump($buff));
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
        $buff = '';
        if ($select === false) {
            return false;
        } else if ($select > 0) {
            $buff = $this->readAll();
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
        if ($this->debug) {
            printf("Read:\n%s\n", wire\hexdump($buff));
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
     * @return void
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
                        $q->addResult($rSet);
                        $rSet = null;
                    } else {
                        $q->addResult(new Result($m));
                    }
                    break;
                case 'ErrorResponse':
                    // Note that responses and response data from previous commands will
                    // still be available as normal in the calling code (although this, and
                    // subsequent responses aren't!)
                    throw new PgException($m, 8751);
                case 'ReadyForQuery':
                    $complete = true;
                    break;
                case 'CopyInResponse':
                    if ($cir = $q->popCopyData()) {
                        $w->clear();
                        $w->writeCopyData($cir);
                        $w->writeCopyDone();
                        $this->write($w->get());
                    } else {
                        $w->clear();
                        $w->writeCopyFail('No input data provided');
                        $this->write($w->get());
                    }
                    break;
                }
            }
        }
    }


    function getMeta () {
        return (is_null($this->meta)) ?
            ($this->meta = new Meta($this))
            : $this->meta;
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



/** Todo: remove support for ErrorResponse - no needed now that
    PgException is in place. */
class Result
{
    private $raw;
    private $resultType; // CommandComplete or ErrorResponse
    private $command; // update, insert, etc.
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

            $this->command = trim(strtoupper(array_shift($bits)));
            if (count($bits) > 1) {
                list($this->commandOid, $this->affectedRows) = $bits;
            } else {
                $this->affectedRows = reset($bits);
            }
            break;
        case 'RowDescription':
            $this->command = 'INSERT';
            break;
        }
    }

    function getResultType () {
        return $this->raw->getName();
    }

    function getCommand () {
        return $this->command;
    }

    function getRowsAffected () {
        return $this->affectedRows;
    }

    function getErrDetail () {
        return $this->errData;
    }
}


class ResultSet extends Result implements \Iterator, \ArrayAccess, \Countable
{
    const ASSOC = 1;
    const NUMERIC = 2;

    public $fetchStyle = self::ASSOC;
    private $colNames = array();
    private $colTypes = array();
    private $rows = array();
    private $i = 0;

    function __construct (wire\Message $rDesc) {
        parent::__construct($rDesc);
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

    function count () {
        return count($this->rows);
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
        return ($this->fetchStyle == self::ASSOC) ?
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






class Statement
{
    private $conn; // Underlying Connection object
    private $sql;  // SQL command
    private $name = false; // Name of the statement / portal
    private $ppTypes = array(); // Input parameter types
    private $canExecute = false; // Internal state flag
    private $paramDesc; // wire\Message of type ParameterDescription
    private $resultDesc; // wire\Message of type NoData or RowDescription


    function __construct (\pg\Connection $conn) {
        $this->conn = $conn;
        $this->reader = new wire\Reader;
        $this->writer = new wire\Writer;
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

    // Sends protocol messages parse, describe, sync; blocks for response
    function parse () {
        $this->writer->clear();
        $this->writer->writeParse($this->name, $this->sql, $this->ppTypes);
        $this->writer->writeDescribe('S', $this->name);
        $this->writer->writeSync();
        $this->conn->write($this->writer->get());

        // Wait for the response
        $complete = false;
        $this->reader->clear();
        while (! $complete) {
            $this->reader->append($this->conn->read());
            foreach ($this->reader->chomp() as $m) {
                switch ($m->getName()) {
                case 'RowDescription':
                case 'NoData':
                    $this->resultDesc = $m;
                    break;
                case 'ParameterDescription':
                    $this->paramDesc = $m;
                    break;
                case 'ReadyForQuery':
                    $complete = true;
                    break;
                case 'ErrorResponse':
                    throw new PgException($m, 7591);
                }
            }
        }
        $this->canExecute = true;
        return true;
    }

    // Sends protocol messages: bind, execute, sync, blocks for response
    function execute (array $params=array(), $rowLimit=0) {
        if (! $this->canExecute) {
            throw new \Exception("Statement is not ready to execute", 7425);
        }
        $this->writer->clear();
        $this->writer->writeBind($this->name, $this->name, $params);
        $this->writer->writeExecute($this->name, $rowLimit);
        $this->writer->writeSync();
        $this->conn->write($this->writer->get());

        $this->reader->clear();
        $complete = $rSet = false;
        $ret = array();

        while (! $complete) {
            $this->reader->append($this->conn->read());
            foreach ($this->reader->chomp() as $m) {
                switch ($m->getName()) {
                case 'BindComplete':
                    // Ignore this one.
                    break;
                case 'RowData':
                    if (! $rSet) {
                        $rSet = new ResultSet($this->resultDesc);
                    }
                    $rSet->addRow($m);
                    break;
                case 'CommandComplete':
                    if ($rSet) {
                        $ret[] = $rSet;
                        $rSet = false;
                    } else {
                        $ret[] = new Result($m);
                    }
                    break;
                case 'ReadyForQuery':
                    $complete = true;
                    break;
                case 'ErrorResponse':
                    throw new PgException($m, 9653);
                }
            }
        }
        return $ret;
    }


    function close () {
    }
}




// Use low level constructs to a database's type meta data
// See: http://www.postgresql.org/docs/9.0/interactive/catalog-pg-type.html#CATALOG-TYPCATEGORY-TABLE
class Meta
{
    private $state = 0;
    private $conn;
    private $types = array(); // Format: array(<type oid> => <typcategory code>)
    private $tables = array(); // Format: array(<table oid> => array(nspname, tablename, array(oid, colname, coltype, collen)));
    private $singleNs;


    /** Maps Postgres catalog type Oids to PHP types */
    function getTypeClass ($typOid) {
        return (array_key_exists($typOid, $this->typeOidMap)) ?
            $this->typeOidMap[$typOid]
            : 'string';

    }

    // Only for docs!
    protected $typeOids = array(
                                  22 => 'int2vector',
                                  30 => 'oidvector',
                                  16 => 'bool', // boolean
                                  702 => 'abstime',
                                  1184 => 'timestamptz',
                                  1114 => 'timestamp',
                                  1083 => 'time',
                                  1266 => 'timetz',
                                  1082 => 'date',
                                  604 => 'polygon',
                                  600 => 'point',
                                  718 => 'circle',
                                  628 => 'line',
                                  601 => 'lseg',
                                  602 => 'path',
                                  603 => 'box',
                                  650 => 'cidr',
                                  869 => 'inet',
                                  3769 => 'regdictionary',
                                  20 => 'int8',//integer
                                  21 => 'int2y',
                                  23 => 'int4',//integer
                                  24 => 'regproc',
                                  26 => 'oid',//integer
                                  700 => 'float4',//float
                                  701 => 'float8',//float
                                  790 => 'money',//float
                                  1700 => 'numeric',//float
                                  2202 => 'regprocedure',
                                  2203 => 'regoper',
                                  2204 => 'regoperator',
                                  2205 => 'regclass',
                                  2206 => 'regtype',
                                  3734 => 'regconfig',
                                  18 => 'char',//string
                                  19 => 'name',//string
                                  25 => 'text',//string
                                  1043 => 'varchar',//string
                                  1042 => 'bpchar',
                                  1186 => 'interval',
                                  703 => 'reltime',
                                  704 => 'tinterval',
                                  1790 => 'refcursor',
                                  3642 => 'gtsvector',
                                  17 => 'bytea',//string
                                  3615 => 'tsquery',
                                  28 => 'xid',
                                  29 => 'cid',
                                  27 => 'tid',
                                  210 => 'smgr',
                                  2950 => 'uuid',
                                  829 => 'macaddr',
                                  2970 => 'txid_snapshot',
                                  142 => 'xml',
                                  1033 => 'aclitem',
                                  3614 => 'tsvector',
                                  1560 => 'bit',
                                  1562 => 'varbit',
                                  705 => 'unknown'
                                  );

    /** Map Postgres type Oids to PHP scalar type.  See here:
        http://archives.postgresql.org/pgsql-novice/2010-07/msg00032.php */
    protected $typeOidMap = array(16 => 'boolean', 20 => 'integer', 23 => 'integer', 26 => 'integer',
                                  700 => 'float', 701 => 'float', 790 => 'float', 1700 => 'float',
                                  18 => 'string', 19 => 'string', 25 => 'string', 1043 => 'string',
                                  17=> 'string');


    function __construct (Connection $conn) {
        $this->conn = $conn;
        $this->initTypeCache();
        $this->initTablesCache();
    }

    function dumpTypes () {
        // Deleteme
        printf("Dump types:\n");
        foreach ($this->types as $tRow) {
            if ($tRow['typname'][0] !== '_') {
                vprintf("  %s => %s\n", $tRow);
            }
        }
    }

    /** Load type data for the given Connection */
    private function initTypeCache () {
        if ($this->state & 1) {
            printf("Shzaaaame = type cache\n");
            return;
        }
        $q = new Query('SELECT oid, typname, typtype, typcategory, typarray FROM pg_type WHERE typtype = \'b\' ORDER BY typcategory;');
        $this->conn->runQuery($q);
        $done = false;
        foreach ($q->getResults() as $res) {
            if ($res instanceof ResultSet) {
                $done = true;
                $res->fetchStyle = ResultSet::ASSOC;
                foreach ($res as $row) {
                    $this->types[$row['oid']] = $row;
                }
            }
        }
        if (! $done) {
            throw new \Exception("Failed to initialise type cache", 2752);
        }
        $this->state = $this->state | 1;
    }

    private function initTablesCache () {
        if ($this->state & 2) {
            printf("Shzaaaame = tables cache\n");
            return;
        }
        $this->initTypeCache();
        $qry = 'select n.nspname, c.oid, c.relname, a.attname, a.atttypid, a.attlen ' .
            'from pg_namespace n ' .
            'inner join pg_class c ' .
            'on n.oid = c.relnamespace ' .
            '    and c.relkind = \'r\' ' .
            '    and n.nspname != \'information_schema\' ' .
            '    and n.nspname not like \'pg_%\' ' .
            'inner join pg_attribute a ' .
            'on c.oid = a.attrelid ' .
            '    and a.attnum > 0 ' .
            'order by n.nspname, c.relname';
        $q = new Query($qry);
        $this->conn->runQuery($q);
        $done = false;
        foreach ($q->getResults() as $res) {
            if ($res instanceof ResultSet) {
                $res->fetchStyle = ResultSet::ASSOC;
                $done = true;
                foreach ($res as $row) {
                    if (! array_key_exists($row['oid'], $this->tables)) {
                        $this->tables[$row['oid']] = array($row['nspname'],
                                                          $row['relname'],
                                                          array($row['attname'], $row['atttypid'], $row['attlen'])
                                                          );
                    } else {
                        $this->tables[$row['oid']][2][] = array($row['attname'], $row['atttypid'], $row['attlen']);
                    }
                }
            }
        }
        if (! $done) {
            trigger_error("No tables were cached", E_USER_WARNING);
        }
        $this->state = $this->state | 2;
    }

    function getTypes () {
        return $this->types;
    }

    function getType ($oid) {
        return isset($this->types[$oid]) ? $this->types[$oid] : null;
    }
}


/** Deals with converting data between PHP variables and wire format,
    which is actually just text.  Doesn't deal with any table-column
    specific bounds, like char length */
class ValueConverter
{
    private $meta;
    function __construct (Meta $meta) {
        $this->meta = $meta;
    }

    /** Use type / class sniffing to format a standard postgres const of type $typId from $v */
    function fromVar ($v, $typId) {
    }

    /** Convert standard postgres const in $buff to a php type corresponding to $typId */
    function toVar ($buff, $typId) {
    }

    function stringToPhp ($s) {
        return $s;
    }
    function stringToPg ($s) {
        return $s;
    }


    function boolToPhp ($s) {
        return ($s[0] == 't');
    }
    function boolToPg ($b) {
        return $b ? 't' : 'f';
    }

    function intToPhp ($s) {
        return (int) $s;
    }

    function intToPg ($i) {
        return (string) $i;
    }

    function floatToPhp ($s) {
        return (float) $s;
    }

    function floatToPg ($f) {
        return (string) $f;
    }
}
