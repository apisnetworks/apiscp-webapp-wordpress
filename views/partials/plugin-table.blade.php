<table class="table table-responsive">
	<thead>
	<th>
		Name
	</th>
	<th>
		Version
	</th>
	<th>
		Status
	</th>
	<th>
		Enabled
	</th>
	<th>
		Auto-update
	</th>
	</thead>
	@php
		$assets = \cmd('wordpress_asset_summary', $app->getHostname(), $app->getPath());
		$prev = null;
	@endphp
	@foreach ($assets as $asset)
		@if ($prev !== $asset['type'])
			<tbody>
			<tr>
				<th colspan="5" class="bg-light">
					<h6 class="mb-0">{{ $asset['type'] }}</h6>
				</th>
			</tr>
			@endif
			<tr class="asset-row">
				<td>
					<abbr class="ui-action ui-action-label fa-question mr-2" data-toggle="popover"
					      data-asset="{{ $asset['type'] }}" data-name="{{ $asset['name'] }}"
					      data-title="{{ $asset['name'] }}" data-content="{{ strip_tags($asset['description']) }}"
					>{{ $asset['name'] }}</abbr>
				</td>
				<td class="text-center version">
					{{ $asset['version'] }}
				</td>
				<td class="text-center version-status">
					@if (null === $asset['update_version'] )
						<i class="fa fa-check text-success font-weight-bold" title="Asset up-to-date"></i>
					@else
						<i class="fa fa-exclamation-triangle font-weight-bold text-danger asset-behind" title="Asset behind version"></i>
							{{ $asset['update_version'] }}
						<button title="{{ "Update" }}" data-current="{{ $asset['update_version'] }}"
						        data-asset="{{ $asset['type'] }}" name="update" value="{{ $asset['name'] }}"
						        class="btn btn-sm btn-secondary">
							<i class="ui-action ui-action-d-compact ui-action-update indicator"></i>
						</button>
					@endif
				</td>
				<td>
					<div class="d-flex">
						<label class="custom-switch custom-control mb-0 px-0 mx-auto">
							<input type="{{ $asset['type'] === 'theme' ? 'radio' : 'checkbox' }}"
							       name="{{ $asset['type'] === 'theme' ? '_THEME' : $asset['name'] }}"
							       class="custom-control-input filter-control"
							       data-asset="{{ $asset['type'] }}" data-name="{{ $asset['name'] }}"
							       data-mode="activate"
							       value="{{ $asset['type'] === 'theme' ? $asset['name'] : '1' }}"
							       @if ($asset['active']) CHECKED @endif />
							<span class="custom-control-indicator mr-0"></span>
						</label>
					</div>
				</td>
				<td>
					<div class="d-flex">
						<label class="mx-auto custom-switch custom-control mb-0 px-0">
							<input type="checkbox" name="{{ $asset['name'] }}"
							       class="custom-control-input filter-control"
							       data-asset="{{ $asset['type'] }}" data-name="{{ $asset['name'] }}"
							       data-mode="skiplist"
							       value="1" @if (!$asset['skipped']) CHECKED @endif />
							<span class="custom-control-indicator mr-0"></span>
						</label>
					</div>
				</td>
			</tr>
			@if ($prev !== $asset['type'] && $prev = $asset['type'])
			</tbody>
		@endif
	@endforeach
</table>

<i id="asset-current-template" class="d-none fa fa-check text-success font-weight-bold" title="Asset up-to-date"></i>