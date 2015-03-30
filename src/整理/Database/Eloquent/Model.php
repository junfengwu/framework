<?php
namespace Leaps\Database\Eloquent;
use DateTime;
use Carbon\Carbon;
use ArrayAccess;
use Leaps\Events\Dispatcher;
use Leaps\Database\Eloquent\Collection;
use Leaps\Database\Eloquent\Relations\HasOne;
use Leaps\Database\Eloquent\Relations\HasMany;
use Leaps\Support\Contracts\JsonableInterface;
use Leaps\Support\Contracts\ArrayableInterface;
use Leaps\Database\Eloquent\Relations\MorphOne;
use Leaps\Database\Eloquent\Relations\MorphMany;
use Leaps\Database\Eloquent\Relations\BelongsTo;
use Leaps\Database\Query\Builder as QueryBuilder;
use Leaps\Database\Eloquent\Relations\BelongsToMany;
use Leaps\Database\ConnectionResolverInterface as Resolver;
abstract class Model implements ArrayAccess, ArrayableInterface, JsonableInterface
{

    /**
     * 连接名称为模型。
     *
     * @var string
     */
    protected $connection;

    /**
     * 与模型相关的表。
     *
     * @var string
     */
    protected $table;

    /**
     * The primary key for the model.
     *
     * @var string
     */
    protected $primaryKey = 'id';

    /**
     * The number of models to return for pagination.
     *
     * @var int
     */
    protected $perPage = 15;

    /**
     * Indicates if the IDs are auto-incrementing.
     *
     * @var bool
     */
    public $incrementing = true;

    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = true;

    /**
     * The model's attributes.
     *
     * @var array
     */
    protected $attributes = array ();

    /**
     * The model attribute's original state.
     *
     * @var array
     */
    protected $original = array ();

    /**
     * The loaded relationships for the model.
     *
     * @var array
     */
    protected $relations = array ();

    /**
     * The attributes that should be hidden for arrays.
     *
     * @var array
     */
    protected $hidden = array ();

    /**
     * The attributes that should be visible in arrays.
     *
     * @var arrays
     */
    protected $visible = array ();

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = array ();

    /**
     * The attributes that aren't mass assignable.
     *
     * @var array
     */
    protected $guarded = array (
            '*'
    );

    /**
     * The relationships that should be touched on save.
     *
     * @var array
     */
    protected $touches = array ();

    /**
     * The relations to eager load on every query.
     *
     * @var array
     */
    protected $with = array ();

    /**
     * Indicates if the model exists.
     *
     * @var bool
     */
    public $exists = false;

    /**
     * Indicates if the model should soft delete.
     *
     * @var bool
     */
    protected $softDelete = false;

    /**
     * Indicates whether attributes are snake cased on arrays.
     *
     * @var bool
     */
    public static $snakeAttributes = true;

    /**
     * The connection resolver instance.
     *
     * @var \Leaps\Database\ConnectionResolverInterface
     */
    protected static $resolver;

    /**
     * The event dispatcher instance.
     *
     * @var \Leaps\Events\Dispatcher
     */
    protected static $dispatcher;

    /**
     * The array of booted models.
     *
     * @var array
     */
    protected static $booted = array ();

    /**
     * Indicates if all mass assignment is enabled.
     *
     * @var bool
     */
    protected static $unguarded = false;

    /**
     * The cache of the mutated attributes for each class.
     *
     * @var array
     */
    protected static $mutatorCache = array ();

    /**
     * The name of the "created at" column.
     *
     * @var string
     */
    const CREATED_AT = 'created_at';

    /**
     * The name of the "updated at" column.
     *
     * @var string
     */
    const UPDATED_AT = 'updated_at';

    /**
     * The name of the "deleted at" column.
     *
     * @var string
     */
    const DELETED_AT = 'deleted_at';

    /**
     * Create a new Eloquent model instance.
     *
     * @param array $attributes
     * @return void
     */
    public function __construct(array $attributes = array())
    {
        if ( ! isset ( static::$booted [get_class ( $this )] ) ) {
            static::boot ();
            static::$booted [get_class ( $this )] = true;
        }
        $this->fill ( $attributes );
    }

    /**
     * The "booting" method of the model.
     *
     * @return void
     */
    protected static function boot()
    {
        $class = get_called_class ();
        static::$mutatorCache [$class] = array ();
        foreach ( get_class_methods ( $class ) as $method ) {
            if ( preg_match ( '/^get(.+)Attribute$/', $method, $matches ) ) {
                if ( static::$snakeAttributes ) $matches [1] = snake_case ( $matches [1] );
                static::$mutatorCache [$class] [] = lcfirst ( $matches [1] );
            }
        }
    }

    /**
     * Register an observer with the Model.
     *
     * @param object $class
     * @return void
     */
    public static function observe($class)
    {
        $instance = new static ();
        $className = get_class ( $class );
        foreach ( $instance->getObservableEvents () as $event ) {
            if ( method_exists ( $class, $event ) ) {
                static::registerModelEvent ( $event, $className . '@' . $event );
            }
        }
    }

    /**
     * Fill the model with an array of attributes.
     *
     * @param array $attributes
     * @return \Leaps\Database\Eloquent\Model static
     */
    public function fill(array $attributes)
    {
        foreach ( $attributes as $key => $value ) {
            $key = $this->removeTableFromKey ( $key );
            if ( $this->isFillable ( $key ) ) {
                $this->setAttribute ( $key, $value );
            } elseif ( $this->totallyGuarded () ) {
                throw new MassAssignmentException ( $key );
            }
        }
        return $this;
    }

    /**
     * Create a new instance of the given model.
     *
     * @param array $attributes
     * @param bool $exists
     * @return \Leaps\Database\Eloquent\Model static
     */
    public function newInstance($attributes = array(), $exists = false)
    {
        $model = new static ( ( array ) $attributes );
        $model->exists = $exists;
        return $model;
    }

