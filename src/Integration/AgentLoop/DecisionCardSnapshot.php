<?php

declare(strict_types=1);

namespace voku\AgentUi\Integration\AgentLoop;

use Throwable;
use voku\AgentLoop\Run\RunManifest;
use voku\AgentLoop\Workflow\WorkflowHumanDecisionProjector;

/**
 * agent-loop's typed decision card for the one decision_required gate a task waits on.
 *
 * The card is Loop's presentation of the decision subject: what is being
 * decided, why now, what may change, what proof exists and which choices exist.
 * agent-ui shows it verbatim before any decision form so a human reads the
 * owner's statement, not a UI paraphrase. It is never authority: the typed
 * decision forms still revalidate everything through agent-loop.
 *
 * Parsing is deliberately strict. A schema major version or a field shape this
 * UI does not understand yields no card at all instead of a partial one that
 * could misstate the decision. The one exception is the owner-defined subject
 * map: scalar facts are shown, a null fact is shown as "none", and nested
 * structures (such as blockers, which Loop restates as `why_now` lines) are left
 * to the owner views that present them.
 */
final readonly class DecisionCardSnapshot
{
    /**
     * @param list<array{label: string, value: string}> $subject
     * @param list<string> $whyNowChanges
     * @param list<string> $mayChange
     * @param list<string> $outOfScope
     * @param list<string> $plannedValidation
     * @param list<array{id: string, label: string, effect: string, command: ?string}> $choices
     * @param list<string> $unknown
     */
    public function __construct(
        public string $type,
        public string $language,
        public string $requestedLanguage,
        public string $title,
        public string $authorizes,
        public array $subject,
        public string $whyNowKind,
        public string $whyNowSummary,
        public array $whyNowChanges,
        public array $mayChange,
        public array $outOfScope,
        public string $validationState,
        public ?string $validationDetail,
        public array $plannedValidation,
        public string $reviewState,
        public ?string $reviewDetail,
        public array $choices,
        public array $unknown,
    ) {
    }

    /**
     * The card for the gate this manifest waits on, through Loop's own entry point.
     *
     * `projectManifest()` evaluates the run policy and passes its blockers, so
     * the card matches the one the CLI prints. A non-decision next action, a
     * card Loop could not build, or an owner failure yields null: the card is
     * presentation, and its absence must never hide the gate itself.
     */
    public static function fromManifest(string $projectRoot, RunManifest $manifest): ?self
    {
        try {
            $decision = (new WorkflowHumanDecisionProjector($projectRoot))->projectManifest($manifest);
        } catch (Throwable) {
            return null;
        }
        $card = $decision['card'] ?? null;

        return is_array($card) ? self::fromOwnerArray($card) : null;
    }

    /** @param array<mixed> $card */
    public static function fromOwnerArray(array $card): ?self
    {
        $schema = $card['schema_version'] ?? null;
        if (!is_string($schema) || !str_starts_with($schema, '1.')) {
            return null;
        }
        $type = $card['type'] ?? null;
        $language = $card['language'] ?? null;
        $requestedLanguage = $card['requested_language'] ?? null;
        $title = $card['title'] ?? null;
        $authorizes = $card['authorizes'] ?? null;
        $whyNow = $card['why_now'] ?? null;
        $proof = $card['proof'] ?? null;
        $choicesRaw = $card['choices'] ?? null;
        $subjectRaw = $card['subject'] ?? null;
        if (
            !is_string($type) || !is_string($title) || !is_string($authorizes)
            || !is_string($language) || !is_string($requestedLanguage)
            || !is_array($whyNow) || !is_array($proof) || !is_array($choicesRaw) || !is_array($subjectRaw)
        ) {
            return null;
        }

        $whyKind = $whyNow['kind'] ?? null;
        $whySummary = $whyNow['summary'] ?? null;
        $whyChanges = self::strings($whyNow['changes'] ?? []);
        $mayChange = self::strings($card['may_change'] ?? []);
        $outOfScope = self::strings($card['out_of_scope'] ?? []);
        $unknown = self::strings($card['unknown'] ?? []);
        $validation = $proof['validation'] ?? null;
        $review = $proof['review'] ?? null;
        if (
            !is_string($whyKind) || !is_string($whySummary) || $whyChanges === null
            || $mayChange === null || $outOfScope === null || $unknown === null
            || !is_array($validation) || !is_array($review)
            || !is_string($validation['state'] ?? null) || !is_string($review['state'] ?? null)
        ) {
            return null;
        }
        $planned = self::strings($validation['planned'] ?? []);
        $validationDetail = self::optionalString($validation['detail'] ?? null);
        $reviewDetail = self::optionalString($review['detail'] ?? null);
        if ($planned === null || $validationDetail === false || $reviewDetail === false) {
            return null;
        }

        $choices = [];
        foreach ($choicesRaw as $choice) {
            if (!is_array($choice)) {
                return null;
            }
            $command = self::optionalString($choice['command'] ?? null);
            if (
                !is_string($choice['id'] ?? null) || !is_string($choice['label'] ?? null)
                || !is_string($choice['effect'] ?? null) || $command === false
            ) {
                return null;
            }
            $choices[] = [
                'id' => $choice['id'],
                'label' => $choice['label'],
                'effect' => $choice['effect'],
                'command' => $command,
            ];
        }

        // The subject is an owner-defined map; only scalar facts are shown, in
        // owner order, and the key is shown as the owner wrote it.
        $subject = [];
        foreach ($subjectRaw as $key => $value) {
            if (!is_string($key) || !($value === null || is_scalar($value))) {
                continue;
            }
            $subject[] = ['label' => $key, 'value' => match (true) {
                $value === null => 'none',
                is_bool($value) => $value ? 'true' : 'false',
                default => (string) $value,
            }];
        }

        return new self(
            type: $type,
            language: $language,
            requestedLanguage: $requestedLanguage,
            title: $title,
            authorizes: $authorizes,
            subject: $subject,
            whyNowKind: $whyKind,
            whyNowSummary: $whySummary,
            whyNowChanges: $whyChanges,
            mayChange: $mayChange,
            outOfScope: $outOfScope,
            validationState: $validation['state'],
            validationDetail: $validationDetail,
            plannedValidation: $planned,
            reviewState: $review['state'],
            reviewDetail: $reviewDetail,
            choices: $choices,
            unknown: $unknown,
        );
    }

    /** @return list<string>|null */
    private static function strings(mixed $value): ?array
    {
        if (!is_array($value) || !array_is_list($value)) {
            return null;
        }
        foreach ($value as $item) {
            if (!is_string($item)) {
                return null;
            }
        }

        /** @var list<string> $value */
        return $value;
    }

    /** @return string|null|false false when the value is neither a string nor null */
    private static function optionalString(mixed $value): string|null|false
    {
        return $value === null || is_string($value) ? $value : false;
    }
}
