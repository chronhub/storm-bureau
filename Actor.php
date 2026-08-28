<?php

declare(strict_types=1);

namespace Storm\Bureau;

use Storm\Bureau\Exception\InvalidActor;

/**
 * Who initiated the work: a stable, transport-neutral identity carried through Storm.
 *
 * Together, id and type name the actor: id is the actor's identifier in the auth backend,
 * such as a user UUID, a service id, or a JWT subclaim; type is the CLASS of actor, a short,
 * STABLE applicative alias such as "user", "service", or "system", never a PHP FQCN: `type`
 * participates in equality and in every audit line, and an FQCN is rename-fragile and leaks a PHP
 * convention into a provenance that is transport-neutral and durable. Pick one alias per kind of
 * principal and keep it; the same human appearing as `(user-1, App\User)` here and `(user-1,
 * user)` there is two different actors forever. The framework stores both and treats them as
 * opaque strings; semantics live in the app. Edge padding is trimmed, space, tab, CR and LF only,
 * while any other control character or a Unicode separator at either edge is refused rather than
 * normalized silently, so pass
 * the backend's canonical identifier: Actor does not case-fold, and a non-canonical id such as a
 * raw email or a username risks splitting one principal across events.
 *
 * Both components are validated for their DESTINATIONS of JSON headers, audit logs and text
 * columns:
 *
 * - Valid UTF-8; an invalid byte would explode `json_encode` at the dispatch or the append, far
 *   from this boundary;
 *
 * - No control or format characters;
 *
 * - Not visually empty, and no Unicode separator at either edge. Rejecting avoids two principals
 *   that render alike; normalization would be worse because it could merge durable identifiers;
 *
 * - At most {@see self::MAX_BYTES} bytes each, since the pair is copied into every message, event
 *   and journal it touches.
 *
 * Constructed at the boundary, such as an HTTP controller or a message consumer entry, and
 * stamped onto the envelope via ActorStamp; downstream layers read it via MessageContext.
 *
 * Warning: an Actor is provenance; once stamped on an event it is a permanent, unverifiable
 * audit. Construct it ONLY from a server-resolved principal, the authenticated security user,
 * NEVER from request or user input: this VO cannot tell a trusted claim from a forged one, so a
 * forged Actor becomes an indelible audit lie. Across an async transport the actor is
 * re-materialized from the wire header by the serializer's `decode()` and is therefore only as
 * trustworthy as the channel; recorded provenance inherits the broker's trust boundary, and
 * Storm is currently the sole producer.
 *
 * Orthogonal to the tenant identity, which lives in the `Message` headers and the Story stamps,
 * never here: who acted and for which tenant are two separate facts.
 */
final readonly class Actor
{
    /** Per-component byte budget: the pair rides in every message header, event and audit line. */
    public const int MAX_BYTES = 256;

    public string $id;

    public string $type;

    /**
     * @throws InvalidActor when id or type is empty, not valid UTF-8, visually empty as Unicode
     *                      whitespace only, carries control or format characters, or exceeds
     *                      {@see self::MAX_BYTES} bytes
     */
    public function __construct(string $id, string $type)
    {
        // Edge padding only, space, tab, CR and LF, never PHP's default charlist: the default also
        // sheds NUL and the vertical tab, control characters the guard below must refuse, not
        // whitespace to trim; shedding one would rewrite a tainted byte into a distinct valid identity
        $this->id = self::assertComponent(trim($id, " \t\n\r"), 'id');
        $this->type = self::assertComponent(trim($type, " \t\n\r"), 'type');
    }

    public function equals(self $other): bool
    {
        return $this->id === $other->id && $this->type === $other->type;
    }

    /**
     * Validated for the DESTINATIONS, not for aesthetics: a control character rides into logs and
     * consoles, and a Unicode-whitespace-only value is a visually anonymous yet distinct principal.
     *
     * @throws InvalidActor
     */
    private static function assertComponent(string $value, string $component): string
    {
        if ($value === '') {
            throw $component === 'id' ? InvalidActor::emptyId() : InvalidActor::emptyType();
        }

        // the byte budget FIRST: it bounds the work every scan below can be made to do
        if (strlen($value) > self::MAX_BYTES) {
            throw InvalidActor::tooLong($component, strlen($value), self::MAX_BYTES);
        }

        if (preg_match('//u', $value) !== 1) {
            throw InvalidActor::invalidEncoding($component);
        }

        // exactly the documented rule, control and format, never the whole \p{C}: Co and Cn would
        // make a DURABLE identity's acceptance ride the PCRE build's Unicode table, constructible
        // on one host and refused after a PHP upgrade on another
        if (preg_match('/[\p{Cc}\p{Cf}\p{Cs}]/u', $value) === 1) {
            throw InvalidActor::controlCharacters($component);
        }

        if (preg_match('/^\p{Z}+$/u', $value) === 1) {
            throw InvalidActor::visuallyEmpty($component);
        }

        if (preg_match('/^\p{Z}|\p{Z}$/u', $value) === 1) {
            throw InvalidActor::separatorAtEdge($component);
        }

        return $value;
    }
}
