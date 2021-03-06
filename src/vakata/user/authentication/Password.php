<?php
namespace vakata\user\authentication;

use vakata\user\User;
use vakata\user\UserException;

class Password extends AbstractAuthentication
{
	protected $db = null;
	protected $tb = null;
	protected $settings = null;

	public function __construct(\vakata\database\DatabaseInterface $db, $tb = 'users_password', array $settings = []) {
		$this->db = $db;
		$this->tb = $tb;
		$this->settings = array_merge([
			'forgot_password'	=> 1800,
			'force_changepass'	=> 0, // никога 2592000 // 30 дни
			'error_timeout'		=> 30,
			'error_timeout_cnt'	=> 3,
			'max_errors'		=> 10,
			'ip_errors'			=> 5
		], $settings);
	}

	public function authenticate($data = null) {
		if (is_array($data) && isset($data['forgotpassword']) && strlen($data['forgotpassword']) && (int)$this->settings['forgot_password']) {
			$tmp = $this->db->one("SELECT password_id, created FROM ".$this->tb."_restore WHERE hash = ? AND used = 0 ORDER BY created DESC LIMIT 1", array($data['forgotpassword']));
			if (!$tmp) {
				throw new UserException('Невалиден токен');
			}
			if (time() - (int)strtotime($tmp['created']) > (int)$this->settings['forgot_password']) {
				throw new UserException('Изтекъл токен.');
			}
			$id = $this->validateChange($tmp['password_id'], $data);
			$this->db->query('UPDATE '.$this->tb.'_restore SET used = 1 WHERE hash = ? AND used = 0', array($data['forgotpassword']));
			$this->db->query('INSERT INTO '.$this->tb.'_log (password_id, action, created, ip, ua) VALUES(?,?,?,?,?)', array($id, 'login', date('Y-m-d H:i:s'), User::ipAddress(), User::userAgent()));
			$temp = $this->db->one("SELECT * FROM " . $this->tb . " WHERE id = ?", [$id]);
			return $this->filterReturn($temp);
		}
		// login with user and pass
		if (!isset($data['username']) || !isset($data['password'])) {
			return null;
		}
		$username = $data['username'];
		$password = $data['password'];

		if ((int)$this->settings['ip_errors'] && (int)$this->db->one('SELECT COUNT(*) FROM ' . $this->tb . '_log WHERE ip = ? AND action = \'error\' AND created > NOW() - INTERVAL 1 HOUR', array(User::ipAddress())) > (int)$this->settings['ip_errors']) {
			throw new UserException('IP адресът е блокиран за един час след ' . (int)$this->settings['ip_errors'] . ' грешни опита.');
		}
		$tmp = $this->db->one('SELECT id, username, password, created FROM ' . $this->tb . ' WHERE username = ? ORDER BY created DESC LIMIT 1', array($username));
		if (!$tmp) {
			throw new UserException('Грешно потребителско име.');
		}
		$err = $this->db->all('SELECT action, created FROM ' . $this->tb . '_log WHERE password_id = ? AND created > NOW() - INTERVAL 1 HOUR ORDER BY created DESC LIMIT 20', array($tmp['id']));
		$err_cnt = 0;
		$err_dtm = 0;
		foreach ($err as $e) {
			if ($e['action'] === 'login') {
				break;
			}
			if ($e['action'] === 'error') {
				$err_cnt ++;
				if (!$err_dtm) {
					$err_dtm = strtotime($e['created']);
				}
			}
		}
		if (
			(int)$this->settings['error_timeout'] &&
			$err_cnt && $err_cnt >= (int)$this->settings['error_timeout_cnt'] &&
			time() - $err_dtm < (int)$this->settings['error_timeout']
		) {
			throw new UserException('Изчакайте ' . (int)$this->settings['error_timeout'] . ' секунди преди нов опит.');
		}
		if ($err_cnt && (int)$this->settings['max_errors'] && $err_cnt >= (int)$this->settings['max_errors'] && time() - $err_dtm < 3600) {
			throw new UserException('Потребителят е блокиран за един час след ' . (int)$this->settings['max_errors'] . ' грешни опита.');
		}
		if ($tmp['password'] === '') {
			throw new UserException('Потребителят не може да влиза с парола.');
		}
		if ($password === $tmp['password']) {
			$this->db->query('INSERT INTO '.$this->tb.'_log (password_id, action, created, ip, ua) VALUES(?,?,?,?,?)', array($tmp['id'], 'login', date('Y-m-d H:i:s'), User::ipAddress(), User::userAgent()));
			$temp = $this->db->one('SELECT * FROM ' . $this->tb . ' WHERE id = ?', [ $this->validateChange($tmp['id'], $data) ]);
			return $this->filterReturn($temp);
		}
		if (!password_verify($password, $tmp['password'])) {
			$this->db->query('INSERT INTO '.$this->tb.'_log (password_id, action, created, ip, ua) VALUES(?,?,?,?,?)', array($tmp['id'], 'error', date('Y-m-d H:i:s'), User::ipAddress(), User::userAgent()));
			throw new UserException('Грешна парола.');
		}
		$this->db->query('INSERT INTO '.$this->tb.'_log (password_id, action, created, ip, ua) VALUES(?,?,?,?,?)', array($tmp['id'], 'login', date('Y-m-d H:i:s'), User::ipAddress(), User::userAgent()));
		if (
			((int)$this->settings['force_changepass'] && isset($tmp['created']) && time() - strtotime($tmp['created']) > (int)$this->settings['force_changepass']) ||
			(isset($data['password1']) && isset($data['password2']) && isset($data['changepassword']) && (int)$data['changepassword'])
		) {
			$temp = $this->db->one('SELECT * FROM ' . $this->tb . ' WHERE id = ?', [ $this->validateChange($tmp['id'], $data) ]);
			return $this->filterReturn($temp);
		}
		if (password_needs_rehash($tmp['password'], PASSWORD_DEFAULT)) {
			$this->rehashPassword($tmp['id'], $password, true);
		}
		return $this->filterReturn($tmp);
	}
	public function restore($data = null) {
		if ((int)$this->settings['forgot_password']) {
			$e = $this->db->one("SELECT id, username FROM ".$this->tb." WHERE password <> '' AND username = ?", array($data['username']));
			if (!$e) {
				throw new UserException('Невалидно потребителско име');
			}
			$m = $e['username'];
			$hsh = md5($e['id'] . $m . time() . rand(0,9));
			if ($this->db->query(
				"INSERT INTO ".$this->tb."_restore (hash, password_id, created, ip, ua) VALUES (?,?,?,?,?)",
				[$hsh, $e['id'], date('Y-m-d H:i:s'), User::ipAddress(), User::userAgent()]
			)->affected()) {
				return array('id' => $m, 'token' => $hsh, 'provider' => $this->provider()); //, 'is_mail' => filter_var($m, FILTER_VALIDATE_EMAIL));
			}
			throw new UserException('Моля, опитайте отново');
		}
		throw new NoRestoreException('Невъзможно възстановяването на парола');
	}

