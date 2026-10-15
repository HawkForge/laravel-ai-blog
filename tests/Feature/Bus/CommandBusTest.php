<?php

namespace ByteMinus\AiBlog\Tests\Feature\Bus;

use ByteMinus\AiBlog\Bus\Actor;
use ByteMinus\AiBlog\Bus\CommandBus;
use ByteMinus\AiBlog\Bus\CommandPresenter;
use ByteMinus\AiBlog\Bus\CommandRegistry;
use ByteMinus\AiBlog\Bus\CommandRequest;
use ByteMinus\AiBlog\Bus\Exceptions\CommandRefused;
use ByteMinus\AiBlog\Enums\CommandStatus;
use ByteMinus\AiBlog\Models\AuditEvent;
use ByteMinus\AiBlog\Models\Command;
use ByteMinus\AiBlog\Tests\Feature\Bus\Handlers\Explodes;
use ByteMinus\AiBlog\Tests\Feature\Bus\Handlers\HandlerRuns;
use ByteMinus\AiBlog\Tests\Feature\Bus\Handlers\IssuesConfirmation;
use ByteMinus\AiBlog\Tests\Feature\Bus\Handlers\PeopleOnly;
use ByteMinus\AiBlog\Tests\Feature\Bus\Handlers\QueuesWork;
use ByteMinus\AiBlog\Tests\Feature\Bus\Handlers\RefusesInside;
use ByteMinus\AiBlog\Tests\Feature\Bus\Handlers\RenamePost;
use ByteMinus\AiBlog\Tests\TestCase;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use Throwable;

class CommandBusTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        HandlerRuns::$count = 0;
        Gate::define(Actor::GATE, fn (GenericUser $user): bool => $user->getAuthIdentifier() === 1);

        $registry = app(CommandRegistry::class);
        $registry->register('posts.rename', RenamePost::class);
        $registry->register('posts.people_only', PeopleOnly::class);
        $registry->register('posts.refuses', RefusesInside::class);
        $registry->register('posts.explodes', Explodes::class);
        $registry->register('posts.queue', QueuesWork::class);
        $registry->register('posts.publish', IssuesConfirmation::class);
    }

    public function test_the_same_key_and_request_replays_the_stored_command(): void
    {
        $request = new CommandRequest('posts.rename', 'rename-key-0001', ['title' => 'Better']);

        $first = $this->bus()->dispatch($this->admin(), $request);
        $second = $this->bus()->dispatch($this->admin(), $request);

        $this->assertFalse($first->replayed);
        $this->assertTrue($second->replayed);
        $this->assertSame($first->command->id, $second->command->id);
        $this->assertSame(['title' => 'Better'], $second->result());
        $this->assertSame(CommandStatus::Completed, $first->command->status);
        $this->assertSame(1, HandlerRuns::$count);
        $this->assertSame(1, Command::query()->count());
        $this->assertSame(1, AuditEvent::query()->where('action', 'posts.rename')->count());
    }

    public function test_the_same_key_with_different_params_is_refused_and_audited(): void
    {
        $this->bus()->dispatch($this->admin(), new CommandRequest('posts.rename', 'rename-key-0001', ['title' => 'One']));

        $refusal = $this->refusal(fn () => $this->bus()->dispatch($this->admin(), new CommandRequest('posts.rename', 'rename-key-0001', ['title' => 'Two'])));

        $this->assertSame('conflict', $refusal->reason);
        $this->assertSame(409, $refusal->httpStatus());
        $this->assertSame(1, HandlerRuns::$count);
        $this->assertTrue(AuditEvent::query()->where('action', 'posts.rename')->where('actor_type', 'user')->where('reason', $refusal->getMessage())->exists());
    }

    public function test_another_actor_cannot_replay_a_key_it_did_not_store(): void
    {
        $this->bus()->dispatch($this->admin(), new CommandRequest('posts.rename', 'rename-key-0001', ['title' => 'One']));
        $credential = Actor::credential('cred-1', ['posts.write']);

        $refusal = $this->refusal(fn () => $this->bus()->dispatch($credential, new CommandRequest('posts.rename', 'rename-key-0001', ['title' => 'One'])));

        $this->assertSame('conflict', $refusal->reason);
        $this->assertSame(1, HandlerRuns::$count);
    }

    public function test_a_credential_without_the_ability_is_refused_before_anything_is_stored(): void
    {
        $credential = Actor::credential('cred-1', ['posts.read'], meta: ['credential' => 'ChatGPT']);

        $missing = $this->refusal(fn () => $this->bus()->dispatch($credential, new CommandRequest('posts.rename', 'rename-key-0001', ['title' => 'X'])));
        $peopleOnly = $this->refusal(fn () => $this->bus()->dispatch($credential, new CommandRequest('posts.people_only', 'people-0001')));

        $this->assertSame('This credential does not carry the [posts.write] permission.', $missing->getMessage());
        $this->assertSame('forbidden', $peopleOnly->reason);
        $this->assertSame(0, Command::query()->count());
        $this->assertSame(2, AuditEvent::query()->where('actor_type', 'credential')->where('meta->outcome', 'refused')->count());
        $this->assertSame('ChatGPT', AuditEvent::query()->firstOrFail()->meta['credential']);
    }

    public function test_a_credential_with_the_ability_runs_the_command(): void
    {
        $receipt = $this->bus()->dispatch(Actor::credential('cred-1', ['posts.write']), new CommandRequest('posts.rename', 'rename-key-0001', ['title' => 'Fine']));

        $this->assertSame(CommandStatus::Completed, $receipt->command->status);
        $this->assertSame('credential', $receipt->command->actor_type->value);
        $this->assertSame('cred-1', $receipt->command->actor_id);
    }

    public function test_a_person_the_host_gate_refuses_cannot_act(): void
    {
        $this->expectException(AuthorizationException::class);

        Actor::user(new GenericUser(['id' => 2]));
    }

    public function test_nobody_may_manage_the_blog_until_the_host_defines_the_gate(): void
    {
        $this->refreshApplication();

        $this->assertFalse(Gate::forUser(new GenericUser(['id' => 1]))->allows(Actor::GATE));
    }

    public function test_the_brain_runs_only_the_commands_listed_with_it(): void
    {
        $brain = Actor::brain(['posts.write'], ['posts.rename' => 'posts.write'], 'run-1');

        $this->bus()->dispatch($brain, new CommandRequest('posts.rename', 'brain-rename-0001', ['title' => 'Planned']));
        $refusal = $this->refusal(fn () => $this->bus()->dispatch($brain, new CommandRequest('posts.people_only', 'brain-people-0001')));

        $this->assertSame('forbidden', $refusal->reason);
        $this->assertSame('run-1', AuditEvent::query()->where('action', 'posts.rename')->firstOrFail()->meta['brain_run_id']);
    }

    public function test_invalid_params_are_refused_before_the_command_is_stored(): void
    {
        $this->expectException(ValidationException::class);

        try {
            $this->bus()->dispatch($this->admin(), new CommandRequest('posts.rename', 'rename-key-0001', ['title' => '']));
        } finally {
            $this->assertSame(0, Command::query()->count());
            $this->assertStringContainsString('Invalid input', (string) AuditEvent::query()->firstOrFail()->reason);
        }
    }

    public function test_a_refusal_inside_the_handler_rejects_the_stored_command_and_frees_its_key(): void
    {
        $refusal = $this->refusal(fn () => $this->bus()->dispatch($this->admin(), new CommandRequest('posts.refuses', 'refuse-key-0001')));

        $command = Command::query()->firstOrFail();
        $this->assertSame('Not while the kill switch is on.', $refusal->getMessage());
        $this->assertSame(CommandStatus::Rejected, $command->status);
        $this->assertSame($command->id, AuditEvent::query()->where('meta->outcome', 'refused')->firstOrFail()->meta['command_id']);

        try {
            $this->bus()->dispatch($this->admin(), new CommandRequest('posts.refuses', 'refuse-key-0001', ['mode' => 'validation']));
            $this->fail('The second refusal was not raised.');
        } catch (ValidationException) {
        }

        $this->assertSame(2, Command::query()->count());
        $this->assertStringStartsWith('refuse-key-0001#refused:', $command->refresh()->idempotency_key);
        $this->assertStringContainsString('Resolve the blockers first.', (string) Command::query()->where('idempotency_key', 'refuse-key-0001')->firstOrFail()->error);
    }

    public function test_a_failure_is_recorded_without_its_message_and_rethrown(): void
    {
        try {
            $this->bus()->dispatch($this->admin(), new CommandRequest('posts.explodes', 'explode-0001'));
            $this->fail('The failure was swallowed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Secret internal detail', $exception->getMessage());
        }

        $command = Command::query()->firstOrFail();
        $this->assertSame(CommandStatus::Failed, $command->status);
        $this->assertSame('The command failed on the server.', $command->error);
        $event = AuditEvent::query()->where('meta->outcome', 'failed')->firstOrFail();
        $this->assertSame(RuntimeException::class, $event->meta['exception']);
        $this->assertStringNotContainsString('Secret', (string) json_encode($event->getAttributes()));
    }

    public function test_a_successful_command_audits_its_subject_and_hashes_and_hides_bookkeeping(): void
    {
        $receipt = $this->bus()->dispatch($this->admin(), new CommandRequest('posts.rename', 'rename-key-0001', ['title' => 'Better']));

        $event = AuditEvent::query()->where('action', 'posts.rename')->firstOrFail();
        $this->assertSame('post', $event->subject_type);
        $this->assertSame('42', $event->subject_id);
        $this->assertNotSame($event->old_hash, $event->new_hash);
        $this->assertSame('1', $event->actor_id);
        $this->assertSame($receipt->command->id, $event->meta['command_id']);
        $this->assertArrayNotHasKey('_internal', $receipt->result());
        $this->assertArrayNotHasKey('_internal', CommandPresenter::present($receipt->command)['result']);
    }

    public function test_a_queued_command_stays_open(): void
    {
        $receipt = $this->bus()->dispatch($this->admin(), new CommandRequest('posts.queue', 'queue-key-0001'));

        $this->assertSame(CommandStatus::Queued, $receipt->command->status);
        $this->assertNull($receipt->command->completed_at);
        $this->assertSame('queued', AuditEvent::query()->firstOrFail()->meta['outcome']);
    }

    public function test_a_dry_run_returns_a_one_time_token_that_is_never_stored(): void
    {
        $credential = Actor::credential('cred-1', ['posts.publish']);

        $preview = $this->bus()->dispatch($credential, new CommandRequest('posts.publish', 'preview-0001', [], true));
        $replay = $this->bus()->dispatch($credential, new CommandRequest('posts.publish', 'preview-0001', [], true));
        $published = $this->bus()->dispatch($credential, new CommandRequest('posts.publish', 'publish-0001', ['confirmation_token' => 'tok-secret-123']));

        $this->assertSame('tok-secret-123', $preview->result()['confirmation_token']);
        $this->assertArrayNotHasKey('confirmation_token', $replay->result());
        $this->assertStringStartsWith('sha256:', $published->command->params['confirmation_token']);
        $this->assertStringNotContainsString('tok-secret-123', (string) json_encode(Command::query()->get()->map->getAttributes()));
        $this->assertSame('dry_run', AuditEvent::query()->where('meta->command_id', $preview->command->id)->firstOrFail()->meta['outcome']);
    }

    public function test_an_unknown_command_or_a_malformed_key_is_refused(): void
    {
        $this->assertSame('not_found', $this->refusal(fn () => $this->bus()->dispatch($this->admin(), new CommandRequest('domains.purchase', 'purchase-0001')))->reason);
        $this->assertSame('invalid', $this->refusal(fn () => $this->bus()->dispatch($this->admin(), new CommandRequest('posts.rename', 'short', ['title' => 'X'])))->reason);
    }

    public function test_the_registry_only_takes_handlers_with_dotted_names(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(CommandRegistry::class)->register('Rename Post', RenamePost::class);
    }

    public function test_hosts_add_handlers_through_config(): void
    {
        config()->set('ai-blog.commands.handlers', ['host.thing' => PeopleOnly::class]);
        $this->app->forgetInstance(CommandRegistry::class);

        $this->assertContains('host.thing', app(CommandRegistry::class)->names());
    }

    public function test_audit_events_are_append_only(): void
    {
        $this->bus()->dispatch($this->admin(), new CommandRequest('posts.rename', 'rename-key-0001', ['title' => 'Better']));
        $event = AuditEvent::query()->firstOrFail();

        $this->expectException(LogicException::class);

        $event->update(['action' => 'something.else']);
    }

    private function bus(): CommandBus
    {
        return app(CommandBus::class);
    }

    private function admin(): Actor
    {
        return Actor::user(new GenericUser(['id' => 1]));
    }

    private function refusal(callable $action): CommandRefused
    {
        try {
            $action();
        } catch (CommandRefused $refusal) {
            return $refusal;
        } catch (Throwable $other) {
            $this->fail('Expected a refusal, got '.$other::class.': '.$other->getMessage());
        }

        $this->fail('Expected a refusal.');
    }
}
