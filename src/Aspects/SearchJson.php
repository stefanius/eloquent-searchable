<?php

namespace TestMonitor\Searchable\Aspects;

use Illuminate\Support\Str;
use Illuminate\Support\Collection;
use TestMonitor\Searchable\Weights;
use Illuminate\Database\Eloquent\Builder;
use TestMonitor\Searchable\Contracts\Search;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * @template TModelClass of \Illuminate\Database\Eloquent\Model
 *
 * @template-implements \App\Models\Search\Search<TModelClass>
 */
class SearchJson implements Search
{
    /**
     * @var array
     */
    protected array $relationConstraints = [];

    /**
     * @param bool $ignoreKeys
     */
    public function __construct(protected bool $ignoreKeys = false)
    {
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model> $query
     * @param \TestMonitor\Searchable\Weights $weights
     * @param string $property
     * @param string $term
     * @param int $weight
     *
     * @throws \InvalidArgumentException
     *
     * @return mixed
     */
    public function __invoke(Builder $query, Weights $weights, string $property, string $term, int $weight = 1): void
    {
        $term = Str::of($term)->pipe('addslashes')->lower();

        if ($this->isRelationProperty($query, $property)) {
            $this->withRelationConstraint($query, $weights, $property, $term, $weight);

            return;
        }

        if ($this->ignoreKeys) {
            $query->whereRaw("JSON_EXTRACT({$property}, '$.*') like \"%{$term}%\"");
        } else {
            $query->where($query->qualifyColumn($property), 'LIKE', "%{$term}%");
        }

        $weights->registerIf(empty($this->relationConstraints), $query, $weight);
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $property
     * @return bool
     */
    protected function isRelationProperty(Builder $query, string $property): bool
    {
        if (! Str::contains($property, '.')) {
            return false;
        }

        $firstRelationship = explode('.', $property)[0];

        if (! method_exists($query->getModel(), $firstRelationship)) {
            return false;
        }

        return is_a($query->getModel()->{$firstRelationship}(), Relation::class);
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param \TestMonitor\Searchable\Weights $weights
     * @param string $property
     * @param string $term
     * @param int $weight
     *
     * @throws \RuntimeException
     */
    protected function withRelationConstraint(
        Builder $query,
        Weights $weights,
        string $property,
        string $term,
        int $weight = 1
    ): void {
        [$relation, $property] = collect(explode('.', $property))
            ->pipe(fn (Collection $parts) => [
                $parts->except(count($parts) - 1)->implode('.'),
                $parts->last(),
            ]);

        $query->whereHas($relation, function (Builder $query) use ($property, $term, $weight, $weights) {
            $this->relationConstraints[] = $property = $query->qualifyColumn($property);

            $this->__invoke($query, $weights, $property, $term, $weight);
        });
    }
}