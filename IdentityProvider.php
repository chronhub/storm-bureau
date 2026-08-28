<?php

declare(strict_types=1);

namespace Storm\Bureau;

use Throwable;

/**
 * The boundary seam where an app's auth backend plugs into Storm, whether Symfony Security,
 * JWT, LDAP, OAuth, session-based, or custom.
 *
 * Storm CONSUMES this contract: ApiOps' audit log reads `currentActor()` on every mutation it
 * records, so the lifecycle below is load-bearing; a stale actor here becomes a WRONG PRINCIPAL
 * on a destructive action's audit line, an indelible lie. A controller or message consumer entry
 * typically calls `authenticate()` once at the boundary, stamps the resulting Actor onto the
 * dispatched envelope, and downstream layers read the actor via MessageContext.
 *
 * LIFECYCLE POSTCONDITIONS, the implementation's contract:
 *
 * - After every `authenticate()` call, `currentActor()` returns EXACTLY that call's result,
 *   including null: a failed authentication must clear any previously bound actor, never leave
 *   it dangling for the next caller;
 *
 * - An exception thrown during `authenticate()` leaves `currentActor()` null, never the previous
 *   actor;
 *
 * - The bound actor's scope is ONE request or ONE consumed message: a shared/worker process must
 *   reset between cycles, via a `finally` at the consumer entry or storage scoped to the cycle.
 *   A per-request source such as Symfony's token storage is conformant ONLY when `authenticate()`
 *   is its sole writer: a firewall writing the same storage on another path leaves the two
 *   structurally unable to agree, and the audit would read a principal `authenticate()` refused.
 *   A stateful property on a shared service is where the between-cycles leak lives.
 *
 * FAIL CLOSED: `null` from `authenticate()` means REFUSED CREDENTIALS, nothing else. A backend
 * that cannot answer, such as an IAM outage or malformed token infrastructure, must THROW rather
 * than return null; the implementation owns the exception type, and an outage must not demote
 * authenticated traffic to an anonymous flow. A system-originated action that should appear in the
 * audit is an explicit `Actor(id, 'system')`, never the absence of an actor.
 *
 * Implementations live in the app, for example a SymfonyAuthAdapter that wraps Symfony Security
 * and builds an Actor from the current user. `Testing\FakeIdentityProvider` SHIPS as the
 * REFERENCE shape, reachable from a consuming app's own suite: it honors every postcondition
 * above and this package's tests pin them.
 *
 * Lives in the Bureau package rather than Contracts because it references the concrete Actor VO,
 * which keeps chronhub/storm-contracts free of dependencies on implementation packages.
 */
interface IdentityProvider
{
    /**
     * Resolve credentials into an Actor, or null when the credentials are REFUSED.
     *
     * Credentials are typed as mixed because their shape depends on the backend, such as a
     * Symfony UserInterface, a JWT string, a username and password pair, or an OAuth token;
     * implementations narrow the type internally.
     *
     * @throws Throwable when the backend cannot ANSWER, failing closed; the implementation owns the
     *                   concrete type
     */
    public function authenticate(mixed $credentials): ?Actor;

    /**
     * The Actor bound by THIS request or consumer cycle's last `authenticate()`, or null when
     * none succeeded in this cycle, whether anonymous, system-originated, or pre-boundary flows.
     */
    public function currentActor(): ?Actor;
}
