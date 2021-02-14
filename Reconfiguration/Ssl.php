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

use Module\Support\Webapps\App\Type\Unknown\Reconfiguration\Ssl as SslParent;
use Module\Support\Webapps\App\Type\Wordpress\Wpcli;

class Ssl extends SslParent
{
	public function handle(&$val): bool
	{
		if (!parent::handle($val)) {
			return false;
		}

		$instance = Wpcli::instantiateContexted($this->getAuthContext());
		$ret = $instance->exec($this->app->getAppRoot(), 'search-replace --skip-columns=guid --precise %(oldp)s://%(domain)s %(newp)s://%(domain)s', [
			'oldp' => $val ? 'http' : 'https',
			'newp' => $val ? 'https' : 'http',
			'domain' => $this->app->getHostname()
		]);

		return $ret['success'] ?: error("Failed to convert links to SSL: %s", coalesce($ret['stderr'], $ret['stdout']));
	}
}

