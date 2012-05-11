<?php
include_once('src/zmsg.php');
define ("HEARTBEAT_INTERVAL", 3); //sec
define ("HEARTBEAT_RETRIES",  5); //server died after this many HB

/**
 * A worker performs a service
 */
class Zmws_Worker_Base {

	public $hbAt         = 0;
	public $hbRetries    = 0;
	public $hbInterval   = 0;

	public $serviceName = 'DEMO';
	public $backendPort = '5556';
	public $context     = NULL;
	public $backend     = NULL;
	public $_identity   = '';

	public function __construct($backendPort='') {

		$this->context   = new ZMQContext();
		if ($backendPort != '') {
			$this->backendPort = $backendPort;
		}
		$this->backendSocket($this->backendPort);

		$this->ready();
	}

	public function ready() {
		$zready = new Zmsg($this->backend);
		$zready->body_set('READY');
		$zready->wrap($this->serviceName);
		$zready->send();
	}

	public function backendSocket($port) {
		$this->backend   = new ZMQSocket($this->context, ZMQ::SOCKET_DEALER);
		$this->backend->connect("tcp://*:".$port);    //  For workers

		$this->backend->setSockOpt(ZMQ::SOCKOPT_IDENTITY, $this->getIdentity());

		//  Configure socket to not wait at close time
		$this->backend->setSockOpt(ZMQ::SOCKOPT_LINGER, 0);

		//configure heartbeat
        $this->hbAt        = microtime(true) + HEARTBEAT_INTERVAL;
        $this->hbRetries   = HEARTBEAT_RETRIES;
        $this->hbInterval  = HEARTBEAT_INTERVAL;
	}

	public function heartbeat() {
		$this->hbAt = microtime(true) + HEARTBEAT_INTERVAL;
		//printf ("D: (%s) worker heartbeat%s", $this->getIdentity(), PHP_EOL);
		$this->backend->send("HEARTBEAT");
	}

	public function setIdentity($id) { 
		$this->_identity = $id;
		return $this;
	} 

	public function getIdentity() { 
		//  Set random identity to make tracing easier
		if ($this->_identity == '') { 
			$identity =  sprintf ("%04X-%04X", rand(0, 0x10000), rand(0, 0x10000));
			$this->setIdentity( $identity );
		} 
		return $this->_identity;
	} 


	public function loop() {
		$read = $write = array();
		$poll = new ZMQPoll();
		$poll->add($this->backend, ZMQ::POLL_IN);

		$events = $poll->poll($read, $write, $this->hbInterval * 1000 );

	printf ("D: poll done.%s", PHP_EOL);
		if($events > 0) {
			foreach($read as $socket) {
				$zmsg = new Zmsg($socket);
				$zmsg->recv();

				$jobid = $zmsg->body();

				if ($jobid == 'HEARTBEAT') {
					//any comms with server resets HB retries
					//but we don't want to treat this message 
					// as a job, so continue
					continue;
				}
				$jobid = substr($jobid, 5);

				if ($this->work($jobid)) {
					$zanswer = new Zmsg($socket);
					$zanswer->body_set("COMPLETE: ".$jobid);
					$zanswer->wrap($this->serviceName);
					$zanswer->send();

				} else {
					$zanswer = new Zmsg($socket);
					$zanswer->body_set("FAIL: ".$jobid);
					$zanswer->wrap($this->serviceName);
					$zanswer->send();
				}
			}
			//communication with server, reset HB retries
        	$this->hbRetries   = HEARTBEAT_RETRIES;
			printf ("hb up (%d).%s", $this->hbRetries, PHP_EOL);
		} else {
			$this->hbRetries--;
			//no communication for HEARTBEAT_INTERVAL seconds
			if ($this->hbRetries == 0) {
				printf ("Server Died.%s", PHP_EOL);
				$this->backendSocket($this->backendPort);
				$this->ready();
			} else {
				printf ("hb down (%d).%s", $this->hbRetries, PHP_EOL);
			}
		}

		if(microtime(true) > $this->hbAt) {
			$this->heartbeat();

		}

		return TRUE;
	}

	/**
	 * @return Boolean true for successfull job
	 */
	public function work($jobid, $param='') {
		return FALSE;
	}
}
