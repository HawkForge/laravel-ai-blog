<?php

declare(strict_types=1);

namespace AiBlog\Bus;

use AiBlog\Enums\AuditActor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;

/**
 * Who is asking for a command.
 *
 * A person acts only once the host's `manageAiBlog` gate lets them, and may
 * then run every command. A credential (a ChatGPT connection) is capped at
 * its abilities. The package's own jobs run every command; the Brain runs
 * only the commands listed with it.
 */
final readonly class Actor
{
    public const GATE = 'manageAiBlog';

    /**
     * @param  list<string>|null  $abilities  null for a person or a job, who may run every command
     * @param  array<string, mixed>  $meta  safe to audit: never a token
     * @param  array<string, string>|null  $commandAbilities  for the Brain: the only commands it may run, each with the ability it runs under
     */
    private function __construct(
        public AuditActor $type,
        public ?string $id,
        public ?array $abilities,
        public string $principal,
        public array $meta = [],
        private ?array $commandAbilities = null,
    ) {}

    /**
     * @throws AuthorizationException when the host's gate refuses this person
     */
    public static function user(Authenticatable $user): self
    {
        if (! Gate::forUser($user)->allows(self::GATE)) {
            throw new AuthorizationException('This account may not manage the blog.');
        }

        $id = (string) $user->getAuthIdentifier();

        return new self(AuditActor::User, $id, null, 'user:'.$id);
    }

    public static function job(string $job): self
    {
        return new self(AuditActor::Job, $job, null, 'job:'.$job, ['job' => $job]);
    }

    /**
     * A connected credential. The principal names the sign-in it came from, so
     * a confirmation started before a token refresh can still be finished
     * after it, by the same connection and no other.
     *
     * @param  array<array-key, string>  $abilities
     * @param  array<string, mixed>  $meta
     */
    public static function credential(string $id, array $abilities, ?string $principal = null, array $meta = []): self
    {
        return new self(AuditActor::Credential, $id, array_values($abilities), 'credential:'.($principal ?? $id), $meta);
    }

    /**
     * The Brain, with its own fixed abilities. Unlike a credential, whose
     * ability is the one each command names, the Brain's commands are listed
     * with it: a command not on the list needs an ability the Brain does not
     * have.
     *
     * @param  list<string>  $abilities
     * @param  array<string, string>  $commandAbilities
     */
    public static function brain(array $abilities, array $commandAbilities, ?string $runId = null): self
    {
        return new self(AuditActor::Brain, 'brain', $abilities, 'brain', array_filter(['brain_run_id' => $runId]), $commandAbilities);
    }

    /**
     * The ability this actor needs to run the named command, whose handler
     * asks for $handlerAbility.
     */
    public function abilityFor(string $command, ?string $handlerAbility): ?string
    {
        if ($this->commandAbilities !== null) {
            return $this->commandAbilities[$command] ?? null;
        }

        return $handlerAbility;
    }

    /**
     * Whether this actor may run a command needing the ability. A null ability
     * means the command is for people and jobs alone: no credential may run it.
     */
    public function can(?string $ability): bool
    {
        if ($this->abilities === null) {
            return true;
        }

        return $ability !== null && in_array($ability, $this->abilities, true);
    }

    public function isCredential(): bool
    {
        return $this->type === AuditActor::Credential;
    }
}
