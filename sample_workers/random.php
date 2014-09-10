<?php

chdir( dirname(dirname(__FILE__)) );

include_once ('src/worker_base.php');

class Zmws_Worker_Random extends Zmws_Worker_Base {

	public function work($jobid, $param='') {
		for ($x=0; $x<100; $x++) {
			$answer = new Zmws_Worker_Answer();
			$answer->status = TRUE;
			$answer->retval = $this->randString(32);
			$this->sendAnswer($answer, 'CONT');
		}
		return TRUE;
	}

	public function randString($length){
		$charset='ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
		$str = '';
		$count = strlen($charset);
		while ($length--) {
			$str .= $charset[mt_rand(0, $count-1)];
		}
		return $str;
	}
}

$w = new Zmws_Worker_Random();
while($w->loop()) {}
