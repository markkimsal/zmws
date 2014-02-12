<?php


function cli_args_parse() {
	global $argv;

	$configList = array();
	//remove name of file
	if (isset($argv[0]) && substr($argv[0],0, 1) !== '-')
	array_shift($argv);
	//var_dump($argv);
	foreach ($argv as $idx => $a) {
		$config = $val = NULL;

		if (strpos($a, '=') !== FALSE) {
			list ($config, $val) = explode('=', $a);
			if (strpos($config, '-') === 0)
				$config = substr($config, 1);
			if (strpos($config, '-') === 0)
				$config = substr($config, 1);

			$configList[] = array('c'=>$config, 'v'=>$val);
		} else if (strpos($a, '-') === 0) {
			$config = substr($a, 1);
			if (strpos($config, '-') === 0)
				$config = substr($config, 1);

			//the v key will get overwritten if there is a value
			// after it.  Look at the next else block.
			$configList[] = array('c'=>$config, 'v'=>TRUE);
		} else {
			$last = count($configList) -1;
			$c = $configList[ $last ];
			$c['v'] = $a;
			$configList[ $last ] = $c;
		}
	}
	$configs = array();
	foreach ($configList as $_c) {
		$configs[ $_c['c'] ] = $_c['v'];
	}
	return $configs;
}

function cli_config_get($args, $key, $default) {
	if ( is_array($key) ) {
		$retval = null;
		foreach ($key as $_k) {
			$val = cli_config_get($args, $_k, $default);
			if ($retval !== null && $val == $default) {
			} else {
				$retval = $val;
			}
		}
		return $retval;
	}

	if (isset($args[$key])) {
		return $args[$key];
	}
	return $default;
}
