<?php
include_once(dirname(__FILE__).'/zmsg.php');
include_once(dirname(__FILE__).'/clihelper.php');

if (!defined("HEARTBEAT_MAXTRIES")) {
	define("HEARTBEAT_MAXTRIES", 3); //  3-5 is reasonable
}
if (!defined("HEARTBEAT_INTERVAL")) {
	define("HEARTBEAT_INTERVAL", 5); //  secs
}

/**
 * A worker performs a service
 */
class Zmws_Worker_Base {

	public $hbAt         = 0;
	public $hbRetries    = 0;
	public $hbInterval   = 0;

	public $idleCount    = 0;

	public $serviceName  = 'DEMO';
	public $backendPort  = '5556';
	public $frontendPort = '5555';
	public $context      = NULL;
	public $backend      = NULL;
	public $frontend     = NULL;
	public $_identity    = '';

	public $log_level    = 'W';

	public $listBackendSrv   = array('127.0.0.1');
	protected $_socketCurrent = NULL;

	/**
	 * Arguments are optional BC only, use static factory
	 */
	public function __construct($backendPort='', $context=NULL, $backendSocket=NULL) {

		$this->_cliFlags();
		//TODO remove this check at v3.0
		if ($context === NULL) {
			$this->context   = new ZMQContext();
		} else {
			$this->context   = $context;
		}

		if ($backendPort != '') {
			$this->backendPort = $backendPort;
		}

		//TODO remove this check at v3.0
		if ($backendSocket === NULL) {
			$this->backendSocket($this->backendPort);
		} else {
			$this->setBackendSocket($backendSocket);
		}
		$this->backendConnect();

		$this->ready();
	}

	/**
	 * Reads CLI flags and applies overrides.
	 * Creates default context and socket.  
	 */
	public static function factory($backendPort='', $context=NULL, $backendSocket=NULL) {

		if ($context === NULL) {
			$context   = new ZMQContext();
		}

		$x = new static($backendPort, $context, $backendSocket);
		return $x;
	}

	public function _cliFlags() {
		$args = cli_args_parse();
		$this->backendPort   = cli_config_get($args, 'backend-port', $this->backendPort);
		$this->frontendPort  = cli_config_get($args, 'frontend-port', $this->frontendPort);
		$this->serviceName   = cli_config_get($args, 'service-name', $this->serviceName);
		//@DEPRECATED persistant IDs do not reconnect well on failure
		//$this->setIdentity(cli_config_get($args, array('zmqid', 'id'), $this->_identity));
		$this->log_level     = cli_config_get($args, array('log',   'log-level'), 'W');

		$this->listBackendSrv    = explode(',', cli_config_get($args, array('backend-server', 'backend-servers'), implode(',',$this->listBackendSrv)));
	}

	public function ready() {
		$zready = new Zmsg($this->backend);
		$zready->body_set('READY');
		$zready->wrap($this->serviceName);
		$zready->send();
	}

	public function frontendSocket($port=FALSE) {
		if ($port === FALSE) {
			$port = $this->frontendPort;
		}
		if (!current($this->listBackendSrv)) {
			reset($this->listBackendSrv);
		}
		$addrBackend = current($this->listBackendSrv);

		$this->frontend   = NULL;
		$this->frontend   = new ZMQSocket($this->context, ZMQ::SOCKET_DEALER);

		//  Configure socket to not wait at close time
//		$this->frontend->setSockOpt(ZMQ::SOCKOPT_LINGER, 0);
		//connect
		$this->frontend->connect("tcp://".$addrBackend.":".$port);
		$this->log("Worker connected as client @".date('r'), "I");
	}

	public function setBackendSocket($sock) {
		$this->backend   = NULL;
		$this->backend   = $sock;
	}

	/**
	 * TODO: remove in 3.0
	 * @DEPRECATED
	 */
	public function backendSocket($port) {
		$this->setBackendSocket(new ZMQSocket($this->context, ZMQ::SOCKET_DEALER));
	}

	public function backendConnect($port=FALSE) {
		if ($port === FALSE) {
			$port = $this->backendPort;
		}

		if (!current($this->listBackendSrv)) {
			reset($this->listBackendSrv);
		}
		$addrBackend = current($this->listBackendSrv);

		//Reconnects with the same identity are ignored by ZMQ sockets.
		//(unless router handover sockopt is used)
		//We must reset our id each time we want to reconnect.

		// there's no way to retrieve your ZMQ identity if you let the
		// zmq library create one.
		$this->setIdentity('');
		$this->backend->setSockOpt(ZMQ::SOCKOPT_IDENTITY, $this->getIdentity());

		//  Configure socket to not wait at close time
		$this->backend->setSockOpt(ZMQ::SOCKOPT_LINGER, 0);


		//configure heartbeat
        $this->hbAt        = microtime(true) + HEARTBEAT_INTERVAL;
        $this->hbRetries   = HEARTBEAT_MAXTRIES;
        $this->hbInterval  = HEARTBEAT_INTERVAL;

		//connect
		$oldlog = $this->log_level;
		$this->log_level = 'I';

		$this->log("Worker connecting to ".$addrBackend." with port: ".$port, "I");
		$this->backend->connect("tcp://".$addrBackend.":".$port);

		$this->log("Worker startup id:".$this->getIdentity()." @".date('r'), "I");
		$this->log_level = $oldlog;
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
		//$this->log("poll done.", "D");

		if($events > 0) {
			$this->log(sprintf("Got %d read events", count($read)), 'D');
			foreach($read as $socket) {
				$this->_socketCurrent = $socket;
				$zmsg = new Zmsg($socket);
				$zmsg->recv();

				$jobid     = $zmsg->body();

				if ($jobid == 'HEARTBEAT') {
					//any comms with server resets HB retries
					//but we don't want to treat this message 
					// as a job, so continue
					$this->onHeartbeat($zmsg);
					continue;
				}

				$this->onMessage($jobid, $zmsg);
			}
			//communication with server, reset HB retries
			$this->hbRetries   = HEARTBEAT_MAXTRIES;
		} else {
			// This part is linked to the polling timeout of the socket.
			// If we poll more frequently, then it seems like the server
			// is dead.  This check should be based on timers.
			//TODO: use 1 timer that is the total of the entirety
			// of all heartbeat intervals that constitute a down server.
			// Each comm with the server, reset the timer out to the
			// total time again.  (This is instead of counting missed intervals)
			$this->hbRetries--;
			$this->log(sprintf ("hb down (%d).", $this->hbRetries), "D");

			//no communication for HEARTBEAT_INTERVAL seconds
			if ($this->hbRetries == 0) {
				$this->log(sprintf ("Server Died."), "E");
				//try the next server
				next($this->listBackendSrv);
				$this->backendSocket($this->backendPort);
				$this->backendConnect($this->backendPort);
				$this->frontendSocket($this->frontendPort);
				$this->ready();
			}
		}

		if(microtime(true) > $this->hbAt) {
			$this->heartbeat();
			if ($this->idleCount > -1) {
				$this->idleCount++;
			}
		}

		if ($this->idleCount >= 3) {
			$this->idle();
			//don't idle again until we get a job
			$this->idleCount=-1;
		}
		return TRUE;
	}

