zmws
====

ZeroMQ Work Server

This server is taken mostly from the Paranoid Pirate example of ZeroMQ but it can handle workers who only know how to do a specific job.  It doesn't send any job to any worker.

The server keeps a list of services from any worker (alive, busy, or dead) which previously connected.  When a job request comes in from a client, the server checks its list of previously seen services and responds right away with a new JOB ID (to be executed later) or a failure notice that there are no workers providing that service.

The client cannot know if the job completed successfully other than checking for whatever results the worker would provide.  Depending on the message load, the client may poll and inspect a list of the last 100 completed jobs by asking the server for SERVER-JOBS.

When a worker has a job, the server de-lists them from the known workers.  When a worker is finished, it replies with a COMPLETE or FAIL status and also includes the type of service it can provide.  The server re-adds the worker to the list of available workers for that service.  When a worker is working, the server knows nothing about them, this is effectively like having the worker be dead.


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
