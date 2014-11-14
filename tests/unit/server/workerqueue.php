<?php
include_once('src/server.php');

class Tests_Server_Workerqueue extends PHPUnit_Framework_TestCase {


	public function test_select_worker_should_find_idle_worker() {
		$client_port = 5555;
		$worker_port = 5556;
		$news_port   = 5559;
		$busywid     = 1;
		$idlewid     = 2;

		$zserver = new Zmws_Server($client_port, $worker_port, $news_port);
		$zserver->appendWorker(1, 'foobar');
		$zserver->appendWorker(2, 'foobar');
		$testwid = $zserver->selectWorker('foobar');
		$zserver->activeWorkerList[] = $testwid;
		$testwid = $zserver->selectWorker('foobar');
		$this->assertEquals( $idlewid, $testwid);

		$zserver->cleanup();
	}

	public function test_select_no_idle_workers_returns_false() {
		$client_port = 5555;
		$worker_port = 5556;
		$news_port   = 5559;
		$busywid     = 1;
		$idlewid     = 2;

		$zserver = new Zmws_Server($client_port, $worker_port, $news_port);
		$zserver->appendWorker(1, 'foobar');
		$zserver->appendWorker(2, 'foobar');

		$zserver->activeWorkerList[] = $busywid;
		$zserver->activeWorkerList[] = $idlewid;

		$testwid = $zserver->selectWorker('foobar');

		$this->assertEquals( false, $testwid);

		$zserver->cleanup();
	}

	public function test_append_worker_multiple_times_does_nothing() {
		$client_port = 5555;
		$worker_port = 5556;
		$news_port   = 5559;
		$busywid     = 1;
		$idlewid     = 2;

		$zserver = new Zmws_Server($client_port, $worker_port, $news_port);
		$zserver->appendWorker(1, 'foobar');
		$zserver->appendWorker(1, 'foobar');

		$this->assertEquals( 1, count($zserver->workerList['foobar']));

		$zserver->cleanup();
	}

	public function test_purse_dead_worker_requeues_job() {
		$client_port = 5555;
		$worker_port = 5556;
		$news_port   = 5559;


		//$this->queueJobList[$id] = array('service'=>$service, 'reqtime'=>time(), 'param'=>$param, 'clientid'=>$clientId, 'jobid'=>$id, 'sync'=>$sync);
		$testJobId =  13;
		$testJob   =  array (
			'jobid'     => $testJobId,
			'service'   => 'sleep',
			'reqtime'   => time(),
			'param'     => null,
			'clientid'  => '1234',
			'sync'      => 'true',
			'worker'    => 1,
			'startedat' => microtime(true)
		);

		$zserver = new Zmws_Server($client_port, $worker_port, $news_port);
		$zserver->appendWorker(1, 'foobar');
		$zserver->activeJobList[ $testJobId ] = $testJob;

		$zserver->workerList['foobar'][1]['hb'] = 0;
		$zserver->purgeStaleWorkers();

		
		$this->assertEquals( 1, count($zserver->queueJobList));
		$this->assertEquals( $testJobId, key($zserver->queueJobList));
		$testJob   = current($zserver->queueJobList);
		$this->assertEquals( 'sleep',  $testJob['service']);
		$this->assertEquals( '1234',   $testJob['clientid']);

		$zserver->cleanup();
	}
}
