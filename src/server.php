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

define("HEARTBEAT_MAXTRIES", 3); //  3-5 is reasonable
define("HEARTBEAT_INTERVAL", 5); //  secs

$args = cli_args_parse();

$client_port   = cli_config_get($args, array('cport', 'client-port'), '5555');
$worker_port   = cli_config_get($args, array('wport', 'worker-port'), '5556');
$news_port     = cli_config_get($args, array('nport', 'news-port'), '5557');
$log_level     = cli_config_get($args, array('log',   'log-level'), 'W');



$zserver = new Zmws_Server($client_port, $worker_port, $news_port);

$zserver->log_level = 'I';
$zserver->log("Server startup @".date('r'), 'I');

$zserver->log_level = $log_level;


while($zserver->poll() ) {
	//poll() returns false when it wants to quit
}

//cleanup sockets
$zserver->cleanup();

class Zmws_Server {

	public $context;
	public $frontend;
	public $backend;
	public $news;

	public $workerList     = array();
	public $listOfJobs     = array();

	public $queueJobList   = array();
	public $activeJobList  = array();
	public $histJobList    = array();

	public $hb_at          = 0;
	public $log_level      = 'W';

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
	 * Find and remove a worker for a particular service.
	 */
	public function selectWorker($svc, $clear=true) {
		reset($this->workerList);
		if (!isset($this->workerList[$svc])) {
			return false;
		}
		if (!count($this->workerList[$svc])) {
			return false;
		}

		$id = key($this->workerList[$svc]);

		if ($clear) {
			$this->log(sprintf ("dequeing worker %s", $id) , 'I' );
			unset($this->workerList[$svc][$id]);
		}
		return $id;
	}

	/*
	 * Look for & kill expired workers
	 */
	public function purgeStaleWorkers() {

		$mt = microtime(true);
		foreach($this->workerList as $serv => $jlist) {
			foreach($jlist as $id => $jdef) {
				if(microtime(true) > $jdef['hb']) {
					$this->log ( sprintf("purging stale worker %s, havent seen HB in %d seconds.",  $id, (HEARTBEAT_INTERVAL * HEARTBEAT_MAXTRIES) ), 'W');
					unset($this->workerList[$serv][$id]);
				}
			}
		}
	}

	public function poll() {
		$read = $write = array();
		$poll = new ZMQPoll();
		$poll->add($this->backend, ZMQ::POLL_IN);

//		$list = $this->getWorkerList();
		//  Poll frontend only if we have available workers
//		if( count($list)) {
//			printf ("D: inside poll %s", PHP_EOL);
//			$poll->add($this->frontend, ZMQ::POLL_IN);
//		}

		//we can't debug without workers if the above
		// "optimization" is in place.
		$poll->add($this->frontend, ZMQ::POLL_IN);
		$events = $poll->poll($read, $write, HEARTBEAT_INTERVAL * 1000 );

		if($events > 0) {

			foreach($read as $socket) {
				$zmsg = new Zmsg($socket);
				$zmsg->recv();

				// BACKEND
				//  Handle worker activity on backend
				if($socket === $this->backend) {
					$this->handleBack($zmsg);
				} else {
					// FRONTEND
					//  Now get next client request, route to next worker
					$this->handleFront($zmsg);
				}
			}
		}

		if(microtime(true) > $this->hb_at) {
			$list = $this->getWorkerList();
			//@printf (" %0.4f %0.4f %s", microtime(true), $this->hb_at, PHP_EOL);
			foreach($list as $serv => $jlist) {
				foreach($jlist as $id => $jdef) {

					$this->log(sprintf ("sending HB to  %s (%s)", $id, $jdef['service']) , 'D' );
					$zmsg = new Zmsg($this->backend);
					$zmsg->body_set("HEARTBEAT");
					$zmsg->wrap($id, NULL);
					$zmsg->send();
				}
			}
			$this->hb_at = microtime(true) + HEARTBEAT_INTERVAL;

			$this->purgeStaleWorkers();
		}
		$this->startJobs();

		return TRUE;
	}

	/**
	 * Send jobs to backend
	 */
	public function startJobs() {

		if (!count($this->queueJobList)) return;
//		printf ("D: * Start Jobs %d%s", count($this->queueJobList), PHP_EOL);
		reset($this->queueJobList);
		do {
			list ($jid, $_j) = each($this->queueJobList);

			$wid = $this->selectWorker($_j['service']);
			//$wid  = $list->get_worker_for_job($_j['service']);
			if (!$wid) {
	//			echo "no worker for job \n";
				continue;
			}
			$zmsg = new Zmsg($this->backend);
			$zmsg->body_set('JOB: '.$jid);
			$zmsg->wrap( $_j['param'] );
			$zmsg->wrap( null );
			$zmsg->wrap( $_j['clientid'] );
			$zmsg->wrap( $wid );

			$zmsg->send();

			$_j['startedat'] = microtime(true);
			$this->activeJobList[$jid] = $_j;
			//remove from array
			unset($this->queueJobList[$jid]);
//			array_shift($this->queueJobList);
			$this->log( sprintf("Starting job %s, Sync: %s, jobs left in queue %d",  $jid, ($_j['sync'])? 'TRUE':'FALSE', count($this->queueJobList)), 'I');
			//started a job? let's yeild to the network
			return;

		} while (next($this->queueJobList));
	}


