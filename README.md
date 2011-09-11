PgPhp - An implementation of the postgres client / server protocol in pure PHP
==============================================================================

Development status :  Toy.  Works for me, and it supports the protocol
COPY command too, which is nice.

Motivation : I wrote this library as a proof of concept and to learn a
bit more about  different TCP protocols.  The main  (only?) reason for
wanting  to do  this  is to  be able  to  write an  "Event Machine"  /
"Twisted" type of server where you can do asynchronous I.O. to several
different  kinds  of application  endpoint,  e.g. RabbitMQ,  Postgres,
MySql, HTTP, etc. etc.

Future : Currently, and for  the forseeable future I'll be devoting my
"open         source"        time         to         my        [Amqphp
project](https://github.com/BraveSirRobin/amqphp),  but eventually I'd
like to expand  this to be a fast,  fully asyncronous, PHP-implemented
"business process server".