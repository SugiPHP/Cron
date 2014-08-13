<?php
/**
 * @package    SugiPHP
 * @subpackage Cron
 * @author     Plamen Popov <tzappa@gmail.com>
 * @license    http://opensource.org/licenses/mit-license.php (MIT License)
 */

namespace SugiPHP\Cron;

/**
 * Cron jobs
 *
 * Cronjobs are configured via crontab files similar to *NIX style.
 * For example one configuration file can look like this:
 * <code>
 * # min    hour    day    month   dayofweek   command
 * # every minute
 * *        *       *      *       *           foo.php
 *
 * # every 2 minutes
 * /2       *       *      *       *           bar.php
 *
 * # not exceuted since it is commented
 * #30      *       *      *       *           bar.php
 *
 * # once per day in 17:00
 * 0        17      *      *       *           bar.php
 *
 * # every minute from 16:00 to 17:00
 * *        16      *      *       *           bar.php
 *
 * # every twice per hour in *:15 and in *:45
 * 15,45    *       *      *       *           foobar.php
 *
 * # every day in 9:00, 9:30, 10:00, 10:30 ... to 17:30
 * /30        9-17    *      *       *         work-hours.php
 *
 * # every day in 20:00, 21:00, 22:00 ... 6:00 and in 12:00
 * 0        20-6,12 *      *       *           at-night-and-at-noon.php
 * </code>
 *
 * Lines starting with # are considered comments and are not executable
 *
 * Cron entry file can be started via CLI or via web request (lynx, wget etc.)
 * It should be started every minute! Local cronjob is preferred
 *
 * <code>
 * * 	*	*	*	*	/usr/bin/php /var/www/example.com/app/files/cron.php >/dev/null 2>&1
 * # or
 * *	*	*	*	*	wget -O /dev/null http://example.com/cron
 * # or
 * *	*	*	*	*	lynx -source http://example.com/cron
 * # or
 * *	*	*	*	*	curl --silent --compressed http://example.com/cron
 * </code>
 */
class Cron
{
	protected $file;
	protected $searchPath = "./";
	protected $timestamp;
	protected $time = array();
	protected $jobs = array();
	protected $regEx;
	protected $jobStartCallback;
	protected $jobEndCallback;
	protected $jobErrorCallback;

	/**
	 * Constructor
	 *
	 * @param array $config
	 */
	public function __construct($config = array())
	{
		// regular exp for crontab file
		$this->regEx = $this->buildRegEx();

		// configuration file
		if (!empty($config["file"])) {
			$this->setFile($config["file"]);
		}

		if (!empty($config["search_path"])) {
			$this->setSearchPath($config["search_path"]);
		}

		if (isset($config["time"])) {
			$this->setCurrentTime($config["time"]);
		} else {
			$this->setCurrentTime(time()); // current time
		}
	}

	/**
	 * Destructor
	 */
	public function __destruct()
	{
		if ($this->file) {
			fclose($this->file);
		}
	}

	public function setFile($file)
	{
		// open the file and parse it
		$this->file = fopen($file, "r");
		$this->parseFile();
	}

	public function setSearchPath($path)
	{
		$this->searchPath = rtrim($path, "/\\") . DIRECTORY_SEPARATOR;
	}

	public function getSearchPath()
	{
		return $this->searchPath;
	}

	public function onJobStart(callable $callback)
	{
		$this->jobStartCallback = $callback;
	}

	public function onJobEnd(callable $callback)
	{
		$this->jobEndCallback = $callback;
	}

	public function onJobError(callable $callback)
	{
		$this->jobErrorCallback = $callback;
	}

	/**
	 * Start each cron task which has to start now
	 */
	public function proceed()
	{
		$jobs = $this->getJobs();

		// handle errors and transform them into ErrorExceptions
		set_error_handler(array($this, "errorHandler"));
		foreach ($jobs as $job) {
			$file = $job["command"];

			if ($this->jobStartCallback) {
				call_user_func_array($this->jobStartCallback, [$file]);
			}

			// each job will be in try/except block. If one job fails others will run (hopefully).
			try {
				include $this->getSearchPath().$file;
				if ($this->jobEndCallback) {
					call_user_func_array($this->jobEndCallback, [$file]);
				}
			} catch (\Exception $e) {
				if ($this->jobErrorCallback) {
					call_user_func_array($this->jobErrorCallback, [$file, $e]);
				}
			}
		}

		// restore old error handler
		restore_error_handler();
	}

	/**
	 * Returns cron tasks that should start now
	 *
	 * @return array
	 */
	public function getJobs()
	{
		$return = array();
		foreach ($this->jobs as $job) {
			if ($this->checkDOW($job["dow"])
				and $this->checkMonth($job["month"])
				and $this->checkDay($job["day"])
				and $this->checkHour($job["hour"])
				and $this->checkMin($job["min"])
			) {
				$return[] = $job;
			}
		}

		return $return;
	}

	/**
	 * Returns all cron tasks
	 *
	 * @return array
	 */
	public function getAllJobs()
	{
		return $this->jobs;
	}

	/**
	 * Sets the current time. This function is for testing purposes.
	 *
	 * @param integer $time UNIX TimeStamp
	 */
	public function setCurrentTime($time)
	{
		$this->timestamp = $time;
		$this->time = $this->parseTime($this->timestamp);
	}

