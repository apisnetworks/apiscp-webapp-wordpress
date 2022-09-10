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
 * Written by Matt Saladna <matt@apisnetworks.com>, August 2022
 */

namespace Module\Support\Webapps\App\Type\Wordpress\Reconfiguration;

use Module\Support\Webapps;
use Module\Support\Webapps\App\Reconfigurator;
use Module\Support\Webapps\App\Type\Wordpress\DefineReplace;
use Module\Support\Webapps\Contracts\ReconfigurableProperty;

class Language extends Reconfigurator implements ReconfigurableProperty
{
	public function handle(&$val): bool
	{
		$fn = function () use ($val) {
			return $this->wordpress_cli('language core install --activate %s', [$val], $this->app->getHostname(),
				$this->app->getPath());
		};
		if ($this->app->isInstalling()) {
			$this->callback($fn);
			return true;
		}

		return array_get($ret = $fn(), 'success') ?: error($ret['stderr']);
	}


	public function getValue()
	{
		if (null !== ($lang = $this->locateLanguageSetting())) {
			return $lang;
		}

		$dbConfig = $this->wordpress_db_config($this->app->getHostname(), $this->app->getPath());
		$db = Webapps::connectorFromCredentials($dbConfig);
		$rs = $db->query('SELECT option_value FROM `' . $dbConfig['prefix'] . 'options` WHERE option_name = \'WPLANG\'');
		return $rs->fetchColumn() ?: 'en_US';
	}

	private function createDefiner(): DefineReplace
	{
		$file = $this->app->getAppRoot() . '/wp-config.php';
		return DefineReplace::instantiateContexted($this->getAuthContext(), [$file]);
	}

	private function locateLanguageSetting(): ?string
	{
		$definer = $this->createDefiner();
		try {
			return $definer->get('WPLANG');
		} catch (\PhpParser\Error $e) {
			return null;
		} catch (\ArgumentError $e) {
			warn("Failed parsing %(file)s - does not exist", [
				'file' => $this->app->getAppRoot() . '/wp-config.php',
			]);

			return null;
		}

	}
}

