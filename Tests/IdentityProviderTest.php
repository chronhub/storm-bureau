<?php

declare(strict_types=1);

namespace Storm\Bureau\Tests;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Storm\Bureau\Actor;
use Storm\Bureau\Testing\FakeIdentityProvider;

/**
 * Pins the port's lifecycle postconditions on the reference implementation, the sequences a
 * signature-conformant-but-leaky provider gets wrong: a refusal or an outage after a success must
 * leave NO actor bound, because the ApiOps audit log records whatever `currentActor()` says.
 */
final class IdentityProviderTest extends TestCase
{
    #[Test]
    public function authenticates_and_binds_the_actor(): void
    {
        $actor = new Actor('user-123', 'user');
        $provider = new FakeIdentityProvider(['good' => $actor]);

        self::assertSame($actor, $provider->authenticate('good'));
        self::assertSame($actor, $provider->currentActor());
    }

    #[Test]
    #[Group('adversarial')]
    public function a_refusal_after_a_success_clears_the_bound_actor(): void
    {
        // the leak the contract forbids: refused credentials returned null while
        // currentActor() kept exposing the PREVIOUS principal; the audit would have attributed
        // the next mutation to the wrong actor
        $provider = new FakeIdentityProvider(['good' => new Actor('user-123', 'user')]);

        $provider->authenticate('good');

        self::assertNull($provider->authenticate('bad'));
        self::assertNull($provider->currentActor(), "a refusal leaves NO actor bound — never the previous cycle's");
    }

    #[Test]
    #[Group('adversarial')]
    public function an_outage_throws_fail_closed_and_leaves_no_actor_bound(): void
    {
        // an unanswerable backend must THROW, never demote to anonymous, and must not leave the
        // previous principal dangling either
        $provider = new FakeIdentityProvider(['good' => new Actor('user-123', 'user')], unavailable: ['down']);

        $provider->authenticate('good');

        $thrown = null;

        try {
            $provider->authenticate('down');
        } catch (RuntimeException $exception) {
            $thrown = $exception;
        }

        self::assertInstanceOf(RuntimeException::class, $thrown, 'an IAM outage must throw, not return null');
        self::assertNull($provider->currentActor(), 'an exception leaves NO actor bound');
    }

    #[Test]
    public function refused_credentials_return_null_and_bind_nothing(): void
    {
        $provider = new FakeIdentityProvider;

        self::assertNull($provider->authenticate('bad-credentials'));
        self::assertNull($provider->currentActor());
    }

    #[Test]
    public function non_string_credentials_are_refused_and_clear_the_bound_actor(): void
    {
        $provider = new FakeIdentityProvider(['good' => new Actor('user-123', 'user')]);
        $provider->authenticate('good');

        self::assertNull($provider->authenticate(['token' => 'good']));
        self::assertNull($provider->currentActor());
    }
}
