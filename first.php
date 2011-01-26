<?php

$dbh = new PDO('pgsql:host=localhost;dbname=test1;user=php;password=letmein');
$dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$st = $dbh->prepare('insert into nobber (fanneh) values (?)');
$st->execute(array('Hiyah!'));