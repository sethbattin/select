<?php
/*
 * This file is part of John Koniges' Select class
 * https://github.com/Venar/select
 *
 * Copyright (c) 2015 John J. Koniges
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace select;

class Select {
	private		$statement     = '';
	private		$where_clause  = '';
	private		$set_clause    = '';

	private		$offset        = null;
	private		$limit         = null;
	private		$group_by      = null;
	private		$order_by      = null;

	private		$params        = array();

	private		$uuid          = null;
	private		$current_ph    = 0;

	private		$where_mode    = array();

	private		$result;

	/* @var $pdo \PDO */
	private		$pdo;

	/**
	 * Example:
	 * $select = new Select('SELECT * FROM Test');
	 * @param string $sql  You can pass in a whole SQL command, or the start of one
	 * @param string $type The kind of QUERY this is: SELECT, UPDATE, INSERT, DELETE
	 * @param \PDO   $pdo  Your PDO class object of a valid connection to the database
	 */
	public function __construct($sql = null, $type = 'SELECT', $pdo = null) {
		$this->uuid = uniqid('param_');
		$this->where_mode[] = 'AND';
		if (!is_null($sql)) {
			if ($type == 'SELECT') {
				$words = explode(' ', $sql);
				if ( count($words) == 1 ) {
					$this->statement = 'SELECT * FROM `' . $sql . '`';
				} else {
					$this->statement = $sql;
				}
			} else if ($type == 'UPDATE') {
				$this->statement = 'UPDATE `' . $sql.'`';
			} else if ($type == 'INSERT') {
				$this->statement = 'INSERT INTO `' . $sql.'`';
			} else if ($type == 'DELETE') {
				$this->statement = 'DELETE FROM `' . $sql.'`';
			}
		}

		if ($pdo instanceof \PDO) {
			$this->pdo = $pdo;
		}
	}

	private function connect() {
		if (!$this->pdo instanceof \PDO) {
			$this->SetConnection();
		}

		if ($this->pdo instanceof \PDO) {
			return true;
		}
		return false;
	}

	/**
	 * This class will let a class extending this to set their own PDO object automatically without passing it in
	 *   the constructor.
	 * Classes that extend this class should overwrite this method with their default way to get a db object.
	 */
	protected function setConnection() {
		//
		return;
	}

	/**
	 * Closes an and control group
	 * @return \select\Select
	 */
	public function endAnd() {
		$this->endConjunction();
		return $this;
	}

	/**
	 * Closes an or control group
	 * @return \select\Select
	 */
	public function endOr() {
		$this->endConjunction();
		return $this;
	}

	/**
	 * If value is an empty string this field is not added to the query
	 *
	 * @param string  $field What is the Table field
	 * @param mixed   $value What value are you comparing
	 * @param int     $type What variable type is this?
	 * @return \select\Select
	 */
	public function eq($field, $value, $type = \PDO::PARAM_STR) {
		if (trim($value) != "" || is_bool($value)) {
			// Convert a bool into the literal strings used by enum in the db layer
			if (is_bool($value)) {
				$value = $value ? 'true' : 'false';
			}
			$this->SetConnection();
			$this->where_clause .= ' ' . $field . ' = ' . $this->addParam($value, $type);
		}
		return $this;
	}

	/**
	 * Is this field null?
	 *
	 * @param string  $field What is the Table field
	 * @return \select\Select
	 */
	public function eqNull($field) {
		$this->SetConnection();
		$this->where_clause .= ' ' . $field . ' IS NULL ';
		return $this;
	}

	/**
	 * Is this field null?
	 *
	 * @param string  $field What is the Table field
	 * @return \select\Select
	 */
	public function eqNotNull($field) {
		$this->SetConnection();
		$this->where_clause .= ' ' . $field . ' IS NOT NULL ';
		return $this;
	}

	/**
	 * similar to eq() however a blank value will cause the query to fail. This is used for required fields
	 *
	 * @param string  $field What is the Table field
	 * @param mixed   $value What value are you comparing
	 * @param int     $type What variable type is this?
	 * @return \select\Select
	 */
	public function eqDie($field, $value, $type = \PDO::PARAM_STR) {
		if (trim($value) != "" || is_bool($value)) {
			$this->eq($field, $value, $type);
		} else {
			$this->SetConnection();
			$this->where_clause .= ' 1 = 0';
		}
		return $this;
	}

	/**
	 * This executes the query and does the PDO Statements.
	 * @param boolean $debug Turn on debug settings out output
	 * @return \select\Select
	 */
	public function execute($debug = false) {
		if (!$this->connect()) {
			return false;
		}

		// If someone didn't close all control groups, we close them for them
		// 1 is always left in the stack as the base, so we don't pop that off
		while (count($this->where_mode) > 1) {
			$this->endConjunction();
		}

		$query = $this->getQuery();

		// We run all SQL as a prepared statement
		$stmt = $this->pdo->prepare($query);
		foreach ($this->params as $param) {
			/* @var $param Param */
			// We bind the parameters which replaces their placeholders in the string.
			$stmt->bindParam(':'.$param->placeholder, $param->value, $param->type);
		}

		if (!$debug) {
			// $stmt->execute() returns a bool if it worked or not... we will extend use later
			$result = $stmt->execute();
			if (!$result) {
				error_log('Bad Query: '.$query.PHP_EOL);
				//echo $query;
			}
		} else {
			return $query;
		}

		// The $stmt now has the results and we stash those
		$this->result = $stmt;
		return $this;
	}

	/**
	 * This will last insert ID.
	 * @return array
	 */
	public function getInsertId() {
		return $this->pdo->lastInsertId();
	}

	/**
	 *
	 * @return string
	 */
	public function getQuery() {
		// If the where clause has data, then we include the WHERE, otherwise we leave it off
		$query = $this->statement;

		if ($this->set_clause != '') {
			$query .= ' SET ' . $this->set_clause;
		}

		if ($this->where_clause != '') {
			$query .= ' WHERE ' . $this->where_clause;
		}

		if ( !is_null($this->group_by) ) {
			$query .= ' GROUP BY ' . $this->group_by;
		}

		if ( !is_null($this->order_by) ) {
			$query .= ' ORDER BY ' . $this->order_by;
		}

		if ( !is_null($this->limit) ) {
			$query .= ' LIMIT ' . $this->limit;
		}

		if ( !is_null($this->offset) ) {
			$query .= ' OFFSET ' . $this->offset;
		}

		return $query;
	}

	/**
	 * @return Param[] returns an Array of the Params for this object.
	 */
	public function getParams() {
		return $this->params;
	}

	/**
	 * This will return an MD Array of ALL of the results. Does not use a generator, PHP 5.5 safe.
	 * @param int $fetchType
	 * @return array
	 */
	public function fetchAllRows($fetchType = \PDO::FETCH_ASSOC) {
		$results = array();
		if (!$this->result instanceof \PDOStatement) {
			return $results;
		}

		try {
			$results = $this->result->FetchAll($fetchType);
		} finally {
			$this->result->closeCursor();
		}
		return $results;
	}

	/**
	 * This will return an MD Array of ALL of the results.
	 * @param int $fetchType
	 * @return \Generator
	 */
	public function getRows($fetchType = \PDO::FETCH_ASSOC) {
		try {
			while($resultSet = $this->result->fetch($fetchType)) {
				yield $resultSet;
			}
		} finally {
			$this->result->closeCursor();
		}
	}

	/**
	 * This will return an MD Array of all of the results.
	 * @return array
	 */
	public function getRowCount() {
		$count = 0;
		if ( $this->result instanceof \PDOStatement) {
			$count = $this->result->rowCount();
		}
		return $count;
	}

	/**
	 * This will only return the value of the first row in the first record
	 * Useful for getting one item returns from SQL
	 *
	 * @return array
	 */
	public function getSingleItem() {
		$result = null;
		if ($this->result instanceof \PDOStatement) {
			$results = $this->result->FetchAll();
			if (array_key_exists(0,$results)) {
				// Get the first value of the first record
				$value = current($results[0]);
				if ( $value !== false ) {
					$result = $value;
				}
			}
		}
		return $result;
	}

	/**
	 * Sets the grouping of the Query
	 * @param string $group_by What fields should be grouped by?
	 * @return \select\Select
	 */
	public function group($group_by) {
		$this->group_by = $group_by;
		return $this;
	}

	/**
	 * Takes in multiple types
	 * 1) array() - An array of all values to look for, this is slower the larger the array
	 * 2) String  - This is a query to run, you should not use any variables to protect against injection
	 * 3) Select object (NOT YET IMPLIMENTED)
	 *
	 * @param string  $field What is the Table field
	 * @param mixed   $value What value are you comparing
	 * @param int     $type What variable type is this?
	 * @param boolean $not What variable type is this?
	 * @return \select\Select
	 */
	public function in($field, $value, $type = \PDO::PARAM_STR, $not = false) {
		$not_string = '';
		if (!$not) {
			$not_string = ' NOT ';
		}

		if (is_array($value)) {
			$subselect = '';
			foreach ($value as $subvalue) {
				if ($subselect != '') {
					$subselect .= ', ';
				}
				$subselect .= $this->addParam($subvalue, $type);
			}
			$this->SetConnection();
			$this->where_clause .= ' ' . $field . $not_string . ' IN (' . $subselect.')';
		} else if ($value instanceof Select) {
			$sql                 = $value->getQuery();
			$this->params        = array_merge($this->params, $value->GetParams());
			$this->SetConnection();
			$this->where_clause .= ' ' . $field . $not_string . ' IN (' . $sql.')';
		} else {
			$this->SetConnection();
			$this->where_clause .= ' ' . $field . $not_string . ' IN (' . $value.')';
		}
		return $this;
	}

	/**
	 * Takes in multiple types
	 * 1) array() - An array of all values to look for, this is slower the larger the array
	 * 2) String  - This is a query to run, you should not use any variables to protect against injection
	 * 3) Select object (NOT YET IMPLIMENTED)
	 *
	 * @param string  $field What is the Table field
	 * @param mixed   $value What value are you comparing
	 * @param int     $type What variable type is this?
	 * @return \select\Select
	 */
	public function notIn($field, $value, $type = \PDO::PARAM_STR) {
		return $this->in($field, $value, $type, true);
	}

	/**
	 * If value is an empty string this field is not added to the query
	 *
	 * @param string  $field What is the Table field
	 * @param mixed   $value What value are you comparing
	 * @param int     $type What variable type is this?
	 * @return \select\Select
	 */
	public function like($field, $value, $type = \PDO::PARAM_STR) {
		if (trim($value) != "") {
			if (strpos($value, '%') === false) {
				$value = '%'.$value.'%';
			}

			// Convert a bool into the literal strings used by enum in the db layer
			$this->SetConnection();
			$this->where_clause .= ' ' . $field . ' LIKE ' . $this->addParam($value, $type);
		}
		return $this;
	}

	/**
	 * Sets the limit of the Query
	 * @param int $limit What is limit of rows that should be returned
	 * @return \select\Select
	 */
	public function limit($limit) {
		if ($limit) {
			$this->limit = $limit;
		}
		return $this;
	}

	/**
	 * If value is an empty string this field is not added to the query
	 *
	 * @param string  $field What is the Table field
	 * @param mixed   $value What value are you comparing
	 * @param int     $type What variable type is this?
	 * @return \select\Select
	 */
	public function notEq($field, $value, $type = \PDO::PARAM_STR) {
		if (trim($value) != "" || is_bool($value)) {
			// Convert a bool into the literal strings used by enum in the db layer
			if (is_bool($value)) {
				$value = $value ? 'true' : 'false';
			}
			$this->SetConnection();
			$this->where_clause .= ' ' . $field . ' != ' . $this->addParam($value, $type);
		}
		return $this;
	}

	/**
	 * Sets the limit of the Query
	 * @param int $offset What is offset of rows that should be returned
	 * @return \select\Select
	 */
	public function offset($offset) {
		if ($offset) {
			$this->offset = $offset;
		}
		return $this;
	}

	/**
	 * Sets the order of the Query
	 * @param string $order_by What should this order by
	 * @return \select\Select
	 */
	public function order($order_by) {
		$this->order_by = $order_by;
		return $this;
	}

	/**
	 * Sets values for inserting or updating
	 * @param string  $field What is the Table field
	 * @param mixed   $value What value are you comparing
	 * @param int     $type What variable type is this?
	 * @return \select\Select
	 */
	public function set($field, $value, $type = \PDO::PARAM_STR) {
		if($this->set_clause != '') {
			$this->set_clause .= rtrim(", \n ");
		}
		$this->set_clause .= ' ' . $field . ' = ' . $this->addParam($value, $type);
		return $this;
	}

	/**
	 * This starts a new control group. All added items will be seperated by AND
	 * @return \select\Select
	 */
	public function startAnd() {
		$this->SetConnection();
		$this->where_clause	.= " (";
		$this->where_mode[]	= "AND";
		return $this;
	}

	/**
	 * This starts a new control group. All added items will be seperated by OR
	 * @return \select\Select
	 */
	public function startOr() {
		$this->SetConnection();
		$this->where_clause	.= " (";
		$this->where_mode[]	= "OR";
		return $this;
	}

	/**
	 * This adds an AND or OR between fields based on what is the current type.
	 */
	private function addConjunction() {
		if($this->where_clause != '' && substr($this->where_clause, -1) != '(') {
			$this->where_clause = rtrim($this->where_clause) . " \n " . end($this->where_mode) . " ";
		}
	}

	/**
	 * This creates a param object and generates a palceholder for it
	 * @param mixed   $value What value are you comparing
	 * @param int     $type What variable type is this?
	 * @return string The Placeholder string for the prepared statement
	 */
	private function addParam($value, $type = \PDO::PARAM_STR) {
		$param = new Param($value, $type);
		$param->placeholder = $this->generatePlaceholder();
		$this->params[] = $param;
		return ':' . $param->placeholder;
	}

	/**
	 * This end the current control group conjunction. Removing the starting ( if no data inside it.
	 */
	private function endConjunction() {
		// MySQL will error if it sees a (), this is to remove those cases
		if (substr($this->where_clause, -1) == "(") {
			// We remove the last two characters since when we added it, it was a space then (
			$this->where_clause = substr($this->where_clause, 0, -2);
		} else {
			$this->where_clause .= ")";
		}
		array_pop($this->where_mode);
	}

	/**
	 * This is the placeholder string for each variable
	 * @return string
	 */
	private function generatePlaceholder() {
		return $this->uuid . $this->current_ph++;
	}
}