    /**
     * Create a new model instance that is existing.
     *
     * @param array $attributes
     * @return \Leaps\Database\Eloquent\Model static
     */
    public function newFromBuilder($attributes = array())
    {
        $instance = $this->newInstance ( array (), true );
        $instance->setRawAttributes ( ( array ) $attributes, true );
        return $instance;
    }

    /**
     * Save a new model and return the instance.
     *
     * @param array $attributes
     * @return \Leaps\Database\Eloquent\Model static
     */
    public static function create(array $attributes)
    {
        $model = new static ( $attributes );
        $model->save ();
        return $model;
    }

    /**
     * Begin querying the model.
     *
     * @return \Leaps\Database\Eloquent\Builder static
     */
    public static function query()
    {
        return with ( new static () )->newQuery ();
    }

    /**
     * Begin querying the model on a given connection.
     *
     * @param string $connection
     * @return \Leaps\Database\Eloquent\Builder static
     */
    public static function on($connection = null)
    {
        $instance = new static ();
        $instance->setConnection ( $connection );
        return $instance->newQuery ();
    }

    /**
     * Get all of the models from the database.
     *
     * @param array $columns
     * @return \Leaps\Database\Eloquent\Collection static[]
     */
    public static function all($columns = array('*'))
    {
        $instance = new static ();
        return $instance->newQuery ()->get ( $columns );
    }

    /**
     * Find a model by its primary key.
     *
     * @param mixed $id
     * @param array $columns
     * @return \Leaps\Database\Eloquent\Model Collection static
     */
    public static function find($id, $columns = array('*'))
    {
        $instance = new static ();
        if ( is_array ( $id ) ) {
            return $instance->newQuery ()->whereIn ( $instance->getKeyName (), $id )->get ( $columns );
        }
        return $instance->newQuery ()->find ( $id, $columns );
    }

    /**
     * Find a model by its primary key or throw an exception.
     *
     * @param mixed $id
     * @param array $columns
     * @return \Leaps\Database\Eloquent\Model Collection static
     */
    public static function findOrFail($id, $columns = array('*'))
    {
        if ( ! is_null ( $model = static::find ( $id, $columns ) ) ) return $model;
        throw new ModelNotFoundException ();
    }

    /**
     * Eager load relations on the model.
     *
     * @param array|string $relations
     * @return void
     */
    public function load($relations)
    {
        if ( is_string ( $relations ) ) $relations = func_get_args ();
        $query = $this->newQuery ()->with ( $relations );
        $query->eagerLoadRelations ( array (
                $this
        ) );
    }

    /**
     * Being querying a model with eager loading.
     *
     * @param array|string $relations
     * @return \Leaps\Database\Eloquent\Builder static
     */
    public static function with($relations)
    {
        if ( is_string ( $relations ) ) $relations = func_get_args ();
        $instance = new static ();
        return $instance->newQuery ()->with ( $relations );
    }

    /**
     * Define a one-to-one relationship.
     *
     * @param string $related
     * @param string $foreignKey
     * @return \Leaps\Database\Eloquent\Relations\HasOne
     */
    public function hasOne($related, $foreignKey = null)
    {
        $foreignKey = $foreignKey ?  : $this->getForeignKey ();
        $instance = new $related ();
        return new HasOne ( $instance->newQuery (), $this, $instance->getTable () . '.' . $foreignKey );
    }

    /**
     * Define a polymorphic one-to-one relationship.
     *
     * @param string $related
     * @param string $name
     * @param string $type
     * @param string $id
     * @return \Leaps\Database\Eloquent\Relations\MorphOne
     */
    public function morphOne($related, $name, $type = null, $id = null)
    {
        $instance = new $related ();
        list ( $type, $id ) = $this->getMorphs ( $name, $type, $id );
        $table = $instance->getTable ();
        return new MorphOne ( $instance->newQuery (), $this, $table . '.' . $type, $table . '.' . $id );
    }

    /**
     * Define an inverse one-to-one or many relationship.
     *
     * @param string $related
     * @param string $foreignKey
     * @return \Leaps\Database\Eloquent\Relations\BelongsTo
     */
    public function belongsTo($related, $foreignKey = null)
    {
        list ( , $caller ) = debug_backtrace ( false );
        $relation = $caller ['function'];
        if ( is_null ( $foreignKey ) ) {
            $foreignKey = snake_case ( $relation ) . '_id';
        }
        $instance = new $related ();
        $query = $instance->newQuery ();
        return new BelongsTo ( $query, $this, $foreignKey, $relation );
    }

    /**
     * Define an polymorphic, inverse one-to-one or many relationship.
     *
     * @param string $name
     * @param string $type
     * @param string $id
     * @return \Leaps\Database\Eloquent\Relations\BelongsTo
     */
    public function morphTo($name = null, $type = null, $id = null)
    {
        if ( is_null ( $name ) ) {
            list ( , $caller ) = debug_backtrace ( false );
            $name = snake_case ( $caller ['function'] );
        }
        list ( $type, $id ) = $this->getMorphs ( $name, $type, $id );
        $class = $this->$type;
        return $this->belongsTo ( $class, $id );
    }

    /**
     * Define a one-to-many relationship.
     *
     * @param string $related
     * @param string $foreignKey
     * @return \Leaps\Database\Eloquent\Relations\HasMany
     */
    public function hasMany($related, $foreignKey = null)
    {
        $foreignKey = $foreignKey ?  : $this->getForeignKey ();
        $instance = new $related ();
        return new HasMany ( $instance->newQuery (), $this, $instance->getTable () . '.' . $foreignKey );
    }