	/**
	 * Must not to reply to $zmsg because workers are REP
	 */
	public function handleBack($zmsg) {

		$this->log( sprintf("Backend IN %s", $zmsg), 'D' );
		$identity      = $zmsg->address();
		$binary        = $zmsg->unwrap();

		$this->log( sprintf("zmsg parts %d", $zmsg->parts()), 'D' );
		if($zmsg->parts() == 1) {
			if($zmsg->body() == 'HEARTBEAT') {
				$this->log( sprintf("HB from %s", $identity), 'D' );
				$this->refreshWorker($identity);
			}
			return;
		}

		//  Return reply to client if it's not a control message
		if($zmsg->parts() > 1) {
			$retval = NULL;
			if ($zmsg->parts() == 2) {
				$service       = $zmsg->unwrap();
			}
			//do we have a return value?
			if ($zmsg->parts() == 3) {
				$retval        = $zmsg->unwrap();
				$service       = $zmsg->unwrap();
			}
			if( substr($zmsg->address(), 0, 5) == "READY") {
				$this->log(sprintf ("ready %s job:%s", $identity, $service), 'I');
				$this->deleteWorker($identity, $service);
				$this->appendWorker($identity, $service);
			}
			if( strtoupper(substr($zmsg->body(), 0, 8) == 'COMPLETE') ) {
				$jobid = substr($zmsg->body(), 10);
				$this->handleFinishJob($zmsg, $jobid, $identity, $service, TRUE, $retval);
			}
			if( strtoupper(substr($zmsg->body(), 0, 4) == 'FAIL') ) {
				$jobid = substr($zmsg->body(), 6);
				$this->handleFinishJob($zmsg, $jobid, $identity, $service, FALSE, $retval);
			}
		} else {
			$this->log( sprintf ("got a response that might have a return value: (%d) parts", count($zmsg->parts())), 'I' );
		}
	}

	/**
	 * Required to send a reply to the front-end since clients are REQ
	 */
	public function handleFront($zmsg) {

		$job = $zmsg->body();

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

		//address unwraps and encodes binary uuids
		$client_id = $zmsg->address();
		$bin_client_id = $zmsg->unwrap();
		$param         = $zmsg->unwrap();

		if (!$this->haveSeenJob($job)) {
			$this->log( sprintf("No worker can handle job \"%s\"", $job), 'E');
			$zmsgReply = new Zmsg($this->frontend);
			$zmsgReply->body_set("FAIL: ".$job);
			$zmsgReply->wrap( null );
			$zmsgReply->wrap( $client_id );
			$zmsgReply->send();
			return;
		}

		$jobid = $this->handleWorkRequest($job, $client_id, $param, $sync);
		if (!$sync) {
			$zmsgReply = new Zmsg($this->frontend);
			$zmsgReply->body_set("JOB: ".$jobid);
			$zmsgReply->wrap( null );
			$zmsgReply->wrap( $client_id );

			$zmsgReply->send();
		}
	}


	/**
	 * Remove job from activeJobList
	 * Add job to stat history
	 * alert notify socket of job completion
	 */
	public function handleFinishJob($zmsg, $jobid, $identity, $service, $success=true, $retval=NULL) {

			$_job = $this->activeJobList[$jobid];

			if ($success)
				$this->log( sprintf ("JOB COMPLETE: %s %s - took %0.4f sec", $_job['service'], $jobid, (microtime(true) - $_job['startedat'])), 'I' );
			else {
				$this->log( sprintf ("JOB FAILED: %s %s - took %0.4f sec", $_job['service'], $jobid, (microtime(true) - $_job['startedat'])), 'I' );
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
				$zmsgReply->body_set("JOB: ".$jobid);
				$zmsgReply->wrap($retval);
				$zmsgReply->wrap( null );
				$zmsgReply->wrap( $_job['clientid'] );

				$zmsgReply->send();
			}

//			$zmsg->set_socket($this->news)->send();
			$this->appendWorker($identity, $service);
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
		$this->log( sprintf ("Request for job %s. queue size: %d", $service, count($this->queueJobList)), 'I' );

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
			$this->log( sprintf ("duplicate worker identity %s", $id), 'E');
		} else {
			$this->log( sprintf ("appending worker %s for %s", $id, $svc) , 'I');
			$this->workerList[$svc][$id] = array(
				'hb'=>microtime(true) + HEARTBEAT_INTERVAL * HEARTBEAT_MAXTRIES,
				'service'=>$svc, 
				'workerid'=>$id 
			);
		}
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
		$this->log(sprintf ("worker %s sent HB, but not in list.", $id) , 'E' );
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

