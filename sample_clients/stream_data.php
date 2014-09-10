<?php
chdir( dirname(dirname(__FILE__)) );

include_once ('src/client_base.php');


class Zmws_Sample_Client_Stream extends Zmws_Client_Base {

	public function start() {
		$this->stream('RANDSTREAM',
			array('charset'=>'abcde1234567890'),
			array($this, 'callback')
		)->execute();

		echo "sleeping for 3 and running again ....\n";
		sleep(3);

		$this->stream('RANDSTREAM',
			array('charset'=>'abcde1234567890'),
			array($this, 'callback')
		)->execute();
	}

	public function callback($data, $status, $sv, $jobid) {
		printf ("Got %s from %s [%s] with data ? %s\n", $status, $sv, $jobid, empty($data)?'NO': $data);
		//COMPLETE streams have no data
		if ($status == 'CONT')
		$rev  = $this->reverseString($data);
	}

	public function callbackRev($data, $status, $sv, $jobid) {
		static $c=0;
		$c++;
		printf ("Got %s from %s [%s] with data ? %s\n", $status, $sv, $jobid, empty($data)?'NO': $data);
	}

	public function reverseString($str) {
		$this->task('REV',
			array('str'=>$str),
			array($this, 'callbackRev')
		)->execute();
	}
}

$client = new Zmws_Sample_Client_Stream();
$client->start();
