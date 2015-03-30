<?php
/**
 * ZeroMQ Work Server
 *
 * This is the main server file.
 * It listens only to ZeroMQ network traffic on the client_port
 * (sometimes called "frontend") and delivers them to the worker_port 
 * (sometimes called "backend").  Any HTTP traffic is handled by the 
 * http_gateway.php file.
 *
 *
 * Copyright 2012 Mark Kimsal
 *
 * Permission is hereby granted, free of charge, to any person obtaining a
 * copy of this software and associated documentation files (the "Software"),
 * to deal in the Software without restriction, including without limitation 
 * the rights to use, copy, modify, merge, publish, distribute, sublicense,
 * and/or sell copies of the Software, and to permit persons to whom the Software
 * is furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
Sat Mar 28 22:04:18 EDT 2015
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL
 * THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING
 * FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS
 * IN THE SOFTWARE.
 */

include "zmsg.php";
include "clihelper.php";

if (!defined("HEARTBEAT_MAXTRIES")) {
	define("HEARTBEAT_MAXTRIES", 3); //  3-5 is reasonable
}
if (!defined("HEARTBEAT_INTERVAL")) {
	define("HEARTBEAT_INTERVAL", 5); //  secs
}

class Zmws_Server {

	public $context;
	public $frontend;
	public $backend;
	public $news;

	public $workerList       = array();
	public $listOfJobs       = array();

	public $queueJobList     = array();
	public $activeJobList    = array();
	public $activeWorkerList = array();
	public $histJobList      = array();

	public $hb_at            = 0;
	public $log_level        = 'W';
	public $running          = FALSE;

	public function __construct($client_port, $worker_port, $news_port) {
		//  Prepare our context and sockets
		$this->context   = new ZMQContext();
		$this->frontendSocket($client_port);
		$this->backendSocket($worker_port);
		$this->newsSocket($news_port);
	}

	public static function gen_id() {
		return sprintf ("%04X-%04X", rand(0, 0x10000), rand(0, 0x10000));
	}

	public function frontendSocket($port) {
		$this->frontend  = new ZMQSocket($this->context, ZMQ::SOCKET_ROUTER);
		$this->frontend->bind("tcp://*:".$port);    //  For clients
	}

	public function backendSocket($port) {
		$this->backend   = new ZMQSocket($this->context, ZMQ::SOCKET_ROUTER);
		$this->backend->bind("tcp://*:".$port);    //  For workers
	}

	/**
	 * Create a PUB socket to announce completed jobs
	 */
	public function newsSocket($port) {
		$this->news      = new ZMQSocket($this->context, ZMQ::SOCKET_PUB);
		$this->news->bind("tcp://*:".$port);    //  For info
	}

	public function getWorkerList() {
		return $this->workerList;
	}

	/**
	 * return a copy of workerList that only has idle workers
	 */
	public function getIdleWorkerList() {
		$listIdle = array();
		foreach ($this->workerList as $_svc => $_worker) {
			if (!array_key_exists($_svc, $listIdle)) {
				$listIdle[$_svc] = array();
			}

			foreach ($_worker as $_wid => $_wstruct) {
				if (in_array($_wid, $this->activeWorkerList)) {
					continue;
				}
				$listIdle[$_svc][$_wid] =  $_wstruct;
			}
		}
		return $listIdle;
	}


	/**
	 * Find an available worker for a particular service.
	 */
	public function selectWorker($svc, $clear=true) {
		reset($this->workerList);
		if (!isset($this->workerList[$svc])) {
			return false;
		}
		if (!count($this->workerList[$svc])) {
			return false;
		}

		do  {
			$wid = key($this->workerList[$svc]);
			if (in_array($wid, $this->activeWorkerList)) {
				next($this->workerList[$svc]);
			} else {
				break;
			}
		} while ($wid);

		return $wid;
	}

	/**
	 * put any active jobs with the given worker id back in queueJobList
	 *
	 * Only one job is assigned per worker, we can return after we found
	 * one job.
	 *
	 * startJobs() adds these fields to a job definition
	 *  $_j['worker'] = $wid;
	 *  $_j['startedat'] = microtime(true);
	 */
	public function restartOrphanedJobs($wid) {

		foreach($this->activeJobList as $jid => $_j) {
			if ($_j['worker'] != $wid) {
				continue;
			}
			unset($_j['worker']);
			unset($_j['startedat']);
			$this->queueJobList[$jid] = $_j;
			unset ($this->activeJobList[$jid]);
			return;
		}
	}

