<?php

chdir( dirname(dirname(__FILE__)) );

include_once ('src/worker_base.php');

class Zmws_Worker_Sleep extends Zmws_Worker_Base {


	public function work($jobid, $param='') {
		echo "sleeping for $jobid\n";
		usleep(800000);
		return TRUE;
	}
}

$w = new Zmws_Worker_Sleep();
while($w->loop()) {}
