<?php
include "zmsg.php";
include "clihelper.php";

$client = new Zmws_Sample_Client();


class Zmws_Sample_Client {

	public $frontend_port = '5555';

	public function __construct() {
		$this->context   = new ZMQContext();
		$this->frontend  = new ZMQSocket($this->context, ZMQ::SOCKET_REQ);
		$this->frontend->connect("tcp://*:".$this->frontend_port);    //  For clients

		$request = new Zmsg($this->frontend);
		$request->body_set('JOB: DEMO');
		$request->send();
	}
}
