<?php
class Auth_Internal extends Auth_Base {

	private $host;

	function about() {
		return array(null,
			"Authenticates against internal tt-rss database",
			"fox",
			true);
	}

	/* @var PluginHost $host */
	function init($host) {
		$this->host = $host;

		$host->add_hook($host::HOOK_AUTH_USER, $this);
	}

	function authenticate($login, $password, $service = '') {

		$otp = (int) ($_REQUEST["otp"] ?? 0);

		// don't bother with null/null logins for auth_external etc
		if ($login && get_schema_version() > 96) {

			$user_id = UserHelper::find_user_by_login($login);

			if ($user_id && UserHelper::is_otp_enabled($user_id)) {

				// only allow app passwords for service logins if OTP is enabled
				if ($service && get_schema_version() > 138) {
					return $this->check_app_password($login, $password, $service);
				}

				if ($otp) {

					/*$base32 = new \OTPHP\Base32();

					$secret = $base32->encode(mb_substr(sha1($row["salt"]), 0, 12), false);
					$secret_legacy = $base32->encode(sha1($row["salt"]));

					$totp = new \OTPHP\TOTP($secret);
					$otp_check = $totp->now();

					$totp_legacy = new \OTPHP\TOTP($secret_legacy);
					$otp_check_legacy = $totp_legacy->now();

					if ($otp !== $otp_check && $otp !== $otp_check_legacy) {
						return false;
					} */

					if (UserHelper::check_otp($user_id, $otp))
						return $user_id;
					else
						return false;

				} else {
					$return = urlencode($_REQUEST["return"]);
					?>
					<!DOCTYPE html>
					<html>
						<head>
							<title>Tiny Tiny RSS</title>
							<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
							<?php foreach (["lib/dojo/dojo.js",
								"lib/dojo/tt-rss-layer.js",
								"js/common.js",
								"js/utility.js"] as $jsfile) {
									echo javascript_tag($jsfile);
								} ?>
								<style type="text/css">
									@media (prefers-color-scheme: dark) {
										body {
											background : #303030;
										}
									}

									body.css_loading * {
										display : none;
									}
								</style>

								<script type="text/javascript">
									require({cache:{}});

									const UtilityApp = {
										init: function() {
											require(['dojo/parser', "dojo/ready", 'dijit/form/Button', 'dijit/form/Form',
													'dijit/form/TextBox','dijit/form/ValidationTextBox'],function(parser, ready){
	                						ready(function() {
													parser.parse();
													dijit.byId("otp").focus();
												});
											});
										},
									};
								</script>
							</head>
							<body class="flat ttrss_utility otp css_loading">
								<h1><?= __("Authentication") ?></h1>
								<div class="content">
									<form dojoType="dijit.form.Form" action="public.php?return=<?= $return ?>" method="post" class="otpform">

										<?php foreach (["login", "password", "bw_limit", "safe_mode", "remember_me", "profile"] as $key) {
											print \Controls\hidden_tag($key, $_POST[$key] ?? "");
										} ?>

										<?= \Controls\hidden_tag("op", "login") ?>

										<fieldset>
											<label><?= __("Please enter your one time password:") ?></label>
											<input id="otp" dojoType="dijit.form.ValidationTextBox" required="1" autocomplete="off" size="6" name="otp" value=""/>
											<?= \Controls\submit_tag(__("Continue")) ?>
										</fieldset>
									</form>
								</div>
							</body>
						</html>
					<?php
					exit;
				}
			}
		}

		// service logins: check app passwords first but allow regular password
		// as a fallback if OTP is not enabled

		if ($service && get_schema_version() > 138) {
			$user_id = $this->check_app_password($login, $password, $service);

			if ($user_id)
				return $user_id;
		}

		if ($login) {
			$try_user_id = $this->find_user_by_login($login);

			if ($try_user_id) {
				return $this->check_password($try_user_id, $password);
			}
		}

		return false;
	}

