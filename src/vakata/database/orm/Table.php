<?php
namespace vakata\database\orm;

use vakata\database\DatabaseInterface;

class Table implements TableInterface
{
	protected $database   = null;
	protected $table      = null;
	protected $definition = null;
	protected $relations  = [];

	protected $ext        = [];
	protected $new        = [];
	protected $del        = [];

	protected $result     = null;
	protected $filter     = [];
	protected $params     = [];
	protected $joined     = [];
	protected $order      = null;

	public function __construct(DatabaseInterface $database, $table, TableDefinitionInterface $definition = null) {
		$this->database   = $database;
		$this->table      = $table;
		$this->definition = isset($definition) ? $definition : new TableDefinition($this->database, $this->table);
	}
	public function __clone() {
		$this->reset();
	}

	public function getDatabase() {
		return $this->database;
	}
	public function getDefinition() {
		return $this->definition;
	}
	public function &getRelations() {
		return $this->relations;
	}
	public function getRelationKeys() {
		return array_keys($this->relations);
	}

	// relations
	protected function getRelatedTable($to_table) {
		if (!($to_table instanceof Table)) {
			if (!is_array($to_table)) {
				$to_table = [ 'table' => $to_table ];
			}
			if (!isset($to_table['definition'])) {
				$to_table['definition'] = null;
			}
			if (is_array($to_table['definition'])) {
				$to_table['definition'] = new TableDefinitionArray($to_table['table'], $to_table['definition']);
			}
			$to_table = new Table($this->getDatabase(), $to_table['table'], $to_table['definition']);
		}
		return $to_table;
	}
	public function addAdvancedRelation($to_table, $name, array $keymap, $many = true, $pivot = null, array $pivot_keymap = []) {
		if (!count($keymap)) {
			throw new ORMException('No linking fields specified');
		}
		$to_table = $this->getRelatedTable($to_table);
		$this->relations[$name] = [
			'name'         => $name,
			'table'        => $to_table,
			'keymap'       => $keymap,
			'many'         => (bool)$many,
			'pivot'        => $pivot,
			'pivot_keymap' => $pivot_keymap
		];
		return $this;
	}
	public function hasOne($to_table, $name = null, $to_table_column = null) {
		$to_table = $this->getRelatedTable($to_table);
		$columns = $to_table->definition->getColumns();

		$keymap = [];
		if (!isset($to_table_column)) {
			$to_table_column = [];
		}
		if (!is_array($to_table_column)) {
			$to_table_column = [ $to_table_column ];
		}
		foreach ($this->definition->getPrimaryKey() as $k => $pk_field) {
			$key = null;
			if (isset($to_table_column[$pk_field])) {
				$key = $to_table_column[$pk_field];
			}
			elseif (isset($to_table_column[$k])) {
				$key = $to_table_column[$k];
			}
			else {
				$key = $this->definition->getName() . '_' . $pk_field;
			}
			if (!in_array($key, $columns)) {
				throw new ORMException('Missing foreign key mapping');
			}
			$keymap[$pk_field] = $key;
		}

		if (!isset($name)) {
			$name = $to_table->definition->getName() . '_' . implode('_', array_keys($keymap));
		}
		$this->relations[$name] = [
			'name'         => $name,
			'table'        => $to_table,
			'keymap'       => $keymap,
			'many'         => false,
			'pivot'        => null,
			'pivot_keymap' => []
		];
		return $this;
	}
	public function hasMany($to_table, $name = null, $to_table_column = null) {
		$to_table = $this->getRelatedTable($to_table);
		$columns = $to_table->definition->getColumns();

		$keymap = [];
		if (!isset($to_table_column)) {
			$to_table_column = [];
		}
		if (!is_array($to_table_column)) {
			$to_table_column = [ $to_table_column ];
		}
		foreach ($this->definition->getPrimaryKey() as $k => $pk_field) {
			$key = null;
			if (isset($to_table_column[$pk_field])) {
				$key = $to_table_column[$pk_field];
			}
			elseif (isset($to_table_column[$k])) {
				$key = $to_table_column[$k];
			}
			else {
				$key = $this->definition->getName() . '_' . $pk_field;
			}
			if (!in_array($key, $columns)) {
				throw new ORMException('Missing foreign key mapping');
			}
			$keymap[$pk_field] = $key;
		}

		if (!isset($name)) {
			$name = $to_table->definition->getName() . '_' . implode('_', array_keys($keymap));
		}
		$this->relations[$name] = [
			'name'         => $name,
			'table'        => $to_table,
			'keymap'       => $keymap,
			'many'         => true,
			'pivot'        => null,
			'pivot_keymap' => []
		];
		return $this;
	}
	public function belongsTo($to_table, $name = null, $local_column = null) {
		$to_table = $this->getRelatedTable($to_table);
		$columns = $this->definition->getColumns();

		$keymap = [];
		if (!isset($local_column)) {
			$local_column = [];
		}
		if (!is_array($local_column)) {
			$local_column = [ $local_column ];
		}
		foreach ($to_table->definition->getPrimaryKey() as $k => $pk_field) {
			$key = null;
			if (isset($local_column[$pk_field])) {
				$key = $local_column[$pk_field];
			}
			elseif (isset($local_column[$k])) {
				$key = $local_column[$k];
			}
			else {
				$key = $to_table->definition->getName() . '_' . $pk_field;
			}
			if (!in_array($key, $columns)) {
				throw new ORMException('Missing foreign key mapping');
			}
			$keymap[$key] = $pk_field;
		}

		if (!isset($name)) {
			$name = $to_table->definition->getName() . '_' . implode('_', array_keys($keymap));
		}
		$this->relations[$name] = [
			'name'         => $name,
			'table'        => $to_table,
			'keymap'       => $keymap,
			'many'         => false,
			'pivot'        => null,
			'pivot_keymap' => []
		];
		return $this;
	}
	public function manyToMany($to_table, $pivot, $name = null, $to_table_column = null, $local_column = null) {
		$to_table = $this->getRelatedTable($to_table);
		$pt_table = $this->getRelatedTable($pivot);

		$pivot_columns = $pt_table->definition->getColumns();

		$keymap = [];
		if (!isset($to_table_column)) {
			$to_table_column = [];
		}
		if (!is_array($to_table_column)) {
			$to_table_column = [ $to_table_column ];
		}
		foreach ($this->definition->getPrimaryKey() as $k => $pk_field) {
			$key = null;
			if (isset($to_table_column[$pk_field])) {
				$key = $to_table_column[$pk_field];
			}
			elseif (isset($to_table_column[$k])) {
				$key = $to_table_column[$k];
			}
			else {
				$key = $this->definition->getName() . '_' . $pk_field;
			}
			if (!in_array($key, $pivot_columns)) {
				throw new ORMException('Missing foreign key mapping');
			}
			$keymap[$pk_field] = $key;
		}

		$pivot_keymap = [];
		if (!isset($local_column)) {
			$local_column = [];
		}
		if (!is_array($local_column)) {
			$local_column = [ $local_column ];
		}
		foreach ($to_table->definition->getPrimaryKey() as $k => $pk_field) {
			$key = null;
			if (isset($local_column[$pk_field])) {
				$key = $local_column[$pk_field];
			}
			elseif (isset($local_column[$k])) {
				$key = $local_column[$k];
			}
			else {
				$key = $to_table->definition->getName() . '_' . $pk_field;
			}
			if (!in_array($key, $pivot_columns)) {
				throw new ORMException('Missing foreign key mapping');
			}
			$pivot_keymap[$key] = $pk_field;
		}

		if (!isset($name)) {
			$name = $to_table->definition->getName() . '_' . implode('_', array_keys($keymap));
		}
		$this->relations[$name] = [
			'name'         => $name,
			'table'        => $to_table,
			'keymap'       => $keymap,
			'many'         => true,
			'pivot'        => $pivot,
			'pivot_keymap' => $pivot_keymap
		];
		return $this;
	}