	/**
	 * Look for & kill expired workers
	 */
	public function purgeStaleWorkers() {

		$mt = microtime(true);
		foreach($this->workerList as $serv => $jlist) {
			foreach($jlist as $id => $jdef) {
				if(microtime(true) > $jdef['hb']) {
					$this->log ( sprintf("purging stale worker %s, havent seen HB in %d seconds.",  $id, (HEARTBEAT_INTERVAL * HEARTBEAT_MAXTRIES) ), 'W');
					$this->deleteWorker($id, $serv);
					$this->restartOrphanedJobs($id);
				}
			}
		}
	}

	/**
	 * set $this->running = FALSE to exit
	 */
	public function run() {
		$this->running = TRUE;
		while ($this->running) {
			$this->poll();
			$this->checkTimers();
			$this->startJobs();
		}
		$zserver->cleanup();
	}

	/**
	 * Poll frontend and backend sockets for 2000ms
	 * Frontend socket messages call handleFront
	 * Backend socket messages call handleBack
	 */
	public function poll() {
		$read = $write = array();
		$poll = new ZMQPoll();
		$poll->add($this->backend, ZMQ::POLL_IN);
		$poll->add($this->frontend, ZMQ::POLL_IN);

		//$events = $poll->poll($read, $write, HEARTBEAT_INTERVAL * 5000 );
		$events = $poll->poll($read, $write, 2000 );

		if($events > 0) {

			foreach($read as $socket) {
				$zmsg = new Zmsg($socket);
				$zmsg->recv();

				// BACKEND
				//  Handle worker activity on backend
				if($socket === $this->backend) {
					$this->log( sprintf("Backend IN %s", $zmsg), 'D' );
					$this->handleBack($zmsg);
				} else {
					// FRONTEND
					//  Now get next client request, route to next worker
					$this->log( sprintf("Frontend IN %s", $zmsg), 'D' );
					$client_id     = $zmsg->address();
					$zmsgReply = $this->handleFront($zmsg, $client_id);
					if (is_object($zmsgReply)) {
						$zmsgReply->wrap( null );
						$zmsgReply->wrap( $client_id );
						$zmsgReply->send();
					}
				}
			}
		}
	}

	public function checkTimers() {
		if(microtime(true) > $this->hb_at) {
			$this->onSendHeartbeat();
			$this->hb_at = microtime(true) + HEARTBEAT_INTERVAL;
			$this->purgeStaleWorkers();
		}
	}

	public function onSendHeartbeat() {
		$list = $this->getIdleWorkerList();
		//@printf (" %0.4f %0.4f %s", microtime(true), $this->hb_at, PHP_EOL);
		foreach($list as $serv => $jlist) {
			foreach($jlist as $id => $jdef) {

				if($id[0] == '@' && strlen($id) == 33) {
					$this->log(sprintf ("id is address to  %s (%s)", $id, $jdef['service']) , 'D' );
				}

				$this->log(sprintf ("sending HB to  %s (%s)", $id, $jdef['service']) , 'D' );
				$zmsg = new Zmsg($this->backend);
				$zmsg->body_set("HEARTBEAT");
				$zmsg->wrap($id, NULL);
				$zmsg->send();
			}
		}
	}

