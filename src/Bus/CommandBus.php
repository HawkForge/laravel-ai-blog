<?php

declare(strict_types=1);

namespace AiBlog\Bus;

use AiBlog\Bus\Exceptions\CommandRefused;
use AiBlog\Enums\CommandStatus;
use AiBlog\Models\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The one way anything changes: a dashboard, ChatGPT, Artisan and the
 * scheduler all come through here.
 *
 * In order: the command must exist, the key must be well formed, and the
 * actor must carry the command's ability. Then the idempotency key: the same
 * key with the same request from the same actor replays the stored command;
 * anything else is refused. A refusal changes nothing, so it never holds a
 * key: one before the command is stored is audited only, and a stored
 * command that was refused gives its key up to the next request (its row
 * stays, renamed).
 *
 * Once stored, the handler runs. Every outcome is written back to the command
 * and audited, and an exception is rethrown after it is recorded, so each
 * caller still reacts to it in its own way (a form error, a 422, a tool
 * error). Nothing here wraps the handler in a transaction: async handlers
 * dispatch jobs, and a job lock must not be taken inside one.
 */
final class CommandBus
{
    private const KEY_PATTERN = '/^[A-Za-z0-9._:-]{8,120}$/';

    public function __construct(
        private readonly CommandRegistry $registry,
        private readonly CommandAudit $audit,
    ) {}

    /**
     * @throws CommandRefused|ValidationException|ModelNotFoundException|Throwable
     */
    public function dispatch(Actor $actor, CommandRequest $request): CommandReceipt
    {
        try {
            $handler = $this->registry->handler($request->name);

            if (preg_match(self::KEY_PATTERN, $request->idempotencyKey) !== 1) {
                throw CommandRefused::invalid('The idempotency key must be 8 to 120 characters of letters, digits and . _ : -');
            }

            $ability = $actor->abilityFor($request->name, $handler->ability());
            if (! $actor->can($ability)) {
                throw CommandRefused::missingAbility($ability);
            }

            $existing = $this->existing($request);

            if ($existing?->status === CommandStatus::Rejected) {
                $existing->update(['idempotency_key' => mb_substr($existing->idempotency_key, 0, 80).'#refused:'.Str::ulid()]);
                $existing = null;
            }

            if ($existing !== null) {
                return $this->replay($existing, $actor, $request);
            }

            $params = $this->validate($handler, $request->params);
        } catch (CommandRefused|ValidationException $refusal) {
            $this->audit->refused($actor, $request, $this->reason($refusal));

            throw $refusal;
        }

        try {
            $command = DB::transaction(fn (): Command => Command::query()->create([
                'name' => $request->name,
                'actor_type' => $actor->type,
                'actor_id' => $actor->id,
                'params' => $this->storable($request->params),
                'idempotency_key' => $request->idempotencyKey,
                'dry_run' => $request->dryRun,
                'status' => CommandStatus::Running,
                'started_at' => now(),
            ]));
        } catch (UniqueConstraintViolationException) {
            // The same key arrived twice at once; the other request stored it.
            return $this->replay($this->existing($request) ?? throw CommandRefused::conflict(), $actor, $request);
        }

        return $this->run($handler, new CommandContext($command, $actor, $params), $request);
    }

    private function run(CommandHandler $handler, CommandContext $context, CommandRequest $request): CommandReceipt
    {
        $command = $context->command;

        try {
            $outcome = $request->dryRun ? $handler->preview($context) : $handler->handle($context);
        } catch (CommandRefused|ValidationException|ModelNotFoundException $refusal) {
            $reason = $this->reason($refusal);
            $command->update(['status' => CommandStatus::Rejected, 'error' => $reason, 'completed_at' => now()]);
            $this->audit->refused($context->actor, $request, $reason, $command);

            throw $refusal;
        } catch (Throwable $exception) {
            $command->update(['status' => CommandStatus::Failed, 'error' => 'The command failed on the server.', 'completed_at' => now()]);
            $this->audit->failed($context->actor, $command, $exception::class);

            throw $exception;
        }

        $command->update([
            'status' => $outcome->status,
            'result' => $outcome->result,
            'completed_at' => $outcome->status === CommandStatus::Queued ? null : now(),
        ]);
        $this->audit->accepted($context->actor, $command, $outcome);

        return new CommandReceipt($command, false, $outcome->once);
    }

    private function existing(CommandRequest $request): ?Command
    {
        return Command::query()->where('idempotency_key', $request->idempotencyKey)->first();
    }

    /**
     * Only the actor that stored a command can replay it: a key another
     * actor used is a conflict, so one caller never reads another's result.
     */
    private function replay(Command $existing, Actor $actor, CommandRequest $request): CommandReceipt
    {
        $same = $existing->actor_type === $actor->type
            && $existing->actor_id === $actor->id
            && $existing->name === $request->name
            && $existing->dry_run === $request->dryRun
            && Fingerprint::canonical($existing->params ?? []) === Fingerprint::canonical($this->storable($request->params));

        if (! $same) {
            throw CommandRefused::conflict();
        }

        return new CommandReceipt($existing, true);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function validate(CommandHandler $handler, array $params): array
    {
        if ($handler->rules() === []) {
            return $params;
        }

        return Validator::make($params, $handler->rules())->validate() + $params;
    }

    /**
     * Params as stored: a value under a key ending in `_token` is kept only as
     * its hash, so a confirmation token is never written down.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function storable(array $params): array
    {
        foreach ($params as $key => $value) {
            if (is_string($key) && str_ends_with($key, '_token') && is_string($value)) {
                $params[$key] = 'sha256:'.hash('sha256', $value);
            }
        }

        return $params;
    }

    private function reason(Throwable $refusal): string
    {
        return match (true) {
            $refusal instanceof ValidationException => 'Invalid input: '.implode(' ', $refusal->validator->errors()->all()),
            $refusal instanceof ModelNotFoundException => 'No such record.',
            default => $refusal->getMessage(),
        };
    }
}