	public function select($limit = 0, $offset = 0, array $fields = null) {
		if ($fields && count($fields)) {
			$temp = [];
			foreach ($fields as $k => $v) {
				if (!strpos($v, '.')) {
					if (in_array($v, $this->definition->getColumns()) || $v === '*') {
						$temp[] = 't.' . $v;
					}
				}
				else {
					if (preg_match('(^[a-z_0-9]+\.[a-z_0-9*]+$)i', $v)) {
						$v = explode('.', $v, 2);
						if (isset($this->relations[$v[0]]) && ($v[1] === '*' || in_array($v[1], $this->relations[$v[0]]['table']->definition->getColumns()))) {
							$this->joined[$v[0]] = 'LEFT';
							$temp[] = $v[1] === '*' ? implode('.', $v) : implode('.', $v) . ' AS ' . implode('___', $v);
						}
					}
				}
			}
			$fields = $temp;
		}
		if (!$fields || !count($fields)) {
			$fields = [];
			foreach ($this->definition->getColumns() as $c) {
				$fields[] = 't.'.$c;
			}
		}
		$sql = 'SELECT ' . implode(', ', $fields). ' FROM ' . $this->table . ' AS t ';

		foreach ($this->joined as $k => $v) {
			if ($this->relations[$k]['pivot']) {
				$sql .= 'LEFT JOIN ' . $this->relations[$k]['pivot'] . ' AS '.$k.'_pivot ON ';
				$tmp = [];
				foreach ($this->relations[$k]['keymap'] as $kk => $vv) {
					$tmp[] = 't.' . $kk . ' = ' . $k . '_pivot.' . $vv . ' ';
				}
				$sql .= implode(' AND ', $tmp);

				$sql .= 'LEFT JOIN ' . $this->relations[$k]['table']->definition->getName() . ' AS '.$k.' ON ';
				$tmp = [];
				foreach ($this->relations[$k]['pivot_keymap'] as $kk => $vv) {
					$tmp[] = $k . '.' . $vv . ' = ' . $k . '_pivot.' . $kk . ' ';
				}
				$sql .= implode(' AND ', $tmp);
			}
			else {
				$sql .= $v . ' JOIN ' . $this->relations[$k]['table']->definition->getName() . ' AS '.$k.' ON ';
				$tmp = [];
				foreach ($this->relations[$k]['keymap'] as $kk => $vv) {
					$tmp[] = 't.' . $kk . ' = ' . $k . '.' . $vv . ' ';
				}
				$sql .= implode(' AND ', $tmp);
			}
		}

		if (count($this->filter)) {
			$sql .= 'WHERE ' . implode(' AND ', $this->filter) . ' ';
		}
		if (count($this->joined)) {
			$sql .= 'GROUP BY t.' . implode(', t.', $this->definition->getPrimaryKey()) . ' ';
		}
		if ($this->order) {
			$sql .= 'ORDER BY ' . $this->order . ' ';
		}
		if ((int)$limit) {
			$sql .= 'LIMIT ' . (int)$limit . ' ';
		}
		if ((int)$limit && (int)$offset) {
			$sql .= 'OFFSET ' . (int)$offset;
		}
		$this->result = $this->database->get($sql, $this->params, null, false, 'assoc', false);
		$this->ext = [];
		return $this;
	}
	public function where($sql, array $params = []) {
		$this->filter[] = '(' . $sql . ')';
		$this->params = array_merge($this->params, array_values($params));
		$this->result = null;
		$this->ext = [];
		return $this;
	}
	public function order($order, $raw = false) {
		if ($raw) {
			$this->order = $order;
			return $this;
		}

		if (!is_array($order)) {
			$order = explode(',', $order);
		}
		$order = array_map('trim', $order);

		$temp = [];
		foreach ($order as $f) {
			$f = explode(' ', $f, 2);
			$f = array_map('trim', $f);
			if (!isset($f[1]) || !in_array($f[1], ['asc','desc','ASC','DESC'])) {
				$f[1] = 'ASC';
			}
			if (in_array($f[0], $this->definition->getColumns())) {
				$temp[] = $f[0] . ' ' . $f[1];
				continue;
			}
			if (strpos($f[0], '.')) {
				$t = explode('.', $f[0], 2);
				if (isset($this->relations[$t[0]]) && in_array($t[1], $this->relations[$t[0]]['table']->definition->getColumns())) {
					$this->joined[$t[0]] = 'LEFT';
					$temp[] = $f[0] . ' ' . $f[1];
				}
			}
		}
		$this->order = implode(', ', $temp);
		return $this;
	}

