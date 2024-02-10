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
 * Written by Matt Saladna <matt@apisnetworks.com>, February 2024
 */

namespace Module\Support\Webapps\App\Type\Wordpress\Reconfiguration;

use Module\Support\Webapps\App\Reconfigurator;
use Module\Support\Webapps\App\Type\Wordpress\Wpcli;
use Module\Support\Webapps\Contracts\ReconfigurableProperty;

class Maintenance extends Reconfigurator implements ReconfigurableProperty
{
	public function handle(&$val): bool
	{
		$instance = Wpcli::instantiateContexted($this->getAuthContext());
		$ret = $instance->exec($this->app->getAppRoot(),
			"maintenance-mode %s", [(bool)$val ? 'activate' : 'deactivate']);

		return $ret['success'] ?: error(coalesce($ret['stderr'], $ret['stdout']));
	}

	public function getValue()
	{
		$instance = Wpcli::instantiateContexted($this->getAuthContext());
		// unable to handle non-zero exit codes without direct access to \Util_Process
		$ret = $instance->exec($this->app->getAppRoot(), "maintenance-mode is-active || echo 0", [], []);
		return !str_starts_with($ret['stdout'], "0");
	}
}