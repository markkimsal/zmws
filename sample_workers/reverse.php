<?php

chdir( dirname(dirname(__FILE__)) );

include_once ('src/worker_base.php');

class Zmws_Worker_Reverse extends Zmws_Worker_Base {

	public function work($jobid, $param='') {
		return strrev($param->str);
	}
}

$w = new Zmws_Worker_Reverse();
while($w->loop()) {}
