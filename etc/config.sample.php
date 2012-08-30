<?php

$serverList = array(
	array(
		'file'=>'src/server.php',
		'name'=>'server',
		'flags'=> array(
			'client-port'=>'5555',
			'worker-port'=>'5556',
		)
	),
	array(
		'file'=>'src/http_gateway.php',
		'name'=>'gateway',
		'flags'=> array(
			'client-port'=>'5555'
		)
	)
);

$workerList = array(
	array(
		'file'=>'sample_workers/sleep.php',
		'name'=>'sleep_a',
		'flags'=> array(
			//without this, the inherited DEMO would be used
			'service-name' => 'SLEEP',

			//without this, a crash would leave unfinished jobs in limbo
			'zmqid' => 'sleep_a'
		)
	)
);
