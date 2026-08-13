<!doctype html>
<html>
<head>
    <title>{!! $metadata['title'] !!}</title>
    <meta charset="utf-8"/>
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"/>
    {{-- Same theme boot as the docs shell: honour the site-wide choice stored
         in localStorage, falling back to the OS preference. --}}
    <script>
        window.relaticleDarkMode = localStorage.getItem('theme') === 'dark'
            || ((!localStorage.getItem('theme') || localStorage.getItem('theme') === 'system') && window.matchMedia('(prefers-color-scheme: dark)').matches);
        document.documentElement.classList.toggle('dark', window.relaticleDarkMode);
    </script>
    <style>
        @font-face {
            font-family: 'Inter';
            src: url('/fonts/inter/InterVariable.woff2') format('woff2');
            font-weight: 100 900;
            font-display: swap;
        }
        body {
            margin: 0;
        }
        .relaticle-nav {
            position: sticky;
            top: 0;
            z-index: 100;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 1.5rem;
            height: 3.5rem;
            background: rgba(255, 255, 255, 0.85);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid rgba(229, 231, 235, 0.7);
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }
        .relaticle-nav-left {
            display: flex;
            align-items: center;
            gap: 0.625rem;
        }
        .relaticle-nav-left a {
            display: flex;
            align-items: center;
            text-decoration: none;
            color: inherit;
        }
        .relaticle-nav-brand {
            font-size: 0.9375rem;
            font-weight: 700;
            letter-spacing: -0.01em;
            color: #111827;
        }
        .relaticle-nav-sep {
            width: 1px;
            height: 0.875rem;
            background: #e5e7eb;
        }
        .relaticle-nav-title {
            font-size: 0.9375rem;
            font-weight: 600;
            letter-spacing: -0.01em;
            color: #6b7280;
        }
        .relaticle-nav-links {
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }
        .relaticle-nav-links a {
            font-size: 0.8125rem;
            font-weight: 500;
            color: #4b5563;
            text-decoration: none;
            padding: 0.375rem 0.75rem;
            border-radius: 0.5rem;
            transition: color 0.15s;
        }
        .relaticle-nav-links a:hover {
            color: #111827;
        }
        @media (max-width: 640px) {
            .relaticle-nav-links { display: none; }
        }
        html.dark .relaticle-nav {
            background: rgba(3, 7, 18, 0.85);
            border-bottom-color: rgba(255, 255, 255, 0.06);
        }
        html.dark .relaticle-nav-sep { background: rgba(255,255,255,0.15); }
        html.dark .relaticle-nav-brand { color: #fff; }
        html.dark .relaticle-nav-title { color: #9ca3af; }
        html.dark .relaticle-nav-links a { color: #9ca3af; }
        html.dark .relaticle-nav-links a:hover { color: #fff; }
    </style>
</head>
<body>

<nav class="relaticle-nav">
    <div class="relaticle-nav-left">
        <a href="/" aria-label="Relaticle Home">
            <img src="/brand/logomark.svg" alt="Relaticle" style="height:1.5rem;width:1.5rem;"/>
        </a>
        <span class="relaticle-nav-brand">Relaticle</span>
        <div class="relaticle-nav-sep"></div>
        <span class="relaticle-nav-title">API Reference</span>
    </div>
    <div class="relaticle-nav-links">
        <a href="/help">Help</a>
        <a href="/developers">Developers</a>
        <a href="/pricing">Pricing</a>
        <a href="https://github.com/Relaticle/relaticle" target="_blank" rel="noopener">GitHub</a>
    </div>
</nav>

<script
    id="api-reference"
@foreach($htmlAttributes as $attribute => $value)
    {{-- Attributes specified first override later ones --}}
    {!! $attribute !!}="{!! $value !!}"
@endforeach
    data-url="{!! $metadata['openapi_spec_url'] !!}">
</script>
{{-- Scalar boots in light mode by default; hand it the site's theme choice. --}}
<script>
    document.getElementById('api-reference').dataset.configuration = JSON.stringify({ darkMode: window.relaticleDarkMode });
</script>
<script src="https://cdn.jsdelivr.net/npm/@scalar/api-reference"></script>
</body>
</html>