	public function filter($column, $value) {
		$column = explode('.', $column, 2);
		if (count($column) === 1 && in_array($column[0], $this->definition->getColumns())) {
			$column = 't.' . $column[0];
		}
		else if (count($column) === 2 && isset($this->relations[$column[0]]) && in_array($column[1], $this->relations[$column[0]]['table']->definition->getColumns())) {
			$this->joined[$column[0]] = 'LEFT';
			$column = implode('.', $column);
		}
		else {
			throw new ORMException('Invalid column: ' . implode('.', $column));
		}

		if (!is_array($value)) {
			return $this->where($column . ' = ?', [$value]);
		}
		if (isset($value['beg']) && isset($value['end'])) {
			return $this->where($column . ' BETWEEN ? AND ?', [ $value['beg'], $value['end'] ]);
		}
		if (count($value)) {
			return $this->where($column . ' IN ('.implode(',', array_fill(0, count($value), '?')).')', $value);
		}
		return $this;
	}

	public function count() {
		$sql = 'SELECT COUNT(DISTINCT t.' . implode(', t.', $this->definition->getPrimaryKey()) . ') FROM ' . $this->definition->getName() . ' AS t ';
		foreach ($this->joined as $k => $v) {
			if ($this->relations[$k]['pivot']) {
				$sql .= 'LEFT JOIN ' . $this->relations[$k]['pivot'] . ' AS '.$k.'_pivot ON ';
				$tmp = [];
				foreach ($this->relations[$k]['keymap'] as $kk => $vv) {
					$tmp[] = 't.' . $kk . ' = ' . $k . '_pivot.' . $vv . ' ';
				}
				$sql .= implode(' AND ', $tmp);

				$sql .= 'LEFT JOIN ' . $this->relations[$k]['table']->definition->getName() . ' AS '.$k.' ON ';
				$tmp = [];
				foreach ($this->relations[$k]['pivot_keymap'] as $kk => $vv) {
					$tmp[] = $k . '.' . $vv . ' = ' . $k . '_pivot.' . $kk . ' ';
				}
				$sql .= implode(' AND ', $tmp);
			}
			else {
				$sql .= $v . ' JOIN ' . $this->relations[$k]['table']->definition->getName() . ' AS '.$k.' ON ';
				$tmp = [];
				foreach ($this->relations[$k]['keymap'] as $kk => $vv) {
					$tmp[] = 't.' . $kk . ' = ' . $k . '.' . $vv . ' ';
				}
				$sql .= implode(' AND ', $tmp);
			}
		}
		if (count($this->filter)) {
			$sql .= 'WHERE ' . implode(' AND ', $this->filter) . ' ';
		}
		return $this->database->one($sql, $this->params);
	}
	public function reset() {
		$this->filter = [];
		$this->params = [];
		$this->result = null;
		$this->joined = [];
		$this->order  = null;
		$this->ext = [];
		$this->new = [];
		$this->del = [];
		return $this;
	}

