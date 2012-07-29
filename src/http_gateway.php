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
			if ($req['hdrcomplete'] == TRUE && $req['complete'] == FALSE) {
				if (isset($req['headers']['Expect'])) {
					socket_write ($this->clientList[$_idx], "HTTP/1.1 100 Continue\r\n\r\n");
				}
			}
		}
		foreach ($this->reqList as $_idx => $req) {
			if (!$req['complete']) continue;

			if (!@is_resource($this->clientList[$_idx])) {
				$this->hangup($_idx);
				return;
			}
			$params = $this->_parseParams($_idx);
			$reply = $this->zm->send($req, $params);

/*
			socket_write ($this->clientList[$_idx], "Here are the headers you sent\n");
			foreach ($req['headers'] as $_hdr => $_hdv) {
				socket_write ($this->clientList[$_idx], $_hdr." => ". $_hdv."\n");
			}

			socket_write ($this->clientList[$_idx], "Here is your reply:\n");
			socket_write ($this->clientList[$_idx], $reply."\n");
			socket_write ($this->clientList[$_idx], "Thanks, goodbye.\n");
*/
			if (strpos($reply, 'FNF') !== FALSE) {
				socket_write ($this->clientList[$_idx], "HTTP 404 NOT FOUND\n");
			} elseif ($reply != '') {
				socket_write ($this->clientList[$_idx], "HTTP 200 OK\n");
			} else {
				socket_write ($this->clientList[$_idx], "HTTP 501 INTERAL SERVER ERROR\n");
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
		//initialize new request
		if (!isset($this->reqList[$idx])) {
			$this->reqList[$idx] = 
				array(
					'request'=>'',
					'headers'=>array(),
					'raw'=>'',
					'body'=>'',
					'error'=>'',
					'complete'=>FALSE,
					'hdrcomplete'=>FALSE
				);
		}
		$req = &$this->reqList[$idx];

		if (!$req['hdrcomplete']) {
			$req['raw'] .= $input;
		} else {
			$req['body'] .= $input;
		}

		//multipart form bodies have  \n\n
		$end = FALSE;
		if (!$req['hdrcomplete']) {
			$end = strpos($input, "\r\n\r\n");
			if ($end === FALSE) {
				$end = strpos($input, "\n\n");
				$pn = 2;
			} else {
				$pn = 4;
			}
		}

		if ($end !== FALSE) {
			$req['hdrcomplete'] = TRUE;

			$hdrList = explode("\n", substr($req['raw'], 0, $end+$pn));
			foreach ($hdrList as $_hd) {
				if (trim($_hd) == '') continue;

				if (strpos($_hd, ':') ) {
					list($k, $v) = explode(":", $_hd);
					$req['headers'][ trim($k) ] = trim($v);
				} else {
					$req['request'] = $_hd;
				}
			}
			unset($hdrList);
			$req['body'] = substr($req['raw'], $end+$pn);
			unset($req['raw']);
		}

		//if this is the second time through and headers are already complete, check body
		if ($req['hdrcomplete'] == TRUE) {
			if( isset($req['headers']['Content-Length']) ) {
				if ($req['headers']['Content-Length'] <= strlen($req['body'])) {
					$req['complete'] = TRUE;

				}
			} else {
				$req['complete'] = TRUE;
			}
		}
	}

	/**
	 * Return an array of key values pairs from the request's body.
	 *
	 * Support both application/x-www-form-urlencoded and multipart/form-data
	 * 
	 * TODO: support multipart/form-data
	 */
	public function _parseParams($idx) {
		$params  = (object) array();
		if (!strpos($this->reqList[$idx]['body'], '&')) return $params;

		$pairs   = explode('&', $this->reqList[$idx]['body']);
		foreach  ($pairs as $_p) {
			list($k, $v) = explode('=', $_p);
			$params->{$k} = $v;
		}
		return $params;
	}

	public function hangup($idx) {
//		echo "Closing socket ...\n";

		if (@is_resource($this->clientList[$idx])) {
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

	public function send ($req, $param) {
		if ($req['request'] == '') {
			return '';
		}
		$parts = explode(' ', $req['request']);
		$job = $parts[1];
		$job = ltrim($job, '/');
		if ($job == 'favicon.ico') {
			return '';
		}
		if (trim($job) == '') {
			return 'FNF';
		}
		$request = new Zmsg($this->frontend);
		$request->body_set('JOB: '.$job);
		if ($param) {
			$request->push('PARAM-JSON: '. json_encode($param));
		}

		$request->send();

		$reply = $request->recv();
		return $reply->body();
	}
}
