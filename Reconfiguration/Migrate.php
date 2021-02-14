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
	class Migrate extends Reconfigurator implements ReconfigurableProperty
	{
		public function handle(&$val): bool
		{
			[$hostname, $path] = explode('/', $val . '/', 2);
			$path = rtrim($path, '/');
			$sslhostname = $this->web_normalize_hostname($hostname);

			if (array_get($this->getComponents(), 'scheme', 'http') === 'https' &&
				$this->ssl_contains_cn($hostname) &&
				!$this->letsencrypt_append($sslhostname))
			{
				// SSL certificate contains hostname, not a CF proxy
				return error("Failed SSL issuance");
			}
			$instance = Wpcli::instantiateContexted($this->getAuthContext());
			$ret = $instance->exec($this->app->getAppRoot(),
				"search-replace --precise --skip-columns=guid --regex '\b(?<!\.)%(olddomain)s%(oldpath)s\b' '%(newdomain)s%(newpath)s'", [
					'olddomain'   => array_get($this->getComponents(), 'host', $this->app->getHostname()),
					'oldpath'     => rtrim(array_get($this->getComponents(), 'path', ''), '/'),
					'newdomain'   => $sslhostname,
					'newpath'     => $path ? "/${path}" : $path
				]);

			if (!array_get($ret, 'success', false)) {
				return error(coalesce($ret['stderr'], $ret['stdout']));
			}
			$this->app->getAppMeta()->replace([
				'hostname' => $hostname,
				'path'     => $path
			]);
			return true;
		}

		public function getValue()
		{
			$components = $this->getComponents();
			return (($components['host'] ?? $this->app->getHostname()) . ($components['path'] ?? ''));
		}

		protected function getComponents(): array
		{
			static $components;
			if (null !== $components) {
				return $components;
			}
			$url = array_get(
				Wpcli::instantiateContexted($this->getAuthContext())->exec(
					$this->app->getAppRoot(),
					'option get siteurl'
				),
				'stdout',
				$this->app->getHostname()
			);

			return $components = parse_url(rtrim($url)) ?: [];
		}
	}