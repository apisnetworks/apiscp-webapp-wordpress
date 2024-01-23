<?php declare(strict_types=1);

namespace Module\Support\Webapps\App\Type\Wordpress;

class Sso
{
	use \ContextableTrait;
	use \apnscpFunctionInterceptorTrait;

	const PACKAGE_NAME = 'aaemnnosttv/wp-cli-login-command';
	protected Handler $app;

	protected function __construct(Handler $app)
	{
		$this->app = $app;
	}


	public function handle(): bool
	{
		$admin = $this->app->wordpress_get_admin($this->app->getHostname(), $this->app->getPath());
		if (!$admin) {
			return error("SSO failed. Cannot lookup admin");
		}
		$ret = Wpcli::instantiateContexted($this->getAuthContext())
			->exec($this->app->getAppRoot(), '--skip-themes login create --url-only %s', [$admin]);
		$magic = trim($ret['stdout']);
		if (!preg_match(\Regex::URL, $magic)) {
			// garbage output, possibly requiring wp-cli-login-command update
			// let's try it again
			$oldVersion = $this->loginCommandVersion();
			$ret = Wpcli::instantiateContexted($this->getAuthContext())->
				exec($this->app->getAppRoot(), 'package update %s', [self::PACKAGE_NAME]);

			if ($ret['success']) {
				info("Updating %s", self::PACKAGE_NAME);
				$this->enable();
			}

			if ($this->loginCommandVersion() === $oldVersion) {
				return error("Failed to upgrade %(package)s: %(err)s", [
					'package' => self::PACKAGE_NAME, 'err' => coalesce($ret['stderr'], $ret['stdout'])
				]);
			}
		}
		if (!$ret['success']) {
			return error("SSO failed. Cannot create session: %s", coalesce($ret['stderr'], $ret['stdout']));
		}

		\Util_HTTP::forwardNoProxy();

		$url = $this->discoverRedirectionUrl($magic);
		header("Location: " . $url, true, 302);
		exit(0);
	}

	private function loginCommandVersion(): ?string
	{
		$ret = Wpcli::instantiateContexted($this->getAuthContext())->exec($this->app->getAppRoot(),
			'package list --format=json ');
		foreach ((array)json_decode($ret['stdout'], true) as $item) {
			if ($item['name'] === self::PACKAGE_NAME) {
				return $item['version'];
			}
		};

		return null;
	}

	public function install(): bool
	{
		return $this->wordpress_install_package(static::PACKAGE_NAME);
	}

	public function enable(): bool
	{
		$ret = Wpcli::instantiateContexted($this->getAuthContext())
			->exec($this->app->getAppRoot(), '--skip-themes --skip-plugins login install --yes --activate');

		return $ret['success'] ?: error("Failed to activate SSO: %s", coalesce($ret['stderr'], $ret['stdout']));
	}

	/**
	 * Ascertain redirection URL on rewrite presence
	 *
	 * @param string $redirect
	 * @return string
	 */
	private function discoverRedirectionUrl(string $redirect): string
	{
		// WordPress can direct (foo.com) or indirect (foo.com/wp2 -> foo.com)
		// Likewise WordPress can be pathless (foo.com) and pathed (foo.com/wp -> foo.com/wp)
		// Lastly, WordPress can have a dispatcher/pretty-print URLs or not (foo.com/index.php/)
		$parts = parse_url($redirect);
		$url = $parts['scheme'] . '://' . $parts['host'];

		$pathComponents = explode('/', $parts['path']);

		$path = implode('/', array_slice($pathComponents, 0, -2));
		// Try parsing the suggested docroot first
		$roots = [$this->app->getDocumentRoot()];
		if (($testRoot = $this->app->web_get_docroot($parts['host'], $path)) && $testRoot !== current($roots)) {
			array_unshift($roots, $testRoot);
		}
		foreach ($roots as $root) {
			$htaccess = $root . '/.htaccess';

			if ($this->file_exists($htaccess) && false !== strpos($this->file_get_file_contents($htaccess),
					'/index.php')) {
				return $url . $parts['path'];
			}

			// No .htaccess
			$loginPathNormalized = ltrim($parts['path'], '/');
			$webappPathNormalized = ltrim($this->app->getPath(), '/');
			if ($this->file_exists($root . '/wp-config.php')) {
				warn("Pretty-print URLs not enabled in WordPress. SSO path likely incorrect");

				// strip common path component to insert /index.php dispatcher
				return $url . '/' . ltrim($this->app->getPath() . '/index.php',
						'/') . '/' . ltrim(substr($loginPathNormalized,
						strlen($webappPathNormalized)), '/');
			}
		}


		return $url . $parts['path'];
	}
}