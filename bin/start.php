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
if (defined('HEARTBEAT_MAXTRIES')) {
	putenv('HEARTBEAT_MAXTRIES='.HEARTBEAT_MAXTRIES);
}
if (defined('HEARTBEAT_INTERVAL')) {
	putenv('HEARTBEAT_INTERVAL='.HEARTBEAT_INTERVAL);
}

if ($serverFlag || (!$serverFlag && !$workerFlag)) {
	foreach ($serverList as $k => $v) {
		restartServer($v['name'], $v['file'], $v['flags']);
	}
}
if ($workerFlag || (!$serverFlag && !$workerFlag)) {
	foreach ($workerList as $k => $v) {
		restartServer($v['name'], $v['file'], $v['flags']);
	}
}


function restartServer($name, $file, $flags) {
	if (!@is_file($file)) {
		echo sprintf("Cannot read %s%s", $file, PHP_EOL);
		return FALSE;
	}

	$pid = trim(@file_get_contents('run/'.$name.'.pid'));

	if ( $pid && pidIsAlive($pid) ) {
		echo sprintf("%s already running (%s)%s", $name, $pid, PHP_EOL);
		return;
	}
	if ($pid) {
		echo sprintf("%s NOT running but stale PID found (%s)%s", $name, $pid, PHP_EOL);
	}

	$output = array();
	$ret   = '';

	$option = '';
	if (isset($flags)) {
		foreach($flags as $_f => $_ff) {
			$option .= ' --'.$_f.'='.$_ff.' ';
		}
	}

	exec('php -d extension=zmq.so '.$file.' '.$option.'  >> logs/'.$name.'.txt 2>&1 & echo $!', $output, $ret);
	$pid = $output[0];
	`echo '$pid' > run/$name.pid`;
	echo $name." started ($pid)\n";
}

/**
 * return true if pid is alive
 */
function pidIsAlive($pid) {
	$ps = array();
	exec('kill -0 '.$pid.' 2>&1; echo $?', $ps);
	//kill with no signal gives error exist status of 1 if no pid is running
	if ( (isset($ps[2]) && $ps[2] == '1') || $ps[0] === '1') {
		return FALSE;
	}
	return TRUE;
}