	function check_password(int $owner_uid, string $password, string $service = '') {

		if (get_schema_version() > 87) {
			$sth = $this->pdo->prepare("SELECT salt,login,otp_enabled,pwd_hash FROM ttrss_users WHERE id = ?");
		} else {
			$sth = $this->pdo->prepare("SELECT login,otp_enabled,pwd_hash FROM ttrss_users WHERE id = ?");
		}

		$sth->execute([$owner_uid]);

		if ($row = $sth->fetch()) {

			$salt = $row['salt'] ?? "";
			$login = $row['login'];
			$pwd_hash = $row['pwd_hash'];

			list ($pwd_algo, $raw_hash) = explode(":", $pwd_hash, 2);

			// check app password only if service is specified
			if ($service && get_schema_version() > 138) {
				return $this->check_app_password($login, $password, $service);
			}

			$test_hash = UserHelper::hash_password($password, $salt, $pwd_algo);

			if (hash_equals($pwd_hash, $test_hash)) {
				if ($pwd_algo != UserHelper::HASH_ALGOS[0]) {
					Logger::log(E_USER_NOTICE, "Upgrading password of user $login to " . UserHelper::HASH_ALGOS[0]);

					$new_hash = UserHelper::hash_password($password, $salt, UserHelper::HASH_ALGOS[0]);

					if ($new_hash) {
						$usth = $this->pdo->prepare("UPDATE ttrss_users SET pwd_hash = ? WHERE id = ?");
						$usth->execute([$new_hash, $owner_uid]);
					}
				}
				return $owner_uid;
			}
		}

		return false;
	}

	function change_password($owner_uid, $old_password, $new_password) {

		if ($this->check_password($owner_uid, $old_password)) {

			$new_salt = UserHelper::get_salt();
			$new_password_hash = UserHelper::hash_password($new_password, $new_salt, UserHelper::HASH_ALGOS[0]);

			$sth = $this->pdo->prepare("UPDATE ttrss_users SET
				pwd_hash = ?, salt = ?, otp_enabled = false
					WHERE id = ?");
			$sth->execute([$new_password_hash, $new_salt, $owner_uid]);

			if ($_SESSION["uid"] ?? 0 == $owner_uid)
				$_SESSION["pwd_hash"] = $new_password_hash;

			$sth = $this->pdo->prepare("SELECT email, login FROM ttrss_users WHERE id = ?");
			$sth->execute([$owner_uid]);

			if ($row = $sth->fetch()) {
				$mailer = new Mailer();

				$tpl = new Templator();

				$tpl->readTemplateFromFile("password_change_template.txt");

				$tpl->setVariable('LOGIN', $row["login"]);
				$tpl->setVariable('TTRSS_HOST', Config::get(Config::SELF_URL_PATH));

				$tpl->addBlock('message');

				$tpl->generateOutputToString($message);

				$mailer->mail(["to_name" => $row["login"],
					"to_address" => $row["email"],
					"subject" => "[tt-rss] Password change notification",
					"message" => $message]);

			}

			return __("Password has been changed.");
		} else {
			return "ERROR: ".__('Old password is incorrect.');
		}
	}

	private function check_app_password($login, $password, $service) {
		$sth = $this->pdo->prepare("SELECT p.id, p.pwd_hash, u.id AS uid
			FROM ttrss_app_passwords p, ttrss_users u
			WHERE p.owner_uid = u.id AND LOWER(u.login) = LOWER(?) AND service = ?");
		$sth->execute([$login, $service]);

		while ($row = $sth->fetch()) {
			list ($pwd_algo, $raw_hash, $salt) = explode(":", $row["pwd_hash"]);

			$test_hash = UserHelper::hash_password($password, $salt, $pwd_algo);

			if (hash_equals("$pwd_algo:$raw_hash", $test_hash)) {
				$usth = $this->pdo->prepare("UPDATE ttrss_app_passwords SET last_used = NOW() WHERE id = ?");
				$usth->execute([$row['id']]);

				if ($pwd_algo != UserHelper::HASH_ALGOS[0]) {
					// upgrade password to current algo
					Logger::log(E_USER_NOTICE, "Upgrading app password of user $login to " . UserHelper::HASH_ALGOS[0]);

					$new_hash = UserHelper::hash_password($password, $salt, UserHelper::HASH_ALGOS[0]);

					if ($new_hash) {
						$usth = $this->pdo->prepare("UPDATE ttrss_app_passwords SET pwd_hash = ? WHERE id = ?");
						$usth->execute(["$new_hash:$salt", $row['id']]);
					}
				}

				return $row['uid'];
			}
		}

		return false;
	}

	function api_version() {
		return 2;
	}

}
