<?php

declare(strict_types=1);

namespace App\Http\Controllers\Nurse;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

final class HomeController extends Controller
{
    /**
     * The nurse's home page. It reads no data and takes no input, so there is no Form Request, Policy or Action: the
     * `role:nurse` group is the only lock, and no Policy allows a nurse anything yet. The triage queue comes with the
     * vitals feature, and it will get its own Policy.
     */
    public function __invoke(): Response
    {
        return Inertia::render('nurse/home');
    }
}
