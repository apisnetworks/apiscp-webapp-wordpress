<div class="form-group">
	<label class="custom-control custom-checkbox form-group mb-1 mr-0 d-block">
		<input type="hidden" name="cache" value="0"/>
		<input type="checkbox" name="cache"
		       class="custom-control-input form-check-input" value="1"
		       @if (array_get($app->getOptions(), 'cache', true)) checked="CHECKED" @endif />
		<span class="custom-control-indicator"></span>
		Enable accelerated content delivery
		&ndash;
		<span class="ui-action-tooltip" data-toggle="tooltip"
		      title="Use W3TC caching plugin to accelerate delivery by up to 30x">
            <abbr class="small text-uppercase">Help</abbr>
        </span>
	</label>
</div>
