<?php

namespace brunojk\LaravelRethinkdb\Query;

use brunojk\LaravelRethinkdb\Connection;
use brunojk\LaravelRethinkdb\Query;
use brunojk\LaravelRethinkdb\RQL\FilterBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use r;

class Builder extends QueryBuilder
{
    /**
     * The query instance.
     *
     * @var Query
     */
    protected $query;

    /**
     * The r\Table instance.
     *
     * @var r\Table
     */
    protected $table;

    /**
     * All of the available clause operators.
     *
     * @var array
     */
    protected $operators = [
        '=', '<', '>', '<=', '>=', '<>', '!=',
        'like', 'not like', 'between', 'ilike',
        '&', '|', '^', '<<', '>>',
        'rlike', 'regexp', 'not regexp',
        '~', '~*', '!~', '!~*',
        'contains', 'exists', 'type', 'mod', 'size',
    ];

    /**
     * Create a new query builder instance.
     *
     * @param Connection $connection
     */
    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
        $this->grammar = $connection->getQueryGrammar();
        $this->processor = $connection->getPostProcessor();
        $this->query = new Query($this->connection);
    }

    public function r() {
        return $this->query;
    }


    /**
     * Get the count of the total records for the paginator.
     *
     * @param  array  $columns
     * @return int
     */
    public function getCountForPagination($columns = ['*'])
    {
        $this->backupFieldsForCount();

        $this->aggregate = ['function' => 'count', 'columns' => $this->clearSelectAliases($columns)];

        $results = $this->r()->count();
        return $results;

//        $this->aggregate = null;
//
//        $this->restoreFieldsForCount();
//
//        if (isset($this->groups)) {
//            return count($results);
//        }
//
//        return isset($results[0]) ? (int) array_change_key_case((array) $results[0])['aggregate'] : 0;
    }

    /**
     * Set the collection which the query is targeting.
     *
     * @param string $table
     *
     * @return Builder
     */
    public function from($table)
    {
        if ($table) {
            $this->table = r\table($table);
            $this->query->table($table);
        }

        return parent::from($table);
    }

    /**
     * Execute the query as a fresh "select" statement.
     *
     * @param array $columns
     *
     * @return array|static[]
     */
    public function getFresh($columns = [])
    {
        $this->compileOrders();
        $this->compileWheres();

        if ($this->offset) {
            $this->query->skip($this->offset);
        }
        if ($this->limit) {
            $this->query->limit($this->limit);
        }
        if ($this->columns) {
            $columns = $this->columns;
        }

        if (!empty($columns) && $columns[0] != '*') {
            $this->query->pluck($columns);
        }

        $results = $this->query->run();

        if (is_object($results)) {
            $results = $results->toArray();
        }

        if (isset($results['$reql_type$'])
            && $results['$reql_type$'] === 'GROUPED_DATA') {
            return $results['data'];
        }

        return $results;
    }

    /**
     * Run the query as a "select" statement against the connection.
     *
     * @return array
     */
    protected function runSelect()
    {
        return $this->getFresh();
    }

    /**
     * Compile orders into query.
     */
    public function compileOrders()
    {
        if (!$this->orders) {
            return;
        }

        foreach ($this->orders as $order) {
            $column = $order['column'];
            $direction = $order['direction'];

            $compiled = strtolower($direction) == 'asc'
                ? r\asc($column) : r\desc($column);

            // Use index as field if needed
            if ($order['index']) {
                $compiled = ['index' => $compiled];
            }

            $this->query->orderBy($compiled);
        }
    }

    /**
     * Insert a new record into the database.
     *
     * @param array $values
     *
     * @return bool
     */
    public function insert(array $values)
    {
        $this->compileWheres();
        $result = $this->query->insert($values);

        return 0 == (int) $result['errors'];
    }

    /**
     * Insert a new record and get the value of the primary key.
     *
     * @param array  $values
     * @param string $sequence
     *
     * @return int
     */
    public function insertGetId(array $values, $sequence = null)
    {
        $this->compileWheres();
        $result = $this->query->insert($values);

        if (0 == (int) $result['errors']) {
            if (isset($values['id'])) {
                return $values['id'];
            }

            // Return id
            return current($result['generated_keys']);
        }
    }

    /**
     * Update a record in the database.
     *
     * @param array $values
     * @param array $options
     *
     * @return int
     */
    public function update(array $values, array $options = [])
    {
        return $this->performUpdate($values, $options);
    }

    /**
     * Perform an update query.
     *
     * @param array $query
     * @param array $options
     *
     * @return int
     */
    protected function performUpdate($query, array $options = [])
    {
        $this->compileWheres();
        $result = $this->query->update($query)->run();

        if (0 == (int) $result['errors']) {
            return $result['replaced'];
        }

        return 0;
    }

    /**
     * Delete a record from the database.
     *
     * @param mixed $id
     *
     * @return int
     */
    public function delete($id = null)
    {
        // If an ID is passed to the method, we will set the where clause to check
        // the ID to allow developers to simply and quickly remove a single row
        // from their database without manually specifying the where clauses.
        if (!is_null($id)) {
            $this->where('id', '=', $id);
        }
        $this->compileWheres();

        return $this->query->delete()->run();
    }

    public function whereIn($column, $values, $boolean = 'and', $not = false)
    {
        $type = $not ? 'NotIn' : 'In';

        if ($values instanceof static) {
            return $this->whereInExistingQuery(
                $column, $values, $boolean, $not
            );
        }

        // If the value of the where in clause is actually a Closure, we will assume that
        // the developer is using a full sub-select for this "in" statement, and will
        // execute those Closures, then we can re-construct the entire sub-selects.
        if ($values instanceof Closure) {
            return $this->whereInSub($column, $values, $boolean, $not);
        }

        if ($values instanceof Arrayable) {
            $values = $values->toArray();
        }

        $this->wheres[] = compact('type', 'column', 'values', 'boolean');

        if( is_array($values) )
            foreach ($values as $value) {
                if (! $value instanceof Expression) {
                    $this->addBinding($value, 'where');
                }
            }

        return $this;
    }

    /**
     * Compile the where array to filter chain.
     *
     * @return array
     */
    protected function compileWheres()
    {
        // Wheres to compile
        $wheres = $this->wheres;

        // If there is nothing to do, then return
        if (!$wheres) {
            return;
        }

        $this->query->filter(function ($document) use ($wheres) {
            $builder = new FilterBuilder($document);

            return $builder->compileWheres($wheres);
        });
    }

    public function buildFilter($document)
    {
        $builder = new FilterBuilder($document);

        return $builder->compileWheres($this->wheres);
    }

    /**
     * Run a truncate statement on the table.
     *
     * @return void
     */
    public function truncate()
    {
        $result = $this->query->delete()->run();

        return 0 == (int) $result['errors'];
    }

    protected function isAssocArray($arr) {
        if( !is_array($arr) ) return false;
        return array_keys($arr) !== range(0, count($arr) - 1);
    }

    /**
     * Append one or more values to an array.
     *
     * @param mixed $column
     * @param mixed $value
     * @param bool  $unique
     *
     * @return bool
     */
    public function push($column, $value = null, $unique = false)
    {
        $operation = is_array($value) ? 'add' : 'append';

        $this->compileWheres();

        if( $this->isAssocArray($value) ) {
            $operation = $unique ? 'merge' : 'append';

            $result = $this->query->update([
                $column => r\row($column)->{$operation}($value),
            ])->run();
        }
        else if ( !$unique ) {
            $result = $this->query->update([
                $column => r\row($column)->rDefault([])->{$operation}($value),
            ])->run();
        }
        else
            $result = $this->query->update([
                $column => r\row($column)->rDefault([])->difference((array)$value)->{$operation}($value),
            ])->run();

        return 0 == (int) $result['errors'];
    }

    /**
     * Remove one or more values from an array.
     *
     * @param mixed $column
     * @param mixed $value
     *
     * @return bool
     */
    public function pull($column, $value = null)
    {
        $value = is_array($value) ? $value : [$value];

        $this->compileWheres();
        $result = $this->query->update([
            $column => r\row($column)->difference($value),
        ])->run();

        return 0 == (int) $result['errors'];
    }

    /**
     * Force the query to only return distinct results.
     *
     * @var string|null
     *
     * @return Builder
     */
    public function distinct($column = null)
    {
        if ($column) {
            $column = ['index' => $column];
        }

        $this->query = $this->query->distinct($column);

        return $this;
    }

    /**
     * Retrieve the "count" result of the query.
     *
     * @param string $columns
     *
     * @return int
     */
    public function count($columns = null)
    {
        $this->compileWheres();
        $result = $this->query->count();

        return (int) $result;
    }

    /**
     * Retrieve the sum of the values of a given column.
     *
     * @param string $column
     *
     * @return mixed
     */
    public function sum($column)
    {
        $this->compileWheres();
        $result = $this->query->sum($column);

        return $result;
    }

    /**
     * Retrieve the minimum value of a given column.
     *
     * @param string $column
     *
     * @return mixed
     */
    public function min($column)
    {
        $this->compileWheres();
        $result = $this->query->min($column)
            ->getField($column)->rDefault(null)
            ->run();

        return $result;
    }

    /**
     * Retrieve the maximum value of a given column.
     *
     * @param string $column
     *
     * @return mixed
     */
    public function max($column)
    {
        $this->compileWheres();
        $result = $this->query->max($column)
            ->getField($column)->rDefault(null)
            ->run();

        return $result;
    }

    /**
     * Retrieve the average of the values of a given column.
     *
     * @param string $column
     *
     * @return mixed
     */
    public function avg($column)
    {
        $this->compileWheres();
        $result = $this->query->avg($column)
            ->rDefault(null)->run();

        return $result;
    }

    /**
     * Remove one or more fields.
     *
     * @param mixed $columns
     *
     * @return int
     */
    public function drop($columns)
    {
        if (!is_array($columns)) {
            $columns = [$columns];
        }

        $this->compileWheres();
        $result = $this->query->replace(function ($doc) use ($columns) {
            return $doc->without($columns);
        })->run();

        return 0 == (int) $result['errors'];
    }

    /**
     * Add a "group by" clause to the query.
     *
     * @param $groups
     * @return $this
     * @internal param array|string $column
     *
     */
    public function groupBy(...$groups)
    {
        foreach ($groups as $group) {
            $this->query->group($group)->ungroup()->map(function ($doc) {
                return $doc('reduction')->nth(0);
            });
        }

        return $this;
    }

    /**
     * Add an "order by" clause to the query.
     *
     * @param string $column
     * @param string $direction
     * @param bool   $index
     *
     * @return $this
     */
    public function orderBy($column, $direction = 'asc', $index = false)
    {
//        $property = $this->unions ? 'unionOrders' : 'orders';
//        $direction = strtolower($direction) == 'desc' ? 'desc' : 'asc';
//        $this->{$property}[] = compact('column', 'direction', 'index');

        if( $index )
            $this->query->orderBy(['index' => call_user_func("r\\{$direction}", $column)]);
        else
            $this->query->orderBy(call_user_func("r\\{$direction}", $column));

//        dd(func_get_args());
        return $this;
    }

    /**
     * Add a where between statement to the query.
     *
     * @param string $column
     * @param array  $values
     * @param string $boolean
     * @param bool   $not
     *
     * @return Builder
     */
    public function whereBetween($column, array $values, $boolean = 'and', $not = false)
    {
        $type = 'between';
        $this->wheres[] = compact('column', 'type', 'boolean', 'values', 'not');

        return $this;
    }

    /**
     * Handle dynamic method calls into the method.
     *
     * @param string $method
     * @param array  $parameters
     *
     * @return mixed
     */
    public function __call($method, $parameters)
    {
        if ($method == 'unset') {
            return call_user_func_array([$this, 'drop'], $parameters);
        }

        return parent::__call($method, $parameters);
    }
}
