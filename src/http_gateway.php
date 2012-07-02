<?php
/**
 * ZeroMQ Work Server - HTTP Gateway
 *
 * This file provides a way for clients to interact with the ZMWS network
 * without having to have language bindings for ZeroMQ.  Job names are
 * accepted as the first part of a URL request, parameters are sent 
 * as part of a POST body, either JSON or PHP serialized.
 * Set Content-encoding: [JSON | PHP]
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
$http_port     = cli_config_get($args, array('wport', 'http-port'),   '5580');
$listen_addr   = cli_config_get($args, array('address', 'iface'),   '0.0.0.0');



set_time_limit (0);
$gserver = new Zmws_Gateway($client_port, $http_port, $listen_addr);


echo "Server startup @".date('r')." polling sockets....\n";
while($gserver->poll() ) {
	//poll() returns false when it wants to quit
}

//cleanup sockets
$gserver->cleanup();


class Zmws_Gateway {

	public $zmport = 0;
	public $htport = 0;
	public $addr   = 0;
	public $sock   = NULL;
	public $zm     = NULL;

	public $clientList = array();
	public $reqList    = array();


	public function __construct($zmport, $httpport, $addr) {
		$this->htport = $httpport;
		$this->zmport = $zmport;
		$this->addr   = $addr;

		$this->createSocket();
		$this->connectZm();
	}

	public function createSocket() {
		$this->sock = socket_create(AF_INET, SOCK_STREAM, 0);
		socket_set_option($this->sock, SOL_SOCKET, SO_REUSEADDR, 1);
		socket_bind($this->sock, $this->addr, $this->htport);
		socket_listen($this->sock);
	}

	public function connectZm() {
		$this->zm = new Zmws_Gateway_Client();
	}


	public function poll() {
		$read   = array_merge(array($this->sock), $this->clientList);
		$write  = NULL;
		$except = $this->clientList;
		$num_changed_sockets = socket_select($read, $write, $except, 30);

		//$read now contains sockets that have a changed status
		if (in_array($this->sock, $read)) {
			// read status on the main socket means we have new connections to accept
			$this->clientList[] = socket_accept($this->sock);
//			$ip = $port = '';
//			socket_getpeername( end($this->clientList), $ip, $port);
//			echo "Got new client $ip $port\n";
		}

		$this->readClients($read, $except);
		$this->handleReqs();

//		socket_close($client);

		return TRUE;
	}

	/**
	 * write responses to those requests that are "complete"
	 */
	public function handleReqs() {
		foreach ($this->reqList as $_idx => $req) {
			if (!$req['complete']) continue;

			if (!is_resource($this->clientList[$_idx])) {
				$this->hangup($_idx);
				return;
			}
			$reply = $this->zm->send($req);

/*
			socket_write ($this->clientList[$_idx], "Here are the headers you sent\n");
			foreach ($req['headers'] as $_hdr => $_hdv) {
				socket_write ($this->clientList[$_idx], $_hdr." => ". $_hdv."\n");
			}

			socket_write ($this->clientList[$_idx], "Here is your reply:\n");
			socket_write ($this->clientList[$_idx], $reply."\n");
			socket_write ($this->clientList[$_idx], "Thanks, goodbye.\n");
*/
			if ($reply != '') {
				socket_write ($this->clientList[$_idx], "HTTP 200 OK\n");
			} else {
				socket_write ($this->clientList[$_idx], "HTTP 404 NOT FOUND\n");
			}
			socket_write ($this->clientList[$_idx], "Content-length: ".strlen($reply)."\n");
			socket_write ($this->clientList[$_idx], "\n");
			socket_write ($this->clientList[$_idx], $reply);
			$this->hangup($_idx);
		}
	}

	public function readClients($read, $except) {
		foreach ($this->clientList as $_idx => $_sock) {
			if (in_array($_sock, $except)) {
				echo "Socket exception....\n";
				var_dump(socket_strerror(socket_last_error($_sock)));
				$this->hangup($_idx);
				continue;
			}

			if (!in_array($_sock, $read)) {
//				echo "Socket not ready for reading ...\n";
				continue;
			}
			//max POST body is 4096
			$input = socket_read($_sock, 4096);
			if ($input === FALSE || $input == '') {
				$this->hangup($_idx);
			}

			//handle input
			$this->buildRequest($_idx, $input);
		}
	}

	public function buildRequest($idx, $input) {
		if (!isset($this->reqList[$idx])) {
			$this->reqList[$idx] = 
				array(
					'reqhdr'=>'',
					'headers'=>array(),
					'raw'=>'',
					'complete'=>FALSE
				);
		}
		$req = &$this->reqList[$idx];

		//single line of \r \n or \r\n

		if ( trim($input) != '') {
			$req['raw'] .= $input;
		}

		//could be \n\n or \r\n\r\n
		$end = substr($req['raw'], -4);

		//if we got no input, or all the last 4 chars are empty
		// and the last one is a newline, then complete the
		// request
		if ( trim($input) == '' || 
			( trim($end) == '' && substr($end, -1) == "\n") 
		) {
			$req['complete'] = TRUE;
			$hdrs = explode("\n", $req['raw']);
			foreach ($hdrs as $_hd) {
				if (trim($_hd) == '') continue;

				if (strpos($_hd, ':') ) {
					list($k, $v) = explode(":", $_hd);
					$req['headers'][ trim($k) ] = trim($v);
				} else {
					$req['reqhdr'] = $_hd;
				}
			}
			unset($req['raw']);
		}
	}

	public function hangup($idx) {
//		echo "Closing socket ...\n";

		if (is_resource($this->clientList[$idx])) {
			socket_shutdown($this->clientList[$idx], 2);
			socket_close($this->clientList[$idx]);
		}
		unset($this->clientList[$idx]);
		unset($this->reqList[$idx]);
	}

	public function cleanup() {
		socket_close($this->sock);
	}
}


class Zmws_Gateway_Client {

	public $frontend_port = '5555';

	public function __construct() {
		$this->context   = new ZMQContext();
		$this->frontend  = new ZMQSocket($this->context, ZMQ::SOCKET_REQ);
		$this->frontend->connect("tcp://*:".$this->frontend_port);    //  For clients
	}

	public function send ($req) {
		if ($req['reqhdr'] == '') {
			return '';
		}
		$parts = explode(' ', $req['reqhdr']);
		$job = $parts[1];
		$job = ltrim($job, '/');
		if ($job == 'favicon.ico') {
			return '';
		}
		$request = new Zmsg($this->frontend);
		$request->body_set('JOB: '.$job);
		$request->send();

		$reply = $request->recv();
		return $reply->body();
	}
}