	protected function filterReturn($data) {
		$data['id'] = $data['username'];
		$data['name'] = $data['username'];
		if (filter_var($data['username'], FILTER_VALIDATE_EMAIL)) {
			$data['mail'] = $data['username'];
		}
		unset($data['password']);
		return $data;
	}
	protected function rehashPassword($id, $password) {
		$this->db->query("UPDATE ".$this->tb." SET password = ? WHERE id = ?", array(password_hash($password, PASSWORD_DEFAULT), $id));
	}
	protected function changePassword($id, $password) {
		//$username = $this->db->one("SELECT username FROM ".$this->tb." WHERE id = ?", [$id]);
		//return $this->db->query("INSERT INTO ".$this->tb." (username, password, created) VALUES(?,?,?)", [$username, password_hash($password, PASSWORD_DEFAULT), date('Y-m-d H:i:s')])->insertId();
		$this->db->query('INSERT INTO '.$this->tb.'_log (password_id, action, created, ip, ua) VALUES(?,?,?,?,?)', array($id, 'change', date('Y-m-d H:i:s'), User::ipAddress(), User::userAgent()));
		$this->db->query("UPDATE ".$this->tb." SET password = ?, created = ? WHERE id = ?", array(password_hash($password, PASSWORD_DEFAULT), date('Y-m-d H:i:s'), $id));
		return $id;
	}
	protected function validateChange($id, array $data) {
		if (!isset($data['password1']) || !isset($data['password2']) || !strlen($data['password2'])) {
			throw new PasswordChangeException('Моля сменете паролата си.');
		}
		if ($data['password1'] !== $data['password2']) {
			throw new PasswordChangeException('Паролите не съвпадат');
		}
		return $this->changePassword($id, $data['password1']);
	}
}
