<?php
namespace vakata\mail\send;

class SMTPSender implements SenderInterface
{
	protected $connection = null;

	protected function host() {
		if (isset($_SERVER) && isset($_SERVER['SERVER_NAME']) && !empty($_SERVER['SERVER_NAME'])) {
			return $_SERVER['SERVER_NAME'];
		}
		if (function_exists('gethostname')) {
			$temp = gethostname();
			if ($temp !== false) {
				return $temp;
			}
		}
		$temp = php_uname('n');
		if ($temp !== false) {
			return $temp;
		}
		return 'local.dev';
	}

	protected function read() {
		stream_set_timeout($this->connection, 300);
		$str = '';
		while (is_resource($this->connection) && !feof($this->connection)) {
			$tmp = @fgets($this->connection, 515);
			$str .= $tmp;
			if ((isset($tmp[3]) && $tmp[3] == ' ')) {
				break;
			}
		}
		return $str;
	}

	protected function data($data) {
		fwrite($this->connection, $data);
	}

	protected function comm($data, array $expect = []) {
		$this->data($data . "\r\n");
		$data = $this->read();
		$code = substr($data, 0, 3);
		$data = substr($data, 4);
		if (count($expect) && !in_array($code, $expect)) {
			throw new \vakata\mail\MailException('SMTP Error : ' . $code);
		}
		return $data;
	}

	public function __construct($connection) {
		$connection = parse_url($connection); // host, port, user, pass 
		if ($connection === false) {
			throw new \vakata\mail\MailException('Could not parse SMTP config');
		}

		$errn = 0;
		$errs = '';
		set_time_limit(300); // default is 5 minutes
		$this->connection = stream_socket_client(
			(isset($connection['scheme']) && $connection['scheme'] === 'ssl' ? 'ssl://' : '') . $connection['host'] . ":" . (isset($connection['port']) ? $connection['port'] : 25),
			$errn,
			$errs,
			300 // default is 5 minutes
		);

		if (!is_resource($this->connection)) {
			throw new \vakata\mail\MailException('Could not connect to SMTP server');
		}

		$this->read(); // get announcement if any
		$host = $this->host();
		try {
			$data = $this->comm('EHLO ' . $host, [250]);
		}
		catch(\vakata\mail\MailException $e) {
			$data = $this->comm('HELO ' . $host, [250]);
		}
		// parse hello fields
		$smtp = array();
		$data = explode("\n", $data);
		foreach ($data as $n => $s) {
			$s = trim(substr($s, 4));
			if (!$s) {
				continue;
			}
			$s = explode(' ', $s);
			if (!empty($s)) {
				if (!$n) {
					$n = 'HELO';
					$s = $s[0];
				}
				else {
					$n = array_shift($s);
					if ($n == 'SIZE') {
						$s = ($s) ? $s[0] : 0;
					}
				}
				$smtp[$n] = ($s ? $s : true);
			}
		}
		if (isset($connection['scheme']) && $connection['scheme'] === 'tls') {
			$this->comm('STARTTLS', [220]);
			if (!stream_socket_enable_crypto($this->connection, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
				throw new \vakata\mail\MailException('Could not secure connection');
			}
		}
		if (isset($connection['user'])) {
			$username = $connection['user'];
			$password = isset($connection['pass']) ? $connection['pass'] : '';
			$auth = 'LOGIN';
			if (isset($smtp['AUTH']) && is_array($smtp['AUTH'])) {
				foreach (['LOGIN', 'CRAM-MD5', 'PLAIN'] as $a) {
					if (in_array($a, $smtp['AUTH'])) {
						$auth = $a;
						break;
					}
				}
			}
			switch ($auth) {
				case 'PLAIN':
					$this->comm('AUTH PLAIN', [334]);
					$this->comm(base64_encode("\0" . $username . "\0" . $password), [235]);
					break;
				case 'LOGIN':
					$this->comm('AUTH LOGIN', [334]);
					$this->comm(base64_encode($username), [334]);
					$this->comm(base64_encode($password), [235]);
					break;
				case 'CRAM-MD5':
					$challenge = $this->comm('AUTH CRAM-MD5', [334]);
					$challenge = base64_decode($challenge);
					$this->comm(base64_encode($username . ' ' . hash_hmac('md5', $challenge, $password)), [235]);
			}
		}
	}

	public function __destruct() {
		try {
			if (is_resource($this->connection)) {
				$this->comm('QUIT', [221]);
				@fclose($this->connection);
			}
			$this->connection = null;
		} catch(\Exception $ignore) { }
	}

	public static function pop($connection) {
		$connection = parse_url($connection); // host, port, user, pass 
		if ($connection === false) {
			throw new \vakata\mail\MailException('Could not parse POP config');
		}

		$errn = 0;
		$errs = '';
		set_time_limit(30);
		$pop = @stream_socket_client(
			$connection['host'] . ":" . (isset($connection['port']) ? $connection['port'] : 110),
			$errn,
			$errs,
			30
		);

		if (!is_resource($pop)) {
			throw new \vakata\mail\MailException('Could not connect to POP server');
		}

		try {
			stream_set_timeout($pop, 30);
			if (substr(fgets($pop, 512), 0, 3) !== '+OK') {
				throw new \vakata\mail\MailException('Error reading POP server');
			}
			if (isset($connection['user'])) {
				fwrite($pop, 'USER ' . $connection['user'] . "\r\n");
				if (substr(fgets($pop, 512), 0, 3) !== '+OK') {
					throw new \vakata\mail\MailException('Error reading POP server');
				}
				fwrite($pop, 'PASS ' . (isset($connection['pass']) ? $connection['pass'] : '') . "\r\n");
				if (substr(fgets($pop, 512), 0, 3) !== '+OK') {
					throw new \vakata\mail\MailException('Error reading POP server');
				}
				try {
					if (is_resource($pop)) {
						fwrite($pop, 'QUIT');
						@fclose($pop);
					}
					$pop = null;
				} catch(\Exception $ignore) { }
			}
		}
		catch(\Exception $e) {
			try {
				if (is_resource($pop)) {
					fwrite($pop, 'QUIT');
					@fclose($pop);
				}
				$pop = null;
			} catch(\Exception $ignore) { }
			throw $e;
		}
	}

	public function send(array $to, array $cc, array $bcc, $from, $subject, $headers, $message) {
		$this->comm('MAIL FROM:<' . $from . '>', [250]);
		$recp = array_merge($to, $cc, $bcc);
		$badr = [];
		$good = [];
		foreach ($recp as $v) {
			try {
				$this->comm('RCPT TO:<' . $v . '>', [250, 251]);
				$good[] = $v;
			}
			catch(\vakata\mail\MailException $e) {
				$badr[] = $v;
			}
		}
		if (count($good)) {
			$this->comm('DATA', [354]);
			$data = $headers . "\r\n\r\n" . $message;

			$data = explode("\n", str_replace(array("\r\n", "\r"), "\n", $data));
			foreach ($data as $line) {
				if (isset($line[0]) && $line[0] === '.') {
					$line = '.' . $line;
				}
				$this->data($line . "\r\n");
			}
		}
		$this->comm('.', [250]);
		$this->comm('RSET', [250]);

		return [ 'good' => $good, 'fail' => $badr ];
	}
}