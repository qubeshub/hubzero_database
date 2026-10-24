<?php
/**
 * @package    framework
 * @copyright  Copyright (c) 2005-2020 The Regents of the University of California.
 * @license    http://opensource.org/licenses/MIT MIT
 */

namespace Qubeshub\Database;

use Hubzero\Base\ServiceProvider;
use Config;

/**
 * Database service provider
 */
class DatabaseServiceProvider extends ServiceProvider
{
	/**
	 * Register the service provider
	 *
	 * @return  void
	 */
	public function register()
	{
		$this->app['db'] = function($app)
		{
			// @FIXME: this isn't pretty, but it will shim the removal of the old mysql library calls from php
			$driver = (Config::get('dbtype') == 'mysql') ? 'pdo' : Config::get('dbtype');

			$options = [
				'driver'   => $app['config']->get('dbtype'),
				'host'     => $app['config']->get('host'),
				'user'     => $app['config']->get('user'),
				'password' => $app['config']->get('password'),
				'database' => $app['config']->get('db'),
				'prefix'   => $app['config']->get('dbprefix'),
				'charset'  => $app['config']->get('dbcharset')
			];

			return Driver::getInstance($options);
		};
	}
}
