# Storm Bureau — who did it, durably

Bureau is Storm's identity substrate: the `Actor` value object (WHO initiated the work), its
`InvalidActor` refusal, and the `IdentityProvider` seam where an app's auth backend plugs in.
Deliberately tiny — no IAM backend, no security framework, no role model: semantics live in the
app; Bureau owns the shape and the invariants of provenance.

## Actor — provenance, not authentication

`Actor(id, type)` is an immutable, atomic pair. `id` is the backend's CANONICAL identifier (a
user UUID, a service id — never a raw email or username: Actor does not case-fold, a
non-canonical id splits one principal across events). `type` is a short, STABLE applicative
alias — `user`, `service`, `system` — never a PHP FQCN: `type` participates in equality and in
every audit line, and an FQCN is rename-fragile and leaks a PHP convention into a transport-
neutral, durable provenance.

Both components are validated for their destinations (JSON headers, audit logs, text columns):
valid UTF-8, no control/format characters, not visually empty (Unicode whitespace alone), at
most `Actor::MAX_BYTES` (256) bytes each. What this VO cannot do is tell a trusted claim from a
forged one — construct it ONLY from a server-resolved principal, NEVER from request input: once
stamped on an event, an Actor is a permanent, unverifiable audit.

## Trust and propagation

The Actor is built ONCE at the boundary (an HTTP controller resolving the security user, a
consumer entry reading the incoming stamp), rides as an `ActorStamp` on the envelope, travels as
the `__actor_id` / `__actor_type` header pair, and is read downstream via `MessageContext` —
never re-resolved from an auth service inside framework or domain code. The pair is ATOMIC at
every frontier: an enricher writes both or neither, the wire edge and the stamp normalizer refuse
a lone half (`halfActorIdentity`) — a partial pair is corruption, never "no actor".

## IdentityProvider — the seam, with a load-bearing lifecycle

Storm consumes this contract: the ApiOps audit log records `currentActor()` on every operator
mutation, so the lifecycle postconditions are not advice — a stale actor is a WRONG PRINCIPAL on
a destructive action's audit line:

- after every `authenticate()`, `currentActor()` returns exactly that call's result — null
  included: a refusal clears any previously bound actor;
- an exception during `authenticate()` leaves `currentActor()` null;
- the bound actor's scope is one request or one consumed message — a worker resets between
  cycles (a `finally` at the consumer entry, or storage scoped to the cycle).

FAIL CLOSED: `null` means refused credentials, nothing else. A backend that cannot answer must
THROW — an IAM outage never demotes authenticated traffic to an anonymous flow. A system action
that should appear in the audit is an explicit `Actor(id, 'system')`, never the absence of one.

`Testing/FakeIdentityProvider` SHIPS as the reference shape — it honors every postcondition and
its tests pin them; mirror it. Reading Symfony's token storage straight from `currentActor()` is
conformant ONLY when `authenticate()` is that storage's sole writer; with a firewall also writing
it, a refused `authenticate()` would leave the firewall's user dangling — the exact wrong
principal this port exists to keep off an audit line. The sketch below therefore keeps its own
per-cycle binding and reads the token storage as input alone:

```php
final class SymfonyAuthAdapter implements IdentityProvider
{
    private ?Actor $actor = null;

    public function __construct(private TokenStorageInterface $tokens) {}

    public function authenticate(mixed $credentials): ?Actor
    {
        // the postcondition first: whatever happens below, currentActor() is THIS call's outcome
        $this->actor = null;

        // resolve $credentials against Security; return null ONLY on refusal, throw on outage
        $user = $this->tokens->getToken()?->getUser();

        return $this->actor = $user instanceof User ? new Actor($user->getUuid(), 'user') : null;
    }

    public function currentActor(): ?Actor
    {
        return $this->actor;
    }
}
```

## Resources

This package is developed in the `chronhub/storm` monorepo; a standalone repository for it is a
READ-ONLY subtree split. Report issues and open pull requests on the monorepo, where the tests,
the architecture gates and the full internal documentation live.

---

*Pre-version: this package changes without deprecation cycles — pin a commit if you need
stability, expect resets rather than migrations until the first tagged version.*
