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

use Module\Support\Webapps\App\Type\Unknown\Reconfiguration\User as Base;
use Module\Support\Webapps\App\Type\Wordpress\DefineReplace;
use Module\Support\Webapps\Contracts\DeferredReconfiguration;

class User extends Base implements DeferredReconfiguration
{
	public function apply(mixed &$val): bool
	{
		$definer = $this->createDefiner();
		try {
			$definer->set("FTP_USER", "{$val}@{$this->app->getHostname()}");

			return $definer->save();
		} catch (\PhpParser\Error $e) {
			return error("Failed parsing %(file)s - cannot update %(directive)s", [
				'file'      => $this->app->getAppRoot() . '/wp-config.php',
				'directive' => 'FTP_USER'
			]);
		} catch (\ArgumentError $e) {
			return warn("Failed parsing %(file)s - does not exist", [
				'file' => $this->app->getAppRoot() . '/wp-config.php',
			]);
		}
	}


	private function createDefiner(): DefineReplace
	{
		$file = $this->app->getAppRoot() . '/wp-config.php';
		return DefineReplace::instantiateContexted($this->getAuthContext(), [$file]);
	}
}