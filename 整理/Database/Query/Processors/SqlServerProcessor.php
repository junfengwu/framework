<?php
namespace Leaps\Database\Query\Processors;

use Leaps\Database\Query\Builder;

class SqlServerProcessor extends Processor {

	/**
	 * Process an "insert get ID" query.
	 *
	 * @param  \Leaps\Database\Query\Builder  $query
	 * @param  string  $sql
	 * @param  array   $values
	 * @param  string  $sequence
	 * @return int
	 */
	public function processInsertGetId(Builder $query, $sql, $values, $sequence = null)
	{
		$query->getConnection()->insert($sql, $values);
		$id = $query->getConnection()->getPdo()->lastInsertId();
		return is_numeric($id) ? (int) $id : $id;
	}

}