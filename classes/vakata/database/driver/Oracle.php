<?php
namespace vakata\database\driver;
use vakata\database\DatabaseException;

class Oracle extends AbstractDriver
{
	protected $aff = 0;
	protected $transaction = false;

	protected function connect() {
		if($this->lnk === null) {
			$this->lnk = ($this->settings->persist) ?
					@oci_pconnect($this->settings->username, $this->settings->password, $this->settings->servername, $this->settings->charset) :
					@oci_connect($this->settings->username, $this->settings->password, $this->settings->servername, $this->settings->charset);
			if($this->lnk === false) {
				throw new DatabaseException('Connect error : ' . oci_error());
			}
			if($this->settings->timezone) {
				$this->real_query("ALTER session SET time_zone = '" . addslashes($this->settings->timezone) . "'");
			}
		}
	}
	protected function disconnect() {
		if(is_resource($this->lnk)) {
			oci_close($this->lnk);
		}
	}
	protected function real($sql) {
		$this->connect();
		$temp = oci_parse($this->lnk, $sql);
		if(!$temp || !oci_execute($temp, $this->transaction ? OCI_NO_AUTO_COMMIT : OCI_COMMIT_ON_SUCCESS)) {
			throw new DatabaseException('Could not execute real query : ' . oci_error($temp));
		}
		$this->aff = oci_num_rows($temp);
		return $temp;
	}

	public function prepare($sql) {
		$this->connect();
		$binder = '?';
		if(strpos($sql, $binder) !== false) {
			$tmp = explode($this->binder, $sql);
			$sql = $tmp[0];
			foreach($tmp as $i => $v) {
				$sql .= ':f' . $i;
				if(isset($tmp[($i + 1)])) {
					$sql .= $tmp[($i + 1)];
				}
			}
		}
		return oci_parse($this->lnk, $sql);
	}
	public function execute($sql, array $data = null) {
		$this->connect();
		if(!is_array($data)) {
			$data = array();
		}
		$data = array_values($data);
		foreach($data as $i => $v) {
			switch(gettype($v)) {
				case "boolean":
				case "integer":
					$data[$i] = (int)$v;
					oci_bind_by_name($sql, 'f'.$i, $data[$i], SQLT_INT);
					break;
				case "array":
					$data[$i] = implode(',', $v);
					oci_bind_by_name($sql, 'f'.$i, $data[$i]);
					break;
				case "object":
				case "resource":
					$data[$i] = serialize($data[$i]);
					oci_bind_by_name($sql, 'f'.$i, $data[$i]);
					break;
				default:
					oci_bind_by_name($sql, 'f'.$i, $data[$i]);
					break;
			}
		}
		$temp = oci_execute($sql, $this->transaction ? OCI_NO_AUTO_COMMIT : OCI_COMMIT_ON_SUCCESS);
		if(!$temp) {
			throw new DatabaseException('Could not execute query : ' . oci_error($sql));
		}
		$this->aff = oci_num_rows($sql);
		return $sql;
	}
	public function nextr($result) {
		return oci_fetch_array($result, OCI_BOTH);
	}
	public function count($result) {
		return oci_num_rows($result);
	}
	public function affected() {
		return $this->aff;
	}

	public function countable() {
		return true;
	}

	public function begin() {
		$this->connect();
		return $this->transaction = true;
	}
	public function commit() {
		$this->connect();
		if(!$this->transaction) {
			return false;
		}
		if(!oci_commit($this->lnk)) {
			return false;
		}
		$this->transaction = false;
		return true;
	}
	public function rollback() {
		$this->connect();
		if(!$this->transaction) {
			return false;
		}
		if(!oci_rollback($this->lnk)) {
			return false;
		}
		$this->transaction = false;
		return true;
	}
	public function isTransaction() {
		return $this->transaction;
	}

	/*
	public function insert_id($name = null) {
		$stm = oci_parse($this->link, 'SELECT '.strtoupper(str_replace("'",'',$name)).'.CURRVAL FROM DUAL');
		oci_execute($stm, $this->transaction ? OCI_NO_AUTO_COMMIT : OCI_COMMIT_ON_SUCCESS);
		$tmp = oci_fetch_array($stm);
		$tmp = $tmp[0];
		oci_free_statement($stm);
		return $tmp;
	}
	public function free($result) {
		//@oci_free_statement($result);
	}
	*/
}