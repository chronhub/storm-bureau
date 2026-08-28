<?php

declare(strict_types=1);

namespace Storm\Bureau\Exception;

use InvalidArgumentException;

/**
 * Thrown when an `Actor` cannot be constructed: empty, invalid UTF-8, control characters,
 * visually-empty Unicode whitespace, edge separators, or over the byte budget. A construction or caller bug, an
 * `InvalidArgumentException` in the `LogicException` family; fail fast. The alternative is a
 * principal that explodes JSON encoding or pollutes the audit far from this boundary.
 */
final class InvalidActor extends InvalidArgumentException
{
    public static function emptyId(): self
    {
        return new self('Actor id must be a non-empty string.');
    }

    public static function emptyType(): self
    {
        return new self('Actor type must be a non-empty string.');
    }

    public static function invalidEncoding(string $component): self
    {
        return new self(sprintf('Actor %s is not valid UTF-8 — it would fail JSON encoding at the dispatch or the append, far from this boundary.', $component));
    }

    public static function controlCharacters(string $component): self
    {
        return new self(sprintf('Actor %s carries control or format characters — they would ride into headers, logs and audit lines.', $component));
    }

    public static function visuallyEmpty(string $component): self
    {
        return new self(sprintf('Actor %s is Unicode whitespace only — a visually anonymous yet distinct principal.', $component));
    }

    public static function separatorAtEdge(string $component): self
    {
        return new self(sprintf('Actor %s has a Unicode separator at an edge — it would create visually identical principals in durable audit lines.', $component));
    }

    public static function tooLong(string $component, int $bytes, int $max): self
    {
        return new self(sprintf('Actor %s is %d bytes, the maximum is %d — the pair is copied into every message, event and journal it touches.', $component, $bytes, $max));
    }
}
