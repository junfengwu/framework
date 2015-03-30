<?php
namespace Leaps\Database\Query\Grammars;
use Leaps\Database\Query\Builder;
use Leaps\Database\Grammar as BaseGrammar;
class Grammar extends BaseGrammar
{

    /**
     * The keyword identifier wrapper format.
     *
     * @var string
     */
    protected $wrapper = '"%s"';

    /**
     * The components that make up a select clause.
     *
     * @var array
     */
    protected $selectComponents = array (
            'aggregate',
            'columns',
            'from',
            'joins',
            'wheres',
            'groups',
            'havings',
            'orders',
            'limit',
            'offset',
            'unions'
    );

    /**
     * Compile a select query into SQL.
     *
     * @param \Leaps\Database\Query\Builder
     * @return string
     */
    public function compileSelect(Builder $query)
    {
        if ( is_null ( $query->columns ) ) $query->columns = array (
                '*'
        );
        return trim ( $this->concatenate ( $this->compileComponents ( $query ) ) );
    }

    /**
     * Compile the components necessary for a select clause.
     *
     * @param \Leaps\Database\Query\Builder
     * @return array
     */
    protected function compileComponents(Builder $query)
    {
        $sql = array ();
        foreach ( $this->selectComponents as $component ) {
            if ( ! is_null ( $query->$component ) ) {
                $method = 'compile' . ucfirst ( $component );

                $sql [$component] = $this->$method ( $query, $query->$component );
            }
        }

        return $sql;
    }

    /**
     * Compile an aggregated select clause.
     *
     * @param \Leaps\Database\Query\Builder $query
     * @param array $aggregate
     * @return string
     */
    protected function compileAggregate(Builder $query, $aggregate)
    {
        $column = $this->columnize ( $aggregate ['columns'] );
        if ( $query->distinct and $column !== '*' ) {
            $column = 'distinct ' . $column;
        }

        return 'select ' . $aggregate ['function'] . '(' . $column . ') as aggregate';
    }

    /**
     * Compile the "select *" portion of the query.
     *
     * @param \Leaps\Database\Query\Builder $query
     * @param array $columns
     * @return string
     */
    protected function compileColumns(Builder $query, $columns)
    {
        if ( ! is_null ( $query->aggregate ) ) return;

        $select = $query->distinct ? 'select distinct ' : 'select ';

        return $select . $this->columnize ( $columns );
    }

    /**
     * Compile the "from" portion of the query.
     *
     * @param \Leaps\Database\Query\Builder $query
     * @param string $table
     * @return string
     */
    protected function compileFrom(Builder $query, $table)
    {
        return 'from ' . $this->wrapTable ( $table );
    }

    /**
     * Compile the "join" portions of the query.
     *
     * @param \Leaps\Database\Query\Builder $query
     * @param array $joins
     * @return string
     */
    protected function compileJoins(Builder $query, $joins)
    {
        $sql = array ();

        foreach ( $joins as $join ) {
            $table = $this->wrapTable ( $join->table );
            $clauses = array ();

            foreach ( $join->clauses as $clause ) {
                $clauses [] = $this->compileJoinConstraint ( $clause );
            }
            $clauses [0] = $this->removeLeadingBoolean ( $clauses [0] );

            $clauses = implode ( ' ', $clauses );

            $type = $join->type;
            $sql [] = "$type join $table on $clauses";
        }

        return implode ( ' ', $sql );
    }

    /**
     * Create a join clause constraint segment.
     *
     * @param array $clause
     * @return string
     */
    protected function compileJoinConstraint(array $clause)
    {
        $first = $this->wrap ( $clause ['first'] );
        $second = $this->wrap ( $clause ['second'] );
        return "{$clause['boolean']} $first {$clause['operator']} $second";
    }

    /**
     * Compile the "where" portions of the query.
     *
     * @param \Leaps\Database\Query\Builder $query
     * @return string
     */
    protected function compileWheres(Builder $query)
    {
        $sql = array ();
        if ( is_null ( $query->wheres ) ) return '';
        foreach ( $query->wheres as $where ) {
            $method = "where{$where['type']}";

            $sql [] = $where ['boolean'] . ' ' . $this->$method ( $query, $where );
        }
        if ( count ( $sql ) > 0 ) {
            $sql = implode ( ' ', $sql );

            return 'where ' . preg_replace ( '/and |or /', '', $sql, 1 );
        }
        return '';
    }

    /**
     * Compile a nested where clause.
     *
     * @param \Leaps\Database\Query\Builder $query
     * @param array $where
     * @return string
     */
    protected function whereNested(Builder $query, $where)
    {
        $nested = $where ['query'];
        return '(' . substr ( $this->compileWheres ( $nested ), 6 ) . ')';
    }

