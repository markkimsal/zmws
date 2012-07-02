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



$zserver = new Zmws_Server($client_port, $worker_port, $news_port);

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
			print ("I: dequeing worker $id\n");
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
//		printf ("I: purging %0.4f%s",  $mt, PHP_EOL);
//		printf ("I: purging %s %0.4f%s", $id,  $jdef['hb'], PHP_EOL);
				if(microtime(true) > $jdef['hb']) {
		printf ("I: purging %s%s",  $id, PHP_EOL);
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
					printf ("D: sending HB to  %s (%s)%s", $id, $jdef['service'], PHP_EOL);
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
		list ($jid, $_j) = each($this->queueJobList);

		$wid = $this->selectWorker($_j['service']);
		//$wid  = $list->get_worker_for_job($_j['service']);
		if (!$wid) {
//			echo "no worker for job \n";
			return;
		}
		$zmsg = new Zmsg($this->backend);
		$zmsg->body_set('JOB: '.$jid);
		$zmsg->wrap( null );
		$zmsg->wrap( $_j['clientid'] );
		$zmsg->wrap( $wid );

//		printf ("D: BE OUT %s", PHP_EOL);
//		print $zmsg->__toString();
		$zmsg->send();

		$_j['startedat'] = microtime(true);
		$this->activeJobList[$jid] = $_j;
		//remove from array
		array_shift($this->queueJobList);
		printf ("I: * Starting Job %s  left %d%s", $jid, count($this->queueJobList), PHP_EOL);
	}


	/**
	 * Must not to reply to $zmsg because workers are REP
	 */
	public function handleBack($zmsg) {

printf ("D: Back IN %s", PHP_EOL);
print $zmsg."\n";
		$identity      = $zmsg->address();
		$binary        = $zmsg->unwrap();

		if($zmsg->parts() == 1) {

			if($zmsg->body() == 'HEARTBEAT') {
				printf ("D: HB from %s %s", $identity,  PHP_EOL);
				$this->refreshWorker($identity);
			}

		}


		//  Return reply to client if it's not a control message
		if($zmsg->parts() == 2) {

			$service       = $zmsg->unwrap();

			if( substr($zmsg->address(), 0, 5) == "READY") {
				printf ("I: ready %s job:%s%s", $identity, $service, PHP_EOL);
				$this->deleteWorker($identity, $service);
				$this->appendWorker($identity, $service);
			}

			if( strtoupper(substr($zmsg->body(), 0, 8) == 'COMPLETE') ) {
				$jobid = substr($zmsg->body(), 10);
				$this->handleFinishJob($zmsg, $jobid, $identity, $service);
			}
			if( strtoupper(substr($zmsg->body(), 0, 4) == 'FAIL') ) {
				$jobid = substr($zmsg->body(), 6);
				$this->handleFinishJob($zmsg, $jobid, $identity, $service, FALSE);
			}
		}
	}

	/**
	 * Required to send a reply to the front-end since clients are REQ
	 */
	public function handleFront($zmsg) {

//		printf ("D: FE IN %s", PHP_EOL);
//		print $zmsg->__toString();


		//$blank = $zmsg->unwrap();
		$job = $zmsg->body();
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

		if ( substr($job, 0, 5) ==  'JOB: ') {
			$job = substr($job, 5);
		}
		printf ("D: request for job %s%s", $job, PHP_EOL);

		//address unwraps and encodes binary uuids
		$client_id = $zmsg->address();
		$bin_client_id = $zmsg->unwrap();

//		printf ("D: client id %s%s", $client_id, PHP_EOL);
		if (!$this->haveSeenJob($job)) {
			printf ("E: no service for job %s%s", $job, PHP_EOL);
			$zmsgReply = new Zmsg($this->frontend);
			$zmsgReply->body_set("FAIL: ".$job);
			$zmsgReply->wrap( null );
			$zmsgReply->wrap( $client_id );
			$zmsgReply->send();
			return;
		}

		$jobid = $this->handleWorkRequest($job, $client_id);

		$zmsgReply = new Zmsg($this->frontend);
		$zmsgReply->body_set("JOB: ".$jobid);
		$zmsgReply->wrap( null );
		$zmsgReply->wrap( $client_id );

//		printf ("D: FE OUT %s", PHP_EOL);
//		print $zmsgReply->__toString();
		$zmsgReply->send();
	}


	/**
	 * Remove job from activeJobList
	 * Add job to stat history
	 * alert notify socket of job completion
	 */
	public function handleFinishJob($zmsg, $jobid, $identity, $service, $success=true) {

			$_job = $this->activeJobList[$jobid];

			if ($success)
				printf ("I: JOB COMPLETE: %s - took %0.4f sec %s", $jobid, (microtime(true) - $_job['startedat']), PHP_EOL);
			else {
				printf ("I: JOB FAILED: %s - took %0.4f sec %s", $jobid, (microtime(true) - $_job['startedat']), PHP_EOL);
			}

			$_job['completedat'] = microtime(true);
			unset($this->activeJobList[$jobid]);
			$this->histJobList[] = $_job;

			if (count($this->histJobList) > 100) {
				array_shift($this->histJobList);
			}
//			$zmsg->set_socket($this->news)->send();
			$this->appendWorker($identity, $service);
			//$list->worker_append($identity, $service);
	}

	/**
	 * Record the request for a new job
	 */
	public function handleWorkRequest($service, $clientId, $id=null) {
		if (!$clientId) {
			return false;
		}

		/*
		if($len == 17 && $part[0] == 0) {
			$part = $this->s_encode_uuid($part);
			$len = strlen($part);
		}
		 */

		if (!$id) {
			$id = Zmws_Server::gen_id();
		}
		$this->queueJobList[$id] = array('service'=>$service, 'reqtime'=>time(), 'clientid'=>$clientId, 'jobid'=>$id);

		return $id;
	}

	public function handleServerJobs($zmsg) {
		$zmsg->body_set( json_encode(array_values($this->queueJobList)) );
		$zmsg->set_socket($this->frontend)->send();
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
			printf ("E: duplicate worker identity %s", $id);
		} else {
			printf ("I: appending worker %s for %s%s", $id, $svc, PHP_EOL);
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
		printf ("E: worker %s not ready\n", $id);
	}

	public function cleanup() {
	}
}

