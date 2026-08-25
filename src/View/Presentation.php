<?php

declare(strict_types=1);

namespace voku\AgentUi\View;

/**
 * Presentation-only vocabulary mapping for the UI.
 *
 * This is deliberately the *only* place agent-ui interprets an owner state
 * string, and it interprets it for colour, glyph and wording — never for
 * meaning. It decides nothing about the lifecycle: `WorkflowSnapshot::$state`
 * and `WorkflowSnapshot::$nextAction` already answer what is true and what
 * happens next, and both come from agent-loop.
 *
 * An unrecognised state renders as neutral rather than being guessed at, so a
 * vocabulary agent-loop adds tomorrow shows up as "not styled yet" instead of
 * as a confident lie.
 */
final class Presentation
{
    public const string TONE_OK = 'ok';
    public const string TONE_ATTENTION = 'attention';
    public const string TONE_BLOCKED = 'blocked';
    public const string TONE_NEUTRAL = 'neutral';

    /**
     * Owner vocabulary as agent-loop, agent-kanban, agent-recall-compiler and
     * agent-learning actually emit it.
     *
     * @var array<string, string>
     */
    private const array TONES = [
        // satisfied
        'ok' => self::TONE_OK,
        'ready' => self::TONE_OK,
        'ready_to_close' => self::TONE_OK,
        'complete' => self::TONE_OK,
        'approved' => self::TONE_OK,
        'current' => self::TONE_OK,
        'compiled' => self::TONE_OK,
        'active' => self::TONE_OK,
        'decided' => self::TONE_OK,
        'passed' => self::TONE_OK,
        'satisfied' => self::TONE_OK,
        'accepted_risk' => self::TONE_OK,
        'linked' => self::TONE_OK,
        'present' => self::TONE_OK,
        'done' => self::TONE_OK,
        'not_required' => self::TONE_OK,
        'run_bound' => self::TONE_OK,
        'completed' => self::TONE_OK,
        'pass' => self::TONE_OK,
        'findings_recorded' => self::TONE_OK,
        'no_durable_learning' => self::TONE_OK,
        // needs a person or a step
        'incomplete' => self::TONE_ATTENTION,
        'warn' => self::TONE_ATTENTION,
        'stale' => self::TONE_ATTENTION,
        'unacknowledged' => self::TONE_ATTENTION,
        'pending_recall' => self::TONE_ATTENTION,
        'pending_approval' => self::TONE_ATTENTION,
        'candidate' => self::TONE_ATTENTION,
        'changes_required' => self::TONE_ATTENTION,
        'needs_clarification' => self::TONE_ATTENTION,
        'follow_up_required' => self::TONE_ATTENTION,
        'decision_required' => self::TONE_ATTENTION,
        'host_work' => self::TONE_ATTENTION,
        // stopped
        'blocked' => self::TONE_BLOCKED,
        'failed' => self::TONE_BLOCKED,
        'fail' => self::TONE_BLOCKED,
        'invalid' => self::TONE_BLOCKED,
        'rejected' => self::TONE_BLOCKED,
        'superseded' => self::TONE_BLOCKED,
        // ordinary "not there yet" facts, which are not problems
        'missing' => self::TONE_NEUTRAL,
        'none' => self::TONE_NEUTRAL,
        'unavailable' => self::TONE_NEUTRAL,
        'not_configured' => self::TONE_NEUTRAL,
        'not_linked' => self::TONE_NEUTRAL,
        'ad_hoc_task' => self::TONE_NEUTRAL,
        'pending_close' => self::TONE_NEUTRAL,
        'run_missing' => self::TONE_NEUTRAL,
        'planned' => self::TONE_NEUTRAL,
        'governed' => self::TONE_NEUTRAL,
        'experiment' => self::TONE_NEUTRAL,
        'command' => self::TONE_NEUTRAL,
        'todo' => self::TONE_NEUTRAL,
    ];

    public static function tone(?string $state): string
    {
        if ($state === null || $state === '') {
            return self::TONE_NEUTRAL;
        }

        return self::TONES[strtolower($state)] ?? self::TONE_NEUTRAL;
    }

    /** Renders a snake_cased owner token as readable words, without renaming it. */
    public static function label(?string $state): string
    {
        if ($state === null || $state === '') {
            return 'unknown';
        }

        return str_replace('_', ' ', $state);
    }

    /**
     * Plain-language gloss for `next_action_kind`.
     *
     * The wording explains how a host must treat the action; the action itself
     * is always rendered verbatim next to it.
     */
    public static function nextActionKindHint(string $kind): string
    {
        return match ($kind) {
            'command' => 'Run this exactly as written.',
            'host_work' => 'Irreducible implementation work. This is a description, not a command.',
            'decision_required' => 'A human decision is required before this can run.',
            'none' => 'Nothing further is required.',
            default => 'See agent-loop for how to treat this action.',
        };
    }

    /** Reads the state of one manifest reference without asserting what it means. */
    public static function referenceState(mixed $reference): ?string
    {
        if (!is_array($reference)) {
            return null;
        }
        $state = $reference['state'] ?? null;

        return is_string($state) ? $state : null;
    }

    public static function referenceOwner(mixed $reference): ?string
    {
        if (!is_array($reference)) {
            return null;
        }
        $owner = $reference['owner'] ?? null;

        return is_string($owner) ? $owner : null;
    }
}
