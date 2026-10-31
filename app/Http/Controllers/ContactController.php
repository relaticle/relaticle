<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ContactInquiry;
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
            'inquiry' => $this->inquiry($request),
        ]);
    }

    public function store(ContactRequest $request): RedirectResponse
    {
        /** @var array{name: string, email: string, company: ?string, message: string} $data */
        $data = $request->validated();

        $inquiry = $this->inquiry($request);

        Mail::to(config('relaticle.contact.email'))->send(new NewContactSubmissionMail($data, $inquiry));

        return to_route('contact', $inquiry instanceof ContactInquiry ? ['plan' => $inquiry->value] : [])
            ->with('success', __('Thanks for reaching out! We\'ll get back to you soon.'));
    }

    private function inquiry(Request $request): ?ContactInquiry
    {
        $requested = $request->query('plan');
        $inquiry = is_string($requested) ? ContactInquiry::tryFrom($requested) : null;

        return $inquiry instanceof ContactInquiry && Feature::active(Billing::class) ? $inquiry : null;
    }
}
