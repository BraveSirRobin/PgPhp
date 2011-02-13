<?php
const HEXDUMP_BIN = '/usr/bin/hexdump -C';


function info () {
    $args = func_get_args();
    $fmt = array_shift($args);
    vprintf("{$fmt}\n", $args);
}


function hexdump($subject) {
    if ($subject === '') {
        return "00000000\n";
    }
    $pDesc = array(
                   array('pipe', 'r'),
                   array('pipe', 'w'),
                   array('pipe', 'r')
                   );
    $pOpts = array('binary_pipes' => true);
    if (($proc = proc_open(HEXDUMP_BIN, $pDesc, $pipes, null, null, $pOpts)) === false) {
        throw new \Exception("Failed to open hexdump proc!", 675);
    }
    fwrite($pipes[0], $subject);
    fclose($pipes[0]);
    $ret = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $errs = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    if ($errs) {
        printf("[ERROR] Stderr content from hexdump pipe: %s\n", $errs);
    }
    proc_close($proc);
    return $ret;
}


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
        $w = new PgWireWriter();
        $r = new PgWireReader();

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

    private function getAuthResponse (PgMessage $authMsg) {
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
            info("Write:\n%s", hexdump($buff));
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
            info("Read:\n%s", hexdump($buff));
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
        $w = new PgWireWriter;
        $w->writeQuery($q->getQuery());
        if (! $this->write($w->get())) {
            throw new \Exception("Query run failed (1)", 736);
        }

        // Select calls system select and blocks
        //if (! $this->select()) {
        //    throw new \Exception("Query run failed (2)", 737);
        //}
        $complete = false;
        $r = new PgWireReader;
        $rSet = array();
        while (! $complete) {
            $this->select();
            if (! ($buff = $this->readAll())) {
                trigger_error("Query read failed", E_USER_WARNING);
                break;
            }

            if ($this->debug) {
                info("Read:\n%s", hexdump($buff));
            }


            $r->set($buff);
            $msgs = $r->chomp();
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

/** Simple data container for a single message */
class PgMessage
{
    /** Name of the message type */
    private $name;

    /** Character of the message type */
    private $char;

    /** Array of message data, all data fields in order, excluding
        the message type and message length fields. */
    private $data;

    function __construct ($name, $char, $data = array()) {
        $this->name = $name;
        $this->char = $char;
        $this->data = $data;
        if (! $this->name || ! $this->char) {
            throw new \Exception("Message type is not complete", 554);
        }
    }

    function getName () {
        return $this->name;
    }
    function getType () {
        return $this->char;
    }
    function getData () {
        return $this->data;
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

    function __construct (PgMessage $rDesc) {
        if ($rDesc->getName() !== 'RowDescription') {
            throw new \Exception("Invalid result set row description message", 7548);
        }
        $this->rDesc = $rDesc;
    }

    function addRow (PgMessage $row) {
        if ($rDesc->getName() !== 'RowData') {
            throw new \Exception("Invalid result set row data message", 7549);
        }
        $this->rows[] = $row;
    }
}


class PgWireReader
{
    private $buff = '';
    private $buffLen = 0;
    private $p = 0;
    private $msgLen = 0;

    function __construct ($buff = '') {
        $this->set($buff);
    }

    function get () {
        return $this->buff;
    }
    function set ($buff) {
        $this->buff = $buff;
        $this->buffLen = strlen($buff);
        $this->p = 0;
    }
    function clear () {
        $this->buff = '';
        $this->p = $this->buffLen = 0;
    }
    function isSpent () {
        return ! ($this->p < $this->buffLen);
    }
    function hasN ($n) {
        return ($n == 0) || ($this->p + $n <= $this->buffLen);
    }

    function doTest ($test = '12345') {
        $this->set($test);
        for ($i = 0; $i < strlen($test) + 2; $i++) {
            $this->p = $i;
            for ($j = 0; $j < strlen($test) + 2; $j++) {
                printf("(\$this->p, \$j) = (%d, %d); isSpent %b, hasN(%d), %b\n", $this->p, $j, $this->isSpent(), $j, $this->hasN($j));
            }
        }
    }


    /** Read and return up to $n messages */
    function chomp ($n = 0) {
        $i = $max = 0;
        $ret = array();
        while ($this->hasN(5) && ($n == 0 || $i++ < $n)) {
            $msgType = substr($this->buff, $this->p, 1);
            $tmp = unpack("N", substr($this->buff, $this->p + 1, 4));
            $this->msgLen = array_pop($tmp);

            if (! $this->hasN($this->msgLen)) {
                info("CHOMP EXIT: Don't have N: %d (%s)", $this->msgLen, dechex($this->msgLen));
                break;
            }
            $this->p += 5;

            switch ($msgType) {
            case 'R':
                $ret[] = $this->readAuthentication();
                break;
            case 'K':
                $ret[] = $this->readBackendKeyData();
                break;
            case 'B':
                $ret[] = $this->readBind();
                break;
            case '2':
                $ret[] = $this->readBindComplete();
                break;
            case '3':
                $ret[] = $this->readCloseComplete();
                break;
            case 'C':
                $ret[] = $this->readCommandComplete();
                break;
            case 'd':
                $ret[] = $this->readCopyData();
                break;
            case 'c':
                $ret[] = $this->readCopyDone();
                break;
            case 'G':
                $ret[] = $this->readCopyInResponse();
                break;
            case 'H':
                $ret[] = $this->readCopyOutResponse();
                break;
            case 'D':
                $ret[] = $this->readDataRow();
                break;
            case 'I':
                $ret[] = $this->readEmptyQueryResponse();
                break;
            case 'E':
                $ret[] = $this->readErrorResponse();
                break;
            case 'V':
                $ret[] = $this->readFunctionCallResponse();
                break;
            case 'n':
                $ret[] = $this->readNoData();
                break;
            case 'N':
                $ret[] = $this->readNoticeResponse();
                break;
            case 'A':
                $ret[] = $this->readNotificationResponse();
                break;
            case 't':
                $ret[] = $this->readParameterDescription();
                break;
            case 'S':
                $ret[] = $this->readParameterStatus();
                break;
            case '1':
                $ret[] = $this->readParseComplete();
                break;
            case 's':
                $ret[] = $this->readPortalSuspended();
                break;
            case 'Z':
                $ret[] = $this->readReadyForQuery();
                break;
            case 'T':
                $ret[] = $this->readRowDescription();
                break;
            default:
                throw new \Exception("Unknown message type", 98765);
            }
        }
        return $ret;
    }

    /** Are of many possibilities! */
    function readAuthentication () {
        $tmp = unpack('N', substr($this->buff, $this->p, 4));
        $authType = array_pop($tmp);
        $this->p += 4;
        switch ($authType) {
        case 0:
            return new PgMessage('AuthenticationOk', 'R', array($authType));
        case 2:
            return new PgMessage('AuthenticationKerberosV5', 'R', array($authType));
        case 3:
            return new PgMessage('AuthenticationCleartextPassword', 'R', array($authType));
        case 5:
            $salt = substr($this->buff, $this->p, 4);
            $this->p += 4;
            return new PgMessage('AuthenticationMD5Password', 'R', array($authType, $salt));
        case 6:
            return new PgMessage('AuthenticationSCMCredential', 'R', array($authType));
        case 7:
            return new PgMessage('AuthenticationGSS', 'R', array($authType));
        case 8:
            throw new \Exception("Unsupported auth message: AuthenticationGSSContinue", 6745);
        case 9:
            return new PgMessage('AuthenticationSSPI', 'R', array($authType));
        default:
            throw new \Exception("Unknown auth message type: {$data['authType']}", 3674);

        }
    }

    function readBackendKeyData () {
        $tmp = unpack('Ni/Nj', substr($this->buff, $this->p, 8));
        $this->p += 8;
        return new PgMessage('BackendKeyData', 'K', array_values($tmp));
    }

    function readBindComplete () {
        throw new \Exception("Message read method not implemented: " . __METHOD__);
    }

    function readCloseComplete () {
        throw new \Exception("Message read method not implemented: " . __METHOD__);
    }

    function readCommandComplete () {
        return new PgMessage('CommandComplete', 'C', array($this->_readString()));
    }

    function readCopyData () {
        throw new \Exception("Message read method not implemented: " . __METHOD__);
    }

    function readCopyDone () {
        throw new \Exception("Message read method not implemented: " . __METHOD__);
    }

    function readCopyInProgress () {
        throw new \Exception("Message read method not implemented: " . __METHOD__);
    }

    function readCopyOutResponse () {
        throw new \Exception("Message read method not implemented: " . __METHOD__);
    }

    function readDataRow () {
        $data = array();
        $ep = $this->p + $this->msgLen - 5;
        $tmp = unpack('n', substr($this->buff, $this->p, 2));
        $this->p += 2;
        $data[] = reset($tmp);

        //info("Consume row descriptions: %d -> %d", $this->p, $ep);
        while ($this->p < $ep) {
            $row = array();
            $tmp = unpack('N', substr($this->buff, $this->p, 4));
            $this->p += 4;
            $row[] = reset($tmp);
            //info("Substr: %d %d : %s", $this->p, $row[0], substr($this->buff, $this->p, $row[0]));
            $row[] = substr($this->buff, $this->p, $row[0]);
            $this->p += $row[0];
            $data[] = $row;
        }
        //var_dump($data);
        return new PgMessage('RowData', 'D', $data);
    }

    function readEmptyQueryResponse () {
        return new PgMessage('EmptyQueryResponse', 'I', array());
    }

    function readErrorResponse () {
        $data = array();
        $ep = $this->p + $this->msgLen - 4;
        while ($this->p < $ep) {
            $ft = substr($this->buff, $this->p++, 1);
            $row = array($ft, $this->_readString());
            $data[] = $row;
        }
        $tmp = unpack('C', substr($this->buff, $this->p++, 1));
        if (reset($tmp) !== 0) {
            throw new \Exception("Protocol error - missed error response end", 4380);
        }
        return new PgMessage('ErrorResponse', 'E', $data);
    }

    function readFunctionCallResponse () {
        throw new \Exception("Message read method not implemented: " . __METHOD__);
    }

    function readNoData () {
        throw new \Exception("Message read method not implemented: " . __METHOD__);
    }

    function readNoticeResponse () {
        throw new \Exception("Message read method not implemented: " . __METHOD__);
    }

    function readNotificationResponse () {
        throw new \Exception("Message read method not implemented: " . __METHOD__);
    }

    function readParameterDescription () {
        throw new \Exception("Message read method not implemented: " . __METHOD__);
    }

    function readParameterStatus () {
        $data = array();
        $data[] = $this->_readString();
        $data[] = $this->_readString();
        return new PgMessage('ParameterStatus', 'S', $data);
    }

    function readParseComplete () {
        throw new \Exception("Message read method not implemented: " . __METHOD__);
    }

    function readPortalSuspended () {
        throw new \Exception("Message read method not implemented: " . __METHOD__);
    }

    function readReadyForQuery () {
        return new PgMessage('ReadyForQuery', 'Z', array(substr($this->buff, $this->p++, 1)));
    }

    function readRowDescription () {
        $data = array();
        $ep = $this->p + $this->msgLen - 4;
        $tmp = unpack('n', substr($this->buff, $this->p, 2));
        $this->p += 2;
        $data[] = $tmp;

        //info("Consume row descriptions: %d -> %d", $this->p, $ep);
        while ($this->p < $ep) {
            $row = array();
            $row[] = $this->_readString();
            $tmp = unpack('Na/nb/Nc/nd/Ne/nf', substr($this->buff, $this->p, 18));
            //var_dump($tmp);
            $row = array_merge($row, array_values($tmp));
            $this->p += 18;
            $data[] = $row;
            //info("Row consume done: %d -> %d", $this->p, $ep);
        }
        //var_dump($data);
        return new PgMessage('RowDescription', 'T', $data);
    }

    private function _readString () {
        $r = substr($this->buff, $this->p, strpos($this->buff, "\x00", $this->p) - $this->p);
        $this->p += strlen($r) + 1;
        return $r;
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


class PgWireWriter
{
    private $buff;
    function __construct ($buff = '') {
        $this->buff = $buff;
    }

    function get () { return $this->buff; }
    function set ($buff) { $this->buff = $buff; }
    function clear () { $this->buff = ''; }

    function writeBind () {}

    function writeCancelRequest() {}

    function writeClose () {}

    function writeCopyData () {}
    function writeCopyDone () {}
    function writeCopyFail () {}
    function writeDescribe () {}
    function writeExecute () {}
    function writeFlush () {}
    function writeFunctionCall () {}
    function writeParse () {}
    function writePasswordMessage ($msg) {
        $this->buff .= 'p' . pack('N', strlen($msg) + 5) . "{$msg}\x00";
    }
    function writeQuery ($q) {
        $this->buff .= 'Q' . pack('N', strlen($q) + 5) . "{$q}\x00";
    }
    function writeSSLRequest () {}

    function writeStartupMessage ($user, $database) {
        $start = pack('N', 196608);
        $start .= "user\x00{$user}\x00";
        $start .= "database\x00{$database}\x00\x00";
        $this->buff .= pack('N', strlen($start) + 4) . $start;
    }
    function writeSync () {}
    function writeTerminate () {}
}





try {
    $dbh = new PgConnection;
    $dbh->connect();
    //    $dbh->close();
    info("Connected OK");
} catch (Exception $e) {
    info("Connect failed:\n%s", $e->getMessage());
}



$q = new PgQuery('select * from nobber;insert into nobber (fanneh) values (\'shitface\');');

try {
    $dbh->debug = true;
    $dbh->runQuery($q);
} catch (Exception $e) {
    info("Query failed:\n%s", $e->getMessage());
}

var_dump($q);