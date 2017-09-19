<?php

/**
 * @private
 * @param mixed $row
 * @return User
 */
function _new_user($row) {
	return new User($row);
}


/**
 * Class User
 *
 * An object representing a row in the "users" table.
 *
 * The currently logged in user will always be accessible via the global variable $user.
 */
class User {
	/** @var int */
	public $id;

	/** @var string */
	public $name;

	/** @var string */
	public $email;

	public $join_date;

	/** @var string */
	public $passhash;

	/** @var UserClass */
	public $class;

	/* * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
	* Initialisation                                               *
	*                                                              *
	* User objects shouldn't be created directly, they should be   *
	* fetched from the database like so:                           *
	*                                                              *
	*    $user = User::by_name("bob");                             *
	* * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * */

	/**
	 * One will very rarely construct a user directly, more common
	 * would be to use User::by_id, User::by_session, etc.
	 *
	 * @param mixed[] $row
	 * @throws SCoreException
	 */
	public function __construct(array $row) {
		global $_shm_user_classes;

		$this->id = int_escape($row['id']);
		$this->name = $row['name'];
		$this->email = $row['email'];
		$this->join_date = $row['joindate'];
		$this->passhash = $row['pass'];

		if(array_key_exists($row["class"], $_shm_user_classes)) {
			$this->class = $_shm_user_classes[$row["class"]];
		}
		else {
			throw new SCoreException("User '{$this->name}' has invalid class '{$row["class"]}'");
		}
	}

	public static function by_session(string $name, string $session) {
		global $config, $database;
		$row = $database->cache->get("user-session:$name-$session");
		if(!$row) {
			if($database->get_driver_name() === "mysql") {
				$query = "SELECT * FROM users WHERE name = :name AND md5(concat(pass, :ip)) = :sess";
			}
			else {
				$query = "SELECT * FROM users WHERE name = :name AND md5(pass || :ip) = :sess";
			}
			$row = $database->get_row($query, array("name"=>$name, "ip"=>get_session_ip($config), "sess"=>$session));
			$database->cache->set("user-session:$name-$session", $row, 600);
		}
		return is_null($row) ? null : new User($row);
	}

	public static function by_id(int $id) {
		global $database;
		if($id === 1) {
			$cached = $database->cache->get('user-id:'.$id);
			if($cached) return new User($cached);
		}
		$row = $database->get_row("SELECT * FROM users WHERE id = :id", array("id"=>$id));
		if($id === 1) $database->cache->set('user-id:'.$id, $row, 600);
		return is_null($row) ? null : new User($row);
	}

	public static function by_name(string $name) {
		global $database;
		$row = $database->get_row($database->scoreql_to_sql("SELECT * FROM users WHERE SCORE_STRNORM(name) = SCORE_STRNORM(:name)"), array("name"=>$name));
		return is_null($row) ? null : new User($row);
	}

	public static function by_name_and_pass(string $name, string $pass) {
		$user = User::by_name($name);
		if($user) {
			if($user->passhash == md5(strtolower($name) . $pass)) {
				$user->set_password($pass);
			}
			if(password_verify($pass, $user->passhash)) {
				return $user;
			}
		}
		return null;
	}


	/* useful user object functions start here */

	public function can(string $ability): bool {
		return $this->class->can($ability);
	}


	public function is_anonymous(): bool {
		global $config;
		return ($this->id === $config->get_int('anon_id'));
	}

	public function is_logged_in(): bool {
		global $config;
		return ($this->id !== $config->get_int('anon_id'));
	}

	public function is_admin(): bool {
		return ($this->class->name === "admin");
	}

	public function set_class(string $class) {
		global $database;
		$database->Execute("UPDATE users SET class=:class WHERE id=:id", array("class"=>$class, "id"=>$this->id));
		log_info("core-user", 'Set class for '.$this->name.' to '.$class);
	}

	public function set_name(string $name) {
		global $database;
		if(User::by_name($name)) {
			throw new Exception("Desired username is already in use");
		}
		$old_name = $this->name;
		$this->name = $name;
		$database->Execute("UPDATE users SET name=:name WHERE id=:id", array("name"=>$this->name, "id"=>$this->id));
		log_info("core-user", "Changed username for {$old_name} to {$this->name}");
	}

	public function set_password(string $password) {
		global $database;
		$hash = password_hash($password, PASSWORD_BCRYPT);
		if(is_string($hash)) {
			$this->passhash = $hash;
			$database->Execute("UPDATE users SET pass=:hash WHERE id=:id", array("hash"=>$this->passhash, "id"=>$this->id));
			log_info("core-user", 'Set password for '.$this->name);
		}
		else {
			throw new SCoreException("Failed to hash password");
		}
	}

	public function set_email(string $address) {
		global $database;
		$database->Execute("UPDATE users SET email=:email WHERE id=:id", array("email"=>$address, "id"=>$this->id));
		log_info("core-user", 'Set email for '.$this->name);
	}

	/**
	 * Get a snippet of HTML which will render the user's avatar, be that
	 * a local file, a remote file, a gravatar, a something else, etc.
	 *
	 * @return String of HTML
	 */
	public function get_avatar_html(): string {
		// FIXME: configurable
		global $config;
		if($config->get_string("avatar_host") === "gravatar") {
			if(!empty($this->email)) {
				$hash = md5(strtolower($this->email));
				$s = $config->get_string("avatar_gravatar_size");
				$d = urlencode($config->get_string("avatar_gravatar_default"));
				$r = $config->get_string("avatar_gravatar_rating");
				$cb = date("Y-m-d");
				return "<img class=\"avatar gravatar\" src=\"http://www.gravatar.com/avatar/$hash.jpg?s=$s&d=$d&r=$r&cacheBreak=$cb\">";
			}
		}
		return "";
	}

	/**
	 * Get an auth token to be used in POST forms
	 *
	 * password = secret, avoid storing directly
	 * passhash = bcrypt(password), so someone who gets to the database can't get passwords
	 * sesskey  = md5(passhash . IP), so if it gets sniffed it can't be used from another IP,
	 *            and it can't be used to get the passhash to generate new sesskeys
	 * authtok  = md5(sesskey, salt), presented to the user in web forms, to make sure that
	 *            the form was generated within the session. Salted and re-hashed so that
	 *            reading a web page from the user's cache doesn't give access to the session key
	 *
	 * @return string A string containing auth token (MD5sum)
	 */
	public function get_auth_token(): string {
		global $config;
		$salt = DATABASE_DSN;
		$addr = get_session_ip($config);
		return md5(md5($this->passhash . $addr) . "salty-csrf-" . $salt);
	}

	public function get_auth_html(): string {
		$at = $this->get_auth_token();
		return '<input type="hidden" name="auth_token" value="'.$at.'">';
	}

	public function check_auth_token(): bool {
		return (isset($_POST["auth_token"]) && $_POST["auth_token"] == $this->get_auth_token());
	}
}

class MockUser extends User {
	public function __construct(string $name) {
		$row = array(
			"name" => $name,
			"id" => 1,
			"email" => "",
			"joindate" => "",
			"pass" => "",
			"class" => "admin",
		);
		parent::__construct($row);
	}
}