    /**
     * Compile a where condition with a sub-select.
     *
     * @param \Leaps\Database\Query\Builder $query
     * @param array $where
     * @return string
     */
    protected function whereSub(Builder $query, $where)
    {
        $select = $this->compileSelect ( $where ['query'] );
        return $this->wrap ( $where ['column'] ) . ' ' . $where ['operator'] . " ($select)";
    }

    /**
     * Compile a basic where clause.
     *
     * @param \Leaps\Database\Query\Builder $query
     * @param array $where
     * @return string
     */
    protected function whereBasic(Builder $query, $where)
    {
        $value = $this->parameter ( $where ['value'] );
        return $this->wrap ( $where ['column'] ) . ' ' . $where ['operator'] . ' ' . $value;
    }

    /**
     * Compile a "between" where clause.
     *
     * @param \Leaps\Database\Query\Builder $query
     * @param array $where
     * @return string
     */
    protected function whereBetween(Builder $query, $where)
    {
        return $this->wrap ( $where ['column'] ) . ' between ? and ?';
    }

    /**
     * Compile a where exists clause.
     *
     * @param \Leaps\Database\Query\Builder $query
     * @param array $where
     * @return string
     */
    protected function whereExists(Builder $query, $where)
    {
        return 'exists (' . $this->compileSelect ( $where ['query'] ) . ')';
    }

    /**
     * Compile a where exists clause.
     *
     * @param \Leaps\Database\Query\Builder $query
     * @param array $where
     * @return string
     */
    protected function whereNotExists(Builder $query, $where)
    {
        return 'not exists (' . $this->compileSelect ( $where ['query'] ) . ')';
    }

    /**
     * Compile a "where in" clause.
     *
     * @param \Leaps\Database\Query\Builder $query
     * @param array $where
     * @return string
     */
    protected function whereIn(Builder $query, $where)
    {
        $values = $this->parameterize ( $where ['values'] );
        return $this->wrap ( $where ['column'] ) . ' in (' . $values . ')';
    }

    /**
     * Compile a "where not in" clause.
     *
     * @param \Leaps\Database\Query\Builder $query
     * @param array $where
     * @return string
     */
    protected function whereNotIn(Builder $query, $where)
    {
        $values = $this->parameterize ( $where ['values'] );
        return $this->wrap ( $where ['column'] ) . ' not in (' . $values . ')';
    }

    /**
     * Compile a where in sub-select clause.
     *
     * @param \Leaps\Database\Query\Builder $query
     * @param array $where
     * @return string
     */
    protected function whereInSub(Builder $query, $where)
    {
        $select = $this->compileSelect ( $where ['query'] );
        return $this->wrap ( $where ['column'] ) . ' in (' . $select . ')';
    }

    /**
     * Compile a where not in sub-select clause.
     *
     * @param \Leaps\Database\Query\Builder $query
     * @param array $where
     * @return string
     */
    protected function whereNotInSub(Builder $query, $where)
    {
        $select = $this->compileSelect ( $where ['query'] );
        return $this->wrap ( $where ['column'] ) . ' not in (' . $select . ')';
    }

    /**
     * Compile a "where null" clause.
     *
     * @param \Leaps\Database\Query\Builder $query
     * @param array $where
     * @return string
     */
    protected function whereNull(Builder $query, $where)
    {
        return $this->wrap ( $where ['column'] ) . ' is null';
    }

    /**
     * Compile a "where not null" clause.
     *
     * @param \Leaps\Database\Query\Builder $query
     * @param array $where
     * @return string
     */
    protected function whereNotNull(Builder $query, $where)
    {
        return $this->wrap ( $where ['column'] ) . ' is not null';
    }

    /**
     * Compile a raw where clause.
     *
     * @param \Leaps\Database\Query\Builder $query
     * @param array $where
     * @return string
     */
    protected function whereRaw(Builder $query, $where)
    {
        return $where ['sql'];
    }

    /**
     * Compile the "group by" portions of the query.
     *
     * @param \Leaps\Database\Query\Builder $query
     * @param array $groups
     * @return string
     */
    protected function compileGroups(Builder $query, $groups)
    {
        return 'group by ' . $this->columnize ( $groups );
    }

    /**
     * Compile the "having" portions of the query.
     *
     * @param \Leaps\Database\Query\Builder $query
     * @param array $havings
     * @return string
     */
    protected function compileHavings(Builder $query, $havings)
    {
        $me = $this;

        $sql = implode ( ' ', array_map ( array (
                $this,
                'compileHaving'
        ), $havings ) );

        return 'having ' . preg_replace ( '/and /', '', $sql, 1 );
    }

    /**
     * Compile a single having clause.
     *
     * @param array $having
     * @return string
     */
    protected function compileHaving(array $having)
    {
        if ( $having ['type'] === 'raw' ) {
            return $having ['boolean'] . ' ' . $having ['sql'];
        }
        return $this->compileBasicHaving ( $having );
    }