	/**
	 * Send jobs to backend
	 */
	public function startJobs() {

		if (!count($this->queueJobList)) return;
		reset($this->queueJobList);

		do {
			$_j = current($this->queueJobList);
			$jid = key($this->queueJobList);

			$wid = $this->selectWorker($_j['service']);
			//$wid  = $list->get_worker_for_job($_j['service']);
			if (!$wid) {
				$this->log ( sprintf("No worker for job: %s", $_j['service'] ), 'D');
				continue;
			}

			$this->log( sprintf("Starting job %s, [%s%s%s] to %s, for client: @%s  Queue size %d",  $_j['service'], ($_j['sync'])? 'SYNC ':'', (strlen($_j['param']))?'PARAM ':'', $jid, $wid, bin2hex($_j['clientid']), count($this->queueJobList)), 'I');
			$zmsg = new Zmsg($this->backend);
			$zmsg->body_set('JOB: '.$jid);
			$zmsg->push( $_j['param'] );

			$zmsg->push(0x01);
			$zmsg->push("MDPC02");
			$zmsg->push( null );
			$zmsg->push( $wid );

			$this->log( sprintf("Backend OUT %s", $zmsg), 'E' );

			$zmsg->send();

			$_j['worker'] = $wid;
			$_j['startedat'] = microtime(true);
			$this->activeJobList[$jid] = $_j;
			$this->activeWorkerList[]  = $wid;
			//remove from array
			unset($this->queueJobList[$jid]);
//			array_shift($this->queueJobList);
			//
			//started a job? let's yield to the network
//			return;

		} while (next($this->queueJobList));
		//$this->log ( sprintf("No jobs started, queue size is : %d",  count($this->queueJobList) ), 'W');
	}


	/**
	 * Must not to reply to $zmsg because workers are REP
	 */
	public function handleBack($zmsg) {

		$body          = $zmsg->body();
		//remove binary ID and blank frame from ROUTER messages
		$client_id_bin = $zmsg->unwrap();
		$protocol      = $zmsg->unwrap();
		$msg_type      = $zmsg->unwrap();
		$service       = $zmsg->unwrap();
		$param         = $zmsg->unwrap();

		$identity      = $client_id_bin;

		if($body == 'HEARTBEAT') {
			$this->log( sprintf("HB from %s", $identity), 'D' );
			$this->refreshWorker($identity);
			return;
		}

		$retval = NULL;
		//param is the last unwrap, could be COMPLETE: JOB [JOB-ID]
		// could be second to last frame, PARAM-JSON: {key: "val"}
		if( substr($param, 0, 5) == "PARAM" ) {
			$retval = $param;
		}
		if( substr($body, 0, 5) == "READY") {
			$this->log(sprintf ("ready %s job:%s", $identity, $service), 'I');
			$this->deleteWorker($identity, $service);
			$this->appendWorker($identity, $service);
		}

		//protocol must ignore workers it thought were dead
		if (!$this->isWorkerAlive($identity)) {
			$this->log( sprintf("message from dead worker %s", $identity), 'W' );
			return;
		}

		if( strtoupper(substr($body, 0, 4) == 'FAIL') ) {
			$jobid = substr($body, 6);
			$this->handleFinishJob($zmsg, $jobid, $identity, $service, FALSE, $retval);
		}
		if( strtoupper(substr($body, 0, 8) == 'COMPLETE') ) {
			$jobid = substr($body, 10);
			$this->handleFinishJob($zmsg, $jobid, $identity, $service, TRUE, $retval);
		}
		if( strtoupper(substr($body, 0, 4) == 'CONT') ) {
			$jobid = substr($body, 6);
			$this->handleJobAnswer($zmsg, $jobid, $identity, $service, TRUE, $retval);
		}
		//protocol says "any communication other than DISCONNECT is to be taken as a heartbeat"
		$this->refreshWorker($identity);
	}