    /**
     * Define a polymorphic one-to-many relationship.
     *
     * @param string $related
     * @param string $name
     * @param string $type
     * @param string $id
     * @return \Leaps\Database\Eloquent\Relations\MorphMany
     */
    public function morphMany($related, $name, $type = null, $id = null)
    {
        $instance = new $related ();
        list ( $type, $id ) = $this->getMorphs ( $name, $type, $id );
        $table = $instance->getTable ();
        return new MorphMany ( $instance->newQuery (), $this, $table . '.' . $type, $table . '.' . $id );
    }

    /**
     * Define a many-to-many relationship.
     *
     * @param string $related
     * @param string $table
     * @param string $foreignKey
     * @param string $otherKey
     * @return \Leaps\Database\Eloquent\Relations\BelongsToMany
     */
    public function belongsToMany($related, $table = null, $foreignKey = null, $otherKey = null)
    {
        $caller = $this->getBelongsToManyCaller ();
        $foreignKey = $foreignKey ?  : $this->getForeignKey ();
        $instance = new $related ();
        $otherKey = $otherKey ?  : $instance->getForeignKey ();
        if ( is_null ( $table ) ) {
            $table = $this->joiningTable ( $related );
        }
        $query = $instance->newQuery ();
        return new BelongsToMany ( $query, $this, $table, $foreignKey, $otherKey, $caller ['function'] );
    }

    /**
     * Get the relationship name of the belongs to many.
     *
     * @return string
     */
    protected function getBelongsToManyCaller()
    {
        $self = __FUNCTION__;
        return array_first ( debug_backtrace ( false ), function ($trace) use($self)
        {
            $caller = $trace ['function'];
            return $caller != 'belongsToMany' and $caller != $self;
        } );
    }

    /**
     * Get the joining table name for a many-to-many relation.
     *
     * @param string $related
     * @return string
     */
    public function joiningTable($related)
    {
        $base = snake_case ( class_basename ( $this ) );
        $related = snake_case ( class_basename ( $related ) );
        $models = array (
                $related,
                $base
        );
        sort ( $models );
        return strtolower ( implode ( '_', $models ) );
    }

    /**
     * Destroy the models for the given IDs.
     *
     * @param array|int $ids
     * @return void
     */
    public static function destroy($ids)
    {
        $ids = is_array ( $ids ) ? $ids : func_get_args ();
        $instance = new static ();
        $key = $instance->getKeyName ();
        foreach ( $instance->whereIn ( $key, $ids )->get () as $model ) {
            $model->delete ();
        }
    }

    /**
     * Delete the model from the database.
     *
     * @return bool null
     */
    public function delete()
    {
        if ( $this->exists ) {
            if ( $this->fireModelEvent ( 'deleting' ) === false ) return false;
            $this->touchOwners ();
            $this->performDeleteOnModel ();
            $this->exists = false;
            $this->fireModelEvent ( 'deleted', false );
            return true;
        }
    }

    /**
     * Force a hard delete on a soft deleted model.
     *
     * @return void
     */
    public function forceDelete()
    {
        $softDelete = $this->softDelete;
        $this->softDelete = false;
        $this->delete ();
        $this->softDelete = $softDelete;
    }

    /**
     * Perform the actual delete query on this model instance.
     *
     * @return void
     */
    protected function performDeleteOnModel()
    {
        $query = $this->newQuery ()->where ( $this->getKeyName (), $this->getKey () );
        if ( $this->softDelete ) {
            $query->update ( array (
                    static::DELETED_AT => $this->freshTimestamp ()
            ) );
        } else {
            $query->delete ();
        }
    }

    /**
     * Restore a soft-deleted model instance.
     *
     * @return bool null
     */
    public function restore()
    {
        if ( $this->softDelete ) {
            $this->{static::DELETED_AT} = null;
            return $this->save ();
        }
    }

    /**
     * Register a saving model event with the dispatcher.
     *
     * @param \Closure|string $callback
     * @return void
     */
    public static function saving($callback)
    {
        static::registerModelEvent ( 'saving', $callback );
    }

    /**
     * Register a saved model event with the dispatcher.
     *
     * @param \Closure|string $callback
     * @return void
     */
    public static function saved($callback)
    {
        static::registerModelEvent ( 'saved', $callback );
    }

    /**
     * Register an updating model event with the dispatcher.
     *
     * @param \Closure|string $callback
     * @return void
     */
    public static function updating($callback)
    {
        static::registerModelEvent ( 'updating', $callback );
    }

    /**
     * Register an updated model event with the dispatcher.
     *
     * @param \Closure|string $callback
     * @return void
     */
    public static function updated($callback)
    {
        static::registerModelEvent ( 'updated', $callback );
    }

    /**
     * Register a creating model event with the dispatcher.
     *
     * @param \Closure|string $callback
     * @return void
     */
    public static function creating($callback)
    {
        static::registerModelEvent ( 'creating', $callback );
    }

    /**
     * Register a created model event with the dispatcher.
     *
     * @param \Closure|string $callback
     * @return void
     */
    public static function created($callback)
    {
        static::registerModelEvent ( 'created', $callback );
    }

    /**
     * Register a deleting model event with the dispatcher.
     *
     * @param \Closure|string $callback
     * @return void
     */
    public static function deleting($callback)
    {
        static::registerModelEvent ( 'deleting', $callback );
    }

    /**
     * Register a deleted model event with the dispatcher.
     *
     * @param \Closure|string $callback
     * @return void
     */
    public static function deleted($callback)
    {
        static::registerModelEvent ( 'deleted', $callback );
    }

    /**
     * Remove all of the event listeners for the model.
     *
     * @return void
     */
    public static function flushEventListeners()
    {
        if ( ! isset ( static::$dispatcher ) ) return;
        $instance = new static ();
        foreach ( $instance->getObservableEvents () as $event ) {
            static::$dispatcher->forget ( "eloquent.{$event}: " . get_called_class () );
        }
    }

