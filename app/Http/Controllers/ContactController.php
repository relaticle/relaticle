<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Features\Billing;
use App\Http\Requests\ContactRequest;
use App\Mail\NewContactSubmissionMail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;
use Laravel\Pennant\Feature;

final readonly class ContactController
{
    public function show(Request $request): View
    {
        return view('contact', [
            'enterpriseInquiry' => $request->query('plan') === 'enterprise' && Feature::active(Billing::class),
        ]);
    }

    public function store(ContactRequest $request): RedirectResponse
    {
        /** @var array{name: string, email: string, company: ?string, message: string} $data */
        $data = $request->validated();

        Mail::to(config('relaticle.contact.email'))->send(new NewContactSubmissionMail($data));

        return to_route('contact')->with('success', 'Thanks for reaching out! We\'ll get back to you soon.');
    }
}
