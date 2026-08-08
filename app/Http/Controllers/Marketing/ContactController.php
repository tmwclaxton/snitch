<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Marketing\ContactRequest;
use App\Mail\Marketing\ContactMessage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;
use Inertia\Response;

class ContactController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('marketing/Contact');
    }

    public function store(ContactRequest $request): RedirectResponse
    {
        $to = (string) config('snitch.contact_to');

        Mail::to($to)->send(
            new ContactMessage(
                name: $request->string('name')->toString(),
                email: $request->string('email')->toString(),
                message: $request->string('message')->toString(),
            ),
        );

        return back()->with('success', 'Thanks - we received your message.');
    }
}