    /**
     * Register a model event with the dispatcher.
     *
     * @param string $event
     * @param \Closure|string $callback
     * @return void
     */
    protected static function registerModelEvent($event, $callback)
    {
        if ( isset ( static::$dispatcher ) ) {
            $name = get_called_class ();
            static::$dispatcher->listen ( "eloquent.{$event}: {$name}", $callback );
        }
    }

    /**
     * Get the observable event names.
     *
     * @return array
     */
    public function getObservableEvents()
    {
        return array (
                'creating',
                'created',
                'updating',
                'updated',
                'deleting',
                'deleted',
                'saving',
                'saved'
        );
    }

    /**
     * Increment a column's value by a given amount.
     *
     * @param string $column
     * @param int $amount
     * @return int
     */
    protected function increment($column, $amount = 1)
    {
        return $this->incrementOrDecrement ( $column, $amount, 'increment' );
    }

    /**
     * Decrement a column's value by a given amount.
     *
     * @param string $column
     * @param int $amount
     * @return int
     */
    protected function decrement($column, $amount = 1)
    {
        return $this->incrementOrDecrement ( $column, $amount, 'decrement' );
    }

    /**
     * Run the increment or decrement method on the model.
     *
     * @param string $column
     * @param int $amount
     * @param string $method
     * @return int
     */
    protected function incrementOrDecrement($column, $amount, $method)
    {
        $query = $this->newQuery ();
        if ( ! $this->exists ) {
            return $query->{$method} ( $column, $amount );
        }
        return $query->where ( $this->getKeyName (), $this->getKey () )->{$method} ( $column, $amount );
    }

    /**
     * Update the model in the database.
     *
     * @param array $attributes
     * @return mixed
     */
    public function update(array $attributes = array())
    {
        if ( ! $this->exists ) {
            return $this->newQuery ()->update ( $attributes );
        }
        return $this->fill ( $attributes )->save ();
    }

    /**
     * Save the model and all of its relationships.
     *
     * @return bool
     */
    public function push()
    {
        if ( ! $this->save () ) return false;
        foreach ( $this->relations as $models ) {
            foreach ( Collection::make ( $models ) as $model ) {
                if ( ! $model->push () ) return false;
            }
        }
        return true;
    }

    /**
     * Save the model to the database.
     *
     * @param array $options
     * @return bool
     */
    public function save(array $options = array())
    {
        $query = $this->newQueryWithDeleted ();
        if ( $this->fireModelEvent ( 'saving' ) === false ) {
            return false;
        }
        if ( $this->exists ) {
            $saved = $this->performUpdate ( $query );
        } else {
            $saved = $this->performInsert ( $query );
        }
        if ( $saved ) $this->finishSave ( $options );
        return $saved;
    }

    /**
     * Finish processing on a successful save operation.
     *
     * @return void
     */
    protected function finishSave(array $options)
    {
        $this->syncOriginal ();
        $this->fireModelEvent ( 'saved', false );
        if ( array_get ( $options, 'touch', true ) ) $this->touchOwners ();
    }

    /**
     * Perform a model update operation.
     *
     * @param \Leaps\Database\Eloquent\Builder
     * @return bool
     */
    protected function performUpdate($query)
    {
        $dirty = $this->getDirty ();
        if ( count ( $dirty ) > 0 ) {
            if ( $this->fireModelEvent ( 'updating' ) === false ) {
                return false;
            }
            if ( $this->timestamps ) {
                $this->updateTimestamps ();
                $dirty = $this->getDirty ();
            }
            $this->setKeysForSaveQuery ( $query )->update ( $dirty );
            $this->fireModelEvent ( 'updated', false );
        }
        return true;
    }

    /**
     * Perform a model insert operation.
     *
     * @param \Leaps\Database\Eloquent\Builder
     * @return bool
     */
    protected function performInsert($query)
    {
        if ( $this->fireModelEvent ( 'creating' ) === false ) return false;
        if ( $this->timestamps ) {
            $this->updateTimestamps ();
        }
        $attributes = $this->attributes;
        if ( $this->incrementing ) {
            $this->insertAndSetId ( $query, $attributes );
        } else {
            $query->insert ( $attributes );
        }
        $this->exists = true;
        $this->fireModelEvent ( 'created', false );
        return true;
    }

    /**
     * Insert the given attributes and set the ID on the model.
     *
     * @param \Leaps\Database\Eloquent\Builder $query
     * @param array $attributes
     * @return void
     */
    protected function insertAndSetId($query, $attributes)
    {
        $id = $query->insertGetId ( $attributes, $keyName = $this->getKeyName () );
        $this->setAttribute ( $keyName, $id );
    }

    /**
     * Touch the owning relations of the model.
     *
     * @return void
     */
    public function touchOwners()
    {
        foreach ( $this->touches as $relation ) {
            $this->$relation ()->touch ();
        }
    }

    /**
     * Determine if the model touches a given relation.
     *
     * @param string $relation
     * @return bool
     */
    public function touches($relation)
    {
        return in_array ( $relation, $this->touches );
    }

    /**
     * Fire the given event for the model.
     *
     * @param string $event
     * @param bool $halt
     * @return mixed
     */
    protected function fireModelEvent($event, $halt = true)
    {
        if ( ! isset ( static::$dispatcher ) ) return true;
        $event = "eloquent.{$event}: " . get_class ( $this );
        $method = $halt ? 'until' : 'fire';
        return static::$dispatcher->$method ( $event, $this );
    }

    /**
     * Set the keys for a save update query.
     *
     * @param \Leaps\Database\Eloquent\Builder
     * @return \Leaps\Database\Eloquent\Builder
     */
    protected function setKeysForSaveQuery($query)
    {
        $query->where ( $this->getKeyName (), '=', $this->getKey () );
        return $query;
    }

    /**
     * Update the model's update timestamp.
     *
     * @return bool
     */
    public function touch()
    {
        $this->updateTimestamps ();
        return $this->save ();
    }

