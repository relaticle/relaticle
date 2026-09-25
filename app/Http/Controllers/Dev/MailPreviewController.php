<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dev;

use App\Support\Mail\MailPreview;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

final readonly class MailPreviewController
{
    public function __construct(private MailPreview $preview) {}

    public function index(): View
    {
        return view('dev.mail-index', ['names' => $this->preview->names()]);
    }

    public function show(Request $request, string $mail): Response
    {
        $html = $this->preview->render($mail);

        if ($request->query('scheme') === 'dark') {
            $html = str_replace('@media (prefers-color-scheme: dark)', '@media all', $html);
        }

        return response($html);
    }
}
