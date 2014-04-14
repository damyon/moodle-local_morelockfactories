Moodle 2.7 introduces a locking api for critical tasks (e.g. cron).

The type of locking used can be set to one of the lock factories contained in this plugin.
by changing the $CFG->lock_factory setting in config.php.

e.g.

$CFG->lock_factory = "\\local_morelockfactories\\memcache_lock_factory";

The 2 lock factories contained in this plugin are:

"\\local_morelockfactories\\memcache_lock_factory" - Memcache locking

The memcache lock type depends on an external Memcache server to hold
the locks. It is dangerous to use this lock type with a Memcache server
that is also used for other purposes. If the memcache server deletes the
locks to reclaim space - the locks will be released. Also if memcache
is restarted, all cluster nodes also need to be restarted because their
active locks will have been released.

"\\local_morelockfactories\\memcached_lock_factory" - Memcached locking

The memcached lock type is identical to the memcache lock type except
it uses the memcached extension rather than the memcache one.

To configure the memcache server that is used for locking with either factory,
set the config variable:

$CFG->lock_memcache_url = 'localhost:11211';

The memcache server url should consist of the memcache server hostname and optionally
the port. E.g. localhost:11211

