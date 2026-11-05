<?php

declare(strict_types=1);

use App\Actions\Intake\GenerateIntakeSummary;
use App\Ai\Agents\IntakeSummarizer;
use App\Enums\Role;
use App\Enums\SummaryStatus;
use App\Models\Intake;
use App\Models\IntakeSummary;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

// The page takes no input: it is one GET with no query string, no form and no route that changes anything. So there is
// no validation test. The edges that decide what the page shows are tested in its place: "Price not set", and the cap
// when it is reached. Time is fixed with travelTo() in Manila time, and rows are written with UTC `created_at` values,
// as the column holds them.

/**
 * A summary row made at a UTC time. Each one gets its own intake (the column is unique).
 *
 * @param  array<string, mixed>  $attributes
 */
function usageRow(string $createdAtUtc, int $in = 0, int $out = 0, SummaryStatus $status = SummaryStatus::Ready, array $attributes = []): IntakeSummary
{
    return IntakeSummary::factory()->create([
        'status' => $status,
        'input_tokens' => $in,
        'output_tokens' => $out,
        'created_at' => $createdAtUtc,
        ...$attributes,
    ]);
}

/** @return array<string, int> status => count, in the order the page lists them */
function statusCounts(mixed $statuses): array
{
    return collect($statuses)->pluck('count', 'status')->all();
}

beforeEach(function () {
    config([
        'app.clinic_timezone' => 'Asia/Manila',
        'medibook.ai.daily_token_cap' => 2_000_000,
        'medibook.ai.price_input_per_million' => null,
        'medibook.ai.price_output_per_million' => null,
    ]);
});

