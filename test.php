<?php

require_once 'pg.php';
//require_once 'pg.exq.php';

//use pg\exq as pgext;




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

$q = new pg\Query('select * from nobber where fanneh = \'K\';select * from nobber;update nobber set fanneh=\'Hiyah!!\' where fanneh=\'Hiyah!\'');


//$q = new pg\Query('copy copy_test from stdin with csv');
//$q->pushCopyData("1, Fooking Heel!\n2, Fooking Heel!\n3, Fooking Heel!\n4, Fooking Heel!\n5, Fooking Heel!\n6, Fooking Heel!\n7, Fooking Heel!\n8, Fooking Heel!");

try {
    //$dbh->debug = true;
    echo "Run Query\n";
    $dbh->runQuery($q);
} catch (Exception $e) {
    info("An Error response was returned:\n%s", $e->getMessage());
}
echo displayQueryResultSet($q->getResults());

$dbh->close();
return;





/** Test code - extended query protocol - Write command */
//$dbh->debug = true;

$p = new pg\Statement($dbh);

$p->setSql('insert into nobber (moofark, floatie) values ($1, $2);');
$p->setName('st1');

echo "\nParse\n\n";
var_dump($p->parse());

echo "\nExecute\n\n";
echo displayQueryResultSet($p->execute(array('6969', '1.01')));











/** Test code - extended query protocol - Select command  
//$dbh->debug = true;

$p = new pg\Statement($dbh);

$p->setSql('select * from nobber n1');
$p->setName('st3');

echo "\nParse\n\n";
var_dump($p->parse());

echo "\n\nExecute:\n";
echo displayQueryResultSet($p->execute());
*/





$dbh->close();


// Return a string representation of the set of results
// in the given Query object
function displayQueryResultSet ($res) {
    $buff = '';
    foreach ($res as $i => $rPart) {
        if ($rPart instanceof pg\ResultSet) {
            $buff .= sprintf("Result Set: (%d results)\n", count($rPart));
            $rPart->fetchStyle = pg\ResultSet::ASSOC;
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