    /**
     * Compile a basic having clause.
     *
     * @param array $having
     * @return string
     */
    protected function compileBasicHaving($having)
    {
        $column = $this->wrap ( $having ['column'] );
        $parameter = $this->parameter ( $having ['value'] );
        return 'and ' . $column . ' ' . $having ['operator'] . ' ' . $parameter;
    }

    /**
     * Compile the "order by" portions of the query.
     *
     * @param \Leaps\Database\Query\Builder $query
     * @param array $orders
     * @return string
     */
    protected function compileOrders(Builder $query, $orders)
    {
        $me = $this;
        return 'order by ' . implode ( ', ', array_map ( function ($order) use($me)
        {
            return $me->wrap ( $order ['column'] ) . ' ' . $order ['direction'];
        }, $orders ) );
    }

    /**
     * Compile the "limit" portions of the query.
     *
     * @param \Leaps\Database\Query\Builder $query
     * @param int $limit
     * @return string
     */
    protected function compileLimit(Builder $query, $limit)
    {
        return "limit $limit";
    }

    /**
     * Compile the "offset" portions of the query.
     *
     * @param \Leaps\Database\Query\Builder $query
     * @param int $offset
     * @return string
     */
    protected function compileOffset(Builder $query, $offset)
    {
        return "offset $offset";
    }

    /**
     * Compile the "union" queries attached to the main query.
     *
     * @param \Leaps\Database\Query\Builder $query
     * @return string
     */
    protected function compileUnions(Builder $query)
    {
        $sql = '';
        foreach ( $query->unions as $union ) {
            $joiner = $union ['all'] ? 'union all ' : 'union ';

            $sql = $joiner . $union ['query']->toSql ();
        }
        return $sql;
    }

    /**
     * Compile an insert statement into SQL.
     *
     * @param \Leaps\Database\Query\Builder $query
     * @param array $values
     * @return string
     */
    public function compileInsert(Builder $query, array $values)
    {
        $table = $this->wrapTable ( $query->from );
        if ( ! is_array ( reset ( $values ) ) ) {
            $values = array (
                    $values
            );
        }
        $columns = $this->columnize ( array_keys ( reset ( $values ) ) );
        $parameters = $this->parameterize ( reset ( $values ) );
        $value = array_fill ( 0, count ( $values ), "($parameters)" );
        $parameters = implode ( ', ', $value );
        return "insert into $table ($columns) values $parameters";
    }

    /**
     * Compile an insert and get ID statement into SQL.
     *
     * @param \Leaps\Database\Query\Builder $query
     * @param array $values
     * @param string $sequence
     * @return string
     */
    public function compileInsertGetId(Builder $query, $values, $sequence)
    {
        return $this->compileInsert ( $query, $values );
    }

    /**
     * Compile an update statement into SQL.
     *
     * @param \Leaps\Database\Query\Builder $query
     * @param array $values
     * @return string
     */
    public function compileUpdate(Builder $query, $values)
    {
        $table = $this->wrapTable ( $query->from );
        $columns = array ();
        foreach ( $values as $key => $value ) {
            $columns [] = $this->wrap ( $key ) . ' = ' . $this->parameter ( $value );
        }
        $columns = implode ( ', ', $columns );
        if ( isset ( $query->joins ) ) {
            $joins = ' ' . $this->compileJoins ( $query, $query->joins );
        } else {
            $joins = '';
        }
        $where = $this->compileWheres ( $query );
        return trim ( "update {$table}{$joins} set $columns $where" );
    }

    /**
     * Compile a delete statement into SQL.
     *
     * @param \Leaps\Database\Query\Builder $query
     * @param array $values
     * @return string
     */
    public function compileDelete(Builder $query)
    {
        $table = $this->wrapTable ( $query->from );
        $where = is_array ( $query->wheres ) ? $this->compileWheres ( $query ) : '';
        return trim ( "delete from $table " . $where );
    }

    /**
     * Compile a truncate table statement into SQL.
     *
     * @param \Leaps\Database\Query\Builder $query
     * @return array
     */
    public function compileTruncate(Builder $query)
    {
        return array (
                'truncate ' . $this->wrapTable ( $query->from ) => array ()
        );
    }

    /**
     * Concatenate an array of segments, removing empties.
     *
     * @param array $segments
     * @return string
     */
    protected function concatenate($segments)
    {
        return implode ( ' ', array_filter ( $segments, function ($value)
        {
            return ( string ) $value !== '';
        } ) );
    }

    /**
     * Remove the leading boolean from a statement.
     *
     * @param string $value
     * @return string
     */
    protected function removeLeadingBoolean($value)
    {
        return preg_replace ( '/and |or /', '', $value, 1 );
    }
}