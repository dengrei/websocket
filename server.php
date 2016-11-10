<?php

include 'websocket/socketServer.php';

$server = socketServer::getInstance();

$server->bootServer('127.0.0.1',8000,true);
