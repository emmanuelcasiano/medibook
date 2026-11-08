<?php

declare(strict_types=1);

use App\Actions\Intake\SaveIntake;
use App\Ai\Agents\IntakeSummarizer;
use App\Enums\Role;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\User;
use App\Support\LogMasker;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\StructuredTextResponse;
use Monolog\Level;
use Monolog\LogRecord;

// Step 11.5: LogMasker hides personal and health keys in what is written to the log (security.md section 8). It has no
// route, no Policy and no input, so there is no denied-access or validation test. The rules of the processor are checked
// on a LogRecord, then the wiring in config/logging.php is checked through the real channels, then a full flow
// (intake saved, summarized, a failed job, the doctor opening the page) is read back from the log file. Logs go to a
// temp folder, so the test never touches storage/logs/laravel.log. "Now" is Friday 30 October 2026, 09:00 in Manila.

/** A string no real log line would hold. If it shows up in the file, an answer leaked. */
const LEAK_MARKER = 'ZEBRA-RASH-MARKER';

function logRecord(array $context = [], array $extra = [], string $message = 'Something happened'): LogRecord
{
    return new LogRecord(new DateTimeImmutable, 'test', Level::Info, $message, $context, $extra);
}

/** Everything the channels wrote into the temp folder, so a daily file name (`test-2026-10-30.log`) doesn't matter. */
function writtenLogs(string $dir): string
{
    return collect(glob($dir.'/*') ?: [])->map(fn (string $file): string => (string) file_get_contents($file))->implode("\n");
}

function logIntakeAnswers(string $reason): array
{
    return [
        'reason' => $reason,
        'symptoms' => 'Itchy red patches, '.LEAK_MARKER,
        'duration' => 'few_days',
        'allergies' => 'Penicillin, '.LEAK_MARKER,
        'medicines' => 'Cetirizine, '.LEAK_MARKER,
        'fever' => false,
        'pregnant' => false,
        'recent_surgery' => false,
    ];
}

function logAiAnswer(): StructuredTextResponse
{
    $fields = [
        'headline' => 'Rash for a few days',
        'timeline' => ['Itchy'],
        'in_english' => null,
        'gaps' => [],
        'check_with_patient' => [],
    ];

    return new StructuredTextResponse($fields, json_encode($fields), new TextUsage(100, 50), new Meta('anthropic', 'test-model'));
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-30 09:00', 'Asia/Manila'));

    $this->logDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'medibook-log-'.bin2hex(random_bytes(6));
    mkdir($this->logDir);

    // The default channel is the same `stack` as in .env.example, pointed at a temp folder. The channels are built again,
    // so the `tap` from config/logging.php runs on them.
    config([
        'logging.default' => 'stack',
        'logging.channels.stack.channels' => ['single'],
        'logging.channels.single.path' => $this->logDir.'/test.log',
        'logging.channels.daily.path' => $this->logDir.'/test.log',
        'logging.channels.single.level' => 'debug',
        'logging.channels.daily.level' => 'debug',
    ]);

    foreach (['stack', 'single', 'daily'] as $channel) {
        Log::forgetChannel($channel);
    }
});

afterEach(function () {
    // Windows can't delete a file that a handler still holds open, so the channels are closed first.
    foreach (Log::getChannels() as $channel) {
        $channel->getLogger()->close();
    }

    array_map('unlink', glob($this->logDir.'/*') ?: []);
    rmdir($this->logDir);
});

describe('the processor', function () {
    it('hides every sensitive key', function (string $key) {
        $record = (new LogMasker)(logRecord([$key => 'a value', 'intake_id' => 7]));

        expect($record->context)->toBe([$key => LogMasker::HIDDEN, 'intake_id' => 7]);
    })->with(['answers', 'symptoms', 'notes', 'email', 'phone', 'password', 'password_confirmation', 'current_password', 'birth_date']);

    it('hides a key whatever its case', function () {
        $record = (new LogMasker)(logRecord(['Email' => 'a@example.test', 'PASSWORD' => 'secret']));

        expect($record->context)->toBe(['Email' => LogMasker::HIDDEN, 'PASSWORD' => LogMasker::HIDDEN]);
    });

    it('hides a key at any depth, also inside a list', function () {
        $record = (new LogMasker)(logRecord(['patient' => ['id' => 3, 'contact' => ['phone' => '0917']], 'rows' => [['notes' => 'x'], ['notes' => 'y']]]));

        expect($record->context)->toBe([
            'patient' => ['id' => 3, 'contact' => ['phone' => LogMasker::HIDDEN]],
            'rows' => [['notes' => LogMasker::HIDDEN], ['notes' => LogMasker::HIDDEN]],
        ]);
    });

    it('hides a key in the extra data too', function () {
        $record = (new LogMasker)(logRecord(extra: ['email' => 'a@example.test', 'request_id' => 'abc']));

        expect($record->extra)->toBe(['email' => LogMasker::HIDDEN, 'request_id' => 'abc']);
    });

    it('leaves safe keys and the message alone', function () {
        $context = ['intake_id' => 7, 'status' => 'ready', 'model' => 'test-model', 'input_tokens' => 100, 'error' => null, 'ids' => [1, 2, 3]];

        $record = (new LogMasker)(logRecord($context, message: 'Intake summary saved'));

        expect($record->context)->toBe($context)
            ->and($record->message)->toBe('Intake summary saved');
    });

    it('writes a model as its class and key, so a decrypted column can not reach the log', function () {
        $user = User::factory()->patient()->create();

        $record = (new LogMasker)(logRecord(['patient' => $user, 'nested' => ['patient' => $user]]));

        expect($record->context)->toBe([
            'patient' => User::class.'#'.$user->id,
            'nested' => ['patient' => User::class.'#'.$user->id],
        ]);
    });
});

