<?php

declare(strict_types=1);

namespace Storm\Bureau\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Bureau\Actor;
use Storm\Bureau\Exception\InvalidActor;

final class ActorTest extends TestCase
{
    #[Test]
    public function holds_id_and_type(): void
    {
        $actor = new Actor('user-123', 'App\\Identity\\User');

        $this->assertSame('user-123', $actor->id);
        $this->assertSame('App\\Identity\\User', $actor->type);
    }

    #[Test]
    public function trims_whitespace_from_id_and_type(): void
    {
        $actor = new Actor('  user-123  ', "\tuser\n");

        $this->assertSame('user-123', $actor->id);
        $this->assertSame('user', $actor->type);
    }

    #[Test]
    #[DataProvider('empty_strings')]
    public function rejects_empty_id(string $id): void
    {
        $this->expectException(InvalidActor::class);
        $this->expectExceptionMessageIsOrContains('Actor id must be a non-empty string.');

        new Actor($id, 'user');
    }

    #[Test]
    #[DataProvider('empty_strings')]
    public function rejects_empty_type(string $type): void
    {
        $this->expectException(InvalidActor::class);
        $this->expectExceptionMessageIsOrContains('Actor type must be a non-empty string.');

        new Actor('user-123', $type);
    }

    #[Test]
    public function equals_returns_true_for_identical_actors(): void
    {
        $a = new Actor('user-123', 'user');
        $b = new Actor('user-123', 'user');

        $this->assertTrue($a->equals($b));
        $this->assertTrue($b->equals($a));
    }

    #[Test]
    public function equals_returns_false_when_id_differs(): void
    {
        $a = new Actor('user-123', 'user');
        $b = new Actor('user-456', 'user');

        $this->assertFalse($a->equals($b));
    }

    #[Test]
    public function equals_returns_false_when_type_differs(): void
    {
        $a = new Actor('user-123', 'user');
        $b = new Actor('user-123', 'service');

        $this->assertFalse($a->equals($b));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function empty_strings(): array
    {
        return [
            'empty string' => [''],
            'spaces only' => ['   '],
            'tab only' => ["\t"],
            'newline only' => ["\n"],
            'mixed whitespace' => [" \t\n "],
        ];
    }

    // -------------------------------------------------------------------------
    // destination-safety hardening: encoding / controls / visual emptiness / size
    // -------------------------------------------------------------------------

    #[Test]
    #[Group('adversarial')]
    #[DataProvider('destination_toxic_values')]
    public function refuses_a_value_its_destinations_would_choke_on(string $value, string $why): void
    {
        // an Actor is copied into JSON headers, audit logs and text columns: what survives
        // construction must be safe THERE; an invalid byte would otherwise explode json_encode at the
        // dispatch, far from this boundary
        $this->expectException(InvalidActor::class);

        new Actor($value, 'user');
    }

    #[Test]
    #[Group('adversarial')]
    public function refuses_a_control_character_at_the_edge_instead_of_silently_rewriting_the_identity(): void
    {
        // only the padding whitespace is shed at the edge: a NUL byte is tainted input for the
        // control guard to refuse, never trimmed into a distinct valid identity
        $this->expectException(InvalidActor::class);

        new Actor("\0admin", 'user');
    }

    #[Test]
    #[Group('adversarial')]
    public function refuses_a_vertical_tab_at_the_edge_like_any_other_control(): void
    {
        // the default charlist's other control: shed, "\x0Badmin" would trim into the canonical
        // 'admin' identity exactly as the NUL would; refused, it stays what it is, tainted input
        $this->expectException(InvalidActor::class);

        new Actor("\x0Badmin", 'user');
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function destination_toxic_values(): array
    {
        return [
            'invalid UTF-8' => ["user\xC3\x28", 'json_encode would throw'],
            'NUL inside' => ["user\0admin", 'text columns truncate / escape'],
            'C0 control' => ["user\x01x", 'rides into logs raw'],
            'ANSI escape' => ["red\x1b[31m", 'console pollution'],
            'zero-width space' => ["a\u{200B}b", 'format character (\p{Cf})'],
            'NBSP only' => ["\u{00A0}\u{00A0}", 'visually anonymous yet distinct'],
            'EM space only' => ["\u{2003}", 'visually anonymous yet distinct'],
        ];
    }

    #[Test]
    public function refuses_a_component_over_the_byte_budget(): void
    {
        $this->expectException(InvalidActor::class);
        $this->expectExceptionMessageIsOrContains('the maximum is '.Actor::MAX_BYTES);

        new Actor(str_repeat('x', Actor::MAX_BYTES + 1), 'user');
    }

    #[Test]
    public function accepts_a_component_at_the_byte_budget_and_legitimate_unicode(): void
    {
        $atMax = new Actor(str_repeat('x', Actor::MAX_BYTES), 'user');
        $this->assertSame(Actor::MAX_BYTES, strlen($atMax->id));

        $unicode = new Actor('utilisateur-éàü-42', 'user');
        $this->assertSame('utilisateur-éàü-42', $unicode->id);
    }

    #[Test]
    #[DataProvider('unicode_separator_edges')]
    public function rejects_unicode_separators_at_an_identity_edge(string $value): void
    {
        // Do not normalize: merging two durable backend identifiers is as unsafe as keeping two
        // principals that render alike. The boundary must hand Actor its canonical identifier.
        $this->expectException(InvalidActor::class);
        $this->expectExceptionMessageIsOrContains('Unicode separator at an edge');

        new Actor($value, 'user');
    }

    #[Test]
    public function reports_a_separator_only_component_as_visually_empty(): void
    {
        $this->expectException(InvalidActor::class);
        $this->expectExceptionMessageIsOrContains('Unicode whitespace only');

        new Actor("\u{00A0}\u{00A0}", 'user');
    }

    #[Test]
    public function accepts_a_unicode_separator_inside_a_component(): void
    {
        $actor = new Actor("alice\u{00A0}admin", 'user');

        $this->assertSame("alice\u{00A0}admin", $actor->id);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unicode_separator_edges(): iterable
    {
        yield 'non-breaking space before text' => ["\u{00A0}alice"];
        yield 'em space after text' => ["alice\u{2003}"];
        yield 'narrow no-break space before text' => ["\u{202F}alice"];
        yield 'ideographic space after text' => ["alice\u{3000}"];
    }

    #[Test]
    public function a_private_use_codepoint_is_accepted_so_durability_never_rides_the_unicode_table(): void
    {
        // the refusal is exactly the documented rule, control and format characters; refusing the
        // whole \p{C} would make a DURABLE identity constructible on one host and refused after a
        // PHP upgrade shipping a newer Unicode table
        $actor = new Actor("backend-\u{E000}-id", 'service');

        $this->assertSame("backend-\u{E000}-id", $actor->id);
    }
}