    /**
     * Update the creation and update timestamps.
     *
     * @return void
     */
    protected function updateTimestamps()
    {
        $time = $this->freshTimestamp ();
        if ( ! $this->isDirty ( static::UPDATED_AT ) ) {
            $this->setUpdatedAt ( $time );
        }
        if ( ! $this->exists and ! $this->isDirty ( static::CREATED_AT ) ) {
            $this->setCreatedAt ( $time );
        }
    }

    /**
     * Set the value of the "created at" attribute.
     *
     * @param mixed $value
     * @return void
     */
    public function setCreatedAt($value)
    {
        $this->{static::CREATED_AT} = $value;
    }

    /**
     * Set the value of the "updated at" attribute.
     *
     * @param mixed $value
     * @return void
     */
    public function setUpdatedAt($value)
    {
        $this->{static::UPDATED_AT} = $value;
    }

    /**
     * Get the name of the "created at" column.
     *
     * @return string
     */
    public function getCreatedAtColumn()
    {
        return static::CREATED_AT;
    }

    /**
     * Get the name of the "updated at" column.
     *
     * @return string
     */
    public function getUpdatedAtColumn()
    {
        return static::UPDATED_AT;
    }

    /**
     * Get the name of the "deleted at" column.
     *
     * @return string
     */
    public function getDeletedAtColumn()
    {
        return static::DELETED_AT;
    }

    /**
     * Get the fully qualified "deleted at" column.
     *
     * @return string
     */
    public function getQualifiedDeletedAtColumn()
    {
        return $this->getTable () . '.' . $this->getDeletedAtColumn ();
    }

    /**
     * Get a fresh timestamp for the model.
     *
     * @return DateTime
     */
    public function freshTimestamp()
    {
        return new DateTime ();
    }

    /**
     * Get a new query builder for the model's table.
     *
     * @param bool $excludeDeleted
     * @return \Leaps\Database\Eloquent\Builder static
     */
    public function newQuery($excludeDeleted = true)
    {
        $builder = new Builder ( $this->newBaseQueryBuilder () );
        $builder->setModel ( $this )->with ( $this->with );
        if ( $excludeDeleted and $this->softDelete ) {
            $builder->whereNull ( $this->getQualifiedDeletedAtColumn () );
        }
        return $builder;
    }

    /**
     * Get a new query builder that includes soft deletes.
     *
     * @return \Leaps\Database\Eloquent\Builder static
     */
    public function newQueryWithDeleted()
    {
        return $this->newQuery ( false );
    }

    /**
     * Determine if the model instance has been soft-deleted.
     *
     * @return bool
     */
    public function trashed()
    {
        return $this->softDelete and ! is_null ( $this->{static::DELETED_AT} );
    }

    /**
     * Get a new query builder that includes soft deletes.
     *
     * @return \Leaps\Database\Eloquent\Builder static
     */
    public static function withTrashed()
    {
        return with ( new static () )->newQueryWithDeleted ();
    }

    /**
     * Get a new query builder that only includes soft deletes.
     *
     * @return \Leaps\Database\Eloquent\Builder static
     */
    public static function onlyTrashed()
    {
        $instance = new static ();
        $column = $instance->getQualifiedDeletedAtColumn ();
        return $instance->newQueryWithDeleted ()->whereNotNull ( $column );
    }

    /**
     * Get a new query builder instance for the connection.
     *
     * @return \Leaps\Database\Query\Builder
     */
    protected function newBaseQueryBuilder()
    {
        $conn = $this->getConnection ();
        $grammar = $conn->getQueryGrammar ();
        return new QueryBuilder ( $conn, $grammar, $conn->getPostProcessor () );
    }

    /**
     * Create a new Eloquent Collection instance.
     *
     * @param array $models
     * @return \Leaps\Database\Eloquent\Collection
     */
    public function newCollection(array $models = array())
    {
        return new Collection ( $models );
    }

    /**
     * Get the table associated with the model.
     *
     * @return string
     */
    public function getTable()
    {
        if ( isset ( $this->table ) ) return $this->table;
        return str_replace ( '\\', '', snake_case ( str_plural ( get_class ( $this ) ) ) );
    }

    /**
     * Set the table associated with the model.
     *
     * @param string $table
     * @return void
     */
    public function setTable($table)
    {
        $this->table = $table;
    }

    /**
     * Get the value of the model's primary key.
     *
     * @return mixed
     */
    public function getKey()
    {
        return $this->getAttribute ( $this->getKeyName () );
    }

    /**
     * Get the primary key for the model.
     *
     * @return string
     */
    public function getKeyName()
    {
        return $this->primaryKey;
    }

    /**
     * Get the table qualified key name.
     *
     * @return string
     */
    public function getQualifiedKeyName()
    {
        return $this->getTable () . '.' . $this->getKeyName ();
    }

    /**
     * Determine if the model uses timestamps.
     *
     * @return bool
     */
    public function usesTimestamps()
    {
        return $this->timestamps;
    }

    /**
     * Determine if the model instance uses soft deletes.
     *
     * @return bool
     */
    public function isSoftDeleting()
    {
        return $this->softDelete;
    }

    /**
     * Set the soft deleting property on the model.
     *
     * @param bool $enabled
     * @return void
     */
    public function setSoftDeleting($enabled)
    {
        $this->softDelete = $enabled;
    }

    /**
     * Get the polymorphic relationship columns.
     *
     * @param string $name
     * @param string $type
     * @param string $id
     * @return array
     */
    protected function getMorphs($name, $type, $id)
    {
        $type = $type ?  : $name . '_type';
        $id = $id ?  : $name . '_id';
        return array (
                $type,
                $id
        );
    }

    /**
     * Get the number of models to return per page.
     *
     * @return int
     */
    public function getPerPage()
    {
        return $this->perPage;
    }

