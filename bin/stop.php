<?php

if (!@include(dirname(dirname(__FILE__)).'/src/clihelper.php')) {
	die("Cannot read src/clihelper.php\n");
}

$flags =  cli_args_parse();

$serverFlag = cli_config_get($flags, 'servers', FALSE);
$workerFlag = cli_config_get($flags, 'workers', FALSE);
$configFile = cli_config_get($flags, 'c', 'etc/config.php');

if (!@include($configFile)) {
	die("Cannot read etc/config.php\n");
}

if ($serverFlag || (!$serverFlag && !$workerFlag)) {
	foreach ($serverList as $k => $v) {
		stopProc($v['name'], $v['file'], $v['flags']);
	}
}
if ($workerFlag || (!$serverFlag && !$workerFlag)) {
	foreach ($workerList as $k => $v) {
		stopProc($v['name'], $v['file'], $v['flags']);
	}
}


function stopProc($name, $file, $flags) {

	$pid = trim(@file_get_contents('run/'.$name.'.pid'));

	if ( !$pid ) {
		echo sprintf("No PID for %s%s", $name, PHP_EOL);
		return;
	}

	$ps = array();
	exec('kill -15 '.$pid.' 2>&1; echo $?', $ps);
	if ($ps[0] == '1') {
		//error!
		echo sprintf("Cannot kill PID %s for process %s%s", $pid, $name, PHP_EOL);
		return FALSE;
	}
	unlink('run/'.$name.'.pid');
	echo sprintf("Stopped %s (%s)%s", $name, $pid, PHP_EOL);
	return TRUE;
}
