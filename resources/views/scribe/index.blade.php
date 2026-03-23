<!doctype html>
<html>
<head>
    <title>Relaticle API</title>
    <meta charset="utf-8"/>
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"/>
    <style>
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
            height: 3rem;
            background: #fff;
            border-bottom: 1px solid #e5e7eb;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }
        .relaticle-nav-left {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        .relaticle-nav-left a {
            display: flex;
            align-items: center;
            text-decoration: none;
            color: inherit;
        }
        .relaticle-nav-left svg {
            height: 1.25rem;
            width: auto;
        }
        .relaticle-nav-sep {
            width: 1px;
            height: 1rem;
            background: #e5e7eb;
        }
        .relaticle-nav-title {
            font-size: 0.8125rem;
            font-weight: 600;
            color: #111827;
        }
        .relaticle-nav-links {
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }
        .relaticle-nav-links a {
            font-size: 0.8125rem;
            font-weight: 500;
            color: #6b7280;
            text-decoration: none;
            padding: 0.375rem 0.75rem;
            border-radius: 0.375rem;
            transition: color 0.15s;
        }
        .relaticle-nav-links a:hover {
            color: #111827;
        }
        @media (max-width: 640px) {
            .relaticle-nav-links { display: none; }
        }
        @media (prefers-color-scheme: dark) {
            .relaticle-nav {
                background: #0a0a0a;
                border-bottom-color: rgba(255,255,255,0.06);
            }
            .relaticle-nav-sep { background: rgba(255,255,255,0.1); }
            .relaticle-nav-title { color: #fff; }
            .relaticle-nav-links a { color: #9ca3af; }
            .relaticle-nav-links a:hover { color: #fff; }
            .relaticle-nav-left img { height: 1.25rem; width: auto; }
        }
    </style>
</head>
<body>

<nav class="relaticle-nav">
    <div class="relaticle-nav-left">
        <a href="/" aria-label="Relaticle Home">
            <img src="/brand/logomark.svg" alt="Relaticle" style="height:1.25rem;width:1.25rem;"/>
        </a>
        <div class="relaticle-nav-sep"></div>
        <span class="relaticle-nav-title">API Reference</span>
    </div>
    <div class="relaticle-nav-links">
        <a href="/docs">Docs</a>
        <a href="/docs/mcp">MCP</a>
        <a href="/pricing">Pricing</a>
        <a href="https://github.com/Relaticle/relaticle" target="_blank" rel="noopener">GitHub</a>
    </div>
</nav>

<script
    id="api-reference"
    data-url="{{ route("scribe.openapi") }}">
</script>
<script src="https://cdn.jsdelivr.net/npm/@scalar/api-reference"></script>
</body>
</html>
