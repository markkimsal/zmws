zmws
====

ZeroMQ Work Server

This server is taken mostly from the Paranoid Pirate example of ZeroMQ but it can handle workers who only know how to do a specific job.  It doesn't send any job to any worker.

The server keeps a list of services from any worker (alive, busy, or dead) which previously connected.  When a job request comes in from a client, the server checks its list of previously seen services and responds right away with a new JOB ID (to be executed later) or a failure notice that there are no workers providing that service.

The client cannot know if the job completed successfully other than checking for whatever results the worker would provide.  Depending on the message load, the client may poll and inspect a list of the last 100 completed jobs by asking the server for SERVER-JOBS.

When a worker has a job, the server de-lists them from the known workers.  When a worker is finished, it replies with a COMPLETE or FAIL status and also includes the type of service it can provide.  The server re-adds the worker to the list of available workers for that service.  When a worker is working, the server knows nothing about them, this is effectively like having the worker be dead.

How is this better than exec() ?
====
Yes, exec('./sometask > /dev/null 2>&1 &'); can get you a background task.  All in all, there isn't much difference between backgrounding a task with exec and sending 
messages to a message queue or work server - if you're only ever on one physical machine.  The benefit from using a work server or other messaging solution is that you are prepared for growth.  If your project becomes successfull, how will you federate that exec('./sometask') to other machines?  Using something like ZMWS allows you to easily move workers onto different nodes, have 4 or 5 workers all performing the same task, or throttle excessive or abusive requests to a managable level.  Using a full messaging solution like ZMWS or Apache ActiveMQ will also force your code to be written with the idea of a messaging framework in it, making it adaptable in the future.

How is this different from gearman?
====
Gearman is a very similar project.  This project uses ZeroMQ to implement the network protocl for performing jobs, gearman uses their own.  Both projects require a PHP extension.  In gearman's case it is the php-gearman extension, in the case of ZMWS it requires the php-zmq extension.  Building on ZeroMQ allows the project to take advantage of future ZeroMQ improvements like security as well as existing ZeroMQ features like multicast.

Gearman's job server - gearmand - is written in C.  This alleviates any potential memory issues associated with long running PHP processes, but may limit your ability to customize the job server.  ZMWS's job server is written in PHP.

Majordomo Pattern
====
This implementation is very similar to the majordomo protocol detailed here: http://rfc.zeromq.org/spec:7
One difference is that job requests are *asynchronous* by default.  Synchronous jobs can be requested by prefixing the service name with "SYNC:".


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

Sample Workers
=====
With the sample config file copied to etc/config.php you should have 2 sample workers by default.  You can verify this by going to  http://localhost:5580/SERVER-WORKERS and verifying the JSON output.

To access the sleep worker, simply go to http://localhost:5580/SLEEP .

You can launch the string reverse  worker one of two ways: either asynchronously or synchronously.  In asynchronous mode (the default) you will not receive the value passed back by the worker.  In this example that is not very useful, but not all workers need to respod to the client who started them.  To start the worker asynchronously, simply go to http://localhost:5580/STR-REV?str=Hello,+world .

To launch the worker as a synchronous job, simply prepend SYNC- to the job name: http://localhost:5580/SYNC-STR-REV?str=Hello,+world
