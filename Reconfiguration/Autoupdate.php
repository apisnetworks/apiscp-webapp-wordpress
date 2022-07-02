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
	 * Written by Matt Saladna <matt@apisnetworks.com>, July 2022
	 */


	namespace Module\Support\Webapps\App\Type\Wordpress\Reconfiguration;

	use Module\Support\Webapps\App\Type\Unknown\Reconfiguration\Autoupdate as AutoupdateBase;
	use Module\Support\Webapps\App\Type\Wordpress\DefineReplace;

	class Autoupdate extends AutoupdateBase
	{
		public function handle(&$val): bool
		{
			if (!parent::handle($val)) {
				return false;
			}

			$this->callback(function () use ($val) {
				// @TODO multipool user checks
				$poolOwner = $this->php_pool_owner();
				$definer = DefineReplace::instantiateContexted($this->getAuthContext(), [
					$this->app->getAppRoot() . '/wp-config.php'
				]);
				if (!$val && $poolOwner !== \Web_Module::WEB_USERNAME) {
					// same-user
					if (false === $definer->get('WP_AUTO_UPDATE_CORE')) {
						info("Setting %(directive)s to %(val)s in %(file)s", [
							'directive' => 'WP_AUTO_UPDATE_CORE',
							'val'       => "true",
							'file'      => $this->app->getAppRoot() . '/wp-config.php'
						]);
						$definer->set('WP_AUTO_UPDATE_CORE', true);
					}
				} else if ($val && $poolOwner === \Web_Module::WEB_USERNAME) {
					$check = $definer->get('WP_AUTO_UPDATE_CORE');
					if ($check !== false) {
						info("Setting %(directive)s to %(val)s in %(file)s", [
							'directive' => 'WP_AUTO_UPDATE_CORE',
							'val'       => "false",
							'file'      => $this->app->getAppRoot() . '/wp-config.php'
						]);
						$definer->set('WP_AUTO_UPDATE_CORE', false);
					}
				}

				return \is_bool($val);
			});

			return true;

		}
	}