	/**
	 * Required to send a reply to the front-end since clients are REQ
	 */
	public function handleFront($zmsg, $client_id) {
		//remove binary ID and blank frame from ROUTER messages
		$client_id_bin = $zmsg->unwrap();
		$protocol      = $zmsg->unwrap();
		$msg_type      = $zmsg->unwrap();
		$param         = $zmsg->unwrap();
		$job           = $zmsg->unwrap();
		//param is optional
		if ($job == '') {
			$job = $param;
		}

		if (intval($msg_type) != 0x01) {
			$this->log( sprintf("Unknown message type [%s] from client  \"%s\"", $msg_type, $client_id), 'E');
			$zmsgReply = new Zmsg($this->frontend);
			$zmsgReply->body_set("FAIL: ".$job);
			$zmsgReply->wrap($job);
			$zmsgReply->wrap(0x03);
			$zmsgReply->wrap("MDPC02");
			return $zmsgReply;
		}

		if ($protocol != 'MDPC02') {
			$this->log( sprintf("Incorrect Protocol [%s] from client  \"%s\"", $msg_type, $client_id), 'E');
			$zmsgReply = new Zmsg($this->frontend);
			$zmsgReply->body_set("FAIL: ".$job);
			$zmsgReply->wrap($job);
			$zmsgReply->wrap(0x03);
			$zmsgReply->wrap("MDPC02");
			return $zmsgReply;
		}

		if ( substr($job, 0, 5) ==  'JOB: ') {
			$job = substr($job, 5);
		}

		$sync = FALSE;
		if (substr($job, 0, 5) === 'SYNC-') {
			$sync = TRUE;
			$job = substr($job, 5);
			$this->log( sprintf("Synchronous job request for  \"%s\"", $job), 'D');
		}

		if ($job == 'SERVER-JOBS') {
			$this->handleServerJobs($zmsg);
			return;
		}
		if ($job == 'SERVER-ACTIVE') {
			$this->handleServerActive($zmsg);
			return;
		}
		if ($job == 'SERVER-HIST') {
			$this->handleServerHistory($zmsg);
			return;
		}
		if ($job == 'SERVER-WORKERS') {
			$this->handleServerWorkers($zmsg);
			return;
		}

		if (!$this->haveSeenJob($job)) {
			$this->log( sprintf("No worker can handle job \"%s\"", $job), 'E');
			$zmsgReply = new Zmsg($this->frontend);
			$zmsgReply->body_set("FAIL: ".$job);
			$zmsgReply->wrap($job);
			$zmsgReply->wrap(0x03);
			$zmsgReply->wrap("MDPC02");
			return $zmsgReply;
		}

		$jobid = $this->handleWorkRequest($job, $client_id, $param, $sync);
		if (!$sync) {
			$zmsgReply = new Zmsg($this->frontend);
			$zmsgReply->body_set("JOB: ".$jobid. " ".$job);
			$zmsgReply->wrap($job);
			$zmsgReply->wrap(0x03);
			$zmsgReply->wrap("MDPC02");
			return $zmsgReply;
		}
	}


	/**
	 * Remove job from activeJobList
	 * Add job to stat history
	 * alert notify socket of job completion
	 */
	public function handleFinishJob($zmsg, $jobid, $identity, $service, $success=true, $retval=NULL) {

		$_job = $this->activeJobList[$jobid];

		$answer = 'COMPLETE';
		if ($success)
			$this->log( sprintf ("JOB COMPLETE: %s %s, client id: @%s - took %0.4f sec", $_job['service'], $jobid, bin2hex($_job['clientid']), (microtime(true) - $_job['startedat'])), 'I' );
		else {
			$this->log( sprintf ("JOB FAILED: %s %s, client id: @%s - took %0.4f sec", $_job['service'], $jobid, bin2hex($_job['clientid']), (microtime(true) - $_job['startedat'])), 'I' );
			$answer = 'FAIL';
		}

		$_job['completedat'] = microtime(true);
		unset($this->activeJobList[$jobid]);
		$this->histJobList[] = $_job;

		if (count($this->histJobList) > 100) {
			array_shift($this->histJobList);
		}

		//if sync, send reply now
		if ($_job['sync'] == TRUE) {
			$zmsgReply = new Zmsg($this->frontend);
			$zmsgReply->body_set($retval);
			$zmsgReply->wrap($answer.": " .$_job['service']. " [".$jobid."]" );
			$zmsgReply->wrap($_job['service']);
			$zmsgReply->wrap(0x03);
			$zmsgReply->wrap("MDPC02");

			//wrap with address
			$zmsgReply->wrap( null );
			$zmsgReply->wrap( $_job['clientid'] );
			$zmsgReply->send();
		}

//			$zmsg->set_socket($this->news)->send();
		$this->appendWorker($identity, $service);

		//remove from active worker list
		if (($key = array_search($identity, $this->activeWorkerList)) !== FALSE) {
			unset($this->activeWorkerList[$key]);
		}
	}


	public function handleJobAnswer($zmsg, $jobid, $identity, $service, $success=true, $retval=NULL) {
		$_job = $this->activeJobList[$jobid];

		$this->log( sprintf ("JOB ANSWER: %s %s, client id: @%s - took %0.4f sec", $_job['service'], $jobid, bin2hex($_job['clientid']), (microtime(true) - $_job['startedat'])), 'I' );
		//if sync, send reply now
		if ($_job['sync'] == TRUE) {
			$zmsgReply = new Zmsg($this->frontend);
			$zmsgReply->body_set($retval);
			$zmsgReply->wrap("CONT: " .$_job['service']. " [".$jobid."]" );
			$zmsgReply->wrap(null);
			$zmsgReply->wrap( $_job['clientid'] );
			$zmsgReply->send();
		}
	}

