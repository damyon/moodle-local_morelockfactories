<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Memcached lock factory.
 *
 * @package    local_morelockfactories
 * @copyright  Damyon Wiese 2014
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_morelockfactories;

defined('MOODLE_INTERNAL') || die();

/**
 * Defines api for locking using memcached (including separate cluster nodes)
 *
 * @package   local_morelockfactories
 * @copyright Damyon Wiese 2014
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class memcached_lock_factory implements \core\lock\lock_factory {

    /** @var Memcached $connection - The connection to the memcache server */
    protected $connection;

    /** @var string $type - The type of locking this factory is being used for */
    protected $type;

    /** @var boolean $verbose - If true, debugging info about the owner of the lock will be written to the lock file. */
    protected $verbose;

    /** @var array $openlocks - List of held locks - used by auto-release */
    protected $openlocks = array();

    /**
     * Create an instance of this class.
     * If the configuration is bad, or the Memcached extension is not loaded,
     * this factory will never return a lock.
     */
    public function __construct($type) {
        global $CFG;

        $this->type = $type;
        $this->connection = null;
        $this->verbose = false;
        if ($CFG->debugdeveloper) {
            $this->verbose = true;
        }
        \core_shutdown_manager::register_function(array($this, 'auto_release'));
    }

    /**
     * Is available.
     * @return boolean - True if this lock type is available in this environment.
     */
    public function is_available() {
        if ($this->connection) {
            return true;
        }
        return $this->open_connection();
    }

     /**
      * Return information about the blocking behaviour of the lock type on this platform.
      * @return boolean - true - will timeout if we can't get a lock.
      */
    public function supports_timeout() {
        return true;
    }

    /**
     * This lock type will NOT be automatically released when a process ends.
     * @return boolean - False
     */
    public function supports_auto_release() {
        return true;
    }

    /**
     * Multiple locks for the same resource can be held by a single process.
     * @return boolean - True
     */
    public function supports_recursion() {
        return false;
    }

    /**
     * Given a resource, generate a unique key (unique across sites).
     * @param string $resource - The identifier for the lock. Should use frankenstyle prefix.
     * @return string - A unique key for the lock.
     */
    protected function generate_key($resource) {
        global $CFG;

        return sha1($CFG->siteidentifier . '_' . $this->type . '_' . $resource);
    }

    /**
     * Open a connection to the memcache servers.
     * @return bool - No error reported for any servers.
     */
    protected function open_connection() {
        global $CFG;

        if (!class_exists('\Memcached')) {
            return false;
        }

        $connection = new \Memcached();

        $server = '';
        if (isset($CFG->lock_memcache_url)) {
            $server = trim($CFG->lock_memcache_url);
        }
        if (empty($server)) {
            $server = 'localhost:11211';
        }

        $hostport = explode(':', $server);
        $host = $hostport[0];
        if (count($hostport) > 1) {
            $port = $hostport[1];
        } else {
            $port = 11211;
        }

        if (!$connection->addServer($host, $port, 100)) {
            return false;
        }
        $this->connection = $connection;
        return true;
    }

    /**
     * Get some info that might be useful for debugging.
     * @return boolean - string
     */
    protected function get_debug_info() {
        return 'host:' . php_uname('n') . ', pid:' . getmypid() . ', time:' . time();
    }

    /**
     * Get a lock within the specified timeout or return false.
     * @param string $resource - The identifier for the lock. Should use frankenstyle prefix.
     * @param int $timeout - The number of seconds to wait for a lock before giving up.
     * @param int $maxlifetime - Unused by this lock type.
     * @return lock|false - An open lock, or false if a lock could not be obtained.
     */
    public function get_lock($resource, $timeout, $maxlifetime = 86400) {
        $giveuptime = time() + $timeout;

        if (!$this->is_available()) {
            return false;
        }
        $value = 1;
        if ($this->verbose) {
            $value = $this->get_debug_info();
        }

        $key = $this->generate_key($resource);
        $locked = false;

        do {
            $locked = $this->connection->add($key, $value, $maxlifetime);
            if (!$locked) {
                usleep(rand(10000, 250000)); // Sleep between 10 and 250 milliseconds.
            }
            // Try until the giveuptime.
        } while (!$locked && time() < $giveuptime);

        if (!$locked) {
            return false;
        }
        $this->openlocks[$key] = 1;
        return new \core\lock\lock($key, $this);
    }

    /**
     * Release a lock that was previously obtained with @lock.
     * @param lock $lock - A lock obtained from this factory.
     * @return boolean - true if the lock is no longer held (including if it was never held).
     */
    public function release_lock(\core\lock\lock $lock) {
        $result = $this->connection->delete($lock->get_key());
        if ($result) {
            unset($this->openlocks[$lock->get_key()]);
        }
        return $result;
    }

    /**
     * Extend a lock that was previously obtained with @lock.
     * @param lock $lock - a lock obtained from this factory.
     * @return boolean - true if the lock was extended.
     */
    public function extend_lock(\core\lock\lock $lock, $maxlifetime = 86400) {
        // Not supported by this factory.
        return false;
    }

    /**
     * Auto release any open locks on shutdown.
     * This is required, because we may be using persistent DB connections.
     */
    public function auto_release() {
        // Must release all open locks.
        if ($this->connection) {
            foreach ($this->openlocks as $key => $unused) {
                $lock = new \core\lock\lock($key, $this);
                $this->release_lock($lock);
            }
            $this->close_connection();
        }
    }

    /**
     * Close the open connection to memcache
     */
    protected function close_connection() {
        if ($this->connection) {
            // Not supported in older versions of memcached.
            if (method_exists($this->connection, "quit")) {
                $this->connection->quit();
            }
            $this->connection = null;
        }
    }

    /**
     * Release resources (close all open locks).
     */
    public function __destruct() {
        $this->auto_release();
    }
}
