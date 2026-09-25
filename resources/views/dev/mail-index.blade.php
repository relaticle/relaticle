<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Mail previews</title>
    <style>
        body { font-family: system-ui, sans-serif; margin: 40px; color: #18181b; }
        li { margin: 8px 0; }
        a { color: #6d28d9; }
    </style>
</head>
<body>
    <h1>Mail previews</h1>
    <ul>
        @foreach($names as $name)
            <li>
                <a href="{{ route('dev.mail.show', ['mail' => $name]) }}">{{ $name }}</a>
                (<a href="{{ route('dev.mail.show', ['mail' => $name, 'scheme' => 'dark']) }}">dark</a>)
            </li>
        @endforeach
    </ul>
</body>
</html>
