<?php
const HEXDUMP_BIN = '/usr/bin/hexdump -C';

class PgPhp
{

    private $sock;
    private $host = 'localhost';
    private $port = 5432;

    private $database = 'test1';

    private $dbUser = 'php';
    private $dbPass = 'letmein';

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
        info("Begin connect");
        // Send 'hello' message
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
        // K, S, E, N
        $resp = $this->read();
        $r->set($resp);
        $msgs = $r->chomp();
        var_dump($msgs);


    }

    function getAuthResponse (PgMessage $authMsg) {
        list($authType, $salt) = $authMsg->getData();
        if ($authType != 5) {
            throw new \Exception("Unsupported auth type {$respType}", 9876);
        }
        $cryptPwd2 = $this->pgMd5Encrypt($this->dbPass, $this->dbUser);
        $cryptPwd = $this->pgMd5Encrypt(substr($cryptPwd2, 3), $salt);
        return $cryptPwd;
    }

    private function pgMd5Encrypt ($passwd, $salt) {
        $buff = $passwd . $salt;
        return "md5" . md5($buff);
    }




    function write ($buff) {
        $bw = 0;
        $contentLength = strlen($buff);
        info("Write:\n%s", hexdump($buff));
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
        if ($read[0] === $this->sock) {
            info("Ready to read!");
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
        info("Read:\n%s", hexdump($buff));
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
        if (! $this->name || ! $this->data) {
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
            info("Chomp: extracted type %s, len %d", $msgType, $this->msgLen);
            if (! $this->hasN($this->msgLen)) {
                info("Exit!");
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
        throw new \Exception("Message read method not implemented: " . __METHOD__);
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
        throw new \Exception("Message read method not implemented: " . __METHOD__);
    }

    function readEmptyQueryResponse () {
        throw new \Exception("Message read method not implemented: " . __METHOD__);
    }

    function readErrorResponse () {
        throw new \Exception("Message read method not implemented: " . __METHOD__);
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
        $data[] = substr($this->buff, $this->p, strpos($this->buff, "\x00", $this->p) - $this->p);
        $this->p += strlen($data[0]) + 1;
        $data[] = substr($this->buff, $this->p, strpos($this->buff, "\x00", $this->p) - $this->p);
        $this->p += strlen($data[1]) + 1;

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
        throw new \Exception("Message read method not implemented: " . __METHOD__);
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
    function writeQuery () {}
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



/*$w = new PgWireReader;
$w->doTest();
die;*/


try {
    $dbh = new PgPhp;
    $dbh->connect();
    $dbh->close();
    info("Test Complete\n[%d] %s", $dbh->lastError(), $dbh->strError());
} catch (Exception $e) {
    info("Connect failed:\n%s", $e->getMessage());
}




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