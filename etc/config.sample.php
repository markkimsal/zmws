<?php

$serverList = array(
	array(
		'file'=>'server.php',
		'name'=>'server',
		'flags'=> array(
			'client-port'=>'5555',
			'worker-port'=>'5556',
		)
	)
);

$workerList = array(
	array(
		'file'=>'sleep.php',
		'name'=>'sleep_a',
		'flags'=> array(
		)
	)
);
