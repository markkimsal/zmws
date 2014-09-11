<?php

include_once(dirname(__FILE__).'/zmsg.php');
class Zmws_Client_Base {

	public $context          = NULL;
	public $frontend         = NULL;
	public $_identity        = '';

	protected $listFeSrv         = array();
	protected $listCallbacks     = array();
	protected $listJobs          = array();
	protected $streamReq         = NULL;
	protected $taskReq           = NULL;
	protected $recving           = FALSE;

	public function __construct($host='localhost', $port=5555) {
		$this->addServer($host, $port);

		$this->_cliFlags();
	}

	public function _cliFlags() {
		if( ! @include_once(dirname(__FILE__).'/clihelper.php') ){
			return;
		}
//		$args = cli_args_parse();
//		$this->dryRun   = cli_config_get($args, 'dry-run', $this->dryRun);
	}

	public function addServer($host='localhost', $port=5555) {
		$this->listFeSrv[] = array('host'=>$host, 'port'=>$port);

		if ($this->frontend) {
			$this->frontendConnect();
		}
	}

	public function frontendSocket() {
		$this->context   = new ZMQContext();
		$this->frontend  = new ZMQSocket($this->context, ZMQ::SOCKET_DEALER);
		//  Configure socket to not wait at close time
//		$this->frontend->setSockOpt(ZMQ::SOCKOPT_LINGER, 0);

		//connect
		$this->frontendConnect();
	}

	public function frontendConnect() {
		//dealer sockets round robin send to all connections
		// and fair-queue receive from all connectsion
		foreach ($this->listFeSrv as $_srv) {
			$this->frontend->connect("tcp://".$_srv['host'].":".$_srv['port']);
		}
	}

	public function task($sv, $param, $callback=NULL, $isbg=FALSE) {

		if (!$this->frontend) {
			$this->frontendSocket();
		}
		//$this->listTaskCb[$sv] = $callback;
		$this->listCallbacks[$sv] = $callback;
		@$this->listJobs[$sv]++;
		$this->taskReq = new Zmsg($this->frontend);
		$this->taskReq->isbg = $isbg;
		$this->taskReq->body_set('JOB: SYNC-'.$sv);
		$this->taskReq->push( 'PARAM-JSON: '.json_encode($param) );
		return $this;
	}

	public function bgtask($sv, $param, $callback=NULL) {
		return $this->task($sv, $param, $callback, TRUE);
	}


	public function stream($sv, $param, $callback=NULL) {

		if (!$this->frontend) {
			$this->frontendSocket();
		}
		$this->listCallbacks[$sv] = $callback;
		@$this->listJobs[$sv]++;
		$this->streamReq = new Zmsg($this->frontend);
		$this->streamReq->body_set('JOB: SYNC-'.$sv);
		$this->streamReq->push( 'PARAM-JSON: '.json_encode($param) );
		return $this;
	}

	public function execute() {
		if (is_object($this->streamReq)) {
			$this->streamReq->send();
			$this->streamReq = NULL;
		}
		if (is_object($this->taskReq)) {
			$this->taskReq->send();
			if (@$this->taskReq->isbg)
				return;
			$this->taskReq = NULL;
		}

		if ($this->recving) return;

		$listener = new Zmsg($this->frontend);
		//while ( count($this->listCallbacks) && $reply = $this->streamReq->recv()) {
		while ( $reply = $listener->recv()) {
			$this->recving = TRUE;
			//ID, null, message (for rep/req sockets)
			$spacer = $reply->unwrap();
			$body   = $reply->unwrap();
			@list ($status, $sv, $jobid) = @explode(' ', $body);
			$status = substr($status, 0, -1);
			$jobid  = rtrim($jobid, ']');
			$jobid  = ltrim($jobid, '[');

            $param = $reply->unwrap();
			if (substr($param, 0, 10) == 'PARAM-JSON') {
				$param = json_decode( substr($param, 12) );
			}
			if ( isset($this->listCallbacks[$sv]) ) {
				$callback = $this->listCallbacks[$sv];

				if (is_callable($callback) && !is_array($callback)) {
					$callback($param, $status, $sv, $jobid);
				}
				if (is_array($callback)) {
					$callback[0]->$callback[1]($param, $status, $sv, $jobid);
				}
			}

			if ($status === 'FAIL') {
				$this->listJobs[$sv]--;
			}
			if ($status === 'COMPLETE') {
				$this->listJobs[$sv]--;
			}

			foreach ($this->listJobs as $sv =>$cnt) {
				if ($cnt > 0) continue;
				unset ($this->listJobs[$sv]);
				unset ($this->listCallbacks[$sv]);
			}
			if (!count($this->listJobs)) {
				$this->recving = FALSE;
				break;
			}
		}
	}
}

