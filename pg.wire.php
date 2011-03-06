<?php



/**
 * Implementation classes for Postgres wirelevel protocol, version 3 only.
 */

namespace pg\wire;

const HEXDUMP_BIN = '/usr/bin/hexdump -C';

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
 * Simple container class for a single protocol-level message.
 */
class Message
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





class Reader
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

    function append ($buff) {
        $this->buff .= $buff;
        $this->buffLen += strlen($buff);
    }


    /**
     * Read and return up to $n messages, formatted as Message objects.
     */
    function chomp ($n = 0) {
        $i = $max = 0;
        $ret = array();
        while ($this->hasN(5) && ($n == 0 || $i++ < $n)) {
            $msgType = substr($this->buff, $this->p, 1);
            $tmp = unpack("N", substr($this->buff, $this->p + 1));
            $this->msgLen = array_pop($tmp);

            if (! $this->hasN($this->msgLen)) {
                // Split response message, calling code is now expected to read more
                // data from the Connection and append to *this* reader to complete.
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

    /**
     * Accounts for many different possible auth messages.
     */
    function readAuthentication () {
        $tmp = unpack('N', substr($this->buff, $this->p));
        $authType = reset($tmp);
        $this->p += 4;
        switch ($authType) {
        case 0:
            return new Message('AuthenticationOk', 'R', array($authType));
        case 2:
            return new Message('AuthenticationKerberosV5', 'R', array($authType));
        case 3:
            return new Message('AuthenticationCleartextPassword', 'R', array($authType));
        case 5:
            $salt = substr($this->buff, $this->p, 4);
            $this->p += 4;
            return new Message('AuthenticationMD5Password', 'R', array($authType, $salt));
        case 6:
            return new Message('AuthenticationSCMCredential', 'R', array($authType));
        case 7:
            return new Message('AuthenticationGSS', 'R', array($authType));
        case 8:
            throw new \Exception("Unsupported auth message: AuthenticationGSSContinue", 6745);
        case 9:
            return new Message('AuthenticationSSPI', 'R', array($authType));
        default:
            throw new \Exception("Unknown auth message type: {$authType}", 3674);

        }
    }

    function readBackendKeyData () {
        $tmp = unpack('Ni/Nj', substr($this->buff, $this->p));
        $this->p += 8;
        return new Message('BackendKeyData', 'K', array_values($tmp));
    }

    function readBindComplete () {
        return new Message('BindComplete', '2', array());
    }

    function readCloseComplete () {
        throw new \Exception("Message read method not implemented: " . __METHOD__);
    }

    function readCommandComplete () {
        return new Message('CommandComplete', 'C', array($this->_readString()));
    }

    function readCopyData () {
        $data = array(substr($this->buff, $this->p, $this->msgLen - 4));
        $this->p += $this->msgLen - 4;
        $ret =  new Message('CopyData', 'd', $data);
        return $ret;
    }

    function readCopyDone () {
        return new Message('CopyDone', 'C', array());
    }

    function readCopyInResponse () {
        return $this->copyResponseImpl('CopyInResponse', 'G');
    }

    private function copyResponseImpl ($msgName, $msgCode) {
        $t = unpack('Ca/nb', substr($this->buff, $this->p));
        $data = array_values($t);
        $this->p += 3;
        $cols = array();
        for ($i = 0; $i < $data[1]; $i++) {
            $t = unpack('n', substr($this->buff, $this->p));
            $cols[] = reset($t);
            $this->p += 2;
        }
        $data[] = $cols;
        return new Message($msgName, $msgCode, $data);
    }

    function readCopyOutResponse () {
        return $this->copyResponseImpl('CopyOutResponse', 'H');
    }

    function readDataRow () {
        $data = array();
        $ep = $this->p + $this->msgLen - 5;
        $tmp = unpack('n', substr($this->buff, $this->p));
        $this->p += 2;
        $data[] = reset($tmp);

        while ($this->p < $ep) {
            $row = array();
            $fLen = substr($this->buff, $this->p, 4);
            $this->p += 4;
            if ($fLen === "\xff\xff\xff\xff") {
                // This is a NULL, map to a null
                $row = array(0, NULL);
            } else {
                $tmp = unpack('N', $fLen);
                $row[] = reset($tmp);
                $row[] = substr($this->buff, $this->p, $row[0]);
                $this->p += $row[0];
            }
            $data[] = $row;
        }
        return new Message('RowData', 'D', $data);
    }

    function readEmptyQueryResponse () {
        return new Message('EmptyQueryResponse', 'I', array());
    }

    function readErrorResponse () {
        $data = array();
        $ep = $this->p + $this->msgLen - 5;
        while ($this->p < $ep) {
            $ft = substr($this->buff, $this->p++, 1);
            $row = array($ft, $this->_readString());
            $data[] = $row;
        }
        $tmp = unpack('C', substr($this->buff, $this->p++));
        if (reset($tmp) !== 0) {
            throw new \Exception("Protocol error - missed error response end", 4380);
        }
        return new Message('ErrorResponse', 'E', $data);
    }

    function readFunctionCallResponse () {
        throw new \Exception("Message read method not implemented: " . __METHOD__);
    }

    function readNoData () {
        return new Message('NoData', 'n', array());
    }

    function readNoticeResponse () {
        throw new \Exception("Message read method not implemented: " . __METHOD__);
    }

    function readNotificationResponse () {
        throw new \Exception("Message read method not implemented: " . __METHOD__);
    }

    function readParameterDescription () {
        $data = array();
        $tmp = unpack('n', substr($this->buff, $this->p));
        $this->p += 2;
        $nParams = reset($tmp);
        for ($i = 0; $i < $nParams; $i++) {
            $tmp = unpack('N', substr($this->buff, $this->p));
            $this->p += 4;
            $data[] = reset($tmp);
        }
        return new Message('ParameterDescription', 't', $data);
    }

    function readParameterStatus () {
        $data = array();
        $data[] = $this->_readString();
        $data[] = $this->_readString();
        return new Message('ParameterStatus', 'S', $data);
    }

    function readParseComplete () {
        return new Message('ParseComplete', 'B', array());
    }

    function readPortalSuspended () {
        throw new \Exception("Message read method not implemented: " . __METHOD__);
    }

    function readReadyForQuery () {
        return new Message('ReadyForQuery', 'Z', array(substr($this->buff, $this->p++, 1)));
    }

    function readRowDescription () {
        $data = array();
        $ep = $this->p + $this->msgLen - 4;
        $tmp = unpack('n', substr($this->buff, $this->p));
        $this->p += 2;
        $data[] = $tmp;

        while ($this->p < $ep) {
            $row = array();
            $row[] = $this->_readString();
            $tmp = unpack('Na/nb/Nc/nd/Ne/nf', substr($this->buff, $this->p));
            $row = array_merge($row, array_values($tmp));
            $this->p += 18;
            $data[] = $row;
        }
        return new Message('RowDescription', 'T', $data);
    }

    private function _readString () {
        $r = substr($this->buff, $this->p, strpos($this->buff, "\x00", $this->p) - $this->p);
        $this->p += strlen($r) + 1;
        return $r;
    }
}






class Writer
{
    private $buff;
    function __construct ($buff = '') {
        $this->buff = $buff;
    }

    function get () { return $this->buff; }
    function set ($buff) { $this->buff = $buff; }
    function clear () { $this->buff = ''; }

    // Lots of stuff hard-coded in here!
    function writeBind ($pName, $stName, $params=array()) {
        $buff = "{$pName}\x00{$stName}\x00\x00\x01\x00\x00" . pack('n', count($params));
        // Next, the following pair of fields appear for each parameter
        foreach ($params as $p) {
            $buff .= pack('N', strlen($p)) . $p;
        }
        $buff .= "\x00\x01\x00\x00";
        $this->buff .= 'B' . pack('N', strlen($buff) + 4) . $buff;
    }

    function writeCancelRequest() {
        throw new \Exception("Unimplemented writer method: " . __METHOD__);
    }

    function writeClose () {
        throw new \Exception("Unimplemented writer method: " . __METHOD__);
    }

    function writeCopyData ($data) {
        $this->buff .= 'd' . pack('N', 4 + strlen($data)) . "{$data}";
    }
    function writeCopyDone () {
        $this->buff .= 'c' . pack('N', 4);
    }
    function writeCopyFail ($reason) {
        $this->buff .= 'c' . pack('N', 5 + strlen($reason)) . "{$reason}\x00";
    }
    function writeDescribe ($flag, $name) {
        $this->buff .= "D" . pack('N', 6 + strlen($name)) . "${flag}{$name}\x00";
    }
    function writeExecute ($stName, $maxRows=0) {
        $this->buff .= 'E' . pack('N', strlen($stName) + 9) . "{$stName}\x00" . pack('N', $maxRows);

    }
    function writeFlush () {
        throw new \Exception("Unimplemented writer method: " . __METHOD__);
    }
    function writeFunctionCall () {
        throw new \Exception("Function call protocol message is not implemented, as per the advise here:" .
                             "http://www.postgresql.org/docs/9.0/static/protocol-flow.html#AEN84425", 8961);
    }
    function writeParse ($stName, $q, $bindParams = array()) {
        $buff = "{$stName}\x00{$q}\x00" . pack('n', count($bindParams));
        foreach ($bindParams as $bp) {
            $buff .= pack('N', $bp);
        }
        $this->buff .= 'P' . pack('N', strlen($buff) + 4) . $buff;
    }
    function writePasswordMessage ($msg) {
        $this->buff .= 'p' . pack('N', strlen($msg) + 5) . "{$msg}\x00";
    }
    function writeQuery ($q) {
        $this->buff .= 'Q' . pack('N', strlen($q) + 5) . "{$q}\x00";
    }
    function writeSSLRequest () {
        throw new \Exception("Unimplemented writer method: " . __METHOD__);
    }

    function writeStartupMessage ($user, $database) {
        $start = pack('N', 196608);
        $start .= "user\x00{$user}\x00";
        $start .= "database\x00{$database}\x00\x00";
        $this->buff .= pack('N', strlen($start) + 4) . $start;
    }
    function writeSync () {
        $this->buff .= "S\x00\x00\x00\x04";
    }
    function writeTerminate () {
        $this->buff .= 'X' . pack('N', 4);
    }
}
