<?php declare(strict_types=1);
	/**
	 * Copyright (C) Apis Networks, Inc - All Rights Reserved.
	 *
	 * Unauthorized copying of this file, via any medium, is
	 * strictly prohibited without consent. Any dissemination of
	 * material herein is prohibited.
	 *
	 * For licensing inquiries email <licensing@apisnetworks.com>
	 *
	 * Written by Matt Saladna <matt@apisnetworks.com>, July 2020
	 */


	namespace Module\Support\Webapps\App\Type\Wordpress\Reconfiguration;

	use Module\Support\Webapps\App\Reconfigurator;
	use Module\Support\Webapps\App\Type\Wordpress\Wpcli;
	use Module\Support\Webapps\Contracts\ReconfigurableProperty;

	/**
	 * Change domain/path for WordPress
	 *
	 * @package Module\Support\Webapps\App\Type\Wordpress\Reconfiguration
	 */
	class Duplicate extends Reconfigurator implements ReconfigurableProperty
	{
		public function handle(&$val): bool
		{
			[$hostname, $path] = explode('/', $val . '/', 2);
			$path = rtrim($path, '/');
			return $this->wordpress_duplicate($this->app->getHostname(), $this->app->getPath(), $hostname, $path, []);
		}
	}