    /**
     * Set the number of models ot return per page.
     *
     * @param int $perPage
     * @return void
     */
    public function setPerPage($perPage)
    {
        $this->perPage = $perPage;
    }

    /**
     * Get the default foreign key name for the model.
     *
     * @return string
     */
    public function getForeignKey()
    {
        return snake_case ( class_basename ( $this ) ) . '_id';
    }

    /**
     * Get the hidden attributes for the model.
     *
     * @return array
     */
    public function getHidden()
    {
        return $this->hidden;
    }

    /**
     * Set the hidden attributes for the model.
     *
     * @param array $hidden
     * @return void
     */
    public function setHidden(array $hidden)
    {
        $this->hidden = $hidden;
    }

    /**
     * Set the visible attributes for the model.
     *
     * @param array $visible
     * @return void
     */
    public function setVisible(array $visible)
    {
        $this->visible = $visible;
    }

    /**
     * Get the fillable attributes for the model.
     *
     * @return array
     */
    public function getFillable()
    {
        return $this->fillable;
    }

    /**
     * Set the fillable attributes for the model.
     *
     * @param array $fillable
     * @return \Leaps\Database\Eloquent\Model
     */
    public function fillable(array $fillable)
    {
        $this->fillable = $fillable;
        return $this;
    }

    /**
     * Set the guarded attributes for the model.
     *
     * @param array $guarded
     * @return \Leaps\Database\Eloquent\Model
     */
    public function guard(array $guarded)
    {
        $this->guarded = $guarded;
        return $this;
    }

    /**
     * Disable all mass assignable restrictions.
     *
     * @return void
     */
    public static function unguard()
    {
        static::$unguarded = true;
    }

    /**
     * Enable the mass assignment restrictions.
     *
     * @return void
     */
    public static function reguard()
    {
        static::$unguarded = false;
    }

    /**
     * Set "unguard" to a given state.
     *
     * @param bool $state
     * @return void
     */
    public static function setUnguardState($state)
    {
        static::$unguarded = $state;
    }

    /**
     * Determine if the given attribute may be mass assigned.
     *
     * @param string $key
     * @return bool
     */
    public function isFillable($key)
    {
        if ( static::$unguarded ) return true;
        if ( in_array ( $key, $this->fillable ) ) return true;
        if ( $this->isGuarded ( $key ) ) return false;
        return empty ( $this->fillable ) and ! starts_with ( $key, '_' );
    }

    /**
     * Determine if the given key is guarded.
     *
     * @param string $key
     * @return bool
     */
    public function isGuarded($key)
    {
        return in_array ( $key, $this->guarded ) or $this->guarded == array (
                '*'
        );
    }

    /**
     * Determine if the model is totally guarded.
     *
     * @return bool
     */
    public function totallyGuarded()
    {
        return count ( $this->fillable ) == 0 and $this->guarded == array (
                '*'
        );
    }

    /**
     * Remove the table name from a given key.
     *
     * @param string $key
     * @return string
     */
    protected function removeTableFromKey($key)
    {
        if ( ! str_contains ( $key, '.' ) ) return $key;
        return last ( explode ( '.', $key ) );
    }

    /**
     * Get the relationships that are touched on save.
     *
     * @return array
     */
    public function getTouchedRelations()
    {
        return $this->touches;
    }

    /**
     * Set the relationships that are touched on save.
     *
     * @param array $touches
     * @return void
     */
    public function setTouchedRelations(array $touches)
    {
        $this->touches = $touches;
    }

    /**
     * Get the value indicating whether the IDs are incrementing.
     *
     * @return bool
     */
    public function getIncrementing()
    {
        return $this->incrementing;
    }

    /**
     * Set whether IDs are incrementing.
     *
     * @param bool $value
     * @return void
     */
    public function setIncrementing($value)
    {
        $this->incrementing = $value;
    }

    /**
     * Convert the model instance to JSON.
     *
     * @param int $options
     * @return string
     */
    public function toJson($options = 0)
    {
        return json_encode ( $this->toArray (), $options );
    }

    /**
     * Convert the model instance to an array.
     *
     * @return array
     */
    public function toArray()
    {
        $attributes = $this->attributesToArray ();
        return array_merge ( $attributes, $this->relationsToArray () );
    }

    /**
     * Convert the model's attributes to an array.
     *
     * @return array
     */
    public function attributesToArray()
    {
        $attributes = $this->getArrayableAttributes ();
        foreach ( $this->getMutatedAttributes () as $key ) {
            if ( ! array_key_exists ( $key, $attributes ) ) continue;

            $attributes [$key] = $this->mutateAttribute ( $key, $attributes [$key] );
        }
        return $attributes;
    }

    /**
     * Get an attribute array of all arrayable attributes.
     *
     * @return array
     */
    protected function getArrayableAttributes()
    {
        if ( count ( $this->visible ) > 0 ) {
            return array_intersect_key ( $this->attributes, array_flip ( $this->visible ) );
        }
        return array_diff_key ( $this->attributes, array_flip ( $this->hidden ) );
    }

    /**
     * Get the model's relationships in array form.
     *
     * @return array
     */
    public function relationsToArray()
    {
        $attributes = array ();
        foreach ( $this->relations as $key => $value ) {
            if ( in_array ( $key, $this->hidden ) ) continue;
            if ( $value instanceof ArrayableInterface ) {
                $relation = $value->toArray ();
            } elseif ( is_null ( $value ) ) {
                $relation = $value;
            }
            if ( static::$snakeAttributes ) {
                $key = snake_case ( $key );
            }
            if ( isset ( $relation ) ) {
                $attributes [$key] = $relation;
            }
        }
        return $attributes;
    }

