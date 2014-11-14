<?php

$serverList = array(
	array(
		'file'=>'src/server_run.php',
		'name'=>'server',
		'flags'=> array(
			'log-level'=>'E',
			'client-port'=>'5555',
			'worker-port'=>'5556',
			'news-port'=>'5557'
		)
	),
	array(
		'file'=>'src/http_gateway.php',
		'name'=>'gateway',
		'flags'=> array(
			'log-level'=>'I',
			'client-port'=>'5555',
			'http-port'=>'5580'
		)
	)
);

$workerList = array(
	array(
		'file'=>'sample_workers/sleep.php',
		//name manages the log name and pid name
		'name'=>'sleep_a',
		'flags'=> array(
			'log-level'=>'E',
			'frontend-port'=>'5555',
			'backend-port'=>'5556',

			//without this, the inherited DEMO would be used
			'service-name' => 'SLEEP'
		)
	),
	array(
		'file'=>'sample_workers/reverse.php',
		//name manages the log name and pid name
		'name'=>'rev_a',
		'flags'=> array(
			'log-level'=>'E',
			'frontend-port'=>'5555',
			'backend-port'=>'5556',

			//without this, the inherited DEMO would be used
			'service-name' => 'STR-REV'
		)
	)
);
