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
        //list($id, $m, $n) = unpack("C/N2", $resp);
        list($id, $m, $n) = array_values(unpack("Cchar/N2nums", $resp));
        info("Auth response from Postgres: %s, %d, %d", chr($id), $m, $n);
        $auth = $this->getAuthResponse($n, $resp);

        // Write Authentication response
        $auth = pack('C', ord("p")) . pack('N', strlen($auth) + 5) . "{$auth}\x00";
        $this->write($auth);
        $this->read();
    }


    function getAuthResponse ($authType, $bin) {
        if ($authType != 5) {
            throw new Exception("Unsupported auth type {$respType}", 9876);
        }
        $salt = substr($bin, -4);
        return md5("{salt}{$this->dbPass}{salt}", true);
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