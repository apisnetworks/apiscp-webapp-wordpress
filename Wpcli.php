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


namespace Module\Support\Webapps\App\Type\Wordpress;

use Module\Support\Webapps\PhpWrapper;
use Symfony\Component\Yaml\Yaml;

class Wpcli extends PhpWrapper
{
	// primary domain document root
	const BIN = '/usr/share/pear/wp-cli.phar';

	// latest release
	const DOWNLOAD_URL = 'https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar';
	const CONFIG_PATH = '.wp-cli/config.yml';
	/**
	 * Run command against wp-cli
	 *
	 * @param string|null $path
	 * @param string      $cmd
	 * @param array       $args
	 * @param array       $env
	 * @return array|bool
	 */
	public function exec(?string $path, string $cmd, array $args = [], array $env = []): array
	{
		if (is_debug()) {
			$cmd = '--debug ' . $cmd;
		}

		if ($path) {
			$cmd = '--path=%(path)s ' . $cmd;
			$args['path'] = $path;
		}
		$ret = parent::exec($path, self::BIN . ' ' . $cmd, $args, ['SERVER_NAME' => $this->getAuthContext()->domain] + $env);
		// $from_email isn't always set, ensure WP can send via wp-includes/pluggable.php
		if (0 === strncmp(coalesce($ret['stderr'], $ret['stdout']), 'Error:', 6)) {
			// move stdout to stderr on error for consistency
			$ret['success'] = false;
			if (!$ret['stderr']) {
				$ret['stderr'] = $ret['stdout'];
			}
		}

		return $ret;
	}

	/**
	 * Update WP-CLI configuration
	 *
	 * @param array $vals
	 * @return bool
	 */
	public function setConfiguration(array $vals): bool
	{
		$path = $this->user_get_home() . '/' . self::CONFIG_PATH;
		if (!$this->file_exists(\dirname($path))) {
			$this->file_create_directory(\dirname($path));
		}

		$raw = '';
		if ($this->file_exists($path)) {
			$raw = $this->file_get_file_contents($path);
		}

		$yaml = Yaml::parse($raw);
		$yaml = array_merge((array)$yaml, $vals);
		return $this->file_put_file_contents($path, Yaml::dump($yaml)) > 0;
	}
}