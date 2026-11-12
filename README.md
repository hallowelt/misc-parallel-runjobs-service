# Usage
## For development
1. Clone this repo
2. Run `composer update`
3. Create local config file (see [Config](#config) for details)
4. Run the script with the local config file:
   ```bash
   ./bin/parallel-runjobs-service --config ./config.yaml
   ``` 
   
### Creating a build
Once you are done with the development, you can create a build using the following steps:
1. Compile the PHAR file using the following command:
   ```bash
   box compile
   ```
   see https://github.com/humbug/box for installation and usage of `box`
2. That will create a PHAR file in `build/` directory.
3. Commit your changes

## For production
1. Clone this repo
   ```bash
    git clone {URL} /opt/parallel-runjobs-service
    cd /opt/parallel-runjobs-service
    ```
2. Create local config file (see [Config](#config) for details) - must configure Database connection (for management wiki (w))
3. Add service
    - Run the following command to add the service:
      ```bash
      ./add-init.d-service.sh
      ```
    - Start the service:
      ```bash
      service wiki-runjobs start
      ```
    - Check the status of the service:
      ```bash
      service wiki-runjobs status
      ```
    - Check the logs of the service:
      ```bash
      tail -f /var/log/farm-runjobs.log
      ``` 
      
# Run modes

## Single instance 

Required configs:
- `wiki.type` must be set to `standalone`

In this mode it runs runJobs.php for a single wiki, the one specified in `wiki.path` config over and over,
with defined cooldown between the runs, and respecting `runjobs.maxjobs` and `runjobs.maxtime` configs.

## Farm database

Required configs:
- `wiki.type` must be set to `farm`
- `queue` must be set to `database`
- `database` must be configured
      
In this mode, it will get list of instances from the root wiki database, and run runJobs.php for each of them in parallel, 
respecting `farm.maxparallel` config, and excluding/including instances based on `farm.exclude_instances` and `farm.include_instances` configs.
It will also respect `runjobs.maxjobs`, `runjobs.maxtime`, and `runjobs.cooldown` configs for each instance.

Warning: Only use for smaller farms without distributed setup of this service, as it is not very efficient.

## Redis

Required configs:
- `wiki.type` must be set to `farm`
- `queue` must be set to `redis`
- `redis` must be configured

In this mode, it will get list of instances with pending jobs from Redis, and run runJobs.php for each of them in parallel,
respecting `farm.maxparallel` config, and excluding/including instances based on `farm.exclude_instances` and `farm.include_instances` configs.

If there are no jobs, or instance is already being processed by another runner, it will be skipped, and runner will wait for the next cycle to check for new jobs.

Only to be used when wiki's jobqueue is configured to use Redis,
and the same Redis instance is used for both jobqueue and this service, so we can be sure that we are getting all the pending jobs.

This is suitable for distributed setups of this service and for large farms.

### Job queue maintenance

MediaWiki only supports `JobQueueRedis` in "daemonized" mode, where a background service has to take care of
delayed and failed jobs. In Redis mode, this service does that every `redis.maintenance-interval` seconds,
for all queues found in Redis:
- releases delayed jobs that are due
- recycles jobs whose claim is older than `redis.claimttl` (failed or crashed runs), or abandons them after
  `redis.maxtries` attempts (logged as warning)
- deletes abandoned jobs older than `redis.pruneage`

`redis.claimttl` and `redis.maxtries` must match `claimTTL` and `maxTries` of `$wgJobTypeConf` of the wiki.
`redis.claimttl` must be greater than `runjobs.maxtime` plus the run time of the longest job, otherwise jobs
may be executed twice.

It is safe to run this on multiple runners. The `s-queuesWithJobs` set of MediaWiki is not cleaned up.

# Config

| name                   | default value                                     | accepted values                         | description                                                                                                                                                                            |
|------------------------|---------------------------------------------------|-----------------------------------------|----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| wiki.type              | `standalone`                                      | `farm`, `standalone`                    | type of the bluespice edition                                                                                                                                                          |
| wiki.path              | (mandatory)                                       | absolute path                           | bluespice installation path (also in case of farm, path to `w`)                                                                                                                        |
| queue                  | database                                          | string                                  | Type of queue to use                                                                                                                                                                   |
| loglevel               | `NOTICE`                                          | `DEBUG`, `INFO`, `NOTICE`, `WARN(ING)`, `ERROR`, ... (case-insensitive) | Minimum level of the log output. Can be overridden by the env var `PARALLEL_RUNJOBS_SERVICE_LOGLEVEL`. `NOTICE`: startup, connections, ready and shutdown; `INFO`: additionally every runJobs.php start/finish; `DEBUG`: additionally the runJobs.php output |
| environment.php        | `/usr/bin/php`                                    | string                                  | Path to PHP executable                                                                                                                                                                 |
| runjobs.maxtime        | `30`                                              | positive integer                        | Maximum life time of a runJobs.php (Per wiki, per cycle) - Seconds                                                                                                                     |
| runjobs.maxjobs        | `50`                                              | positive integer                        | Max number of jobs to execute per cycle                                                                                                                                                |
| runjobs.cooldown       | `1`                                               | positive integer                        | Pause between script executions                                                                                                                                                        |
| farm.maxparallel       | `10`                                              | positive integer                        | Maximum number parallel processes                                                                                                                                                      |
| farm.exclude_instances | `[]`                                              | array of valid wiki farm instance names | contains list of farm instances name for which the runjobs should executed (has priority over includes, ie. If instance is specified in both include and exclude, it will be excluded) |
| farm.include_instances | `[]`                                              | array of valid wiki farm instance names | contains list of farm instances name for which the runjobs should be run, if left empty, all the farm instances will be considered except the ones in `exclude_instances` list         |
| database               | can be ommited competely for standalone - not used | string                                  | Database server                                                                                                                                                                        |
| database.dbserver      | `localhost`                                       | string                                  | Database server                                                                                                                                                                        |
| database.dbname        | (mandatory for farm)                              | string                                  | Name of management database for farms)                                                                                                                                                 |
| database.dbuser        | (mandatory for farm)                              | string                                  | Database user                                                                                                                                                                          |
| database.dbpassword    | (mandatory for farm)                              | string                                  | Database password                                                                                                                                                                      |
| database.dbprefix      | (empty)                                           | string                                  | Database prefix                                                                                                                                                                        |
| database.dbport        | 3306                                              | integer                                 | Database port                                                                                                                                                                          |
| redis                  | can be omitted for non Redis setups               | object                                  | Configuration of Redis server                                                                                                                                                          |
| redis.host             | (mandatory)                                       | string                                  | Redis host                                                                                                                                                                             |
| redis.port             | 6379                                              | integer                                 | Redis port                                                                                                                                                                             |
| redis.passsword        | `''`                                              | string                                  | Redis password (if applicable)                                                                                                                                                         |
| redis.database         | 0                                                 | string| integer                                                                                                                                                                 |Redis databse
| redis.maintenance-interval | `60`                                          | integer                                 | Seconds between two runs of the job queue maintenance. `0` disables it (not recommended)                                                                                             |
| redis.claimttl         | `3600`                                            | integer                                 | Seconds after which a claimed job is considered failed and retried. Must match `claimTTL` of `$wgJobTypeConf`. `0`: never                                                             |
| redis.maxtries         | `3`                                               | positive integer                        | Total attempts of a job before it is abandoned. Must match `maxTries` of `$wgJobTypeConf`                                                                                            |
| redis.pruneage         | `604800`                                          | integer                                 | Seconds after which abandoned jobs are deleted. `0`: never                                                                                                                           |