	public function sendAnswer($answer, $header='COMPLETE') {
		$jobid = $this->_jobidCurrent;
		$zanswer = new Zmsg($this->_socketCurrent);
		try {
			//transform answer into zanswer
			if ($answer->status) {
				$zanswer->body_set($header.": ".$jobid);
				$zanswer->wrap($this->serviceName);
				if ($answer->retval !== NULL) {
					$zanswer->push('PARAM-JSON: '. json_encode($answer->retval));
				}
				$this->log(sprintf("Job %s complete", $jobid), 'D');
			} else {
				$zanswer->body_set("FAIL: ".$jobid);
				$zanswer->wrap($this->serviceName);
				$this->log(sprintf("Job %s failed", $jobid), 'W');
			}
		} catch (Exception $e) {
			$this->log($e->getMessage(), 'E');
			$this->log(print_r($e->getTrace(),1), 'E');
			$zanswer->body_set("FAIL: ".$jobid);
			$zanswer->wrap($this->serviceName);
		}
		$zanswer->send();
		//work may have taken longer than one HB interval,
		//we should start timing new HBs from now
		$this->hbAt = microtime(true) + HEARTBEAT_INTERVAL;
	}

	/**
	 * Called after 3 heartbeat intervals with no work requests.
	 *
	 * This function can be used to close resources like file handles 
	 * and database connections.
	 */
	public function idle() {
	}

	/**
	 * @return Boolean true for successfull job
	 */
	public function work($jobid, $param='') {
		return FALSE;
	}

	public function onHeartbeat($zmsg) {
		$this->hbRetries   = HEARTBEAT_MAXTRIES;
	}

	public function onMessage($body, $zmsg) {
		$client_id = $zmsg->address();
		//this is just to remove the address and null to
		// test for sizes (params)
		$bin_client_id = $zmsg->unwrap();

		$p = (object)array();
		//params
		//id, null, params, body
		if ($zmsg->parts() == 2) {
			$param = $zmsg->unwrap();
			if (strpos($param, 'PARAM') !== FALSE) {
				list($k, $v) = explode(': ', $param, 2);
				if (strpos($k, 'JSON') !== FALSE) {
					$p = json_decode($v);
				}
				if (strpos($k, 'PHP') !== FALSE) {
					$p = unserialize($v);
				}
			}
			unset($v);
		}

		//got a real job, restart idle
		$this->idleCount = 0;

		//remove initial JOB:_
		$jobid = substr($body, 5);
		$this->_jobidCurrent = $jobid;

		//workers can return TRUE/FALSE, or an object
		// with a status and a return value
		try {
			$answer = $this->work($jobid, $p);
		} catch (Exception $e) {
			$answer = new Zmws_Worker_Answer();
			$answer->status = FALSE;
		}
		if (!is_object($answer)) {
			$x = new Zmws_Worker_Answer();
			if ($answer !== TRUE && $answer !== FALSE) {
				//we have something that might be
				// null or any non-boolean value
				// let's treat it as a return value 
				// with a passing status
				$x->retval = $answer;
				$x->status = TRUE;
			} else {
				// we only have T/F return from worker
				// let's treat it as pass/fail status
				$x->status = $answer;
			}
			$answer    = $x;
		}
		$this->sendAnswer($answer);
	}

	/**
	 * Always log E
	 * E is error
	 * W is error
	 * I is info
	 * D is debug
	 */
	public function log($msg, $lvl='W') {
		if ($this->log_level == 'E') {
			if ($lvl == 'W') return;
			if ($lvl == 'I') return;
			if ($lvl == 'D') return;
		}
		if ($this->log_level == 'W') {
			if ($lvl == 'I') return;
			if ($lvl == 'D') return;
		}
		if ($this->log_level == 'I') {
			if ($lvl == 'D') return;
		}
		if ($this->log_level == 'D') {
			//always
		}

		printf("[%s] [%s] - %s\n", date('r'), $lvl, $msg);
	}
}

class Zmws_Worker_Answer {
	public $status = NULL;
	public $retval = NULL;
}
