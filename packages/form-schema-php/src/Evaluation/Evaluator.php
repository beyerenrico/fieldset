<?php

declare(strict_types=1);

namespace FormSchema\Evaluation;

use FormSchema\Definition\Definition;
use FormSchema\Definition\DependencyGraph;
use FormSchema\Json\Json;
use FormSchema\Logic\JsonLogic;
use FormSchema\Logic\LogicEvaluator;

/**
 * Conforming evaluator (spec §5.5): normalize, iterate visibility → defaults → calculations
 * (up to 5 rounds), strip invisible values, validate. Normalization errors come first.
 */
final readonly class Evaluator
{
    public const MAX_ROUNDS = 5;

    public function __construct(
        private LogicEvaluator $logic = new JsonLogic,
    ) {}

    /**
     * @param  mixed  $definition  definition JSON tree (or a Definition)
     * @param  mixed  $data  submission JSON tree; anything but an object counts as {}
     */
    public function evaluate(mixed $definition, mixed $data, EvaluationContext $context): EvaluationResult
    {
        $parsed = Definition::fromJson($definition);
        $normalized = Normalizer::normalize($parsed, Json::copy($data));
        $state = new EvaluationState($parsed, $context, $this->logic, $normalized->values);
        $order = DependencyGraph::build($parsed)->order();

        for ($round = 0; $round < self::MAX_ROUNDS; $round++) {
            $before = $state->fingerprint();
            Passes::visibility($state);
            Passes::defaults($state);
            Passes::calculations($state, $order);

            if ($state->fingerprint() === $before) {
                break;
            }
        }

        Passes::strip($state);

        return new EvaluationResult(
            DataTree::toJson($parsed, $parsed->rootScope(), $state->values()),
            [...$normalized->errors, ...SubmissionValidator::validate($state)],
            $state->visibility(),
        );
    }
}
