<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\Plan;
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
            'inquiryPlan' => $this->inquiryPlan($request),
        ]);
    }

    public function store(ContactRequest $request): RedirectResponse
    {
        /** @var array{name: string, email: string, company: ?string, message: string} $data */
        $data = $request->validated();

        $plan = $this->inquiryPlan($request);

        Mail::to(config('relaticle.contact.email'))->send(new NewContactSubmissionMail($data, $plan));

        return to_route('contact', $plan instanceof Plan ? ['plan' => $plan->value] : [])
            ->with('success', __('Thanks for reaching out! We\'ll get back to you soon.'));
    }

    private function inquiryPlan(Request $request): ?Plan
    {
        $requestedPlan = $request->query('plan');
        $plan = is_string($requestedPlan) ? Plan::tryFrom($requestedPlan) : null;

        return $plan?->isManaged() === true && Feature::active(Billing::class) ? $plan : null;
    }
}
