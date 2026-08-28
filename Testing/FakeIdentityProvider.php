<?php

declare(strict_types=1);

namespace Storm\Bureau\Testing;

use RuntimeException;
use Storm\Bureau\Actor;
use Storm\Bureau\IdentityProvider;

use function in_array;
use function is_string;

/**
 * The REFERENCE implementation of the port's lifecycle postconditions, not a stub that echoes its
 * constructor: every `authenticate()` clears the previously bound actor FIRST, so a refusal or an
 * outage can never leave a stale principal for `currentActor()`; the audit log reads it, and a
 * stale actor there is a wrong principal on a destructive action's audit line. The tests pin these
 * postconditions; a real adapter can mirror this shape or read a naturally-scoped source such as
 * Symfony's token storage.
 */
final class FakeIdentityProvider implements IdentityProvider
{
    private ?Actor $actor = null;

    /**
     * @param  array<string, Actor>  $accepted  credentials this fake accepts, mapped to their Actor
     * @param  list<string>  $unavailable  credentials that simulate a backend OUTAGE; fail closed
     */
    public function __construct(
        private readonly array $accepted = [],
        private readonly array $unavailable = [],
    ) {}

    public function authenticate(mixed $credentials): ?Actor
    {
        // the postcondition, enforced FIRST: whatever happens below, refusal, outage, or success,
        // currentActor() ends up being exactly this call's outcome, never the previous cycle's
        $this->actor = null;

        if (! is_string($credentials)) {
            return null;
        }

        if (in_array($credentials, $this->unavailable, true)) {
            throw new RuntimeException('IAM backend unavailable (simulated) — fail closed, never an anonymous flow.');
        }

        if (isset($this->accepted[$credentials])) {
            return $this->actor = $this->accepted[$credentials];
        }

        return null; // refused credentials: the ONLY meaning of null
    }

    public function currentActor(): ?Actor
    {
        return $this->actor;
    }
}