    /**
     * Get an attribute from the model.
     *
     * @param string $key
     * @return mixed
     */
    public function getAttribute($key)
    {
        $inAttributes = array_key_exists ( $key, $this->attributes );
        if ( $inAttributes or $this->hasGetMutator ( $key ) ) {
            return $this->getAttributeValue ( $key );
        }
        if ( array_key_exists ( $key, $this->relations ) ) {
            return $this->relations [$key];
        }
        $camelKey = camel_case ( $key );
        if ( method_exists ( $this, $camelKey ) ) {
            $relations = $this->$camelKey ()->getResults ();
            return $this->relations [$key] = $relations;
        }
    }

    /**
     * Get a plain attribute (not a relationship).
     *
     * @param string $key
     * @return mixed
     */
    protected function getAttributeValue($key)
    {
        $value = $this->getAttributeFromArray ( $key );
        if ( $this->hasGetMutator ( $key ) ) {
            return $this->mutateAttribute ( $key, $value );
        } elseif ( in_array ( $key, $this->getDates () ) ) {
            if ( $value ) return $this->asDateTime ( $value );
        }
        return $value;
    }

    /**
     * Get an attribute from the $attributes array.
     *
     * @param string $key
     * @return mixed
     */
    protected function getAttributeFromArray($key)
    {
        if ( array_key_exists ( $key, $this->attributes ) ) {
            return $this->attributes [$key];
        }
    }

    /**
     * Determine if a get mutator exists for an attribute.
     *
     * @param string $key
     * @return bool
     */
    public function hasGetMutator($key)
    {
        return method_exists ( $this, 'get' . studly_case ( $key ) . 'Attribute' );
    }

    /**
     * Get the value of an attribute using its mutator.
     *
     * @param string $key
     * @param mixed $value
     * @return mixed
     */
    protected function mutateAttribute($key, $value)
    {
        return $this->{'get' . studly_case ( $key ) . 'Attribute'} ( $value );
    }

    /**
     * Set a given attribute on the model.
     *
     * @param string $key
     * @param mixed $value
     * @return void
     */
    public function setAttribute($key, $value)
    {
        if ( $this->hasSetMutator ( $key ) ) {
            $method = 'set' . studly_case ( $key ) . 'Attribute';
            return $this->{$method} ( $value );
        } elseif ( in_array ( $key, $this->getDates () ) ) {
            if ( $value ) {
                $value = $this->fromDateTime ( $value );
            }
        }
        $this->attributes [$key] = $value;
    }

    /**
     * Determine if a set mutator exists for an attribute.
     *
     * @param string $key
     * @return bool
     */
    public function hasSetMutator($key)
    {
        return method_exists ( $this, 'set' . studly_case ( $key ) . 'Attribute' );
    }

    /**
     * Get the attributes that should be converted to dates.
     *
     * @return array
     */
    public function getDates()
    {
        return array (
                static::CREATED_AT,
                static::UPDATED_AT,
                static::DELETED_AT
        );
    }

    /**
     * Convert a DateTime to a storable string.
     *
     * @param DateTime|int $value
     * @return string
     */
    protected function fromDateTime($value)
    {
        $format = $this->getDateFormat ();
        if ( $value instanceof DateTime ) {
            //
        } elseif ( is_numeric ( $value ) ) {
            $value = Carbon::createFromTimestamp ( $value );
        } elseif ( preg_match ( '/^(\d{4})-(\d{2})-(\d{2})$/', $value ) ) {
            $value = Carbon::createFromFormat ( 'Y-m-d', $value );
        } elseif ( ! $value instanceof DateTime ) {
            $value = Carbon::createFromFormat ( $format, $value );
        }
        return $value->format ( $format );
    }

    /**
     * Return a timestamp as DateTime object.
     *
     * @param mixed $value
     * @return DateTime
     */
    protected function asDateTime($value)
    {
        if ( is_numeric ( $value ) ) {
            return Carbon::createFromTimestamp ( $value );
        } elseif ( preg_match ( '/^(\d{4})-(\d{2})-(\d{2})$/', $value ) ) {
            return Carbon::createFromFormat ( 'Y-m-d', $value );
        } elseif ( ! $value instanceof DateTime ) {
            $format = $this->getDateFormat ();
            return Carbon::createFromFormat ( $format, $value );
        }
        return Carbon::instance ( $value );
    }

    /**
     * Get the format for database stored dates.
     *
     * @return string
     */
    protected function getDateFormat()
    {
        return $this->getConnection ()->getQueryGrammar ()->getDateFormat ();
    }

    /**
     * Clone the model into a new, non-existing instance.
     *
     * @return \Leaps\Database\Eloquent\Model
     */
    public function replicate()
    {
        $attributes = array_except ( $this->attributes, array (
                $this->getKeyName ()
        ) );
        with ( $instance = new static () )->setRawAttributes ( $attributes );
        return $instance->setRelations ( $this->relations );
    }

    /**
     * Get all of the current attributes on the model.
     *
     * @return array
     */
    public function getAttributes()
    {
        return $this->attributes;
    }

    /**
     * Set the array of model attributes. No checking is done.
     *
     * @param array $attributes
     * @param bool $sync
     * @return void
     */
    public function setRawAttributes(array $attributes, $sync = false)
    {
        $this->attributes = $attributes;
        if ( $sync ) $this->syncOriginal ();
    }

    /**
     * Get the model's original attribute values.
     *
     * @param string $key
     * @param mixed $default
     * @return array
     */
    public function getOriginal($key = null, $default = null)
    {
        return array_get ( $this->original, $key, $default );
    }

    /**
     * Sync the original attributes with the current.
     *
     * @return \Leaps\Database\Eloquent\Model
     */
    public function syncOriginal()
    {
        $this->original = $this->attributes;
        return $this;
    }

    /**
     * Determine if a given attribute is dirty.
     *
     * @param string $attribute
     * @return bool
     */
    public function isDirty($attribute)
    {
        return array_key_exists ( $attribute, $this->getDirty () );
    }