	/**
	 * Record the request for a new job
	 */
	public function handleWorkRequest($service, $clientId, $param, $sync=FALSE, $id=null) {
		if (!$clientId) {
			return false;
		}

		if (!$id) {
			$id = Zmws_Server::gen_id();
		}
		$this->queueJobList[$id] = array('service'=>$service, 'reqtime'=>time(), 'param'=>$param, 'clientid'=>$clientId, 'jobid'=>$id, 'sync'=>$sync);
		$this->log( sprintf ("Request for job %s (sync:".$sync."). queue size: %d", $service, count($this->queueJobList)), 'D' );

		return $id;
	}

	public function handleServerJobs($zmsg) {
		$client_id = $zmsg->address();


		$zmsgReply = new Zmsg($this->frontend);
		$zmsgReply->body_set( json_encode(array_values($this->queueJobList)) );
		$zmsgReply->wrap( null );
		$zmsgReply->wrap( $client_id );
		$zmsgReply->send();

//		$zmsg->body_set( json_encode(array_values($this->queueJobList)) );
//		$zmsg->set_socket($this->frontend)->send();
	}

	public function handleServerActive($zmsg) {
		$zmsg->body_set( json_encode(array_values($this->activeJobList)) );
		$zmsg->set_socket($this->frontend)->send();
	}

	public function handleServerHistory($zmsg) {
		$zmsg->body_set( json_encode($this->histJobList) );
		$zmsg->set_socket($this->frontend)->send();
	}

	public function handleServerWorkers($zmsg) {
		$workers = array();
		$l = $this->getWorkerList();
		foreach ($l as $_sublist) {
			$workers = $workers + $_sublist;
		}
		$zmsg->body_set( json_encode(array_values( $workers )) );
		$zmsg->set_socket($this->frontend)->send();
	}


	/**  
	 * Insert worker at end of list, reset expiry
	 * Worker must not already be in list
	 */
	public function appendWorker($id, $svc) {
		$this->seeJob($svc);

		if(isset($this->workerList[$svc][$id])) {
			//this is normal because we're not going to remove
			// workers that have jobs, they better start to respond in 
			// 15 seconds ;)
			$this->workerList[$svc][$id]['hb'] = microtime(true) + HEARTBEAT_INTERVAL * HEARTBEAT_MAXTRIES;
		} else {
			//$this->log( sprintf ("Append worker %s [%s]", $id, $svc) , 'D');
			$this->workerList[$svc][$id] = array(
				'hb'=>microtime(true) + HEARTBEAT_INTERVAL * HEARTBEAT_MAXTRIES,
				'service'=>$svc, 
				'workerid'=>$id 
			);
		}
	}

	public function isWorkerAlive($wid) {
		foreach ($this->workerList as $_svc => $_list) {
			foreach ($_list as $_wid => $_worker) {
				if ($wid == $_wid) return TRUE;
			}
		}
		return FALSE;
	}

	public function seeJob($service) {
		if (!in_array($service, $this->listOfJobs)) {
			$this->listOfJobs[] = $service;
		}
	}

	/**
	 * foo
	 */
	public function haveSeenJob($service) {
		if (!in_array($service, $this->listOfJobs)) {
			return FALSE;
		}
		return TRUE;
	}

	/**
	 * Remove worker from list, if present
	 */
	public function deleteWorker($id, $job) {
		unset($this->workerList[$job][$id]);
	}


	/**
	 * Reset worker expiry, worker must be present
	 */
	public function refreshWorker($id) {

		foreach ($this->workerList as $job => $wlist) {
			foreach ($wlist as $_wid => $_w) {
				if ($id == $_wid) {
					$this->workerList[$job][$id]['hb'] = microtime(true) + HEARTBEAT_INTERVAL * HEARTBEAT_MAXTRIES;
					return;
				}
			}
		}

		$this->log(sprintf ("worker @%s sent HB, but not in list.", bin2hex($id)) , 'E' );
	}

	public function cleanup() {
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
