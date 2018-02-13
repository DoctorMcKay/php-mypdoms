<?php
namespace Corn\MyPDOMS;

use PDO;

class MyPDOMS extends PDO {
	const HINT_MASTER = 'ms=master';
	const HINT_SLAVE = 'ms=slave';
	const HINT_LAST_USED = 'ms=last_used';

	const MASTER_COMMANDS = ['INSERT', 'UPDATE', 'DELETE', 'REPLACE', 'LOAD'];

	/** @var array $config */
	protected static $config = [];

	/** @var array $dbConfig Config from static::$config */
	protected $dbConfig;

	// options for PDO constructors
	/** @var string $dsn */
	/** @var string $username */
	/** @var string $password */
	/** @var array $options */
	protected $dsn;
	protected $username;
	protected $password;
	protected $connectOptions;

	/** @var array $attributes */
	protected $attributes = [];

	/** @var PDO $master */
	/** @var PDO[] $slaves */
	/** @var PDO $last */
	/** @var string $lastUsedHost */
	protected $master;
	protected $slaves = [];
	protected $last;
	public $lastUsedHost;

	/**
	 * @param array $config
	 */
	public static function setConfig($config) {
		static::$config = $config;
	}

	/**
	 * MyPDOMS constructor.
	 * @param string $dsn
	 * @param string $username
	 * @param string $passwd
	 * @param array $options
	 * @throws \Exception
	 */
	public function __construct($dsn, $username = null, $passwd = null, $options = []) {
		// username and passwd are ignored if they're in the config
		if (substr($dsn, 0, 6) != 'mysql:') {
			throw new \Exception('MyPDOMS only supports MySQL databases');
		}

		// make sure we have config for this "host"
		$dsn_parts = explode(';', substr($dsn, 6));
		$dsn_params = [];
		foreach ($dsn_parts as $part) {
			$part = trim($part);
			$pos = strpos($part, '=');
			if ($pos === false) {
				throw new \Exception('Malformed DSN string');
			}

			$name = strtolower(trim(substr($part, 0, $pos)));
			$val = trim(substr($part, $pos + 1));
			$dsn_params[$name] = $val;
		}

		if (!isset($dsn_params['host'])) {
			throw new \Exception('Missing host option in DSN string');
		}

		if (!isset(static::$config[$dsn_params['host']])) {
			throw new \Exception('No configuration for virtual host "' . $dsn_params['host'] . '"');
		}

		$this->dbConfig = static::$config[$dsn_params['host']];
		$this->connectOptions = $options;

		$this->username = $username;
		$this->password = $passwd;

		$dsn_params['host'] = '{{host}}';
		$dsn_params['port'] = '{{port}}';
		$this->dsn = 'mysql:' . implode(';', $dsn_params);
		// try to connect to the master immediately

		$this->master = $this->getPDO('master');
	}

	/**
	 * @param string $configName
	 * @return PDO
	 * @throws \Exception
	 */
	protected function getPDO($configName) {
		if ($configName == 'master') {
			if (!isset($this->dbConfig['master'])) {
				throw new \Exception('No master config');
			}

			$config = $this->dbConfig['master'];
		} else {
			if (!isset($this->dbConfig['slaves'][$configName])) {
				throw new \Exception('No slave config for ' . $configName);
			}

			$config = $this->dbConfig['slaves'][$configName];
		}

		if (!isset($config['host'])) {
			throw new \Exception('No host config for ' . $configName);
		}

		if (!isset($config['port'])) {
			$config['port'] = 3306;
		}

		$username = isset($config['username']) ? $config['username'] : $this->username;
		$password = isset($config['password']) ? $config['password'] : $this->password;
		$dsn = str_replace(['{{host}}', '{{port}}'], [$config['host'], $config['port']], $this->dsn);
		$pdo = new PDO($dsn, $username, $password, $this->connectOptions);

		foreach ($this->attributes as $attr => $val) {
			$pdo->setAttribute($attr, $val);
		}

		return $pdo;
	}