	// row processing
	protected function extend($key, array $data = null) {
		if (isset($this->ext[$key])) {
			return $this->ext[$key];
		}
		if ($data === null) {
			return null;
		}
		return $this->ext[$key] = new TableRow($this, $data);
	}

	// array stuff - collection handling
	public function offsetGet($offset) {
		if ($this->result === null) {
			$this->select();
		}
		return $this->result->offsetExists($offset) ? $this->extend($offset, $this->result->offsetGet($offset)) : null;
	}
	public function offsetSet($offset, $value) {
		if ($offset === null) {
			if (is_array($value)) {
				$this->create($value);
				return ;
			}
			if ($value instanceof TableRow) {
				return $this->new[] = $value;
			}
			throw new ORMException('Invalid input to offsetSet');
		}
		if ($this->result === null) {
			$this->select();
		}
		if (!$this->offsetExists($offset)) {
			throw new ORMException('Invalid offset used with offsetSet', 404);
		}
		$temp = $this->offsetGet($offset);
		if (is_array($value)) {
			return $temp->fromArray($value);
		}
		if ($value instanceof TableRow) {
			$this->del[] = $temp;
			$this->new[] = $value;
		}
		throw new ORMException('Invalid input to offsetSet');
	}
	public function offsetExists($offset) {
		if ($this->result === null) {
			$this->select();
		}
		return $this->result->offsetExists($offset);
	}
	public function offsetUnset($offset) {
		if ($this->result === null) {
			$this->select();
		}
		if (!$this->offsetExists($offset)) {
			throw new ORMException('Invalid offset used with offsetUnset', 404);
		}
		$temp = $this->offsetGet($offset);
		if (!$temp) {
			throw new ORMException('Invalid offset used with offsetUnset', 404);
		}
		$this->del[] = $temp;
	}
	public function current() {
		if ($this->result === null) {
			$this->select();
		}
		return $this->extend($this->result->key(), $this->result->current());
	}
	public function key() {
		if ($this->result === null) {
			$this->select();
		}
		return $this->result->key();
	}
	public function next() {
		if ($this->result === null) {
			$this->select();
		}
		$this->result->next();
	}
	public function rewind() {
		if ($this->result === null) {
			$this->select();
		}
		$this->result->rewind();
	}
	public function valid() {
		if ($this->result === null) {
			$this->select();
		}
		return $this->result->valid();
	}

