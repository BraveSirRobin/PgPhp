<?php

require_once 'pg.php';






function info () {
    $args = func_get_args();
    $fmt = array_shift($args);
    vprintf("{$fmt}\n", $args);
}



try {
    $dbh = new pg\Connection;
    $dbh->connect();
    info("Connected OK");
} catch (Exception $e) {
    info("Connect failed:\n%s", $e->getMessage());
}



/* Test code - Basic queries  */
$q = new pg\Query('select * from nobber;insert into nobber (fanneh) values (\'shitface\');');

$q = new pg\Query('select * from nobber where fanneh = \'K\';select * from nobber limit 3;update nobber set fanneh=\'Hiyah!!\' where fanneh=\'Hiyah!\';select nextval(\'myseq\')');


//$q = new pg\Query('copy nobber from stdin with csv');
//$q->pushCopyData("Hiyah!,69,2011-02-13 19:48:14.591936\n");

try {
    //    $dbh->debug = true;
    $dbh->runQuery($q);
    echo displayQueryResultSet($q);
} catch (Exception $e) {
    info("Query failed:\n%s", $e->getMessage());
}

return;



/* Test code - TypeDict  */
$td = new pg\TypeDict($dbh);

echo "Type dictionary constructed OK";


$dbh->close();



function displayQueryResultSet (pg\Query $qry) {
    $buff = '';
    foreach ($qry->getResults() as $i => $rPart) {
        if ($rPart instanceof pg\ResultSet) {
            $buff .= "Result Set:\n";
            $rPart->resultStyle = pg\ResultSet::NUMERIC;
            foreach ($rPart as $row) {
                foreach ($row as $colName => $col) {
                    $buff .= "  $colName: $col";
                }
                $buff .= "\n";
            }
        } else if ($rPart instanceof pg\Result) {
            switch ($rPart->getResultType()) {
            case 'CommandComplete':
                $buff .= sprintf("CommandComplete: %s %d\n", $rPart->getCommand(), $rPart->getRowsAffected());
                break;
            case 'ErrorResponse':
                $eData = $rPart->getErrDetail();
                $buff .= sprintf("Error Response: Code %s, Message %s\n",
                                 $eData[pg\ERR_CODE], $eData[pg\ERR_MESSAGE]);
            }
        } else {
            $buff .= "\n[$i]: Unknown result type:\n" . print_r($rPart, true);
        }
    }
    return $buff;
}