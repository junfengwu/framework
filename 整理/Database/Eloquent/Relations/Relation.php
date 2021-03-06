<?php
namespace Leaps\Database\Eloquent\Relations;
use Leaps\Database\Eloquent\Model;
use Leaps\Database\Eloquent\Builder;
use Leaps\Database\Query\Expression;
use Leaps\Database\Eloquent\Collection;
abstract class Relation
{

    /**
     * The Eloquent query builder instance.
     *
     * @var \Leaps\Database\Eloquent\Builder
     */
    protected $query;

    /**
     * The parent model instance.
     *
     * @var \Leaps\Database\Eloquent\Model
     */
    protected $parent;

    /**
     * The related model instance.
     *
     * @var \Leaps\Database\Eloquent\Model
     */
    protected $related;

    /**
     * Create a new relation instance.
     *
     * @param \Leaps\Database\Eloquent\Builder
     * @param \Leaps\Database\Eloquent\Model
     * @return void
     */
    public function __construct(Builder $query, Model $parent)
    {
        $this->query = $query;
        $this->parent = $parent;
        $this->related = $query->getModel ();
        $this->addConstraints ();
    }

    /**
     * Set the base constraints on the relation query.
     *
     * @return void
     */
    abstract public function addConstraints();

    /**
     * Set the constraints for an eager load of the relation.
     *
     * @param array $models
     * @return void
     */
    abstract public function addEagerConstraints(array $models);

    /**
     * Initialize the relation on a set of models.
     *
     * @param array $models
     * @param string $relation
     * @return void
     */
    abstract public function initRelation(array $models, $relation);

    /**
     * Match the eagerly loaded results to their parents.
     *
     * @param array $models
     * @param \Leaps\Database\Eloquent\Collection $results
     * @param string $relation
     * @return array
     */
    abstract public function match(array $models, Collection $results, $relation);

    /**
     * Get the results of the relationship.
     *
     * @return mixed
     */
    abstract public function getResults();

    /**
     * Touch all of the related models for the relationship.
     *
     * @return void
     */
    public function touch()
    {
        $column = $this->getRelated ()->getUpdatedAtColumn ();
        $this->rawUpdate ( array (
                $column => $this->getRelated ()->freshTimestamp ()
        ) );
    }

    /**
     * Restore all of the soft deleted related models.
     *
     * @return int
     */
    public function restore()
    {
        return $this->query->withTrashed ()->restore ();
    }

    /**
     * Run a raw update against the base query.
     *
     * @param array $attributes
     * @return int
     */
    public function rawUpdate(array $attributes = array())
    {
        return $this->query->update ( $attributes );
    }

    /**
     * Remove the original where clause set by the relationship. The remaining
     * constraints on the query will be reset and returned.
     *
     * @return array
     */
    public function getAndResetWheres()
    {
        if ( $this->query->getModel ()->isSoftDeleting () ) {
            $this->removeSecondWhereClause ();
        } else {
            $this->removeFirstWhereClause ();
        }
        return $this->getBaseQuery ()->getAndResetWheres ();
    }

    /**
     * Remove the first where clause from the relationship query.
     *
     * @return void
     */
    protected function removeFirstWhereClause()
    {
        $first = array_shift ( $this->getBaseQuery ()->wheres );
        return $this->removeWhereBinding ( $first );
    }

    /**
     * Remove the second where clause from the relationship query.
     *
     * @return void
     */
    protected function removeSecondWhereClause()
    {
        $wheres = & $this->getBaseQuery ()->wheres;
        $second = $wheres [1];
        unset ( $wheres [1] );
        $wheres = array_values ( $wheres );
        return $this->removeWhereBinding ( $second );
    }

    /**
     * Remove a where clause from the relationship query.
     *
     * @param array $clause
     * @return void
     */
    public function removeWhereBinding($clause)
    {
        $query = $this->getBaseQuery ();
        $bindings = $query->getBindings ();
        if ( array_key_exists ( 'value', $clause ) ) {
            $bindings = array_slice ( $bindings, 1 );
        }
        $query->setBindings ( array_values ( $bindings ) );
    }

    /**
     * Add the constraints for a relationship count query.
     *
     * @param \Leaps\Database\Eloquent\Builder $query
     * @return \Leaps\Database\Eloquent\Builder
     */
    public function getRelationCountQuery(Builder $query)
    {
        $query->select ( new Expression ( 'count(*)' ) );
        $key = $this->wrap ( $this->parent->getQualifiedKeyName () );
        return $query->where ( $this->getForeignKey (), '=', new Expression ( $key ) );
    }

    /**
     * Get all of the primary keys for an array of models.
     *
     * @param array $models
     * @return array
     */
    protected function getKeys(array $models)
    {
        return array_values ( array_map ( function ($value)
        {
            return $value->getKey ();
        }, $models ) );
    }

    /**
     * Get the underlying query for the relation.
     *
     * @return \Leaps\Database\Eloquent\Builder
     */
    public function getQuery()
    {
        return $this->query;
    }

    /**
     * Get the base query builder driving the Eloquent builder.
     *
     * @return \Leaps\Database\Query\Builder
     */
    public function getBaseQuery()
    {
        return $this->query->getQuery ();
    }

    /**
     * Get the parent model of the relation.
     *
     * @return \Leaps\Database\Eloquent\Model
     */
    public function getParent()
    {
        return $this->parent;
    }

    /**
     * Get the related model of the relation.
     *
     * @return \Leaps\Database\Eloquent\Model
     */
    public function getRelated()
    {
        return $this->related;
    }

    /**
     * Get the name of the "created at" column.
     *
     * @return string
     */
    public function createdAt()
    {
        return $this->parent->getCreatedAtColumn ();
    }

    /**
     * Get the name of the "updated at" column.
     *
     * @return string
     */
    public function updatedAt()
    {
        return $this->parent->getUpdatedAtColumn ();
    }

    /**
     * Get the name of the related model's "updated at" column.
     *
     * @return string
     */
    public function relatedUpdatedAt()
    {
        return $this->related->getUpdatedAtColumn ();
    }

    /**
     * Wrap the given value with the parent query's grammar.
     *
     * @param string $value
     * @return string
     */
    public function wrap($value)
    {
        return $this->parent->getQuery ()->getGrammar ()->wrap ( $value );
    }

    /**
     * Handle dynamic method calls to the relationship.
     *
     * @param string $method
     * @param array $parameters
     * @return mixed
     */
    public function __call($method, $parameters)
    {
        $result = call_user_func_array ( array (
                $this->query,
                $method
        ), $parameters );
        if ( $result === $this->query ) return $this;
        return $result;
    }
}