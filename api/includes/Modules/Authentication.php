<?php
/**
 * This module handles the authentication functions
 *
 * @package phpbb.json
 * @license http://opensource.org/licenses/gpl-license.php GNU Public License
 * @author  Florin Pavel
 */
namespace phpBBJson\Modules;

class Authentication extends Base
{
	/**
	 * @param \Slim\Http\Request  $request
	 * @param \Slim\Http\Response $response
	 * @param string[]            $args
	 * @return \Slim\Http\Response
	 * @throws \phpBBJson\Exception\Unauthorized
	 */
	public function login($request, $response, $args)
	{
		$auth = $this->phpBB->get_auth();
		$db   = $this->phpBB->get_db();

		$username = $request->getParam('username');
		$password = $request->getParam('password');

		//print_r($this->request->get_datum());
		$result = $auth->login($username, $password);
		//print_r($result);

		if ($username == "" || empty($username) || $password == "" || empty($password)) {
			throw new \phpBBJson\Exception\Unauthorized("One of the parameters is empty or null");
		}

		if ($result['status'] == 3) {
			$secret = $this->pbkdf2('sha256', $password, $username, 10000, 24);
			//echo $secret;
			$user_row = $result['user_row'];
			//print_r($user_row);
			$sql    = "SELECT COUNT(*) AS num_count FROM " . API_SECRET . " WHERE secret = '{$secret}'";
			$result = $db->sql_query($sql);
			$count  = (int)$db->sql_fetchfield('num_count');
			if ($count == 0) {
				$db->sql_query(
					"INSERT INTO " . API_SECRET . " (`secret`, `user_id`) VALUES ('" . $secret . "', '" . $user_row['user_id'] . "')"
				);
			}
			return $response->withJson(['secret' => $secret, 'user_id' => $user_row['user_id']]);
		} else {
			throw new \phpBBJson\Exception\Unauthorized("Login failed");
		}
	}

	/**
	 * @param \Slim\Http\Request  $request
	 * @param \Slim\Http\Response $response
	 * @param string[]            $args
	 * @return \Slim\Http\Response
	 */
	public function logout($request, $response, $args)
	{
		$db     = $this->phpBB->get_db();
		$secret = $request->getParam('secret');
		$result = $db->sql_query("SELECT COUNT(*) AS num_count FROM " . API_SECRET . " WHERE secret = '{$secret}'");
		$count  = (int)$db->sql_fetchfield('num_count');
		if ($count > 0) {
			$db->sql_query("DELETE FROM " . API_SECRET . " WHERE secret = '{$secret}'");
		}
		return $response->withJson(['success', 'You have been logged out']);
	}

	/**
	 * @author Chris Horeweg
	 * @url http://www.phphulp.nl/php/script/beveiliging/pbkdf2-een-veilige-manier-om-wachtwoorden-op-te-slaan/1956/pbkdf2php/1757/
	 *
	 * PBKDF2 key derivation function as defined by RSA's PKCS #5: https://www.ietf.org/rfc/rfc2898.txt
	 * Test vectors can be found here: https://www.ietf.org/rfc/rfc6070.txt
	 * This implementation of PBKDF2 was originally created by defuse.ca
	 * With improvements by variations-of-shadow.com
	 *
	 * @param string  $algorithm  - The hash algorithm to use. Recommended: SHA256
	 * @param string  $password   - The password.
	 * @param string  $salt       - A salt that is unique to the password.
	 * @param int     $count      - Iteration count. Higher is better, but slower. Recommended: At least 1024.
	 * @param int     $key_length - The length of the derived key in bytes.
	 * @param boolean $raw_output - If true, the key is returned in raw binary format. Hex encoded otherwise.
	 * @return string A $key_length-byte key derived from the password and salt.
	 */
	private function pbkdf2($algorithm, $password, $salt, $count, $key_length, $raw_output = false)
	{
		$algorithm = strtolower($algorithm);
		if (!in_array($algorithm, hash_algos(), true)) {
			die('PBKDF2 ERROR: Invalid hash algorithm.');
		}
		if ($count <= 0 || $key_length <= 0) {
			die('PBKDF2 ERROR: Invalid parameters.');
		}

		$hash_length = strlen(hash($algorithm, "", true));
		$block_count = ceil($key_length / $hash_length);

		$output = "";
		for ($i = 1; $i <= $block_count; $i++) {
			// $i encoded as 4 bytes, big endian.
			$last = $salt . pack("N", $i);
			// first iteration
			$last = $xorsum = hash_hmac($algorithm, $last, $password, true);
			// perform the other $count - 1 iterations
			for ($j = 1; $j < $count; $j++) {
				$xorsum ^= ($last = hash_hmac($algorithm, $last, $password, true));
			}
			$output .= $xorsum;
		}

		if ($raw_output) {
			return substr($output, 0, $key_length);
		} else {
			return bin2hex(substr($output, 0, $key_length));
		}
	}

	/**
	 * @return \Closure
	 */
	public function constructRoutes()
	{
		$self = $this;
		return function () use ($self) {
			/** @var \Slim\App $this */
			$this->get('/login', [$self, 'login']);
			$this->get('/logout', [$self, 'logout']);
		};
	}

	/**
	 * @return string
	 */
	public static function getGroup()
	{
		return '/auth';
	}
}