<?php

declare(strict_types=1);

namespace Fieldset\Evaluation;

use Fieldset\Definition\Definition;
use Fieldset\Json\Json;
use Fieldset\Logic\LogicError;
use Fieldset\Logic\LogicEvaluator;
use stdClass;

/**
 * Working state of one evaluation run (data, visibility, cached logic view).
 *
 * Internal and short-lived: created per Evaluator::evaluate() call and never shared. Values
 * are replaced through DataTree's copy-on-write helpers; the logic view is rebuilt lazily
 * after any change.
 */
final class EvaluationState
{
    /** @var array<string, bool> */
    private array $visibility = [];

    private ?stdClass $view = null;

    /**
     * @param  array<array-key, mixed>  $values
     */
    public function __construct(
        public readonly Definition $definition,
        private readonly EvaluationContext $context,
        private readonly LogicEvaluator $logic,
        private array $values,
    ) {}

    /**
     * @return array<array-key, mixed>
     */
    public function values(): array
    {
        return $this->values;
    }

    /**
     * @return array<string, bool>
     */
    public function visibility(): array
    {
        return $this->visibility;
    }

    /**
     * Elements are visible until the visibility pass has decided otherwise.
     */
    public function isVisible(string $pointer): bool
    {
        return $this->visibility[$pointer] ?? true;
    }

    public function setVisible(string $pointer, bool $visible): void
    {
        if ($this->isVisible($pointer) !== $visible) {
            $this->view = null;
        }

        $this->visibility[$pointer] = $visible;
    }

    /**
     * @param  list<array{string, int}>  $rowPath
     */
    public function value(array $rowPath, string $key): mixed
    {
        return DataTree::get($this->values, $rowPath, $key);
    }

    /**
     * @param  list<array{string, int}>  $rowPath
     */
    public function has(array $rowPath, string $key): bool
    {
        return DataTree::has($this->values, $rowPath, $key);
    }

    /**
     * @param  list<array{string, int}>  $rowPath
     */
    public function set(array $rowPath, string $key, mixed $value): void
    {
        $this->values = DataTree::set($this->values, $rowPath, $key, $value);
        $this->view = null;
    }

    /**
     * @param  list<array{string, int}>  $rowPath
     */
    public function remove(array $rowPath, string $key): void
    {
        $this->values = DataTree::remove($this->values, $rowPath, $key);
        $this->view = null;
    }

    /**
     * @return list<Instance>
     */
    public function instances(): array
    {
        return InstanceWalker::walk($this->definition, $this->values);
    }

    /**
     * Evaluates logic in the scope of the given row; a LogicError (unknown operator, too deep)
     * yields null.
     *
     * @param  list<array{string, int}>  $rowPath
     */
    public function evaluate(mixed $logic, array $rowPath): mixed
    {
        $this->view ??= LogicView::build($this->definition, $this->values, $this->visibility);

        try {
            return $this->logic->evaluate($logic, LogicView::scope($this->view, $rowPath, $this->context));
        } catch (LogicError) {
            return null;
        }
    }

    /**
     * Canonical JSON of data and visibility; rounds compare it structurally (spec §5.5).
     */
    public function fingerprint(): string
    {
        $data = DataTree::toJson($this->definition, $this->definition->rootScope(), $this->values);

        return Json::canonical([$data, (object) $this->visibility]);
    }
}