describe('ai usage screen', function () {
    it('shows this month by status, the token totals, the cost and the cap', function () {
        // 1 Nov 00:30 in Manila is still 31 Oct 16:30 in UTC, so a UTC month filter would call this October.
        $this->travelTo(CarbonImmutable::parse('2026-11-01 00:30', 'Asia/Manila'));
        config(['medibook.ai.price_input_per_million' => '0.30', 'medibook.ai.price_output_per_million' => '2.50']);

        // The first second of November in Manila (31 Oct 16:00:00 UTC) counts. One second earlier is October.
        usageRow('2026-10-31 16:00:00', in: 800_000, out: 100_000, status: SummaryStatus::Ready);
        usageRow('2026-10-31 16:20:00', in: 200_000, out: 50_000, status: SummaryStatus::Failed);
        usageRow('2026-10-31 16:25:00', status: SummaryStatus::Pending, attributes: ['input_tokens' => null, 'output_tokens' => null]);
        usageRow('2026-10-31 16:29:00', status: SummaryStatus::Skipped, attributes: ['input_tokens' => null, 'output_tokens' => null]);
        usageRow('2026-10-31 15:59:59', in: 5_000_000, out: 5_000_000, status: SummaryStatus::Ready);

        $this->actingAs(userWithRole(Role::Admin))
            ->get(route('admin.ai-usage.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/ai-usage/index')
                ->where('usage.month', '2026-11-01')
                ->where('usage.statuses', fn ($rows) => statusCounts($rows) === ['pending' => 1, 'ready' => 1, 'failed' => 1, 'skipped' => 1])
                // Failed rows count for their tokens (the model was paid for); pending and skipped rows have none.
                ->where('usage.tokens', ['input' => 1_000_000, 'output' => 150_000, 'total' => 1_150_000])
                // 1.0 M x $0.30 + 0.15 M x $2.50 = $0.675
                ->where('usage.estimatedCost', '0.6750')
                ->where('usage.cap', ['daily' => 2_000_000, 'usedToday' => 1_150_000, 'reached' => false]));
    });

    it('tells today apart from this month, and leaves out last month', function () {
        $this->travelTo(CarbonImmutable::parse('2026-11-10 12:00', 'Asia/Manila'));
        config(['medibook.ai.daily_token_cap' => 500_000]);

        usageRow('2026-11-03 02:00:00', in: 300_000, out: 100_000);
        usageRow(now()->utc()->toDateTimeString(), in: 50_000, out: 10_000);
        usageRow('2026-10-31 15:00:00', in: 900_000, out: 0);

        $this->actingAs(userWithRole(Role::Admin))
            ->get(route('admin.ai-usage.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('usage.statuses', fn ($rows) => statusCounts($rows) === ['pending' => 0, 'ready' => 2, 'failed' => 0, 'skipped' => 0])
                ->where('usage.tokens.total', 460_000)
                ->where('usage.cap', ['daily' => 500_000, 'usedToday' => 60_000, 'reached' => false]));
    });

    it('counts a row made late in the evening in Manila on the clinic day it happened', function () {
        $this->travelTo(CarbonImmutable::parse('2026-11-10 08:00', 'Asia/Manila'));

        // 9 Nov 23:30 UTC is 10 Nov 07:30 in Manila: today. 9 Nov 15:59 UTC is 9 Nov 23:59 in Manila: yesterday.
        usageRow('2026-11-09 23:30:00', in: 1_000, out: 500);
        usageRow('2026-11-09 15:59:59', in: 70_000, out: 30_000);

        $this->actingAs(userWithRole(Role::Admin))
            ->get(route('admin.ai-usage.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('usage.tokens.total', 101_500)
                ->where('usage.cap.usedToday', 1_500));
    });

    it('shows zeros for a month with no summaries, and still lists all four statuses', function () {
        $this->travelTo(CarbonImmutable::parse('2026-11-10 12:00', 'Asia/Manila'));
        config(['medibook.ai.price_input_per_million' => '0.30', 'medibook.ai.price_output_per_million' => '2.50']);

        $this->actingAs(userWithRole(Role::Admin))
            ->get(route('admin.ai-usage.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('usage.statuses', fn ($rows) => statusCounts($rows) === ['pending' => 0, 'ready' => 0, 'failed' => 0, 'skipped' => 0])
                ->where('usage.tokens', ['input' => 0, 'output' => 0, 'total' => 0])
                ->where('usage.estimatedCost', '0.0000')
                ->where('usage.cap.usedToday', 0));
    });

    it('rounds the cost half up to 4 decimals', function () {
        $this->travelTo(CarbonImmutable::parse('2026-11-10 12:00', 'Asia/Manila'));
        config(['medibook.ai.price_input_per_million' => '0.30', 'medibook.ai.price_output_per_million' => '2.50']);

        // 1,000 x $0.30 / 1 M = $0.00030 and 500 x $2.50 / 1 M = $0.00125, together $0.00155.
        usageRow('2026-11-05 02:00:00', in: 1_000, out: 500);

        $this->actingAs(userWithRole(Role::Admin))
            ->get(route('admin.ai-usage.index'))
            ->assertInertia(fn (Assert $page) => $page->where('usage.estimatedCost', '0.0016'));
    });

    it('reads the numbers with SQL aggregates and never selects the summary text', function () {
        $this->travelTo(CarbonImmutable::parse('2026-11-10 12:00', 'Asia/Manila'));
        usageRow('2026-11-05 02:00:00', in: 10, out: 10);
        usageRow('2026-11-06 02:00:00', in: 10, out: 10);
        usageRow('2026-11-07 02:00:00', in: 10, out: 10);
        $admin = userWithRole(Role::Admin);

        DB::enableQueryLog();
        $this->actingAs($admin)->get(route('admin.ai-usage.index'))->assertOk();
        $queries = collect(DB::getQueryLog())->pluck('query')->filter(fn (string $sql) => str_contains($sql, 'intake_summaries'));

        // One grouped query for the month and one sum for today, however many rows there are.
        expect($queries)->toHaveCount(2)
            ->and($queries->every(fn (string $sql) => str_contains($sql, 'sum(') && ! str_contains($sql, '`summary`')))->toBeTrue();
    });

    it('sends counts only: no intake text, summary text or patient name reaches the page', function () {
        $this->travelTo(CarbonImmutable::parse('2026-11-10 12:00', 'Asia/Manila'));
        $intake = Intake::factory()->create(['answers' => [
            'reason' => 'SECRET-REASON-TEXT',
            'symptoms' => 'SECRET-SYMPTOM-TEXT',
            'allergies' => 'None',
            'medicines' => 'None',
        ]]);
        IntakeSummary::factory()->for($intake)->create([
            'summary' => ['chief_complaint' => 'SECRET-SUMMARY-TEXT', 'key_points' => [], 'allergies_and_meds' => 'Not given', 'questions_for_doctor' => []],
            'created_at' => '2026-11-05 02:00:00',
        ]);

        $this->actingAs(userWithRole(Role::Admin))
            ->get(route('admin.ai-usage.index'))
            ->assertOk()
            ->assertDontSee('SECRET-REASON-TEXT')
            ->assertDontSee('SECRET-SYMPTOM-TEXT')
            ->assertDontSee('SECRET-SUMMARY-TEXT')
            ->assertDontSee($intake->appointment->patient->name)
            ->assertInertia(fn (Assert $page) => $page
                ->where('usage', fn ($usage) => array_keys(collect($usage)->all()) === ['month', 'statuses', 'tokens', 'estimatedCost', 'cap']));
    });
});

describe('ai usage edges', function () {
    it('says the price is not set, and not 0, until both prices are set', function (mixed $input, mixed $output) {
        $this->travelTo(CarbonImmutable::parse('2026-11-10 12:00', 'Asia/Manila'));
        config(['medibook.ai.price_input_per_million' => $input, 'medibook.ai.price_output_per_million' => $output]);
        usageRow('2026-11-05 02:00:00', in: 1_000_000, out: 1_000_000);

        $this->actingAs(userWithRole(Role::Admin))
            ->get(route('admin.ai-usage.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('usage.estimatedCost', null)
                // The tokens are still shown: only the cost needs a price.
                ->where('usage.tokens.total', 2_000_000));
    })->with([
        'neither price' => [null, null],
        'empty strings, as an empty .env line gives' => ['', ''],
        'only the input price' => ['0.30', null],
        'only the output price' => [null, '2.50'],
        'a price that is not a number' => ['cheap', '2.50'],
        'a negative price' => ['-0.30', '2.50'],
    ]);

    it('accepts a price that config holds as a number', function () {
        $this->travelTo(CarbonImmutable::parse('2026-11-10 12:00', 'Asia/Manila'));
        config(['medibook.ai.price_input_per_million' => 1, 'medibook.ai.price_output_per_million' => 2.5]);
        usageRow('2026-11-05 02:00:00', in: 1_000_000, out: 1_000_000);

        $this->actingAs(userWithRole(Role::Admin))
            ->get(route('admin.ai-usage.index'))
            ->assertInertia(fn (Assert $page) => $page->where('usage.estimatedCost', '3.5000'));
    });

    it('marks the cap reached once today\'s tokens equal it, like the cap check does', function () {
        $this->travelTo(CarbonImmutable::parse('2026-11-10 12:00', 'Asia/Manila'));
        config(['medibook.ai.daily_token_cap' => 1_000]);
        usageRow(now()->utc()->toDateTimeString(), in: 600, out: 400);

        $this->actingAs(userWithRole(Role::Admin))
            ->get(route('admin.ai-usage.index'))
            ->assertInertia(fn (Assert $page) => $page->where('usage.cap', ['daily' => 1_000, 'usedToday' => 1_000, 'reached' => true]));
    });

    it('agrees with the cap check: when the screen says reached, a new summary is skipped without a prompt', function () {
        $this->travelTo(CarbonImmutable::parse('2026-11-10 12:00', 'Asia/Manila'));
        config(['medibook.ai.enabled' => true, 'medibook.ai.provider' => 'anthropic', 'medibook.ai.model' => 'test-model', 'medibook.ai.daily_token_cap' => 1_000]);
        usageRow(now()->utc()->toDateTimeString(), in: 600, out: 400);
        IntakeSummarizer::fake()->preventStrayPrompts();

        $this->actingAs(userWithRole(Role::Admin))
            ->get(route('admin.ai-usage.index'))
            ->assertInertia(fn (Assert $page) => $page->where('usage.cap.reached', true));

        $summary = app(GenerateIntakeSummary::class)->handle(Intake::factory()->create());

        expect($summary->status)->toBe(SummaryStatus::Skipped);
        IntakeSummarizer::assertNeverPrompted();
    });
});

describe('ai usage access', function () {
    it('denies the screen to everyone but admins', function (Role $role) {
        $this->actingAs(userWithRole($role))->get(route('admin.ai-usage.index'))->assertForbidden();
    })->with('non_admins');

    it('sends a guest to the login page', function () {
        $this->get(route('admin.ai-usage.index'))->assertRedirect(route('login'));
    });

    it('has no route that changes anything', function (string $method) {
        usageRow('2026-11-05 02:00:00', in: 10, out: 10);

        $this->actingAs(userWithRole(Role::Admin))
            ->call($method, '/admin/ai-usage')
            ->assertStatus(405);

        expect(IntakeSummary::query()->count())->toBe(1);
    })->with(['POST', 'PUT', 'PATCH', 'DELETE']);
});
