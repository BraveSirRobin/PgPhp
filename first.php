<?php
const HEXDUMP_BIN = '/usr/bin/hexdump -C';

class PgPhp
{

    private $sock;
    private $host = 'localhost';
    private $port = 5432;

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
        info("Begin connect");
        // Send 'hello' message
        $start = '';
        $start .= pack('N', 196608);
        $start .= "user\x00php\x00";
        $start .= "database\x00test1\x00\x00";

        $start = pack('N', strlen($start) + 4) . $start;
        $this->write($start);

        // Read AuthenticationOk
        $resp = $this->read();
        list($id, $m, $n) = array_values(unpack("Cchar/N2nums", $resp));
        $auth = $this->getAuthResponse($n, $resp);

        // Write Authentication response
        $auth = 'p' . pack('N', strlen($auth) + 5) . "{$auth}\x00";
        $this->write($auth);

        // expect BackendKeyData, ParameterStatus, ErrorResponse or NoticeResponse
        // K, S, E, N
        $resp = $this->read();

        // Strip out AuthenticationOk
        if (substr($resp, 0, 1) != 'R') {
            info("Warning: auth failed!");
        }
        $aOk = unpack("NmsgLen/NauthResp", substr($resp, 1, 8));
        info("AuthOK:\n%s", print_r($aOk, true));
        $resp = substr($resp, 9);

        switch ($tmp = substr($resp, 0, 1)) {
        case 'K':
            $data = unpack("NmsgLen/NprocId/NsecKey");
            info("BackendKeyData:\n%s", print_r($resp, true));
            break;
        case 'S':
            //$data = unpack("NmsgLen", substr($resp, 1));
            $data = $this->chompMessages($resp);
            info("ParameterStatus:\n%s", print_r($data, true));
            break;
        case 'E':
            $data = unpack("NmsgLen", substr($resp, 1, 4));
            info("ErrorResponse (TODO: Unpack details!):\n%s", print_r($data, true));
            break;
        case 'N':
            $data = unpack("NmsgLen", substr($resp, 1, 4));
            info("NoticeResponse (TODO: Unpack details!):\n%s", print_r($data, true));
            break;
        default:
            info("WARNING! Unexpected post-auth response: $tmp");
        }

    }


    function chompMessages ($buff) {
        $p = 0;
        $data = array();
        while (substr($buff, $p, 1) == 'S') {
            $p += 5; // Discard message length
            $item = array();
            $item['name'] = substr($buff, $p, strpos($buff, "\x00", $p) - $p);
            $p += strlen($item['name']) + 1;
            $item['value'] = substr($buff, $p, strpos($buff, "\x00", $p) - $p);
            $p += strlen($item['value']) + 1;
            $data[] = $item;
        }
        return $data;
    }


    function getAuthResponse ($authType, $bin) {
        if ($authType != 5) {
            throw new Exception("Unsupported auth type {$respType}", 9876);
        }
        $salt = substr($bin, -4);
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





class PgWireReader
{
    private $buff = '';
    private $p = 0;
    function __construct ($buff = '') {
        $this->buff = $buff;
    }


    /** Read and return up to $n messages */
    function chomp ($n = 0) {
        $i = $max = 0;
        while ($n == 0 || $i++ < $n) {
            switch (substr($this->buff, $this->p, 1)) {
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
                $ret[] = $this->copyDone();
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
    }

    function chompAuthMessage () {
    }
}


try {
    $dbh = new PgPhp;
    $dbh->connect();
    $dbh->close();
    info("Test Complete\n[%d] %s", $dbh->lastError(), $dbh->strError());
} catch (Exception $e) {
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