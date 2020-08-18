@component('email.indicator', ['status' => 'success'])
    Your application is installed.
@endcomponent

@component('mail::message')
{{-- Body --}}
# Howdy!

{{ $appname }} has been successfully installed on {{ $uri }}!

## Admin panel
You can access the panel at [{{$proto}}{{$uri}}/wp-admin]({{$proto}}{{$uri}}/wp-admin) using the following information:

**Login**: <code>{{ $login }}</code><br/>
**Password**: <code>{{ str_replace('@', '\\@', $password) }}</code>

---

When installing plugins or themes, you will be required to enter your {{ PANEL_BRAND }}
password. It is the same password used to login to this panel.

Security is important with any application, so extra steps are taken to reduce
the risk of hackers. By default **Maximum** Fortification is enabled. This will
work for most people, but if you run into any problems change Fortification to
**Minimum**.

Here's how to do it:

1. Visit **Web** > **Web Apps** in {{PANEL_BRAND}}
2. Select {{ $appname }} installed under **{{$uri}}**
3. Select **Fortification (MIN)** under _Actions_

You can learn more about [Fortification technology]({{MISC_KB_BASE}}/control-panel/understanding-fortification/) within the [knowledgebase]({{MISC_KB_BASE}}).

@include('email.webapps.common-footer')
@endcomponent