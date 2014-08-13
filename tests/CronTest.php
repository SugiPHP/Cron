<?php
/**
 * @package    SugiPHP
 * @subpackage Cron
 * @category   tests
 * @author     Plamen Popov <tzappa@gmail.com>
 * @license    http://opensource.org/licenses/mit-license.php (MIT License)
 */

namespace SugiPHP\Cron;

use SugiPHP\Cron\Cron;
use PHPUnit_Framework_TestCase;

class CronTest extends PHPUnit_Framework_TestCase
{
	public function testCreateWithoutParams()
	{
		$cron = new Cron();
		$this->assertEmpty($cron->getAllJobs());
		$this->assertEmpty($cron->getJobs());
	}

	public function testCreateWithConfigFile()
	{
		$cron = new Cron(["file" => __DIR__."/cron.conf"]);
		$this->assertCount(8, $cron->getAllJobs());
		// Starting jobs (getJobs() method) can vary depending on current time
	}

	public function testJobsToStartBasedOnTime()
	{
		$cron = new Cron(["file" => __DIR__."/cron.conf"]);

		// 00 seconds
		$cron->setCurrentTime(strtotime("2013-09-24 11:47:00"));
		$jobs = $cron->getJobs();
		$this->assertCount(1, $jobs);
		$this->assertSame("foo.php 1", $jobs[0]["command"]);

		// 01 seconds
		$cron->setCurrentTime(strtotime("2013-09-24 11:47:01"));
		$jobs = $cron->getJobs();
		$this->assertCount(1, $jobs);
		$this->assertSame("foo.php 1", $jobs[0]["command"]);

		// 59 seconds
		$cron->setCurrentTime(strtotime("2013-09-24 11:47:59"));
		$jobs = $cron->getJobs();
		$this->assertCount(1, $jobs);
		$this->assertSame("foo.php 1", $jobs[0]["command"]);

		// starts jobs with minutes: */2
		$cron->setCurrentTime(strtotime("2013-09-24 11:48"));
		$jobs = $cron->getJobs();
		$this->assertCount(2, $jobs);
		$this->assertSame("foo.php 3", $jobs[1]["command"]);

		// 17:00
		$cron->setCurrentTime(strtotime("2013-09-24 17:00"));
		$jobs = $cron->getJobs();
		$this->assertCount(3, $jobs);
		$this->assertSame("foo.php 4", $jobs[2]["command"]);

		// 15,45
		$cron->setCurrentTime(strtotime("2013-09-24 17:15"));
		$jobs = $cron->getJobs();
		$this->assertCount(2, $jobs);
		$this->assertSame("foo.php 6", $jobs[1]["command"]);
		$cron->setCurrentTime(strtotime("2013-09-24 17:45"));
		$jobs = $cron->getJobs();
		$this->assertCount(2, $jobs);
		$this->assertSame("foo.php 6", $jobs[1]["command"]);

		// every minute from 16:00 to 16:59
		$cron->setCurrentTime(strtotime("2013-09-24 16:00"));
		$jobs = $cron->getJobs();
		$this->assertCount(3, $jobs);
		$this->assertSame("foo.php 5", $jobs[2]["command"]);
		$cron->setCurrentTime(strtotime("2013-09-24 16:01"));
		$jobs = $cron->getJobs();
		$this->assertCount(2, $jobs);
		$this->assertSame("foo.php 5", $jobs[1]["command"]);

		// every day in 9:00, 9:30, 10:00, 10:30 ... to 15:30
		$cron->setCurrentTime(strtotime("2013-09-24 9:00"));
		$jobs = $cron->getJobs();
		$this->assertSame("foo.php 7", $jobs[2]["command"]);
		$cron->setCurrentTime(strtotime("2013-09-24 9:30"));
		$jobs = $cron->getJobs();
		$this->assertSame("foo.php 7", $jobs[2]["command"]);
		$cron->setCurrentTime(strtotime("2013-09-24 15:00"));
		$jobs = $cron->getJobs();
		$this->assertSame("foo.php 7", $jobs[2]["command"]);
		$cron->setCurrentTime(strtotime("2013-09-24 15:30"));
		$jobs = $cron->getJobs();
		$this->assertSame("foo.php 7", $jobs[2]["command"]);

		// every year September, 25th in 13:33
		$cron->setCurrentTime(strtotime("2013-09-25 13:33"));
		$jobs = $cron->getJobs();
		$this->assertSame("foo.php 9", $jobs[1]["command"]);
		$cron->setCurrentTime(strtotime("2012-09-25 13:33"));
		$jobs = $cron->getJobs();
		$this->assertSame("foo.php 9", $jobs[1]["command"]);
		$cron->setCurrentTime(strtotime("2024-09-25 13:33"));
		$jobs = $cron->getJobs();
		$this->assertSame("foo.php 9", $jobs[1]["command"]);
	}
}