describe('the configured channels', function () {
    it('hides the keys on the default stack', function () {
        Log::info('Probe', ['answers' => ['reason' => LEAK_MARKER], 'intake_id' => 42]);

        $log = writtenLogs($this->logDir);

        expect($log)->toContain('Probe')
            ->and($log)->toContain('"answers":"[hidden]"')
            ->and($log)->toContain('"intake_id":42')
            ->not->toContain(LEAK_MARKER);
    });

    it('hides the keys on a single channel and on a daily channel', function (string $channel) {
        Log::channel($channel)->info('Probe', ['symptoms' => LEAK_MARKER, 'email' => 'leak@example.test']);

        $log = writtenLogs($this->logDir);

        expect($log)->toContain('Probe')
            ->and($log)->toContain('[hidden]')
            ->not->toContain(LEAK_MARKER)
            ->not->toContain('leak@example.test');
    })->with(['single', 'daily']);

    // The other channels write to a place the test can't read (stderr, syslog), so the processors of each built channel are
    // run on a record by hand. A channel that kept the masker out of its list would leave the phone number in clear text.
    it('puts the masker on every other channel that writes somewhere', function (string $channel) {
        $record = logRecord(['phone' => '0917 000 0000', 'intake_id' => 5], message: 'Contact {phone}');

        foreach (Log::channel($channel)->getLogger()->getProcessors() as $processor) {
            $record = $processor($record);
        }

        expect($record->context)->toBe(['phone' => LogMasker::HIDDEN, 'intake_id' => 5])
            ->and($record->message)->not->toContain('0917');
    })->with(['monthly', 'errorlog', 'syslog', 'stderr']);

    // `{phone}` in a message is filled from the context. The masker has to run first, or the clear value gets into the text.
    it('hides a sensitive key that a message placeholder would copy into the text', function () {
        Log::info('Contact {phone}', ['phone' => '0917 000 0000']);

        expect(writtenLogs($this->logDir))->toContain('Contact [hidden]')
            ->not->toContain('0917');
    });

    // Laravel's shared Context is added to the record by its own processor, which runs before the masker.
    it('hides a sensitive key in the shared log context', function () {
        Context::add('email', 'context-leak@example.test');
        Context::add('request_id', 'abc-123');

        Log::info('Probe');

        $log = writtenLogs($this->logDir);

        expect($log)->toContain('abc-123')
            ->and($log)->toContain('"email":"[hidden]"')
            ->not->toContain('context-leak@example.test');
    });
});

describe('a full flow', function () {
    it('leaves no intake text, name or email in the log', function () {
        config([
            'medibook.ai.enabled' => true,
            'medibook.ai.provider' => 'anthropic',
            'medibook.ai.model' => 'test-model',
            'medibook.ai.daily_token_cap' => 100000,
        ]);

        $doctorUser = userWithRole(Role::Doctor);
        $doctor = Doctor::query()->where('user_id', $doctorUser->id)->firstOrFail();
        $patient = User::factory()->patient()->create();
        $book = fn (int $days): Appointment => Appointment::factory()->for($doctor)->for($patient, 'patient')->booked()->startingIn(days: $days)->create();

        // A ready summary: the job logs "Intake summary saved".
        IntakeSummarizer::fake([logAiAnswer()]);
        $first = $book(2);
        $intake = app(SaveIntake::class)->handle($patient, $first, logIntakeAnswers('Rash on both arms, '.LEAK_MARKER));

        // A failing provider: the job logs "Intake summary job failed". The sync queue lets the exception out.
        IntakeSummarizer::fake(fn () => throw new RuntimeException('timeout'));
        $second = $book(3);
        expect(fn () => app(SaveIntake::class)->handle($patient, $second, logIntakeAnswers('Second rash, '.LEAK_MARKER)))
            ->toThrow(RuntimeException::class);

        // The doctor opens the page with the raw answers.
        $this->actingAs($doctorUser)->get(route('doctor.appointments.show', $first))->assertOk();

        $log = writtenLogs($this->logDir);

        // The lines are there, so an empty file can't pass for a clean one.
        expect($log)->toContain('Intake summary saved')
            ->and($log)->toContain('Intake summary job failed')
            ->and($log)->toContain('"intake_id":'.$intake->id)
            ->not->toContain(LEAK_MARKER)
            ->not->toContain('Rash on both arms')
            ->not->toContain($patient->email)
            ->not->toContain($patient->name);
    });
});
