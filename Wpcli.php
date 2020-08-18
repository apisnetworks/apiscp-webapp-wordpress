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

use Opcenter\Filesystem;
use Symfony\Component\Yaml\Yaml;

class Wpcli {
	use \apnscpFunctionInterceptorTrait;
	use \ContextableTrait;

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
	public function exec(?string $path, string $cmd, array $args = [], array $env = [])
	{
		// client may override tz, propagate to bin
		$tz = $this->getAuthContext()->timezone;
		$cli = 'php -d display_errors=' . (is_debug() ? 'on' : 'off') . ' -d mysqli.default_socket=' . escapeshellarg(ini_get('mysqli.default_socket')) .
			' -d date.timezone=' . $tz . ' -d memory_limit=128m ' . self::BIN;
		if (!is_array($args)) {
			$args = array_slice(func_get_args(), 2);
		}
		$user = $this->getAuthContext()->username;

		if (is_debug()) {
			$cmd = '--debug ' . $cmd;
		}

		if ($path) {
			$cmd = '--path=%(path)s --skip-packages ' . $cmd;
			$args['path'] = $path;
			$user = $this->getDocrootUser($path);
		}
		$cmd = $cli . ' ' . $cmd;
		// $from_email isn't always set, ensure WP can send via wp-includes/pluggable.php
		$ret = $this->pman_run($cmd, $args, ['SERVER_NAME' => $this->getAuthContext()->domain] + $env, ['user' => $user]);
		if (0 === strncmp(coalesce($ret['stderr'], $ret['stdout']), 'Error:', 6)) {
			// move stdout to stderr on error for consistency
			$ret['success'] = false;
			if (!$ret['stderr']) {
				$ret['stderr'] = $ret['stdout'];
			}
		}

		return $ret;
	}

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

	protected function getDocrootUser(string $docroot): string
	{
		if (!($this->getAuthContext()->level & PRIVILEGE_SITE)) {
			return $this->getAuthContext()->username;
		}
		$stat = $this->file_stat($docroot);
		if (!$stat) {
			return $this->getAuthContext()->username;
		}
		// don't change if system user
		if ($stat['uid'] < \apnscpFunctionInterceptor::get_autoload_class_from_module('user')::MIN_UID) {
			return $this->getAuthContext()->username;
		}

		if (!($username = $this->user_get_username_from_uid($stat['uid']))) {
			return $this->getAuthContext()->username;
		}

		return $username;
	}

}