<?php

include_once(__DIR__.'/server.php');

$args = cli_args_parse();

$client_port   = cli_config_get($args, array('cport', 'client-port'), '5555');
$worker_port   = cli_config_get($args, array('wport', 'worker-port'), '5556');
$news_port     = cli_config_get($args, array('nport', 'news-port'), '5557');
$log_level     = cli_config_get($args, array('log',   'log-level'), 'W');

$zserver = new Zmws_Server($client_port, $worker_port, $news_port);

$zserver->log_level = 'I';
$zserver->log("Server startup @".date('r'), 'I');

$zserver->log_level = $log_level;


$zserver->run();

