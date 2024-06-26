<?php declare(strict_types=1);
	/**
	 *  +------------------------------------------------------------+
	 *  | apnscp                                                     |
	 *  +------------------------------------------------------------+
	 *  | Copyright (c) Apis Networks                                |
	 *  +------------------------------------------------------------+
	 *  | Licensed under Artistic License 2.0                        |
	 *  +------------------------------------------------------------+
	 *  | Author: Matt Saladna (msaladna@apisnetworks.com)           |
	 *  +------------------------------------------------------------+
	 */

	use Module\Support\Webapps\App\Loader;
	use Module\Support\Webapps\App\Type\Wordpress\DefineReplace;
	use Module\Support\Webapps\App\Type\Wordpress\Wpcli;
	use Module\Support\Webapps\ComposerWrapper;
	use Module\Support\Webapps\DatabaseGenerator;
	use Module\Support\Webapps\Git;
	use Module\Support\Webapps\Messages;
	use Module\Support\Webapps\MetaManager;
	use Opcenter\Versioning;

	/**
	 * WordPress management
	 *
	 * An interface to wp-cli
	 *
	 * @package core
	 */
	class Wordpress_Module extends \Module\Support\Webapps
	{
		const APP_NAME = 'WordPress';
		const ASSET_SKIPLIST = '.wp-update-skip';

		const VERSION_CHECK_URL = 'https://api.wordpress.org/core/version-check/1.7/';
		const PLUGIN_VERSION_CHECK_URL = 'https://api.wordpress.org/plugins/info/1.0/%plugin%.json';
		const THEME_VERSION_CHECK_URL = 'https://api.wordpress.org/themes/info/1.2/?action=theme_information&request[slug]=%theme%&request[fields][versions]=1';
		const DEFAULT_VERSION_LOCK = 'none';

		protected $aclList = array(
			'min' => array(
				'wp-content',
				'.htaccess',
				'wp-config.php'
			),
			'max' => array(
				'wp-content/uploads',
				'wp-content/cache',
				'wp-content/wflogs',
				'wp-content/updraft'
			)
		);

		/**
		 * @var array files detected by Wordpress when determining write-access
		 */
		protected $controlFiles = [
			'/wp-admin/includes/file.php'
		];

		/**
		 * @var array list of plugin/theme types that cannot be updated manually
		 */
		const NON_UPDATEABLE_TYPES = [
			'dropin',
			'must-use'
		];

		/**
		 * Install WordPress
		 *
		 * @param string $hostname domain or subdomain to install WordPress
		 * @param string $path     optional path under hostname
		 * @param array  $opts     additional install options
		 * @return bool
		 */
		public function install(string $hostname, string $path = '', array $opts = array()): bool
		{
			if (!$this->mysql_enabled()) {
				return error(Messages::ERR_INSTALL_MISSING_PREREQ,
					['what' => 'MySQL', 'app' => static::APP_NAME]);
			}

			if (!$this->parseInstallOptions($opts, $hostname, $path)) {
				return false;
			}

			$docroot = $this->getDocumentRoot($hostname, $path);

			$ret = $this->execCommand(
				$docroot,
				'core %(mode)s --version=%(version)s',
				[
					'mode'    => 'download',
					'version' => $opts['version'],
					'user'    => $opts['user']
				]
			);

			if (!$ret['success']) {
				return error(
					Messages::ERR_APP_DOWNLOAD_FAILED,
					[
						'app' => static::APP_NAME,
						'version' => $opts['version'],
						'msg' => coalesce($ret['stdout'], $ret['stderr'])
					]
				);
			}

			$dbCred = DatabaseGenerator::mysql($this->getAuthContext(), $hostname);
			if (!$dbCred->create()) {
				return false;
			}

			if (!$this->generateNewConfiguration($hostname, $docroot, $dbCred)) {
				info(Messages::MSG_CHECKPOINT_REMOVING_TEMP_FILES);
				if (!array_get($opts, 'hold')) {
					$this->file_delete($docroot, true);
					$dbCred->rollback();
				}
				return false;
			}

			if (!isset($opts['title'])) {
				$opts['title'] = 'A Random Blog for a Random Reason';
			}

			if (!isset($opts['password'])) {
				$opts['password'] = \Opcenter\Auth\Password::generate();
				info(Messages::MSG_CHECKPOINT_GENERATED_PASSWORD, ['password' => $opts['password']]);
			}

			info(Messages::MSG_CHECKPOINT_SET_USERNAME, ['user' => $this->username]);
			// fix situations when installed on global subdomain
			$fqdn = $this->web_normalize_hostname($hostname);
			$opts['url'] = rtrim($fqdn . '/' . $path, '/');
			$args = array(
				'email'    => $opts['email'],
				'mode'     => 'install',
				'url'      => $opts['url'],
				'title'    => $opts['title'],
				'user'     => $opts['user'],
				'password' => $opts['password'],
				'proto'    => !empty($opts['ssl']) ? 'https://' : 'http://',
				'mysqli81' => 'function_exists("mysqli_report") && mysqli_report(0);'
			);
			$ret = $this->execCommand($docroot, 'core %(mode)s --admin_email=%(email)s --skip-email ' .
				'--url=%(proto)s%(url)s --title=%(title)s --admin_user=%(user)s --exec=%(mysqli81)s ' .
				'--admin_password=%(password)s', $args);
			if (!$ret['success']) {
				if (!array_get($opts, 'hold')) {
					$dbCred->rollback();
				}
				return error(Messages::ERR_DATABASE_CREATION_FAILED, coalesce($ret['stderr'], $ret['stdout']));
			}

			// @TODO - move to post-install wrapper
			// Setting meta concludes UI progress check,
			// postpone so features like git are present in UI without refresh
			if (!file_exists($this->domain_fs_path() . "/${docroot}/.htaccess")) {
				$this->file_touch("${docroot}/.htaccess");
			}

			$wpcli = Wpcli::instantiateContexted($this->getAuthContext());
			$wpcli->setConfiguration(['apache_modules' => ['mod_rewrite']]);

			$ret = $this->execCommand($docroot, "rewrite structure --hard '/%%postname%%/'");
			if (!$ret['success']) {
				// @TODO WP-specific error
				return error('failed to set rewrite structure, error: %s', coalesce($ret['stderr'], $ret['stdout']));
			}

			if (!empty($opts['cache'])) {
				if ($this->install_plugin($hostname, $path, 'w3-total-cache')) {
					$wpcli->exec($docroot, 'w3-total-cache option set pgcache.enabled true --type=boolean');
					$wpcli->exec($docroot, 'w3-total-cache fix_environment');
					$httxt = preg_replace(
						'/^\s*AddType\s+.*$[\r\n]?/mi',
						'',
						$this->file_get_file_contents($docroot . '/.htaccess')
					);
					$this->file_put_file_contents($docroot . '/.htaccess', $httxt);
				} else {
					warn("Failed to install caching plugin - performance will be suboptimal");
				}
			}
			if (!$this->file_exists($docroot . '/wp-content/cache')) {
				$this->file_create_directory($docroot . '/wp-content/cache');
			}
			// by default, let's only open up ACLs to the bare minimum
			$this->notifyInstalled($hostname, $path, $opts);

			return info(Messages::MSG_CHECKPOINT_APP_INSTALLED, ['app' => static::APP_NAME, 'email' => $opts['email']]);
		}

		protected function execCommand(?string $path, string $cmd, array $args = [], array $env = [])
		{
			return Wpcli::instantiateContexted($this->getAuthContextFromDocroot($path ?? \Web_Module::MAIN_DOC_ROOT))->exec($path, $cmd, $args, $env);
		}

		protected function generateNewConfiguration(string $domain, string $docroot, DatabaseGenerator $dbcredentials, array $ftpcredentials = array()): bool
		{
			// generate db
			if (!isset($ftpcredentials['user'])) {
				$ftpcredentials['user'] = $this->username . '@' . $this->domain;
			}
			if (!isset($ftpcredentials['host'])) {
				$ftpcredentials['host'] = 'localhost';
			}
			if (!isset($ftpcredentials['password'])) {
				$ftpcredentials['password'] = '';
			}
			$svc = \Opcenter\SiteConfiguration::shallow($this->getAuthContext());
			$xtraPHP = (string)(new \Opcenter\Provisioning\ConfigurationWriter('@webapp(wordpress)::templates.wp-config-extra', $svc))->compile([
				'svc' => $svc,
				'afi' => $this->getApnscpFunctionInterceptor(),
				'db'  => $dbcredentials,
				'ftp' => [
						'username' => $ftpcredentials['user'],
						'hostname' => 'localhost',
						'password' => $ftpcredentials['password']
					],
				'hostname' => $domain,
				'docroot'  => $docroot
			]);

			$xtraphp = '<<EOF ' . "\n" . $xtraPHP . "\n" . 'EOF';
			$args = array(
				'mode'     => 'config',
				'db'       => $dbcredentials->database,
				'password' => $dbcredentials->password,
				'user'     => $dbcredentials->username
			);

			$ret = $this->execCommand($docroot,
				'core %(mode)s --dbname=%(db)s --dbpass=%(password)s --dbuser=%(user)s --dbhost=localhost --extra-php ' . $xtraphp,
				$args);
			if (!$ret['success']) {
				return error('failed to generate configuration, error: %s', coalesce($ret['stderr'], $ret['stdout']));
			}

			return true;
		}

		/**
		 * Get installed version
		 *
		 * @param string $hostname
		 * @param string $path
		 * @return string version number
		 */
		public function get_version(string $hostname, string $path = ''): ?string
		{
			if (!$this->valid($hostname, $path)) {
				return null;
			}
			$docroot = $this->getAppRoot($hostname, $path);
			$ret = $this->execCommand($docroot, 'core version');
			if (!$ret['success']) {
				return null;
			}

			return trim($ret['stdout']);

		}

		/**
		 * Location is a valid WP install
		 *
		 * @param string $hostname or $docroot
		 * @param string $path
		 * @return bool
		 */
		public function valid(string $hostname, string $path = ''): bool
		{
			if ($hostname[0] === '/') {
				$docroot = $hostname;
			} else {
				$docroot = $this->getAppRoot($hostname, $path);
				if (!$docroot) {
					return false;
				}
			}

			return $this->file_exists($docroot . '/wp-config.php') || $this->file_exists($docroot . '/wp-config-sample.php');
		}

		/**
		 * Restrict write-access by the app
		 *
		 * @param string $hostname
		 * @param string $path
		 * @param string $mode
		 * @param array  $args
		 * @return bool
		 */
		public function fortify(string $hostname, string $path = '', string $mode = 'max', $args = []): bool
		{
			if (!parent::fortify($hostname, $path, $mode, $args)) {
				return false;
			}

			$docroot = $this->getAppRoot($hostname, $path);
			if ($mode === 'min') {
				// allow direct access on min to squelch FTP dialog
				$this->shareOwnershipSystemCheck($docroot);
			} else {
				// flipping from min to max, reset file check
				$this->assertOwnershipSystemCheck($docroot);
			}

			$this->setFsMethod($docroot, $mode);

			return true;
		}

		/**
		 * Update FS_METHOD
		 *
		 * @param string $approot
		 * @param string|false $mode
		 * @return bool
		 */
		protected function setFsMethod(string $approot, $mode): bool
		{
			$method = \in_array($mode, [false, 'learn', 'write', null /* release */], true) ? 'direct' : false;
			return $this->updateConfiguration($approot, ['FS_METHOD' => $method]);
		}

		/**
		 * Replace configuration with new values
		 *
		 * @param string $approot
		 * @param array  $pairs
		 * @return bool
		 */
		protected function updateConfiguration(string $approot, array $pairs): bool
		{
			$file = $approot . '/wp-config.php';
			try {
				$instance = DefineReplace::instantiateContexted($this->getAuthContext(), [$file]);
				foreach ($pairs as $k => $v) {
					$instance->set($k, $v);
				}
				return $instance->save();
			} catch (\PhpParser\Error $e) {
				return warn("Failed parsing %(file)s - cannot update %(directive)s",
					['file' => $file, 'directive' => 'FS_METHOD']);
			} catch (\ArgumentError $e) {
				return warn("Failed parsing %(file)s - does not exist");
			}

			return false;
		}

		/**
		 * Share ownership of a WordPress install allowing WP write-access in min fortification
		 *
		 * @param string $docroot
		 * @return int num files changed
		 */
		protected function shareOwnershipSystemCheck(string $docroot): int
		{
			$changed = 0;
			$options = $this->getOptions($docroot);
			if (!array_get($options, 'fortify', 'min')) {
				return $changed;
			}
			$user = array_get($options, 'user', $this->getDocrootUser($docroot));
			$webuser = $this->web_get_user($docroot);
			foreach ($this->controlFiles as $file) {
				$path = $docroot . $file;
				if (!file_exists($this->domain_fs_path() . $path)) {
					continue;
				}
				$this->file_chown($path, $webuser);
				$this->file_set_acls($path, $user, 6);
				$changed++;
			}

			return $changed;
		}

		/**
		 * Change ownership over to WordPress admin
		 *
		 * @param string $docroot
		 * @return int num files changed
		 */
		protected function assertOwnershipSystemCheck(string $docroot): int
		{
			$changed = 0;
			$options = $this->getOptions($docroot);
			$user = array_get($options, 'user', $this->getDocrootUser($docroot));
			foreach ($this->controlFiles as $file) {
				$path = $docroot . $file;
				if (!file_exists($this->domain_fs_path() . $path)) {
					continue;
				}
				$this->file_chown($path, $user);
				$changed++;
			}

			return $changed;
		}

		/**
		 * Enumerate plugin states
		 *
		 * @param string      $hostname
		 * @param string      $path
		 * @param string|null $plugin optional plugin
		 * @return array|bool
		 */
		public function plugin_status(string $hostname, string $path = '', string $plugin = null)
		{
			$docroot = $this->getAppRoot($hostname, $path);
			if (!$docroot) {
				return error('invalid WP location');
			}

			$matches = $this->assetListWrapper($docroot, 'plugin', [
				'name',
				'status',
				'version',
				'update_version'
			]);

			if (!$matches) {
				return false;
			}

			$pluginmeta = [];

			foreach ($matches as $match) {
				if (\in_array($match['status'], self::NON_UPDATEABLE_TYPES , true)) {
					continue;
				}
				$name = $match['name'];
				$version = $match['version'];
				if (!$versions = $this->pluginVersions($name)) {
					// commercial plugin
					if (empty($match['update_version'])) {
						$match['update_version'] = $match['version'];
					}

					$versions = [$match['version'], $match['update_version']];
				}
				$pluginmeta[$name] = [
					'version' => $version,
					'next'    => Versioning::nextVersion($versions, $version),
					'max'     => $this->pluginInfo($name)['version'] ?? end($versions),
					'active'  => $match['status'] !== 'inactive'
				];
				// dev version may be present
				$pluginmeta[$name]['current'] = version_compare((string)array_get($pluginmeta, "${name}.max",
					'99999999.999'), (string)$version, '<=') ?:
					(bool)Versioning::current($versions, $version);
			}

			return $plugin ? $pluginmeta[$plugin] ?? error("unknown plugin `%s'", $plugin) : $pluginmeta;
		}

		protected function assetListWrapper(string $approot, string $type, array $fields): ?array {
			$ret = $this->execCommand($approot,
				$type . ' list --format=json --fields=%s', [implode(',', $fields)]);
			// filter plugin garbage from Elementor, et al
			// enqueued updates emits non-JSON in stdout
			$line = strtok($ret['stdout'], "\n");
			do {
				if ($line[0] === '[') {
					break;
				}
			} while (false !== ($line = strtok("\n")));
			if (!$ret['success']) {
				error('failed to get %s status: %s', $type, coalesce($ret['stderr'], $ret['stdout']));
				return null;
			}

			if (null === ($matches = json_decode(str_replace(':""', ':null', $ret['stdout']), true))) {
				dlog('Failed decode results: %s', var_export($ret, true));
				return nerror('Failed to decode %s output', $type);
			}

			return $matches;
		}

		protected function pluginVersions(string $plugin): ?array
		{
			$info = $this->pluginInfo($plugin);
			if (!$info || empty($info['versions'])) {
				return null;
			}
			array_forget($info, 'versions.trunk');

			return array_keys($info['versions']);
		}

		/**
		 * Get information about a plugin
		 *
		 * @param string $plugin
		 * @return array
		 */
		protected function pluginInfo(string $plugin): array
		{
			$cache = \Cache_Super_Global::spawn();
			$key = 'wp.pinfo-' . $plugin;
			if (false !== ($data = $cache->get($key))) {
				return $data;
			}
			$url = str_replace('%plugin%', $plugin, static::PLUGIN_VERSION_CHECK_URL);
			$info = [];
			$contents = silence(static function() use($url) {
				return file_get_contents($url);
			});
			if (false !== $contents) {
				$info = (array)json_decode($contents, true);
				if (isset($info['versions'])) {
					uksort($info['versions'], 'version_compare');
				}
			} else {
				info("Plugin `%s' detected as commercial. Using transient data.", $plugin);
			}
			$cache->set($key, $info, 86400);

			return $info;
		}

		/**
		 * Install and activate plugin
		 *
		 * @param string $hostname domain or subdomain of wp install
		 * @param string $path     optional path component of wp install
		 * @param string $plugin   plugin name
		 * @param string $version  optional plugin version
		 * @return bool
		 */
		public function install_plugin(
			string $hostname,
			string $path,
			string $plugin,
			string $version = ''
		): bool {
			$docroot = $this->getAppRoot($hostname, $path);
			if (!$docroot) {
				return error('invalid WP location');
			}

			$args = array(
				'plugin' => $plugin
			);
			$cmd = 'plugin install %(plugin)s --activate';
			if ($version) {
				$cmd .= ' --version=%(version)s';
				$args['version'] = $version;
			}

			$ret = $this->execCommand(
				$docroot,
				$cmd,
				$args,
				[
					'REQUEST_URI' => '/' . rtrim($path)
				]
			);
			if (!$ret['success']) {
				return error("failed to install plugin `%s': %s", $plugin, coalesce($ret['stderr'], $ret['stdout']));
			}
			info("installed plugin `%s'", $plugin);

			return true;
		}

		/**
		 * Uninstall a plugin
		 *
		 * @param string $hostname
		 * @param string $path
		 * @param string $plugin plugin name
		 * @param bool   $force  delete even if plugin activated
		 * @return bool
		 */
		public function uninstall_plugin(string $hostname, string $path, string $plugin, bool $force = false): bool
		{
			$docroot = $this->getAppRoot($hostname, $path);
			if (!$docroot) {
				return error('invalid WP location');
			}

			$args = array(
				'plugin' => $plugin
			);
			$cmd = 'plugin uninstall %(plugin)s';
			if ($force) {
				$cmd .= ' --deactivate';
			}
			$ret = $this->execCommand($docroot, $cmd, $args);

			if (!$ret['stdout'] || !strncmp($ret['stdout'], 'Warning:', strlen('Warning:'))) {
				return error("failed to uninstall plugin `%s': %s", $plugin, coalesce($ret['stderr'], $ret['stdout']));
			}
			info("uninstalled plugin `%s'", $plugin);

			return true;
		}

		/**
		 * Disable plugin
		 *
		 * @param string $hostname
		 * @param string $path
		 * @param string $plugin
		 * @return bool
		 */
		public function disable_plugin(string $hostname, string $path, string $plugin): bool
		{
			$docroot = $this->getAppRoot($hostname, $path);
			if (!$docroot) {
				return error('invalid WP location');
			}

			return $this->assetManagerWrapper($docroot, 'plugin', 'deactivate', $plugin);
		}

		/**
		 * Enable plugin
		 *
		 * @param string $hostname
		 * @param string $path
		 * @param string $plugin
		 * @return bool
		 */
		public function enable_plugin(string $hostname, string $path, string $plugin): bool
		{
			$docroot = $this->getAppRoot($hostname, $path);
			if (!$docroot) {
				return error('invalid WP location');
			}

			return $this->assetManagerWrapper($docroot, 'plugin', 'activate', $plugin);
		}

		/**
		 * Disable theme
		 *
		 * @param string $hostname
		 * @param string $path
		 * @param string $plugin
		 * @return bool
		 */
		public function disable_theme(string $hostname, string $path, string $plugin): bool
		{
			$docroot = $this->getAppRoot($hostname, $path);
			if (!$docroot) {
				return error('invalid WP location');
			}

			return $this->assetManagerWrapper($docroot, 'theme', 'disable', $plugin);
		}

		/**
		 * Enable theme
		 *
		 * @param string $hostname
		 * @param string $path
		 * @param string $plugin
		 * @return bool
		 */
		public function enable_theme(string $hostname, string $path, string $plugin): bool
		{
			$docroot = $this->getAppRoot($hostname, $path);
			if (!$docroot) {
				return error('invalid WP location');
			}

			return $this->assetManagerWrapper($docroot, 'theme', 'activate', $plugin);
		}

		private function assetManagerWrapper(string $docroot, string $type, string $mode, string $asset): bool
		{
			$ret = $this->execCommand($docroot, '%s %s %s', [$type, $mode, $asset]);

			return $ret['success'] ?: error("Failed to %(mode)s `%(asset)s': %(err)s", [
				'mode' => $mode, 'asset' => $asset, 'err' => coalesce($ret['stderr'], $ret['stdout'])
			]);
		}


		/**
		 * Remove a Wordpress theme
		 *
		 * @param string $hostname
		 * @param string $path
		 * @param string $theme
		 * @param bool   $force unused
		 * @return bool
		 */
		public function uninstall_theme(string $hostname, string $path, string $theme, bool $force = false): bool
		{
			$docroot = $this->getAppRoot($hostname, $path);
			if (!$docroot) {
				return error('invalid WP location');
			}

			$args = array(
				'theme' => $theme
			);
			if ($force) {
				warn('Force parameter unused - deactivate theme first through WP panel if necessary');
			}
			$cmd = 'theme uninstall %(theme)s';
			$ret = $this->execCommand($docroot, $cmd, $args);

			if (!$ret['stdout'] || !strncmp($ret['stdout'], 'Warning:', strlen('Warning:'))) {
				return error("failed to uninstall plugin `%s': %s", $theme, coalesce($ret['stderr'], $ret['stdout']));
			}
			info("uninstalled theme `%s'", $theme);

			return true;
		}

		/**
		 * Recovery mode to disable all plugins
		 *
		 * @param string $hostname subdomain or domain of WP
		 * @param string $path     optional path
		 * @return bool
		 */
		public function disable_all_plugins(string $hostname, string $path = ''): bool
		{
			$docroot = $this->getAppRoot($hostname, $path);
			if (!$docroot) {
				return error('failed to determine path');
			}

			$ret = $this->execCommand($docroot, 'plugin deactivate --all --skip-plugins');
			if (!$ret['success']) {
				return error('failed to deactivate all plugins: %s', coalesce($ret['stderr'], $ret['stdout']));
			}

			return info('plugin deactivation successful: %s', $ret['stdout']);
		}

		/**
		 * Uninstall WP from a location
		 *
		 * @param        $hostname
		 * @param string $path
		 * @param string $delete "all", "db", or "files"
		 * @return bool
		 */
		public function uninstall(string $hostname, string $path = '', string $delete = 'all'): bool
		{
			return parent::uninstall($hostname, $path, $delete);
		}

		/**
		 * Get database configuration for a blog
		 *
		 * @param string $hostname domain or subdomain of wp blog
		 * @param string $path     optional path
		 * @return array|bool
		 */
		public function db_config(string $hostname, string $path = '')
		{
			$docroot = $this->getAppRoot($hostname, $path);
			if (!$docroot) {
				return error('failed to determine WP');
			}
			$code = 'ob_start(); register_shutdown_function(static function() { global $table_prefix; file_put_contents("php://fd/3", serialize(array("user" => DB_USER, "password" => DB_PASSWORD, "db" => DB_NAME, "host" => DB_HOST, "prefix" => $table_prefix))); ob_get_level() && ob_clean(); die(); }); include("./wp-config.php"); die();';
			$ret = \Module\Support\Webapps\PhpWrapper::instantiateContexted($this->getAuthContextFromDocroot($docroot))->exec(
				$docroot, '-r %(code)s 3>&1-', ['code' => $code],
			);

			if (empty($ret['stdout']) && !$ret['success']) {
				return error("failed to obtain WP configuration for `%s': %s", $docroot, $ret['stderr']);
			}

			return \Util_PHP::unserialize(trim($ret['stdout']));
		}

		/**
		 * Change WP admin credentials
		 *
		 * $fields is a hash whose indices match wp_update_user
		 * common fields include: user_pass, user_login, and user_nicename
		 *
		 * @link https://codex.wordpress.org/Function_Reference/wp_update_user
		 *
		 * @param string $hostname
		 * @param string $path
		 * @param array  $fields
		 * @return bool
		 */
		public function change_admin(string $hostname, string $path, array $fields): bool
		{
			$docroot = $this->getAppRoot($hostname, $path);
			if (!$docroot) {
				return warn('failed to change administrator information');
			}
			$admin = $this->get_admin($hostname, $path);

			if (!$admin) {
				return error('cannot determine admin of WP install');
			}

			if (isset($fields['user_login'])) {
				return error('user login field cannot be changed in WP');
			}

			$args = array(
				'user' => $admin
			);
			$cmd = 'user update %(user)s';
			foreach ($fields as $k => $v) {
				$cmd .= ' --' . $k . '=%(' . $k . ')s';
				$args[$k] = $v;
			}

			$ret = $this->execCommand($docroot, $cmd, $args);
			if (!$ret['success']) {
				return error("failed to update admin `%s', error: %s",
					$admin,
					coalesce($ret['stderr'], $ret['stdout'])
				);
			}

			return $ret['success'];
		}

		/**
		 * Get the primary admin for a WP instance
		 *
		 * @param string      $hostname
		 * @param null|string $path
		 * @return string admin or false on failure
		 */
		public function get_admin(string $hostname, string $path = ''): ?string
		{
			$docroot = $this->getAppRoot($hostname, $path);
			$ret = $this->execCommand($docroot, 'user list --role=administrator --field=user_login');
			if (!$ret['success'] || !$ret['stdout']) {
				warn('failed to enumerate WP administrative users');

				return null;
			}

			return strtok($ret['stdout'], "\r\n");
		}

		/**
		 * Update core, plugins, and themes atomically
		 *
		 * @param string $hostname subdomain or domain
		 * @param string $path     optional path under hostname
		 * @param string $version
		 * @return bool
		 */
		public function update_all(string $hostname, string $path = '', string $version = null): bool
		{
			$docroot = $this->getAppRoot($hostname, $path);
			if (is_dir($this->domain_fs_path($docroot . '/wp-content/upgrade'))) {
				// ensure upgrade/ is writeable. WP may create the directory if permissions allow
				// during a self-directed upgrade
				$ctx = null;
				$stat = $this->file_stat($docroot);
				if (!$stat || !$this->file_set_acls($docroot . '/wp-content/upgrade', [
						[$stat['owner'] => 'rwx'],
						[$stat['owner'] => 'drwx']
					], File_Module::ACL_MODE_RECURSIVE)) {
					warn('Failed to apply ACLs for %s/wp-content/upgrade. WP update may fail', $docroot);
				}
			}
			$ret = ($this->update_themes($hostname, $path) && $this->update_plugins($hostname, $path) &&
					$this->update($hostname, $path, $version)) || error('failed to update all components');

			$this->setInfo($this->getDocumentRoot($hostname, $path), [
				'version' => $this->get_version($hostname, $path),
				'failed'  => !$ret
			]);

			return $ret;
		}

		/**
		 * Get next asset version
		 *
		 * @param string $name
		 * @param array  $assetInfo
		 * @param string $lock
		 * @param string $type theme or plugin
		 * @return null|string
		 */
		private function getNextVersionFromAsset(string $name, array $assetInfo, string $lock, string $type): ?string
		{
			if (!isset($assetInfo['version'])) {
				error("Unable to query version for %s `%s', ignoring. Asset info: %s",
					ucwords($type),
					$name,
					var_export($assetInfo, true)
				);

				return null;
			}

			$version = $assetInfo['version'];
			$versions = $this->{$type . 'Versions'}($name) ?? [$assetInfo['version'], $assetInfo['max']];
			$next = $this->windThroughVersions($version, $lock, $versions);
			if ($next === null && end($versions) !== $version) {
				info("%s `%s' already at maximal version `%s' for lock spec `%s'. " .
					'Newer versions available. Manually upgrade or disable version lock to ' .
					'update this component.',
					ucwords($type), $name, $version, $lock
				);
			}

			return $next;
		}

		/**
		 * Move pointer through versions finding the next suitable candidate
		 *
		 * @param string      $cur
		 * @param null|string $lock
		 * @param array       $versions
		 * @return string|null
		 */
		private function windThroughVersions(string $cur, ?string $lock, array $versions): ?string
		{
			$maximal = $tmp = $cur;
			do {
				$tmp = $maximal;
				$maximal = Versioning::nextSemanticVersion(
					$tmp,
					$versions,
					$lock
				);
			} while ($maximal && $tmp !== $maximal);

			if ($maximal === $cur) {
				return null;
			}

			return $maximal;
		}

		/**
		 * Update WordPress themes
		 *
		 * @param string $hostname subdomain or domain
		 * @param string $path     optional path under hostname
		 * @param array  $themes
		 * @return bool
		 */
		public function update_themes(string $hostname, string $path = '', array $themes = array()): bool
		{
			$docroot = $this->getAppRoot($hostname, $path);
			if (!$docroot) {
				return error('update failed');
			}
			$flags = [];
			$lock = $this->getVersionLock($docroot);
			$skiplist = $this->getSkiplist($docroot, 'theme');

			if (!$skiplist && !$themes && !$lock) {
				$ret = $this->execCommand($docroot, 'theme update --all ' . implode(' ', $flags));
				if (!$ret['success']) {
					return error("theme update failed: `%s'", coalesce($ret['stderr'], $ret['stdout']));
				}

				return $ret['success'];
			}

			$status = 1;
			if (false === ($allthemeinfo = $this->theme_status($hostname, $path))) {
				return false;
			}
			$themes = $themes ?: array_keys($allthemeinfo);
			foreach ($themes as $theme) {
				$version = null;
				$name = $theme['name'] ?? $theme;
				$themeInfo = $allthemeinfo[$name];
				if ((isset($skiplist[$name]) || $themeInfo['current']) && !array_get((array)$theme, 'force')) {
					continue;
				}

				if (isset($theme['version'])) {
					$version = $theme['version'];
				} else if ($lock && !($version = $this->getNextVersionFromAsset($name, $themeInfo, $lock, 'theme'))) {
					// see if 'next' will satisfy the requirement
					continue;
				}

				$cmd = 'theme update %(name)s';
				$args = [
					'name' => $name
				];

				// @XXX theme update --version=X.Y.Z NAME
				// bad themes (better-wp-security) will induce false positives on remote versions
				// if a version is specified, pass this explicitly to force an update
				// see wp-cli issue #370

				$cmdTmp = $cmd;
				if ($version) {
					$cmd .= ' --version=%(version)s';
					$args['version'] = $version;
				}

				$cmd .= ' ' . implode(' ', $flags);
				$ret = $this->execCommand($docroot, $cmd, $args);

				if (!$ret['success'] && $version) {
					warn(
						"Update failed for %s, falling back to versionless update: %s",
						$name,
						coalesce($ret['stderr'], $ret['stdout'])
					);
					$cmdTmp .= ' ' . implode(' ', $flags);
					$ret = $this->execCommand($docroot, $cmdTmp, $args);
				}

				if (!$ret['success']) {
					error("failed to update theme `%s': %s", $name, coalesce($ret['stderr'], $ret['stdout']));
				}
				$status &= $ret['success'];
			}

			return (bool)$status;
		}

		/**
		 * Get update protection list
		 *
		 * @param string $docroot
		 * @param string|null $type
		 * @return array
		 */
		protected function getSkiplist(string $docroot, ?string $type)
		{
			if ($type !== null && $type !== 'plugin' && $type !== 'theme') {
				error("Unrecognized skiplist type `%s'", $type);

				return [];
			}
			$skiplist = $this->skiplistContents($docroot);

			return array_flip(array_filter(array_map(static function ($line) use ($type) {
				if (false !== ($pos = strpos($line, ':'))) {
					if (!$type || strpos($line, $type . ':') === 0) {
						return substr($line, $pos + 1);
					}

					return;
				}

				return $line;
			}, $skiplist)));
		}

		private function skiplistContents(string $approot): array
		{
			$skipfile = $this->domain_fs_path($approot . '/' . self::ASSET_SKIPLIST);
			if (!file_exists($skipfile)) {
				return [];
			}

			return (array)file($skipfile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
		}

		/**
		 * Update WordPress plugins
		 *
		 * @param string $hostname domain or subdomain
		 * @param string $path     optional path within host
		 * @param array  $plugins  flat list of plugins or multi-dimensional of name, force, version
		 * @return bool
		 */
		public function update_plugins(string $hostname, string $path = '', array $plugins = array()): bool
		{
			$docroot = $this->getAppRoot($hostname, $path);
			if (!$docroot) {
				return error('update failed');
			}
			$flags = [];
			$lock = $this->getVersionLock($docroot);
			$skiplist = $this->getSkiplist($docroot, 'plugin');

			if (!$plugins && !$skiplist && !$lock) {
				$ret = $this->execCommand($docroot, 'plugin update --all ' . implode(' ', $flags));
				if (!$ret['success']) {
					return error("plugin update failed: `%s'", coalesce($ret['stderr'], $ret['stdout']));
				}

				return $ret['success'];
			}

			$status = 1;
			if (false === ($allplugininfo = $this->plugin_status($hostname, $path))) {
				return false;
			}
			$plugins = $plugins ?: array_keys($allplugininfo);
			foreach ($plugins as $plugin) {

				$version = null;
				$name = $plugin['name'] ?? $plugin;
				$pluginInfo = $allplugininfo[$name];
				if ((isset($skiplist[$name]) || $pluginInfo['current']) && !array_get((array)$plugin, 'force')) {
					continue;
				}

				if (isset($plugin['version'])) {
					$version = $plugin['version'];
				} else if ($lock && !($version = $this->getNextVersionFromAsset($name, $pluginInfo, $lock, 'plugin'))) {
					// see if 'next' will satisfy the requirement
					continue;
				}

				$cmd = 'plugin update %(name)s';
				$args = [
					'name' => $name
				];
				// @XXX plugin update --version=X.Y.Z NAME
				// bad plugins (better-wp-security) will induce false positives on remote versions
				// if a version is specified, pass this explicitly to force an update
				// see wp-cli issue #370
				//
				// confirm with third party checks

				$cmdTmp = $cmd;
				if ($version) {
					$cmd .= ' --version=%(version)s';
					$args['version'] = $version;
				}
				$cmd .= ' ' . implode(' ', $flags);
				$ret = $this->execCommand($docroot, $cmd, $args);

				if (!$ret['success'] && $version) {
					warn(
						"Update failed for %s, falling back to versionless update: %s",
						$name,
						coalesce($ret['stderr'], $ret['stdout'])
					);
					$cmdTmp .= ' ' . implode(' ', $flags);
					$ret = $this->execCommand($docroot, $cmdTmp, $args);
				}

				if (!$ret['success']) {
					error("failed to update plugin `%s': %s", $name, coalesce($ret['stderr'], $ret['stdout']));
				}
				$status &= $ret['success'];
			}

			return (bool)$status;
		}

		/**
		 * Update WordPress to latest version
		 *
		 * @param string $hostname domain or subdomain under which WP is installed
		 * @param string $path     optional subdirectory
		 * @param string $version
		 * @return bool
		 */
		public function update(string $hostname, string $path = '', string $version = null): bool
		{
			$docroot = $this->getAppRoot($hostname, $path);
			if (!$docroot) {
				return error('update failed');
			}
			$this->assertOwnershipSystemCheck($docroot);

			$cmd = 'core update';
			$args = [];

			if ($version) {
				if (!is_scalar($version) || strcspn($version, '.0123456789')) {
					return error('invalid version number, %s', $version);
				}
				$cmd .= ' --version=%(version)s';
				$args['version'] = $version;

				$ret = $this->execCommand($docroot, 'option get WPLANG');
				if (trim($ret['stdout']) === 'en') {
					// issue seen with Softaculous installing under "en" locale, which generates
					// an invalid update URI
					warn('Bugged WPLANG setting. Changing en to en_US');
					$this->execCommand($docroot, 'site switch-language en_US');
				}
			}

			$oldversion = $this->get_version($hostname, $path);
			$ret = $this->execCommand($docroot, $cmd, $args);

			if (!$ret['success']) {
				$output = coalesce($ret['stderr'], $ret['stdout']);
				if (str_starts_with($output, 'Error: Download failed.')) {
					return warn('Failed to fetch update - retry update later: %s', $output);
				}

				return error("update failed: `%s'", coalesce($ret['stderr'], $ret['stdout']));
			}

			// Sanity check as WP-CLI is known to fail while producing a 0 exit code
			if ($oldversion === $this->get_version($hostname, $path) &&
				!$this->is_current($oldversion, Versioning::asMajor($oldversion))) {
				return error('Failed to update WordPress - old version is same as new version - %s! ' .
					'Diagnostics: (stderr) %s (stdout) %s', $oldversion, $ret['stderr'], $ret['stdout']);
			}

			info('updating WP database if necessary');
			$ret = $this->execCommand($docroot, 'core update-db');
			$this->shareOwnershipSystemCheck($docroot);

			if (!$ret['success']) {
				return warn('failed to update WP database - ' .
					'login to WP admin panel to manually perform operation');
			}

			return $ret['success'];
		}

		/**
		 * Get theme status
		 *
		 * Sample response:
		 * [
		 *  hestia => [
		 *      version => 1.1.50
		 *      next => 1.1.51
		 *      current => false
		 *      max => 1.1.66
		 *  ]
		 * ]
		 *
		 * @param string      $hostname
		 * @param string      $path
		 * @param string|null $theme
		 * @return array|bool
		 */
		public function theme_status(string $hostname, string $path = '', string $theme = null)
		{
			$docroot = $this->getAppRoot($hostname, $path);
			if (!$docroot) {
				return error('invalid WP location');
			}

			$matches = $this->assetListWrapper($docroot, 'theme', [
				'name',
				'status',
				'version',
				'update_version'
			]);

			if (!$matches) {
				return false;
			}

			$themes = [];
			foreach ($matches as $match) {
				if (\in_array($match['status'], self::NON_UPDATEABLE_TYPES, true)) {
					continue;
				}
				$name = $match['name'];
				$version = $match['version'];
				if (!$versions = $this->themeVersions($name)) {
					// commercial themes
					if (empty($match['update_version'])) {
						$match['update_version'] = $match['version'];
					}

					$versions = [$match['version'], $match['update_version']];
				}

				$themes[$name] = [
					'version' => $version,
					'next'    => Versioning::nextVersion($versions, $version),
					'max'     => $this->themeInfo($name)['version'] ?? end($versions)
				];
				// dev version may be present
				$themes[$name]['current'] = version_compare((string)array_get($themes, "${name}.max",
					'99999999.999'), (string)$version, '<=') ?:
					(bool)Versioning::current($versions, $version);
			}

			return $theme ? $themes[$theme] ?? error("unknown theme `%s'", $theme) : $themes;
		}

		/**
		 * Get theme versions
		 *
		 * @param string $theme
		 * @return null|array
		 */
		protected function themeVersions($theme): ?array
		{
			$info = $this->themeInfo($theme);
			if (!$info || empty($info['versions'])) {
				return null;
			}
			array_forget($info, 'versions.trunk');

			return array_keys($info['versions']);
		}

		/**
		 * Get theme information
		 *
		 * @param string $theme
		 * @return array|null
		 */
		protected function themeInfo(string $theme): ?array
		{
			$cache = \Cache_Super_Global::spawn();
			$key = 'wp.tinfo-' . $theme;
			if (false !== ($data = $cache->get($key))) {
				return $data;
			}
			$url = str_replace('%theme%', $theme, static::THEME_VERSION_CHECK_URL);
			$info = [];
			$contents = silence(static function () use ($url) {
				return file_get_contents($url);
			});
			if (false !== $contents) {
				$info = (array)json_decode($contents, true);
				if (isset($info['versions'])) {
					uksort($info['versions'], 'version_compare');
				}
			} else {
				info("Theme `%s' detected as commercial. Using transient data.", $theme);
			}
			$cache->set($key, $info, 86400);

			return $info;
		}

		public function install_theme(string $hostname, string $path, string $theme, string $version = null): bool
		{
			$docroot = $this->getAppRoot($hostname, $path);
			if (!$docroot) {
				return error('invalid WP location');
			}

			$args = array(
				'theme' => $theme
			);
			$cmd = 'theme install %(theme)s --activate';
			if ($version) {
				$cmd .= ' --version=%(version)s';
				$args['version'] = $version;
			}
			$ret = $this->execCommand($docroot, $cmd, $args);
			if (!$ret['success']) {
				return error("failed to install theme `%s': %s", $theme, coalesce($ret['stderr'], $ret['stdout']));
			}
			info("installed theme `%s'", $theme);

			return true;
		}

		/**
		 * Relax permissions to allow write-access
		 *
		 * @param string $hostname
		 * @param string $path
		 * @return bool
		 * @internal param string $mode
		 */
		public function unfortify(string $hostname, string $path = ''): bool
		{
			return parent::unfortify($hostname, $path) && $this->setFsMethod($this->getAppRoot($hostname, $path), false);
		}

		/**
		 * Install wp-cli if necessary
		 *
		 * @return bool
		 * @throws \Exception
		 */
		public function _housekeeping()
		{
			if (file_exists(Wpcli::BIN) && filemtime(Wpcli::BIN) < filemtime(__FILE__)) {
				unlink(Wpcli::BIN);
			}

			if (!file_exists(Wpcli::BIN)) {
				$url = Wpcli::DOWNLOAD_URL;
				$tmp = tempnam(storage_path('tmp'), 'wp-cli') . '.phar';
				$res = Util_HTTP::download($url, $tmp);
				if (!$res) {
					file_exists($tmp) && unlink($tmp);

					return error('failed to install wp-cli module');
				}
				try {
					(new \Phar($tmp))->getSignature();
					rename($tmp, Wpcli::BIN) && chmod(Wpcli::BIN, 0755);
					info('downloaded wp-cli');
				} catch (\UnexpectedValueException $e) {
					return error('WP-CLI signature failed, ignoring update');
				} finally {
					if (file_exists($tmp)) {
						unlink($tmp);
					}
				}
				// older platforms
				$local = $this->service_template_path('siteinfo') . Wpcli::BIN;
				if (!file_exists($local) && !copy(Wpcli::BIN, $local)) {
					return false;
				}
				chmod($local, 0755);

			}

			if (is_dir($this->service_template_path('siteinfo'))) {
				$link = $this->service_template_path('siteinfo') . '/usr/bin/wp-cli';
				$local = $this->service_template_path('siteinfo') . Wpcli::BIN;
				if (!is_link($link) || realpath($link) !== realpath($local)) {
					is_link($link) && unlink($link);
					$referent = $this->file_convert_absolute_relative($link, $local);

					return symlink($referent, $link);
				}
			}

			return true;
		}

		/**
		 * Get all available WordPress versions
		 *
		 * @return array versions descending
		 */
		public function get_versions(): array
		{
			$versions = $this->_getVersions();

			return array_reverse(array_column($versions, 'version'));
		}

		protected function mapFilesFromList(array $files, string $docroot): array
		{
			if (file_exists($this->domain_fs_path($docroot . '/wp-content'))) {
				return parent::mapFilesFromList($files, $docroot);
			}
			$path = $tmp = $docroot;
			// WP can allow relocation of assets, look for them
			$ret = \Module\Support\Webapps\PhpWrapper::instantiateContexted($this->getAuthContextFromDocroot($docroot))->exec(
				$docroot, '-r %(code)s', [
					'code' => 'set_error_handler(function() { echo defined("WP_CONTENT_DIR") ? constant("WP_CONTENT_DIR") : dirname(__FILE__); die(); }); include("./wp-config.php"); trigger_error("");define("ABS_PATH", "/dev/null");'
				]
			);

			if ($ret['success']) {
				$tmp = $ret['stdout'];
				if (0 === strpos($tmp, $this->domain_fs_path() . '/')) {
					$tmp = $this->file_unmake_path($tmp);
				}
			}

			if ($path !== $tmp) {
				$relpath = $this->file_convert_absolute_relative($docroot . '/wp-content/', $tmp);
				foreach ($files as $k => $f) {
					if (0 !== strncmp($f, 'wp-content/', 11)) {
						continue;
					}
					$f = $relpath . substr($f, strlen('wp-content'));
					$files[$k] = $f;
				}
			}

			return parent::mapFilesFromList($files, $docroot);
		}

		/**
		 * Get latest WP release
		 *
		 * @return string
		 */
		protected function _getLastestVersion()
		{
			$versions = $this->_getVersions();
			if (!$versions) {
				return null;
			}

			return $versions[0]['version'];
		}

		/**
		 * Get all current major versions
		 *
		 * @return array
		 */
		protected function _getVersions()
		{
			$key = 'wp.versions';
			$cache = Cache_Super_Global::spawn();
			if (false !== ($ver = $cache->get($key))) {
				return $ver;
			}
			$url = self::VERSION_CHECK_URL;
			$contents = file_get_contents($url);
			if (!$contents) {
				return array();
			}
			$versions = json_decode($contents, true);
			$versions = $versions['offers'];
			if (isset($versions[1]['version'], $versions[0]['version'])
				&& $versions[0]['version'] === $versions[1]['version']) {
				// WordPress sends most current + version tree
				array_shift($versions);
			}
			$cache->set($key, $versions, 43200);

			return $versions;
		}


		/**
		 * Get basic summary of assets
		 *
		 * @param string $hostname
		 * @param string $path
		 * @return array
		 */
		public function asset_summary(string $hostname, string $path = ''): array
		{
			if (!$approot = $this->getAppRoot($hostname, $path)) {
				return [];
			}

			$plugin = $this->assetListWrapper($approot, 'plugin', ['name', 'status', 'version', 'description', 'update_version']);
			$theme = $this->assetListWrapper($approot, 'theme', ['name', 'status', 'version', 'description', 'update_version']);
			$skippedtheme = $this->getSkiplist($approot, 'theme');
			$skippedplugin = $this->getSkiplist($approot, 'plugin');
			$merged = [];
			foreach (['plugin', 'theme'] as $type) {
				$skipped = ${'skipped' . $type};
				$assets = (array)${$type};
				usort($assets, static function ($a1, $a2) {
					return strnatcmp($a1['name'], $a2['name']);
				});
				foreach ($assets as &$asset) {
					if (\in_array($asset['status'], self::NON_UPDATEABLE_TYPES, true)) {
						continue;
					}
					$name = $asset['name'];
					$asset['skipped'] = isset($skipped[$name]);
					$asset['active'] = $asset['status'] !== 'inactive';
					$asset['type'] = $type;
					$merged[] = $asset;
				}
				unset($asset);
			}
			return $merged;
		}

		/**
		 * Skip updating an asset
		 *
		 * @param string      $hostname
		 * @param string      $path
		 * @param string      $name
		 * @param string|null $type
		 * @return bool
		 */
		public function skip_asset(string $hostname, string $path, string $name, ?string $type): bool
		{
			if (!$approot = $this->getAppRoot($hostname, $path)) {
				return error("App root for `%s'/`%s' does not exist", $hostname, $path);
			}

			$contents = implode("\n", $this->skiplistContents($approot));
			$contents .= "\n" . $type . ($type ? ':' : '') . $name;

			return $this->file_put_file_contents("${approot}/" . self::ASSET_SKIPLIST, ltrim($contents));
		}

		/**
		 * Permit updates of an asset
		 *
		 * @param string      $hostname
		 * @param string      $path
		 * @param string      $name
		 * @param string|null $type
		 * @return bool
		 */
		public function unskip_asset(string $hostname, string $path, string $name, ?string $type): bool
		{
			if (!$approot = $this->getAppRoot($hostname, $path)) {
				return error("App root for `%s'/`%s' does not exist", $hostname, $path);
			}

			$assets = $this->getSkiplist($approot, $type);

			if (!isset($assets[$name])) {
				return warn("%(type)s `%(asset)s' not present in skiplist", ['type' => $type, 'asset' => $name]);
			}

			$skiplist = array_flip($this->skiplistContents($approot));
			unset($skiplist["${type}:${name}"],$skiplist[$name]);
			return $this->file_put_file_contents("${approot}/" . self::ASSET_SKIPLIST, implode("\n", array_keys($skiplist)));
		}


		public function asset_skipped(string $hostname, string $path, string $name, ?string $type): bool
		{
			if (!$approot = $this->getAppRoot($hostname, $path)) {
				return error("App root for `%s'/`%s' does not exist", $hostname, $path);
			}

			$assets = $this->getSkiplist($approot, $type);
			return isset($assets[$name]);
		}

		/**
		 * Duplicate a WordPress instance
		 *
		 * @param string $shostname
		 * @param string $spath
		 * @param string $dhostname
		 * @param string $dpath
		 * @param array  $opts clean: (bool) purge target directory
		 * @return bool
		 */
		public function duplicate(string $shostname, string $spath, string $dhostname, string $dpath, array $opts = []): bool
		{
			if (!$this->valid($shostname, $spath)) {
				return error("%(hostname)s/%(path)s does not contain a valid %(app)s install",
					['hostname' => $shostname, 'path' => $spath, 'app' => static::APP_NAME]
				);
			}

			return (bool)serial(function () use ($spath, $dpath, $dhostname, $shostname, $opts) {
				$sapproot = $this->getAppRoot($shostname, $spath);
				$dapproot = $this->getAppRoot($dhostname, $dpath);
				// nesting directories is permitted, denesting will fail in checkDocroot() below
				// otherwise add reciprocal strpos() check
				if ($sapproot === $dapproot || 0 === strpos("${dapproot}/", "${sapproot}/")) {
					return error("Source `%(source)s' and target `%(target)s' are the same or nested",
						['source' => $sapproot, 'target' => $dapproot]);
				}

				if (!empty($opts['clean']) && is_dir($this->domain_fs_path($dapproot))) {
					if ($this->webapp_valid($dhostname, $dpath)) {
						$this->webapp_uninstall($dhostname, $dpath);
					} else {
						$this->file_delete($dapproot, true);
					}
				}

				$this->checkDocroot($dapproot);

				// @todo $opts['link-uploads']
				$this->file_copy("${sapproot}/", $dapproot, true);
				$db = DatabaseGenerator::mysql($this->getAuthContext(), $dhostname);
				$db->create();
				$db->autoRollback = true;
				$oldDbConfig = $this->db_config($shostname, $spath);
				$this->mysql_clone($oldDbConfig['db'], $db->database);
				$sapp = Loader::fromDocroot('wordpress', $sapproot, $this->getAuthContext());
				$dapp = Loader::fromDocroot('wordpress', $dapproot, $this->getAuthContext());

				$vals = array_diff_key(
					$this->get_reconfigurable($shostname, $spath, $sapp->getReconfigurables()),
					array_flip($dapp::TRANSIENT_RECONFIGURABLES)
				);
				info("Reconfiguring %s", implode(", ", array_key_map(static function ($k, $v) {
					if (is_bool($v)) {
						$v = $v ? "true" : "false";
					}
					return "$k => $v";
				}, $vals)));
				$dapp->reconfigure($vals);
				$this->updateConfiguration($dapproot, [
					'DB_NAME'     => $db->database,
					'DB_USER'     => $db->username,
					'DB_PASSWORD' => $db->password,
					'DB_HOST'     => $db->hostname,
				]);
				$db->autoRollback = false;
				$cli = Wpcli::instantiateContexted($this->getAuthContext());
				$cli->exec($dapproot, 'config shuffle-salts');
				$dapp->reconfigure(['migrate' => $dhostname . '/' . $dpath]);

				if ($dapp->hasGit()) {
					$git = Git::instantiateContexted(
						$this->getAuthContext(), [
							$dapp->getAppRoot(),
							MetaManager::factory($this->getAuthContext())->get($dapp->getDocumentRoot())
						]
					);
					$git->remove();
					$git->createRepository();
				}

				return null !== $this->webapp_discover($dhostname, $dpath);
			});
		}

		/**
		 * Install a WP-CLI package
		 *
		 * @param string $package
		 * @return bool
		 */
		public function install_package(string $package): bool
		{
			$cli = ComposerWrapper::instantiateContexted($this->getAuthContext());
			if (!$this->file_exists('~/.wp-cli/packages')) {
				$this->file_create_directory('~/.wp-cli/packages', 493, true);
				$cli->exec('~/.wp-cli/packages', 'init -n --name=local/wp-cli-packages -sdev --repository=https://wp-cli.org/package-index/');
			}
			$ret = $cli->exec('~/.wp-cli/packages', 'require %s', [$package]);


			return $ret['success'] ?:
				error("Failed to install %s: %s", $package, coalesce($ret['stderr'], $ret['stdout']));
		}

		/**
		 * WP-CLI package installed
		 *
		 * @param string $package
		 * @return bool
		 */
		public function package_installed(string $package): bool
		{
			$cli = Wpcli::instantiateContexted($this->getAuthContext());
			$ret = $cli->exec(null, 'package path %s', [$package]);
			return $ret['success'];
		}

		/**
		 * Uninstall WP-CLI package
		 *
		 * @param string $package
		 * @return bool
		 */
		public function uninstall_package(string $package): bool
		{
			$cli = ComposerWrapper::instantiateContexted($this->getAuthContext());
			$ret = $cli->exec('~/.wp-cli/packages', 'remove %s', [$package]);
			return $ret['success'] ?:
				error("Failed to uninstall %s: %s", $package, coalesce($ret['stderr'], $ret['stdout']));
		}

		/**
		 * Apply WP-CLI directive
		 *
		 * @param string       $command  directive
		 * @param array|string $args     formatted args
		 * @param string       $hostname hostname
		 * @param string       $path     subpath
		 * @return mixed hash of paths or single arraycomprised of @see pman:run() + ['hostname', 'path']
		 *
		 * Sample usage:
		 *
		 * `wordpress:cli "plugin uninstall dolly"`
		 * `wordpress:cli "plugin uninstall %s" ["dolly"]`
		 * - Remove dolly plugin from all WP sites
		 * `wordpress:cli "core verify-checksums" "" domain.com`
		 * - Run verify-checksums on core distribution against domain.com
		 * `wordpress:cli "--json plugin list"`
		 * - Report all plugins encoded in JSON
		 *
		 */
		public function cli(string $command, $args = [], string $hostname = null, string $path = ''): array
		{
			if (!$hostname) {
				$apps = (new \Module\Support\Webapps\Finder($this->getAuthContext()))->getApplicationsByType($this->getModule());
			} else {
				$docroot = $this->getDocumentRoot($hostname, $path);
				$apps = [$docroot => ['path' => $path, 'hostname' => $hostname]];
			}

			$wpcli = Wpcli::instantiateContexted($this->getAuthContext());
			$processed = [];
			foreach ($apps as $info) {
				if (!$this->valid($info['hostname'], $info['path'] ?? '')) {
					debug("%(host)/%(path)s is not valid %(type)s, skipping", [
						'host' => $info['hostname'],
						'path' => $info['path'] ?? '',
						'type' => $this->getModule()
					]);
				}
				$appRoot = $this->getAppRoot($info['hostname'], $info['path']);
				$ret = $wpcli->exec($appRoot, $command, (array)$args);

				$processed[$appRoot] = array_only($info, ['hostname', 'path']) + $ret;
			}

			return $hostname ? array_pop($processed) : $processed;
		}
	}
