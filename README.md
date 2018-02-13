# MyPDOMS

MyPDOMS is intended to be a drop-in replacement for most common tasks performed with
[mysqlnd_ms](http://php.net/manual/en/book.mysqlnd-ms.php).

Requires PHP 5.6 or above.

**While this is based on PDO, it only supports MySQL.**

## Table of Contents

- [Configuring](#configuring)
- [Establishing a Connection](#establishing-a-connection)
- [Differences from PDO](#differences-from-pdo)
- [Query Routing](#query-routing)
- [SQL Hints](#sql-hints)
- [Slave Selection](#slave-selection)
- [Prepared Statements](#prepared-statements)

# Configuring

Before you instantiate an instance of `MyPDOMS`, you need to configure it. Configuration is accomplished using the static
`setConfig` method, which expects a single parameter of type array. The structure of the expected associative array is:

- `[config name]` - The name of a configuration. Configurations are collections of servers.
    - `master` - Database configuration for your master server
        - `host` - The host where this database is running (required)
        - `port` - The port on which this database is running (optional; defaults to 3306)
        - `username` - Database username (optional; defers to constructor argument if missing)
        - `password` - Database password (optional; defers to constructor argument if missing)
    - `slaves` - Contains your slave server database configurations
        - `[slave name]` - Database configuration for a slave server (can be anything except `master`)
            - `host` - The host where this database is running (required)
                    - `port` - The port on which this database is running (optional; defaults to 3306)
                    - `username` - Database username (optional; defers to constructor argument if missing)
                    - `password` - Database password (optional; defers to constructor argument if missing)

So, for example, you might want to do this:

```php
<?php
use Corn\MyPDOMS\MyPDOMS;

MyPDOMS::setConfig([
	'my_site_1' => [
		'master' => [
			'host' => '127.0.0.1',
			'port' => 3306,
			'username' => 'my_site_user',
			'password' => 'apples'
        ],
        'slaves' => [
        	'slave_1' => [
        		'host' => '10.0.1.1',
        		'username' => 'slave_user',
        		'password' => 'readonly'
            ],
            'slave_2' => [
            	'host' => '10.0.1.2',
            	'username' => 'slave_user',
            	'password' => 'readonly'
            ]
        ]
    ],
    'my_site_2' => [
    	'master' => [
    		'host' => 'localhost',
    		'username' => 'my_site_2_user',
    		'password' => 'oranges'
        ]
    ]
]);
```

You don't need to supply any slave configuration. If you don't configure any slaves, then all queries will go to the
master.

# Establishing a Connection

Slave connections are lazily-established, but a master connection is established when you construct a new `MyPDOMS`
instance. The constructor is identical to the standard [PDO constructor](http://php.net/manual/en/pdo.construct.php)
but with these caveats:

- The `host` in the DSN should be the name of one of your configurations (in the above example, `my_site_1` or `my_site_2`)
- The `port` in the DSN is ignored if supplied
- If you supply database credentials (`$username` and `$passwd`) in both the constructor and in your config (`setConfig`), `setConfig` wins
    - For this reason it's recommended you supply your credentials in `setConfig`, to prevent any possible credential leakage e.g. in stack traces
- Any connection `$options` you supply will be used for establishing connections to the master **and** to all slave connections

Here's an example:

```php
<?php
use Corn\MyPDOMS\MyPDOMS;

$db = new MyPDOMS('mysql:host=my_site_1;dbname=my_database;charset=utf8mb4', null, null, [MyPDOMS::ATTR_TIMEOUT => 5]);
```

# Differences from PDO

`MyPDOMS` is a subclass of [`PDO`](http://php.net/manual/en/class.pdo.php) so PDO's documentation also applies to `MyPDOMS`
with these core differences:

- The differences noted above in the [Establishing a Connection](#establishing-a-connection) section
- The `lastUsedHost` property contains the name of the last host that was sent a query (e.g. `master` or `slave_1`)
- These methods will always be sent to the master connection:
    - [`beginTransaction`](http://php.net/manual/en/pdo.begintransaction.php)
    - [`commit`](http://php.net/manual/en/pdo.commit.php)
    - [`rollBack`](http://php.net/manual/en/pdo.rollback.php)
    - [`inTransaction`](http://php.net/manual/en/pdo.intransaction.php)
    - [`lastInsertId`](http://php.net/manual/en/pdo.lastinsertid.php)
    - [`quote`](http://php.net/manual/en/pdo.quote.php)
        - Although `quote` does not result in any network I/O, it's always called on the master connection
- These methods will be sent to the connection named by `lastUsedHost`:
    - [`errorCode`](http://php.net/manual/en/pdo.errorcode.php)
    - [`errorInfo`](http://php.net/manual/en/pdo.errorinfo.php)
- [`getAvailableDrivers`](http://php.net/manual/en/pdo.getavailabledrivers.php) will always return `['mysql']`
- Calling [`setAttribute`](http://php.net/manual/en/pdo.setattribute.php) will result in this sequence of events:
    - The attribute and value you passed in will be stored internally in the `MyPDOMS` object
    - The attribute will be set on the master connection
    - The attribute will be set on any established slave connections
    - When a new slave connection is established, all previously-set attributes will be immediately set on it
    - Returns `true` iff all connections returned `true` when `setAttribute` was called on them
- Calling [`getAttribute`](http://php.net/manual/en/pdo.getattribute.php) will return the value from the internal cache, not from a `PDO` connection object
- [`prepare`](http://php.net/manual/en/pdo.prepare.php), [`query`](http://php.net/manual/en/pdo.query.php), and [`exec`](http://php.net/manual/en/pdo.exec.php) will route to a connection based on the criteria noted in [Query Routing](#query-routing)

# Query Routing

Queries will be routed to either the master or to a slave depending on this sequence of checks:

1. Leading comments in the SQL will be checked for [SQL Hints](#sql-hints)
    1. If `HINT_MASTER` is found then the query will be sent to the master
    2. If `HINT_SLAVE` is found then the query will be sent to a slave
    3. If `HINT_LAST_USED` is found then the query will be sent to the last used connection
2. If the first SQL-word is one of `INSERT`, `UPDATE`, `DELETE`, `REPLACE`, or `LOAD` then the query will be sent to the master
3. If the first SQL-word is `SELECT` and the query ends in `FOR UPDATE` then the query will be sent to the master
4. If none of the above match, then the query will be sent to a slave

Note: The routing logic does not check to see if a transaction is open, because all queries that can result in updates or locks are already automatically routed to the master.

# SQL Hints

SQL hints can be used to override the default query routing logic.
These SQL hints are available, and should be prepended to queries in a comment:

- `MyPDOMS::HINT_MASTER` - Send this query to the master
- `MyPDOMS::HINT_SLAVE` - Send this query to a slave
- `MyPDOMS::HINT_LAST_USED` - Send this query to the server last used
    - This may be the master, if the master was last used
    - If the last used server was a slave, then the query will be sent to that slave
    
Example:

```php
<?php
use Corn\MyPDOMS\MyPDOMS;

$db = new MyPDOMS($dsn);
$db->query("/*" . MyPDOMS::HINT_MASTER . "*/SELECT 1"); // will be sent to the master even though it's a SELECT
```

If you want to replace mysqlnd_ms and not go back and update all your code, you can use this snippet:

```php
<?php
use Corn\MyPDOMS\MyPDOMS;

if (!defined('MYSQLND_MS_MASTER_SWITCH')) {
    define('MYSQLND_MS_MASTER_SWITCH', MyPDOMS::HINT_MASTER);
    define('MYSQLND_MS_SLAVE_SWITCH', MyPDOMS::HINT_SLAVE);
    define('MYSQLND_MS_LAST_USED_SWITCH', MyPDOMS::HINT_LAST_USED);
}
```

# Slave Selection

When a query is due to be routed to a slave, a slave is selected **per-query**. That is, slaves are not selected per-request
but are selected every time a query is executed. Presently, the only supported selection mechanism is unweighted random,
in which every query will be sent to a random slave, with all slaves having an equal probability of being chosen.

Example:

```php
<?php
use Corn\MyPDOMS\MyPDOMS;

// Assume configured slaves are slave_{1-5}

$db = new MyPDOMS($dsn);
$db->query("SELECT 1");
echo $db->lastUsedHost . "\n"; // slave_4
$db->query("SELECT 1");
echo $db->lastUsedHost . "\n"; // slave_3
$db->query("SELECT 1");
echo $db->lastUsedHost . "\n"; // slave_4
$db->query("SELECT 1");
echo $db->lastUsedHost . "\n"; // slave_2
$db->query("SELECT 1");
echo $db->lastUsedHost . "\n"; // slave_2
$db->query("SELECT 1");
echo $db->lastUsedHost . "\n"; // slave_2
```

Different selection algorithms are expected to be added in later releases, but if you wish to define your own
selection algorithm, you may extend `MyPDOMS` and override the
[`getSlave`](https://github.com/DoctorMcKay/php-mypdoms/blob/cb03707ba87437192062931decd0ff6aa672a507/src/MyPDOMS.php#L200) method.

# Prepared Statements

Both emulated and non-emulated prepared statements are fully supported, as they are assigned a connection at prepare-time.
That is, after a `PDOStatement` is returned from `prepare()`, the statement will always use the same database each time
it is executed.

Note: `lastUsedHost` is updated when a statement is *prepared*, but not when it is *executed*. This means that the
following is possible:

```php
<?php
use Corn\MyPDOMS\MyPDOMS;

$db = new MyPDOMS($dsn);
$stmt = $db->prepare("SELECT 1");
echo $db->lastUsedHost . "\n"; // slave_1

$db->query("SELECT 1");
echo $db->lastUsedHost . "\n"; // slave_2

$stmt->execute(); // this is executed on slave_1 since it was prepared on slave_1
echo $db->lastUsedHost . "\n"; // slave_2

$db->query("/*" . MyPDOMS::HINT_LAST_USED . "*/ SELECT 1");
echo $db->lastUsedHost . "\n"; // slave_2
```
