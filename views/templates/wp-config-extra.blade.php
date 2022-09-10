{{--
	Additional vars:
	$svc: SiteConfiguration instance
	$afi: apnscpFunctionInterceptor instance
	$db:  Module\Support\Webapps\DatabaseGenerator
	$ftp: array of FTP credentials
	$hostname: hostname
	$docroot: document root for  app
	Use web:extract_components_from_path() to get path component from $docroot

	Must be manually escaped.
--}}

// defer updates to CP
define('WP_AUTO_UPDATE_CORE', false);
@if ($afi->ftp_enabled() || $afi->ssh_enabled())
define('FTP_USER', {!! escapeshellarg($ftp['username']) !!});
define('FTP_HOST', {!! escapeshellarg($ftp['hostname']) !!});
@if (!empty($ftp['password']))
define('FTP_PASS', {!! escapeshellarg($ftp['password']) !!});
@endif
@endif
define('FTP_SSL', {{ FTP_SSL_ONLY ? 'true' : 'false' }});
define('FS_METHOD', false);
define('WP_POST_REVISIONS', 5);