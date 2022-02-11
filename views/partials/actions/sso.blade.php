@if (\cmd('wordpress_package_installed', 'aaemnnosttv/wp-cli-login-command'))

	<div class="btn-group mb-3">
		@php
			$pluginActive = \Error_Reporter::silence(
				function () use ($app) {
					return array_get(\cmd('wordpress_plugin_status', $app->getHostname(), $app->getPath(), 'wp-cli-login-server'), 'active');
				}
			);
		@endphp
		@if ($pluginActive)
			<button name="wordpress-sso" type="submit"
			        class="btn btn-secondary " value="1" @if (\Page_Renderer::externalOpener()) rel="external" formtarget="{{ \Page_Renderer::externalOpenerTarget()  }}" @endif >
				<i class="fa fa-sign-in"></i>
				Single Sign-on
			</button>
		@else
			<button name="enable-sso" type="submit"
			        class="btn btn-secondary " value="1">
				<i class="fa fa-sign-in"></i>
				Activate SSO Support
			</button>
		@endif
		<button type="button" class="btn btn-secondary dropdown-toggle" data-toggle="dropdown" aria-haspopup="true"
		        aria-expanded="false">
			<span class="sr-only">Toggle Dropdown</span>
		</button>
		<div class="dropdown-menu dropdown-menu-right dropdown-menu-form">
			<button name="uninstall-package" type="submit"
			        class="btn-block dropdown-item ui-action ui-action-delete warn ui-action-label"
			        value="aaemnnosttv/wp-cli-login-command">
				Remove SSO Support
			</button>
		</div>
	</div>

@else
	<button name="install-sso" type="submit"
	        class="mb-3 btn btn-secondary " value="aaemnnosttv/wp-cli-login-command">
		<i class="fa fa-magic"></i>
		Enable SSO Support
	</button>
@endif