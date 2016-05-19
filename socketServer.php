<?php



include 'SocketBase.php';


$server = SocketBase::getinstance();


$server->run('103.20.192.253', '4001', 50);