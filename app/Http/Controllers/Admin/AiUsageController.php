<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\GetAiUsage;
use App\Http\Controllers\Controller;
use App\Models\IntakeSummary;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class AiUsageController extends Controller
{
    /** The AI usage, read only. The page takes no input, so there is no Form Request, and no other method. */
    public function index(GetAiUsage $usage): Response
    {
        Gate::authorize('viewUsage', IntakeSummary::class);

        return Inertia::render('admin/ai-usage/index', [
            'usage' => $usage->handle(),
        ]);
    }
}