    /**
     * Get the attributes that have been changed since last sync.
     *
     * @return array
     */
    public function getDirty()
    {
        $dirty = array ();
        foreach ( $this->attributes as $key => $value ) {
            if ( ! array_key_exists ( $key, $this->original ) or $value !== $this->original [$key] ) {
                $dirty [$key] = $value;
            }
        }
        return $dirty;
    }

    /**
     * Get all the loaded relations for the instance.
     *
     * @return array
     */
    public function getRelations()
    {
        return $this->relations;
    }

    /**
     * Get a specified relationship.
     *
     * @param string $relation
     * @return mixed
     */
    public function getRelation($relation)
    {
        return $this->relations [$relation];
    }

    /**
     * Set the specific relationship in the model.
     *
     * @param string $relation
     * @param mixed $value
     * @return \Leaps\Database\Eloquent\Model
     */
    public function setRelation($relation, $value)
    {
        $this->relations [$relation] = $value;
        return $this;
    }

    /**
     * Set the entire relations array on the model.
     *
     * @param array $relations
     * @return \Leaps\Database\Eloquent\Model
     */
    public function setRelations(array $relations)
    {
        $this->relations = $relations;
        return $this;
    }

    /**
     * Get the database connection for the model.
     *
     * @return \Leaps\Database\Connection
     */
    public function getConnection()
    {
        return static::resolveConnection ( $this->connection );
    }

    /**
     * Get the current connection name for the model.
     *
     * @return string
     */
    public function getConnectionName()
    {
        return $this->connection;
    }

    /**
     * Set the connection associated with the model.
     *
     * @param string $name
     * @return void
     */
    public function setConnection($name)
    {
        $this->connection = $name;
    }

    /**
     * Resolve a connection instance.
     *
     * @param string $connection
     * @return \Leaps\Database\Connection
     */
    public static function resolveConnection($connection = null)
    {
        return static::$resolver->connection ( $connection );
    }

    /**
     * Get the connection resolver instance.
     *
     * @return \Leaps\Database\ConnectionResolverInterface
     */
    public static function getConnectionResolver()
    {
        return static::$resolver;
    }

    /**
     * Set the connection resolver instance.
     *
     * @param \Leaps\Database\ConnectionResolverInterface $resolver
     * @return void
     */
    public static function setConnectionResolver(Resolver $resolver)
    {
        static::$resolver = $resolver;
    }

    /**
     * Get the event dispatcher instance.
     *
     * @return \Leaps\Events\Dispatcher
     */
    public static function getEventDispatcher()
    {
        return static::$dispatcher;
    }

    /**
     * Set the event dispatcher instance.
     *
     * @param \Leaps\Events\Dispatcher $dispatcher
     * @return void
     */
    public static function setEventDispatcher(Dispatcher $dispatcher)
    {
        static::$dispatcher = $dispatcher;
    }

    /**
     * Unset the event dispatcher for models.
     *
     * @return void
     */
    public static function unsetEventDispatcher()
    {
        static::$dispatcher = null;
    }

    /**
     * Get the mutated attributes for a given instance.
     *
     * @return array
     */
    public function getMutatedAttributes()
    {
        $class = get_class ( $this );
        if ( isset ( static::$mutatorCache [$class] ) ) {
            return static::$mutatorCache [get_class ( $this )];
        }
        return array ();
    }

    /**
     * Dynamically retrieve attributes on the model.
     *
     * @param string $key
     * @return mixed
     */
    public function __get($key)
    {
        return $this->getAttribute ( $key );
    }

    /**
     * Dynamically set attributes on the model.
     *
     * @param string $key
     * @param mixed $value
     * @return void
     */
    public function __set($key, $value)
    {
        $this->setAttribute ( $key, $value );
    }

    /**
     * Determine if the given attribute exists.
     *
     * @param mixed $offset
     * @return bool
     */
    public function offsetExists($offset)
    {
        return isset ( $this->$offset );
    }

    /**
     * Get the value for a given offset.
     *
     * @param mixed $offset
     * @return mixed
     */
    public function offsetGet($offset)
    {
        return $this->$offset;
    }

    /**
     * Set the value for a given offset.
     *
     * @param mixed $offset
     * @param mixed $value
     * @return void
     */
    public function offsetSet($offset, $value)
    {
        $this->$offset = $value;
    }

    /**
     * Unset the value for a given offset.
     *
     * @param mixed $offset
     * @return void
     */
    public function offsetUnset($offset)
    {
        unset ( $this->$offset );
    }

    /**
     * Determine if an attribute exists on the model.
     *
     * @param string $key
     * @return void
     */
    public function __isset($key)
    {
        return isset ( $this->attributes [$key] ) or isset ( $this->relations [$key] );
    }

    /**
     * Unset an attribute on the model.
     *
     * @param string $key
     * @return void
     */
    public function __unset($key)
    {
        unset ( $this->attributes [$key] );
        unset ( $this->relations [$key] );
    }

    /**
     * Handle dynamic method calls into the method.
     *
     * @param string $method
     * @param array $parameters
     * @return mixed
     */
    public function __call($method, $parameters)
    {
        if ( in_array ( $method, array (
                'increment',
                'decrement'
        ) ) ) {
            return call_user_func_array ( array (
                    $this,
                    $method
            ), $parameters );
        }
        $query = $this->newQuery ();
        return call_user_func_array ( array (
                $query,
                $method
        ), $parameters );
    }

    /**
     * Handle dynamic static method calls into the method.
     *
     * @param string $method
     * @param array $parameters
     * @return mixed
     */
    public static function __callStatic($method, $parameters)
    {
        $instance = new static ();
        return call_user_func_array ( array (
                $instance,
                $method
        ), $parameters );
    }

    /**
     * Convert the model to its string representation.
     *
     * @return string
     */
    public function __toString()
    {
        return $this->toJson ();
    }
}
