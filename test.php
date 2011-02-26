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



$q = new pg\Query('select * from nobber;insert into nobber (fanneh) values (\'shitface\');');

$q = new pg\Query('select fanneh, moofark, monkey_do, biggie from nobber limit 1;');


//$q = new pg\Query('copy nobber from stdin with csv');
//$q->pushCopyData("Hiyah!,69,2011-02-13 19:48:14.591936\n");

try {
    $dbh->runQuery($q);
} catch (Exception $e) {
    info("Query failed:\n%s", $e->getMessage());
}

var_dump($q);


$dbh->close();