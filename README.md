ZeroMQ Work Server (zmws)
====

This project provides a work server which can distribute named jobs to distributed worker processes over a network.  It's slightly different from a "Message Queue" in that it is specifically tailored for requesting jobs and - optionally - getting return parameters.

The protocol is implemented in ZeroMQ and follows the Majordomo Protocol with a slight difference.  When a job is sent to a worker, the server may quickly disconnect and let the worker perform tasks in isolation, or it may wait synchronously for the worker to finish and accept return parameters.

When a client wants to run a job, it sends a ZMQ message with "JOB: service-name" where service-name is a service that can be performed by a worker.  When workers connect to the server, they identify themselves as being able to perform a single service.  A client may send parameters as another ZMQ message frame like: "PARAM: php-serialized-params"  or "JSON-PARAM: json-encoded-object".  Parameters are automatically decoded by workers before any work begins.

The project also includes an HTTP gateway server so clients don't need to be compiled with any ZeroMQ library to take advantage of requesting work to be done.  Parameters are collected either from GET or POST and are passed to the worker as JSON-PARAM (never PHP serialization).


How is this different from exec() ?
====
Yes, exec('./sometask > /dev/null 2>&1 &'); can get you a background task.  But, when using a message queue or work server, the central server acts as a buffer - or rubber band - stretching it's capacity to remember jobs and only executing one at a time.  This keeps resources of a single machine in check.  More than one worker can be spawned from each worker class file, meaning you can be ready to handle as many simultaneous requests as your server hardware can handle for any particular job.  Also, the workers can be located on a physically seperate machine from the Web tier, or spread out across lots of machines, the work server delivers jobs in a Round Robin scheme.

How is this different from gearman?
====
Gearman is a very similar project.  This project uses ZeroMQ to implement the network protocl for performing jobs, gearman uses their own.  Both projects require a PHP extension.  In gearman's case it is the php-gearman extension, in the case of ZMWS it requires the php-zmq extension.  Building on ZeroMQ allows the project to take advantage of future ZeroMQ improvements like security as well as existing ZeroMQ features like multicast.

Gearman's job server - gearmand - is written in C.  This alleviates any potential memory issues associated with long running PHP processes, but may limit your ability to customize the job server.  ZMWS's job server is written in PHP.

Majordomo Pattern
====
This implementation is very similar to the majordomo protocol detailed here: http://rfc.zeromq.org/spec:7
One difference is that job requests are *asynchronous* by default.  Synchronous jobs can be requested by prefixing the service name with "SYNC-".


Installation
====
Clone and build the PHP-ZQM bindings from: https://github.com/mkoppanen/php-zmq (phpize; ./configure; make; sudo make install).
Now you make fork, clone or use this project as a dependency inside your own project (http://bower.io).

Configuration
====
Defining servers and workers happens in the etc/config.php file.  Use the config.sample.php as a guide to create your own config file.  At a minimum you need one server or one worker per installation.  If you are just using ZMWS as a way to process long tasks in the background, you will probably want servers and workers running on the same installation.

Running
====
You can start and stop all servers and workers with
```bash
  php ./bin/start.php
  php ./bin/stop.php
```

If you want to start or stop just servers or just workers you can pass the appropriate flag:


```bash
  php ./bin/start.php --servers
  php ./bin/stop.php  --servers

  php ./bin/start.php --workers
  php ./bin/stop.php  --workers

  #this is the same as passing no parameters
  php ./bin/start.php --servers --workers
  php ./bin/stop.php  --servers --workers
```

Writing a Worker
====
There are 3 main parts to a worker:
  * $serviceName which tells the server what jobs this worker can handle
  * The work() method which accepts a $jobId and $param and performs the actual work.
  * The idle() method which is called during long periods of inactivity.

Each worker can (basically, 'must') subclass Worker_Base.  A sample worker is shown below:

```php
<?php

include (dirname(dirname(__FILE__))."/src/worker_base.php");
include (dirname(dirname(__FILE__))."/src/zmsg.php");

class W_Sleep extends Worker_Base { 

    public  $port   = '5556';
    public  $serviceName = 'SLEEP';
    public  $heartbeat_at = 0;

    public function work($jobid, $param='') { 
        usleep (1800000);
        return TRUE;
    } 
}
```

Retunring TRUE from the work function is important.  Without that the Worker_Base will think the work function failed and return 
a failure to the main server.

### Worker Startup
Since each worker is an independent process, each must startup it's own instance.  As much start-up code that is practical is handled by 
the Worker_Base parent class, but there are a few loose ends that you need to paste into your worker file after the class is written.

```php
<?
$worker = new W_Sleep();
// Send out heartbeats at regular intervals
while( $worker->loop() ) { 
}

```

This will cause the worker to loop and read the ZMQ socket indifinitely, waiting for a job.  When it recieves a job request, work() is called.

If you want to accept command line parameters, you need to parse them and set them yourself on your worker class.  The Worker_Base already looks for the special 
ZemroMQ client ID that the network needs to resend messages after a crash.  Other flags can be read like this

```php
<?
$worker = new W_Sleep();
$args   = get_cli_args();
// here we search all command line flags for 
// --db=X
//  -db X
// --dbname=X
// --dbname X
// or any combination of flag and value syntax.
//  If no flag is found, an empty string is the default value.
$dbname = cli_config_get($args, array('db','dbname'), '');
$worker->setDb($dbname);

//  Send out heartbeats at regular intervals
while(  $worker->loop() ) { 
}
```

### Idle Loop
After a certain amount of time without receiving work a worker will call its own idle() method.  This can be useful for freeing resources like database connections and open file handles.

```php
class W_Logger extends Worker_Base { 

    public  $serviceName = 'LOG';
    public  $fh;

    public function work($jobid, $param='') { 
        if (!$this->fh) {
            $this->fh = fopen('/tmp/foo', 'w');
        }
        fputs ($this->fh, 'Logging job '. $jobid.PHP_EOL);
        usleep (800000);
        return TRUE;
    }

    public function idle() {
        if ($this->fh) {
            fputs ($this->fh, 'Worker idling... '.PHP_EOL);
            fclose($this->fh);
        }
    }
}
```


Sample Workers
=====
With the sample config file copied to etc/config.php you should have 2 sample workers by default.  You can verify this by going to  http://localhost:5580/SERVER-WORKERS and verifying the JSON output.

To access the sleep worker, simply go to http://localhost:5580/SLEEP .

You can launch the string reverse  worker one of two ways: either asynchronously or synchronously.  In asynchronous mode (the default) you will not receive the value passed back by the worker.  In this example that is not very useful, but not all workers need to respod to the client who started them.  To start the worker asynchronously, simply go to http://localhost:5580/STR-REV?str=Hello,+world .

To launch the worker as a synchronous job, simply prepend SYNC- to the job name: http://localhost:5580/SYNC-STR-REV?str=Hello,+world
