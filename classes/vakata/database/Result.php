<?php
namespace vakata\database;

class Result implements ResultInterface, \JsonSerializable 
{
	protected $all  = null;
	protected $rdy  = false;
	protected $rslt	= null;
	protected $mode	= null;
	protected $fake	= null;
	protected $skip	= false;
	protected $opti	= true;

	protected $fake_key	= 0;
	protected $real_key	= 0;
	public function __construct(QueryResult $rslt, $key = null, $skip_key = false, $mode = 'assoc', $opti = true) {
		$this->rslt = $rslt;
		$this->mode = $mode;
		$this->fake = $key;
		$this->skip = $skip_key;
		$this->opti = $opti;
	}
	public function count() {
		if(!$this->rslt->countable() && !$this->rdy) {
			$this->get();
		}
		return $this->rdy ? count($this->all) : $this->rslt->count();
	}
	public function current() {
		if(($this->rslt->countable() || $this->rdy) && !$this->count()) {
			return null;
		}
		if($this->rdy) {
			return current($this->all);
		}
		$tmp = $this->rslt->row();
		$row = [];

		switch($this->mode) {
			case 'num':
				foreach($tmp as $k => $v) {
					if(is_int($k)) {
						$row[$k] = $v;
					}
				}
				break;
			case 'both':
				$row = $tmp;
				break;
			case 'assoc':
			default:
				foreach($tmp as $k => $v) {
					if(!is_int($k)) {
						$row[$k] = $v;
					}
				}
				break;
		}
		if($this->fake) {
			$this->fake_key = $row[$this->fake];
		}
		if($this->skip) {
			unset($row[$this->fake]);
		}
		if($this->opti && is_array($row) && count($row) <= 1) {
			$row = count($row) ? current($row) : current($tmp);
		}
		return $row;
	}
	public function key() {
		if($this->rdy) {
			return key($this->all);
		}
		return $this->fake ? $this->fake_key : $this->real_key;
	}
	public function next() {
		if($this->rdy) {
			return next($this->all);
		}
		$this->rslt->nextr();
		$this->real_key++;
	}
	public function rewind() {
		if($this->real_key !== 0 && !$this->rdy && !$this->rslt->seekable()) {
			$this->get();
		}
		if($this->rdy) {
			return reset($this->all);
		}
		$this->rslt->seek(($this->real_key = 0));
		$this->rslt->nextr();
	}
	public function valid() {
		if($this->rdy) {
			return current($this->all) !== false;
		}
		return $this->rslt->row() !== false && $this->rslt->row() !== null;
	}

	public function one() {
		$this->rewind();
		return $this->current();
	}
	public function get() {
		if(!$this->rdy) {
			$this->all = [];
			foreach($this as $k => $v) {
				$this->all[$k] = $v;
			}
			$this->rdy = true;
		}
		return $this->all;
	}
	public function offsetExists($offset) {
		if($this->rdy) {
			return isset($this->all[$offset]);
		}
		if($this->fake === null && $this->rslt->seekable()) {
			return $this->rslt->seek(($this->real_key = $offset));
		}
		$this->get();
		return isset($this->all[$offset]);
	}
	public function offsetGet($offset) {
		if($this->rdy) {
			return $this->all[$offset];
		}
		if($this->fake === null && $this->rslt->seekable()) {
			$this->rslt->seek(($this->real_key = $offset));
			$this->rslt->nextr();
			return $this->current();
		}
		$this->get();
		return $this->all[$offset];
	}
	public function offsetSet ($offset, $value) {
		throw new DatabaseException('Cannot set result');
	}
	public function offsetUnset ($offset) {
		throw new DatabaseException('Cannot unset result');
	}
	public function __sleep() {
		$this->get();
		return array('all', 'rdy', 'mode', 'fake', 'skip');
	}
	public function __toString() {
		return print_r($this->get(), true);
	}
	public function jsonSerialize() {
	    return $this->get();
	}
}