	// helpers
	public function toArray($full = true) {
		$temp = [];
		foreach ($this as $k => $v) {
			$temp[$k] = $v->toArray($full);
		}
		return $temp;
	}
	public function __debugInfo() {
		return $this->toArray();
	}
	public function jsonSerialize() {
		return $this->toArray();
	}

	// modifiers
	public function create(array $data = []) {
		$temp = new TableRow(clone $this, [], true);
		$temp->fromArray($data);
		return $this->new[] = $temp;
	}
	public function save(array $data = [], $delete = true) {
		$trans = $this->database->begin();
		try {
			$ret = [];
			$ids = null;
			foreach ($this->new as $temp) {
				foreach ($data as $k => $v) {
					$temp->{$k} = $v;
				}
				$ids = $temp->save();
				$ret[md5(serialize($ids))] = array_merge($temp->toArray(false), $ids);
			}
			foreach ($this as $temp) {
				foreach ($data as $k => $v) {
					$temp->{$k} = $v;
				}
				$ids = $temp->save();
				$ret[md5(serialize($ids))] = array_merge($temp->toArray(false), $ids);
			}
			foreach ($this->del as $temp) {
				$ids = $delete ? $temp->delete() : $temp->getID();
				unset($ret[md5(serialize($ids))]);
			}
			$this->database->commit($trans);
			return $ret;
		} catch (DatabaseException $e) {
			$this->database->rollback($trans);
			throw $e;
		}
	}
	public function delete() {
		$trans = $this->database->begin();
		try {
			foreach ($this as $temp) {
				$temp->delete();
			}
			$this->database->commit($trans);
		} catch (DatabaseException $e) {
			$this->database->rollback($trans);
			throw $e;
		}
	}
}