	/**
	 * @param string $query
	 * @return PDO
	 * @throws \Exception
	 */
	protected function getQueryConnection($query) {
		$query = trim($query);
		$preceding_comment = '';
		$len = strlen($query);

		// parse out any leading comments. we check ALL leading comments for hints
		$in_comment = false;
		for ($i = 0; $i < $len; $i++) {
			if (!$in_comment && $query[$i] == '/' && $query[$i + 1] == '*') {
				$in_comment = true;
				$i++;
			} elseif ($in_comment && $query[$i] == '*' && $query[$i + 1] == '/') {
				$in_comment = false;
				$i++;
			} elseif ($in_comment) {
				$preceding_comment .= $query[$i];
			} else {
				break; // done parsing
			}
		}

		// do we have any hints?
		if (stristr($preceding_comment, static::HINT_MASTER)) {
			$this->last = $this->master;
			$this->lastUsedHost = 'master';
			return $this->master;
		} elseif (stristr($preceding_comment, static::HINT_SLAVE)) {
			return $this->getSlave();
		} elseif (stristr($preceding_comment, static::HINT_LAST_USED)) {
			return $this->last;
		}

		// no applicable hints
		$query = strtoupper(trim(substr($query, $i)));
		$tokens = explode(' ', $query);

		$command = $tokens[0];
		if (in_array($command, static::MASTER_COMMANDS)) {
			$this->last = $this->master;
			$this->lastUsedHost = 'master';
			return $this->master;
		}

		// SELECT ... FOR UPDATE?
		$trailer = implode(' ', array_slice($tokens, count($tokens) - 2));
		$trailer = rtrim($trailer, ';');

		if ($command == 'SELECT' && $trailer == 'FOR UPDATE') {
			$this->last = $this->master;
			$this->lastUsedHost = 'master';
			return $this->master;
		}

		// use a slave
		return $this->getSlave();
	}

	/**
	 * @return PDO
	 * @throws \Exception
	 */
	protected function getSlave() {
		if (empty($this->dbConfig['slaves'])) {
			return $this->master;
		}

		// pick one at random
		$names = array_keys($this->dbConfig['slaves']);
		$name = $names[rand(0, count($names) - 1)];

		if (!isset($this->slaves[$name])) {
			$this->slaves[$name] = $this->getPDO($name);
		}

		$this->lastUsedHost = $name;
		$this->last = $this->slaves[$name];
		return $this->last;
	}

	/**
	 * @return bool
	 */
	public function beginTransaction() {
		return $this->master->beginTransaction();
	}

	/**
	 * @return bool
	 */
	public function commit() {
		return $this->master->commit();
	}

	/**
	 * @return string
	 */
	public function errorCode() {
		// for some reason this is necessary to make IntelliJ stop whining
		/** @var PDO $last */
		$last = $this->last;
		return $last->errorCode();
	}

	/**
	 * @return array
	 */
	public function errorInfo() {
		// for some reason this is necessary to make IntelliJ stop whining
		/** @var PDO $last */
		$last = $this->last;
		return $last->errorInfo();
	}

	/**
	 * @param string $statement
	 * @return int
	 * @throws \Exception
	 */
	public function exec($statement) {
		return $this->getQueryConnection($statement)->exec($statement);
	}

	/**
	 * @param int $attribute
	 * @return mixed
	 */
	public function getAttribute($attribute) {
		return isset($this->attributes[$attribute]) ? $this->attributes[$attribute] : null;
	}

	/**
	 * @return array
	 */
	public static function getAvailableDrivers() {
		return ['mysql'];
	}

	/**
	 * @return bool
	 */
	public function inTransaction() {
		return $this->master->inTransaction();
	}

	/**
	 * @param null|string $name
	 * @return string
	 */
	public function lastInsertId($name = null) {
		return $this->master->lastInsertId($name);
	}

	/**
	 * @param string $statement
	 * @param array $driver_options
	 * @return \PDOStatement
	 * @throws \Exception
	 */
	public function prepare($statement, array $driver_options = array()) {
		return $this->getQueryConnection($statement)->prepare($statement, $driver_options);
	}

	/**
	 * @param string $statement
	 * @param int $mode
	 * @param null $arg3
	 * @param array $ctorargs
	 * @return \PDOStatement
	 * @throws \Exception
	 */
	public function query($statement, $mode = PDO::ATTR_DEFAULT_FETCH_MODE, $arg3 = null, array $ctorargs = array()) {
		$db = $this->getQueryConnection($statement);
		return call_user_func_array([$db, 'query'], func_get_args());
	}

	/**
	 * @param string $string
	 * @param int $parameter_type
	 * @return string
	 */
	public function quote($string, $parameter_type = PDO::PARAM_STR) {
		return $this->master->quote($string, $parameter_type);
	}

	/**
	 * @return bool
	 */
	public function rollBack() {
		return $this->master->rollBack();
	}

	/**
	 * @param int $attribute
	 * @param mixed $value
	 * @return bool
	 */
	public function setAttribute($attribute, $value) {
		$this->attributes[$attribute] = $value;
		$ret = $this->master->setAttribute($attribute, $value);
		foreach ($this->slaves as $slave) {
			$ret = $slave->setAttribute($attribute, $value) && $ret;
		}

		return $ret;
	}
}
