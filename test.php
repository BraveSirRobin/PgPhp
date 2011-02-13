<?php

require_once 'pg.php';





function info () {
    $args = func_get_args();
    $fmt = array_shift($args);
    vprintf("{$fmt}\n", $args);
}



try {
    $dbh = new pg\PgConnection;
    $dbh->connect();
    //    $dbh->close();
    info("Connected OK");
} catch (Exception $e) {
    info("Connect failed:\n%s", $e->getMessage());
}



$q = new pg\PgQuery('select * from nobber;insert into nobber (fanneh) values (\'shitface\');');

$q = new pg\PgQuery('select NULL as A, NULL as B, NULL as C from nobber;');

try {
    $dbh->debug = true;
    $dbh->runQuery($q);
} catch (Exception $e) {
    info("Query failed:\n%s", $e->getMessage());
}

var_dump($q);