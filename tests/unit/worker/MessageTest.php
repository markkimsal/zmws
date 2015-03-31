<?php
include_once('src/worker_base.php');

class Tests_Worker_Message extends PHPUnit_Framework_TestCase {


	public function test_bad_protocol_doesnt_call_work() {
		$zmsg = new Zmsg();
		$zmsg->body_set('JOB: DEMO');
		$zmsg->push('DEMO');
		$zmsg->push(0x01);
		$zmsg->push('MDPC00');
		$zmsg->push(NULL);
		$zmsg->push('0000-0000');

		$worker = new  Zmws_Worker_Base();
		$worker->log_level = 'E';
		$reply = $worker->onMessage('JOB: DEMO', $zmsg);
		$this->assertEquals( 'FAIL', substr( $reply->body(), 0, 4) );
	}

	public function test_good_protocol_calls_work() {
		$zmsg = new Zmsg();
		$zmsg->body_set('JOB: DEMO');
		$zmsg->push('DEMO');
		$zmsg->push(0x01);
		$zmsg->push('MDPC02');
		$zmsg->push(NULL);
		$zmsg->push('0000-0000');

		$worker = $this->getMockBuilder('Zmws_Worker_Base')
					   ->setMethods(array('work', 'sendAnswer'))
					   ->getMock();

		$worker->expects($this->once())
			    ->method('work')
			    ->will($this->returnValue(TRUE));

		$worker->log_level = 'E';
		$reply = $worker->onMessage('JOB: DEMO', $zmsg);
		$this->assertEquals( 'COMPLETE', substr( $reply->body(), 0, 8) );
	}
}
