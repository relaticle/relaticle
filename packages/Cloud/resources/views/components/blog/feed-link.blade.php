@use(Relaticle\Ink\Ink)
<link rel="alternate" type="application/rss+xml" title="{{ __('Relaticle Engineering Blog') }}" href="{{ route(Ink::routeName('feed', Ink::requestLocale())) }}" />