	/**
	 * error handler function
	 */
	public function errorHandler($errno, $errstr, $errfile, $errline, $errcontext)
	{
		$errortype = array(
			E_ERROR             => "Error",
			E_WARNING           => "Warning",
			E_PARSE             => "Parse Error",
			E_NOTICE            => "Notice",
			E_CORE_ERROR        => "Core Error",
			E_CORE_WARNING      => "Core Warning",
			E_COMPILE_ERROR     => "Compile Error",
			E_COMPILE_WARNING   => "Compile Warning",
			E_USER_ERROR        => "User Error",
			E_USER_WARNING      => "User Warning",
			E_USER_NOTICE       => "User Notice",
			E_STRICT            => "Runtime Notice",
			E_RECOVERABLE_ERROR => "Catchable fatal error",
			E_DEPRECATED        => "Deprecated Notice",
			E_USER_DEPRECATED   => "User Dreprecated Notice",
		);

		throw new \ErrorException($errortype[$errno] . ": " .$errstr, 0, $errno, $errfile, $errline);

		// Don't execute PHP internal error handler
		// return true;
	}

	/**
	 * Parsing unix timestamp
	 *
	 * @param  integer $timestamp Unix Timestamp
	 * @return array
	 */
	protected function parseTime($timestamp)
	{
		$time = array();
		// mins, hours, days, months are for recursive tasks
		$time["min"]    = (int) date("i", $timestamp);
		$time["mins"]   = (int) floor($timestamp / 60);
		$time["hour"]   = (int) date("H", $timestamp);
		$time["hours"]  = (int) floor($timestamp / 3600);
		$time["day"]    = (int) date("d", $timestamp);
		$time["days"]   = (int) floor($timestamp / 86400);
		$time["month"]  = (int) date("m", $timestamp);
		$time["months"] = (int) floor($timestamp / 2592000);
		$time["dow"]    = (int) date("w", $timestamp);

		return $time;
	}

	/**
	 * Parses configuration file - cron jobs
	 *
	 * @return boolean - false on empty job list OR when no crontab file available
	 */
	protected function parseFile()
	{
		// clear all jobs
		$this->jobs = array();

		// is the file loaded
		if (!$this->file) {
			return false;
		}

		while (($row = fgets($this->file)) !== false) {
			// check for commented rows
			if (!$row = trim($row) or strpos($row, "#") === 0) {
				continue;
			}
			if ($res = preg_match($this->regEx, $row, $matches) !== 0) {
				$this->jobs[] = array(
					"min"     => $matches["min"],
					"hour"    => $matches["hour"],
					"day"     => $matches["day"],
					"month"   => $matches["month"],
					"dow"     => $matches["dow"],
					"command" => $matches["command"],
					// "start"   => $matches["start"],
				);
			} else {
				throw new \Exception("Invalid syntax in cron job $row");
			}
		}
	}

	protected function checkTimeValue($value, $min, $max, $time, $times)
	{
		// if we have lists
		$values = explode(",", $value);
		// for each instance of the list
		foreach ($values as $val) {
			// start always
			if ($val == "*") {
				return true;
			}
			// start at some interval
			if (strpos($val, "-") > 0) {
				list($from, $to) = explode("-", $val);
				// like 8-20
				if (($from <= $to) && ($time >= $from) && ($time <= $to)) {
					return true;
				}
				// like 21-7
				if (($from > $to) && (($time >= $from) || ($time <= $to))) {
					return true;
				}
			}
			// exact match
			if (($this->filterInt($val, $min, $max, false) !== false) && ($time == $val)) {
				return true;
			}
			// recursive
			if (($val = $this->checkRecursive($val)) && ($times % $val === 0)) {
				return true;
			}
		}

		return false;
	}


	protected function checkMonth($value)
	{
		return $this->checkTimeValue($value, 1, 12, $this->time["month"], $this->time["months"]);
	}

	protected function checkDay($value)
	{
		return $this->checkTimeValue($value, 0, 31, $this->time["day"], $this->time["days"]);
	}

	protected function checkHour($value)
	{
		return $this->checkTimeValue($value, 0, 23, $this->time["hour"], $this->time["hours"]);
	}

	protected function checkMin($value)
	{
		return $this->checkTimeValue($value, 0, 59, $this->time["min"], $this->time["mins"]);
	}

	protected function checkDOW($value)
	{
		$values = explode(",", $value);
		foreach ($values as $val) {
			if (($val == "*") || (($this->filterInt($val, 0, 7, false) !== false) && ($this->time["dow"] == $val))) {
				return true;
			}
		}

		return false;
	}

	protected function checkRecursive($value)
	{
		if (strpos($value, "/") === 0) {
			return substr($value, 1);
		}
		if (strpos($value, "*/") === 0) {
			return substr($value, 2);
		}

		return false;
	}

	/**
	 * Validates integer value.
	 *
	 * @param  mixed $value
	 * @param  integer $min
	 * @param  integer $max
	 * @param  mixed $default - this is what will be returned if the filter fails
	 * @return mixed
	 */
	protected function filterInt($value, $min = false, $max = false, $default = false)
	{
		$options = array("options" => array());
		if (isset($default)) {
			$options["options"]["default"] = $default;
		}
		if (!is_null($min) && ($min !== false)) {
			$options["options"]["min_range"] = $min;
		}
		if (!is_null($max) && ($max !== false)) {
			$options["options"]["max_range"] = $max;
		}

		return filter_var($value, FILTER_VALIDATE_INT, $options);
	}

	protected function buildRegEx()
	{
		$cols = array(
			"min"   => "[0-5]?\d",
			"hour"  => "[01]?\d|2[0-3]",
			"day"   => "0?[1-9]|[12]\d|3[01]",
			"month" => "[1-9]|1[012]",
			"dow"   => "[0-6]"
		);

		$regex = "";
		foreach ($cols as $field => $value) {
			$interval = "($value)(\-($value))?";
			$regex .= "(?<$field>($interval)(\,($interval))*|\*|\*?\/($value))\s+";
		}
		$regex .= "(?<command>.*)";

		return "~^$regex$~";
	}
}
