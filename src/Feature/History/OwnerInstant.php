<?php

declare(strict_types=1);

namespace voku\AgentUi\Feature\History;

use DateTimeImmutable;
use Exception;

/**
 * The one rule this page uses to decide whether an owner string names a moment.
 *
 * It exists because the rule has to be the same in three places - the guard on
 * TaskActivityEvent, the composer's choice between the timeline and the untimed
 * list, and the sort - and because the obvious implementation is wrong in two
 * ways. `new DateTimeImmutable($at)` accepts `now`, `tomorrow` and the empty
 * string, all of which would be resolved against the clock and land on the
 * timeline as today; and it throws only for input it cannot make any sense of,
 * so `2026-02-30` comes back as March 2nd, reporting the rollover through
 * getLastErrors() rather than by failing.
 *
 * So: the string must open with an absolute calendar date and time, it must
 * carry no relative component, it must parse, and the parse must be clean.
 * Anything else is a string the owner published and this page cannot place,
 * which is a fact about the owner and is shown as one rather than sorted into
 * the story by its spelling.
 */
final readonly class OwnerInstant
{
    /**
     * Deliberately wider than ATOM: `Z`, microseconds and a space separator are
     * all unambiguous, and narrowing to one owner's current formatting would
     * turn a harmless format change into facts vanishing off the timeline.
     */
    private const string ABSOLUTE = '/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}/';

    public static function parse(string $at): ?DateTimeImmutable
    {
        if (preg_match(self::ABSOLUTE, $at) !== 1) {
            return null;
        }

        // Anchoring only the start is not enough, because DateTimeImmutable goes
        // on reading after the date: `2026-09-24T08:00:00+00:00 +1 week` opens
        // with a real moment, passes the pattern, and comes back as October 1st
        // without a warning. Asking date_parse() whether anything relative was
        // found catches every shape of that - `+1 week`, `tomorrow`,
        // `next monday`, `3 days ago` - without this class having to enumerate
        // the absolute formats an owner is allowed to write.
        $parts = date_parse($at);
        if (isset($parts['relative'])) {
            return null;
        }

        try {
            $instant = new DateTimeImmutable($at);
        } catch (Exception) {
            return null;
        }

        $errors = DateTimeImmutable::getLastErrors();
        if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
            return null;
        }

        return $instant;
    }
}
