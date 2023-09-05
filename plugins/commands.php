<?php

# Ignore inline messages (via @)
if ($v->via_bot || $v->update['chosen_inline_result']) die;

# Define variables
if (!defined('ITelegraph_Vars')) {
	define('ITelegraph_Vars', true);
	# Encrypt/Decrypt functions
	function decrypt($string, $username, $password) {
		$key = hash('sha256', $password);
		$iv = substr(hash('sha256', $username), 0, 8);
		return openssl_decrypt(base64_decode($string), 'BF-OFB', $key, 0, $iv);
	}
	function encrypt($datas, $username, $password) {
		if (is_array($datas)) $datas = json_encode($datas);
		$key = hash('sha256', $password);
		$iv = substr(hash('sha256', $username), 0, 8);
		return str_replace('=', '', base64_encode(openssl_encrypt($datas, 'BF-OFB', $key, 0, $iv)));
	}

	# Start Telegraph class
	$itg = new Telegraph($configs);

	# Check account validation
	$user['account'] = json_decode($user['account'], true);
	if (!is_array($user['account'])) $user['account'] = [];
	$user['accounts'] = json_decode($user['accounts'], true);
	if (!is_array($user['accounts'])) $user['accounts'] = [];
	$loggedIn = false;
	if (!empty($user['account'])) {
		$loggedIn = true;
		# Save login in cache
		if (!isset($user['accounts'][$user['account']['username']])) {
			$user['accounts'][$user['account']['username']] = $user['account'];
			$db->query('UPDATE users SET accounts = ? WHERE id = ?', [json_encode($user['accounts']), $v->user_id]);
		}
		# Auto-Disconnect after 5 minutes
		/*if ($user['account']['last_action'] < (time() - (60 * 5))) {
			$db->query('UPDATE users SET account = ? WHERE id = ?', ['[]', $v->user_id]);
			$loggedIn = false;
			$disconnectedNow = true;
		}*/
	}
	if (!isset($user['settings']['link'])) $user['settings']['link'] = 'https://' . $itg->links[0];
	$itg->setLink($user['settings']['link']);
}

# Inline commands
if (isset($v->update['inline_query'])) {
	$itg = new Telegraph($configs);
	$results = [];
	if (!empty($user['account'])) {
		$sw_text = $user['account']['short_name'];
		if ($v->query) {
			if ($pages = $db->rget('ITGP-' . $user['account']['username'])) {
				$pages = json_decode($pages, 1);
			} else {
				$pages = $itg->getPageList(decrypt($user['account']['encrypted_key'], $user['account']['username'], $itg->getTempPw()), 0, 200);
				if ($pages['ok']) $db->rset('ITGP-' . $user['account']['username'], json_encode($pages), 60);
			}
			if ($pages['ok'] && !empty($pages['result']['pages'])) {
				foreach ($pages['result']['pages'] as $page) {
					if (
						$page['title'] !== '404: Not found' && 
						(strpos($page['title'], $v->query) === 0 || strpos($page['title'], $v->query) !== false) &&
						(!isset($num) || $num < 50)
					) {
						$results[] = $bot->createInlineArticle(
							$num += 1,
							$page['title'],
							$page['description'],
							$bot->createTextInput($page['url'], 'def', false)
						);
					}
				}
			}
		} else {
			if ($pages = $db->rget('ITGP-' . $user['account']['username'])) {
				$pages = json_decode($pages, 1);
			} else {
				$pages = $itg->getPageList(decrypt($user['account']['encrypted_key'], $user['account']['username'], $itg->getTempPw()), 0, 200);
				if ($pages['ok']) $db->rset('ITGP-' . $user['account']['username'], json_encode($pages), 60);
			}
			if ($pages['ok'] && !empty($pages['result']['pages'])) {
				foreach ($pages['result']['pages'] as $page) {
					if ($page['title'] !== '404: Not found' && (!isset($num) || $num < 50)) {
						$results[] = $bot->createInlineArticle(
							$num += 1,
							$page['title'],
							$page['description'],
							$bot->createTextInput($page['url'], 'def', false)
						);
					}
				}
			}
		}
	}
	$bot->answerIQ($v->id, $results, $tr->getTranslation('switchAccount'), 'switch-account');
}

# Private chat
elseif ($v->chat_type == 'private') {
	# First presentation message
	if ($user['registration'] > time() - 1) {
		$bot->sendMessage($v->chat_id, $tr->getTranslation('startAbout'));
		if ($user['status'] !== 'started') $db->setStatus($v->user_id, 'started');
	}
	
	# Cancel action
	if ($v->command == 'cancel' || strpos($v->query_data, 'cancel') === 0) {
		$db->rdel('ITGB-' . $v->user_id . '-action');
		if (isset($user['settings']['temp_username'])) {
			unset($user['settings']['temp_username']);
			$db->query('UPDATE users SET settings = ? WHERE id = ?', [json_encode($user['settings']), $v->user_id]);
		}
		if ($v->command) {
			$bot->sendMessage($v->chat_id, $tr->getTranslation('commandCancelled'));
			die;
		} else {
			if (strpos($v->query_data, 'cancel|') === 0) {
				$v->query_data = str_replace('cancel|', '', $v->query_data);
			} else {
				$t = $v->text . PHP_EOL . PHP_EOL . $tr->getTranslation('commandCancelled');
				$v->entities[] = ['type' => 'bold', 'offset' => mb_strlen($v->text, 'UTF-8'), 'length' => round(mb_strlen($t, 'UTF-8') - mb_strlen($v->text, 'UTF-8'))];
				$bot->editText($v->chat_id, $v->message_id, $t, [], $v->entities);
				$bot->answerCBQ($v->query_id);
				die;
			}
		}
	}
	
	# Actions
	if ($action = $db->rget('ITGB-' . $v->user_id . '-action')) {
		# Action commands
		if (!empty($action) && !is_null($action)) {
			# Login on ITelegraph
			if ($action == 'botLogIn') {
				if ($key = $db->rget('ITGB-login-' . $v->user_id)) {
					# Maintain the key up until register it
					$db->redis->expire('ITGB-login-' . $v->user_id, 60 * 2);
					if ($v->query_data == 'botLogIn-remove') {
						unset($user['settings']['temp_username']);
						$db->query('UPDATE users SET settings = ? WHERE id = ?', [json_encode($user['settings']), $v->user_id]);
					}
					if (!isset($user['settings']['temp_username'])) {
						# Username Input
						if (!empty($v->text) && !preg_match('/'.preg_quote('^\'£$%^&*()}{@#~?><,@|-=-_+-¬', '/').'/', $string) && strlen($v->text) < 32) {
							$if_exists = $db->query('SELECT username FROM telegraph WHERE LOWER(username) = ? LIMIT 1', [strtolower($v->text)], 1);
							if (!$if_exists['username']) {
								$t = $bot->bold($tr->getTranslation('internalLogInPhase1NotFound')) . PHP_EOL . PHP_EOL . $tr->getTranslation('internalLogInPhase1Down');
							} else {
								$user['settings']['temp_username'] = $v->text;
								$db->query('UPDATE users SET settings = ? WHERE id = ?', [json_encode($user['settings']), $v->user_id]);
								$buttons[][] = $bot->createInlineButton($tr->getTranslation('switchAccount'), 'botLogIn-remove');
								$t = $tr->getTranslation('internalLogInPhase2', [$user['settings']['temp_username']]);
							}
						} else {
							if ($v->text) {
								$t = $tr->getTranslation('internalLogInPhase1Invalid', [32]);
							} else {
								if (!isset($user['settings']['temp_username'])) {
									$t = $tr->getTranslation('internalLogInPhase1');
								} else {
									$t = $tr->getTranslation('internalLogInPhase2', [$user['settings']['temp_username']]);
									$buttons[][] = $bot->createInlineButton($tr->getTranslation('switchAccount'), 'botLogIn-remove');
								}
								$buttons[][] = $bot->createInlineButton($tr->getTranslation('cancel'), 'cancel|login');
							}
						}
					} else {
						# Password Input
						$account = $db->query('SELECT username, tkey, 2fa FROM telegraph WHERE LOWER(username) = ?', [strtolower($user['settings']['temp_username'])], 1);
						if (!$account['username']) {
							unset($user['settings']['temp_username']);
							$db->query('UPDATE users SET settings = ? WHERE id = ?', [json_encode($user['settings'], $v->user_id)]);
							$t = $bot->bold($tr->getTranslation('internalLogInPhase1NotFound')) . PHP_EOL . PHP_EOL . $tr->getTranslation('internalLogInPhase1Down');
						} elseif (!empty($v->text) && strlen($v->text) >= 8 && strlen($v->text) <= 64) {
							$bot->editConfigs('response', true);
							$m = $bot->sendMessage($v->chat_id, $bot->bold($bot->italic($tr->getTranslation('decryptingAccount'), 1)));
							$decrypted_key = decrypt($account['tkey'], $account['username'], $v->text);
							$taccount = $itg->getAccountInfo($decrypted_key, ['short_name', 'author_name', 'author_url', 'page_count']);
							if ($taccount['ok']) {
								$user['account'] = $taccount['result'];
								$user['account']['username'] = $account['username'];
								$user['account']['encrypted_key'] = encrypt($decrypted_key, $account['username'], $itg->getTempPw());
								$user['accounts'][$user['account']['username']] = $user['account'];
								unset($user['settings']['temp_username']);
								$db->query('UPDATE users SET settings = ?, account = ?, accounts = ? WHERE id = ?', [json_encode($user['settings']), json_encode($user['account']), json_encode($user['accounts']), $v->user_id]);
								$db->rdel('ITGB-login-' . $v->user_id);
								$db->rdel('ITGB-' . $v->user_id . '-action');
								$buttons[][] = $bot->createInlineButton($tr->getTranslation('myAccount'), 'start');
								$bot->editText($v->chat_id, $m['result']['message_id'], $tr->getTranslation('logInDone', [$taccount['result']['short_name'], $v->user_id]), $buttons);
								sleep(30);
								$bot->deleteMessage($v->chat_id, $v->message_id);
								die;
							} else {
								$t = $tr->getTranslation('logInWrong');
								$bot->editText($v->chat_id, $m['result']['message_id'], $t, $buttons);
								if (isset($user['settings']['temp_username'])) {
									$t = $tr->getTranslation('internalLogInPhase2', [$user['settings']['temp_username']]);
									$buttons[][] = $bot->createInlineButton($tr->getTranslation('switchAccount'), 'botLogIn-remove');
								} else {
									$t = $tr->getTranslation('internalLogInPhase1');
								}
							}
						} elseif (!empty($v->text)) {
							$t = $tr->getTranslation('internalLogInPhase2Invalid', [8, 64]);
						} else {
							$buttons[][] = $bot->createInlineButton($tr->getTranslation('switchAccount'), 'botLogIn-remove');
							$t = $tr->getTranslation('internalLogInPhase2', [$user['settings']['temp_username']]);
						}
					}
				} else {
					$db->rdel('ITGB-' . $v->user_id . '-action');
					$t = $bot->bold($tr->getTranslation('logInSessionExpired'));
					$buttons[][] = $bot->createInlineButton($tr->getTranslation('login'), 'botLogIn');
				}
				if ($v->query_data) {
					$bot->editText($v->chat_id, $v->message_id, $t, $buttons);
					$bot->answerCBQ($v->query_id, $cbtext, $show);
				} else {
					$bot->sendMessage($v->chat_id, $t, $buttons);
				}
				die;
			}
			# Register on ITelegraph
			elseif ($action == 'RegisterAccount') {
				if ($key = $db->rget('ITGB-tempkey-' . $v->user_id)) {
					# Maintain the key up until register it
					$db->redis->expire('ITGB-tempkey-' . $v->user_id, 60 * 2);
					$account = $itg->getAccountInfo($key, ['short_name', 'author_name', 'author_url', 'page_count']);
					if ($account['ok']) {
						if (isset($user['settings']['temp_username'])) {
							# Password Input
							$if_exists = $db->query('SELECT username FROM telegraph WHERE LOWER(username) = ?', [strtolower($v->text)]);
							if ($if_exists['username']) {
								unset($user['settings']['temp_username']);
								$db->query('UPDATE users SET settings = ? WHERE id = ?', [json_encode($user['settings'], $v->user_id)]);
								$t = $tr->getTranslation('registerNewAccountPhase1Exists');
							} elseif (strlen($v->text) >= 8 && strlen($v->text) <= 64) {
								$bot->editConfigs('response', true);
								$m = $bot->sendMessage($v->chat_id, $bot->bold($bot->italic($tr->getTranslation('cryptingAccount'), 1)));
								# Revoke the Token for Security reasons
								$key = $itg->revokeAccessToken($key)['result']['access_token'];
								$db->query('UPDATE telegraph SET tkey = ? WHERE username = ?', [encrypt($revoked['result']['access_token'], $account['username'], $v->text), $account['username']]);
								$encrypted_key = encrypt($key, $user['settings']['temp_username'], $v->text);
								$db->query('INSERT INTO telegraph (short_name, author_name, author_url, username, tkey) VALUES (?,?,?,?,?)', [
									$account['result']['short_name'],
									$account['result']['author_name'],
									$account['result']['author_url'],
									$user['settings']['temp_username'],
									$encrypted_key
								]);
								$user['account'] = $taccount['result'];
								$user['account']['username'] = $user['settings']['temp_username'];
								$user['account']['encrypted_key'] = encrypt($decrypted_key, $account['username'], $itg->getTempPw());
								$user['accounts'][$user['account']['username']] = $user['account'];
								unset($user['settings']['temp_username']);
								$db->query('UPDATE users SET settings = ?, account = ?, accounts = ? WHERE id = ?', [json_encode($user['settings']), json_encode($user['account']), json_encode($user['accounts']), $v->user_id]);
								$db->rdel('ITGB-tempkey-' . $v->user_id);
								$db->rdel('ITGB-' . $v->user_id . '-action');
								$buttons[][] = $bot->createInlineButton($tr->getTranslation('login'), 'botLogIn');
								$bot->editText($v->chat_id, $m['result']['message_id'], $tr->getTranslation('registrationDone'), $buttons);
								sleep(30);
								$bot->deleteMessage($v->chat_id, $v->message_id);
								die;
							} else {
								$t = $tr->getTranslation('registerNewAccountPhase2Invalid', [8, 64]);
								$delete = true;
							}
						} else {
							# Username Input
							if (!preg_match('/'.preg_quote('^\'£$%^&*()}{@#~?><,@|-=-_+-¬', '/').'/', $string) && strlen($v->text) < 32) {
								$if_exists = $db->query('SELECT username FROM telegraph WHERE LOWER(username) = ?', [strtolower($v->text)], 1);
								if ($if_exists['username']) {
									$t = $tr->getTranslation('registerNewAccountPhase1Exists');
								} else {
									$user['settings']['temp_username'] = $v->text;
									$db->query('UPDATE users SET settings = ? WHERE id = ?', [json_encode($user['settings']), $v->user_id]);
									$t = $tr->getTranslation('registerNewAccountPhase2');
								}
							} else {
								$t = $tr->getTranslation('registerNewAccountPhase1Invalid', [32]);
							}
						}
					} else {
						$t = $bot->bold($tr->getTranslation('accountNotFound'));
					}
				} else {
					$t = $bot->bold($tr->getTranslation('registerSessionExpired'));
				}
				if ($v->query_data) {
					$bot->editText($v->chat_id, $v->message_id, $t, $buttons);
					$bot->answerCBQ($v->query_id, $cbtext, $show);
				} else {
					$bot->sendMessage($v->chat_id, $t, $buttons);
					if ($delete) {
						sleep(30);
						$bot->deleteMessage($v->chat_id, $v->message_id);
					}
				}
				die;
			}
			# Edit ITelegraph Account
			elseif (strpos($action, 'editAccount-') === 0) {
				$e = explode('-', $action);
				if (in_array($e[1], ['username', 'password']) && $v->text && !$v->query_id) {
					$limits = [
						'username'	=> 32,
						'password'	=> 64
					];
					if ($limits[$e[1]] >= strlen($v->text)) {
						if ($e[1] == 'username') {
							if (!preg_match('/'.preg_quote('^\'£$%^&*()}{@#~?><,@|-=-_+-¬', '/').'/', $string)) {
								$if_exists = $db->query('SELECT username FROM telegraph WHERE LOWER(username) = ?', [strtolower($v->text)], 1);
								if ($if_exists['username']) {
									$t = $tr->getTranslation('registerNewAccountPhase1Exists');
								} else {
									if ($e[2] && !empty($v->text) && strlen($v->text) >= 8 && strlen($v->text) <= 64) {
										$itgac = $db->query('SELECT tkey FROM telegraph WHERE username = ? LIMIT 1', [$user['account']['username']], 1);
										$decrypted_key = decrypt($itgac['tkey'], $user['account']['username'], $v->text);
										$taccount = $itg->getAccountInfo($decrypted_key, ['short_name', 'author_name', 'author_url', 'page_count']);
										if ($taccount['ok']) {
											$db->rdel('ITGP-' . $user['account']['username']);
											$db->rdel('ITGB-' . $v->user_id . '-action');
											$taccount = $taccount['result'];
											$db->query('UPDATE telegraph SET short_name = ?, author_name = ?, author_url = ?, username = ?, tkey = ? WHERE username = ?', [$taccount['short_name'], $taccount['author_name'], $taccount['author_url'], $e[2], encrypt($decrypted_key, $e[2], $v->text), $user['account']['username']]);
											unset($user['accounts'][$user['account']['username']]);
											unset($user['account']);
											$user['account'] = $taccount;
											$user['account']['username'] = $e[2];
											$user['account']['encrypted_key'] = encrypt($decrypted_key, $user['account']['username'], $itg->getTempPw());
											$user['accounts'][$user['account']['username']] = $user['account'];
											$db->query('UPDATE users SET account = ?, accounts = ? WHERE id = ?', [json_encode($user['account']), json_encode($user['accounts']), $v->user_id]);
											$t = $tr->getTranslation('editAccountUsernameSuccessful', [$e[2]]);
											$buttons[][] = $bot->createInlineButton('← ' . $tr->getTranslation('cancel'), 'cancel|settingsEditAccount');
										} else {
											$t = $tr->getTranslation('logInWrong');
											$buttons[][] = $bot->createInlineButton('← ' . $tr->getTranslation('cancel'), 'cancel|settingsEditAccount');
										}
										$delete = true;
									} else {
										$t = $tr->getTranslation('confirmPassword', [$user['account']['username']]);
										$db->rset('ITGB-' . $v->user_id . '-action', 'editAccount-username-' . $v->text, (60 * 5));
										$buttons[][] = $bot->createInlineButton('← ' . $tr->getTranslation('cancel'), 'cancel|settingsEditAccount');
									}
								}
							} else {
								$t = $tr->getTranslation('registerNewAccountPhase1Invalid', [$limits[$e[1]]]);
							}
						} else {
							if (!empty($v->text) && strlen($v->text) >= 8 && strlen($v->text) <= 64) {
								if ($e[2]) {
									$itgac = $db->query('SELECT tkey, username FROM telegraph WHERE username = ? LIMIT 1', [$user['account']['username']], 1);
									$decrypted_key = decrypt($itgac['tkey'], $user['account']['username'], $v->text);
									$taccount = $itg->getAccountInfo($decrypted_key, ['short_name', 'author_name', 'author_url', 'page_count']);
									if ($taccount['ok']) {
										$db->rdel('ITGB-' . $v->user_id . '-action');
										$taccount = $taccount['result'];
										$db->query('UPDATE telegraph SET tkey = ? WHERE username = ?', [encrypt($decrypted_key, $itgac['username'], $e[2]), $itgac['username']]);
										$user['account']['encrypted_key'] = encrypt($decrypted_key, $user['account']['username'], $itg->getTempPw());
										$user['accounts'][$itgac['username']] = $user['account'];
										$db->query('UPDATE users SET account = ?, accounts = ? WHERE id = ?', [json_encode($user['account']), json_encode($user['accounts']), $v->user_id]);
										$t = $tr->getTranslation('editAccountPasswordSuccessful');
										$buttons[][] = $bot->createInlineButton('← ' . $tr->getTranslation('back'), 'cancel|settingsEditAccount');
									} else {
										$t = $tr->getTranslation('logInWrong');
										$buttons[][] = $bot->createInlineButton('← ' . $tr->getTranslation('cancel'), 'cancel|settingsEditAccount');
									}
								} else {
									$t = $tr->getTranslation('confirmPassword', [$user['account']['username']]);
									$db->rset('ITGB-' . $v->user_id . '-action', 'editAccount-password-' . $v->text, (60 * 5));
									$buttons[][] = $bot->createInlineButton('← ' . $tr->getTranslation('cancel'), 'cancel|settingsEditAccount');
								}
								$delete = true;
							} else {
								$t = $tr->getTranslation('registerNewAccountPhase2Invalid', [8, 64]);
							}
						}
					} else {
						if ($e[1] == 'username') {
							$t = $tr->getTranslation('registerNewAccountPhase1Invalid');
						} elseif ($e[1] == 'password') {
							$t = $tr->getTranslation('registerNewAccountPhase2Invalid');
						}
					}
				}
				if ($v->query_data) {
					$bot->answerCBQ($v->query_id, $cbtext, $show);
					if (isset($t) && !empty($t)) {
						$bot->editText($v->chat_id, $v->message_id, $t, $buttons);
						die;
					}
				} else {
					if (isset($t) && !empty($t)) {
						$bot->sendMessage($v->chat_id, $t, $buttons);
						if ($delete) {
							sleep(30);
							$bot->deleteMessage($v->chat_id, $v->message_id);
						}
						die;
					}
				}
			}
			# Edit Telegra.ph Account
			elseif (strpos($action, 'editProfile-') === 0) {
				$e = explode('-', $action);
				if (in_array($e[1], ['short_name', 'author_name', 'author_url']) && $v->text && !$v->query_id) {
					$limits = [
						'short_name'	=> 32,
						'author_name'	=> 128,
						'author_url'	=> 512
					];
					if ($limits[$e[1]] >= strlen($v->text)) {
						if ($e[1] == 'author_url') $v->text = str_replace('@', 'https://t.me/', $v->text); 
						if (in_array($e[1], ['author_name', 'author_url']) && $v->command == 'skip') $v->text = '';
						$user['accounts'][$user['account']['username']][$e[1]] = $user['account'][$e[1]] = $v->text;
						$r = $itg->editAccountInfo(decrypt($user['account']['encrypted_key'], $user['account']['username'], $itg->getTempPw()), $user['account']['short_name'], $user['account']['author_name'], $user['account']['author_url']);
						if ($r['ok']) {
							$bot->editConfigs('response', 1);
							$m = $bot->sendMessage($v->chat_id, '✅');
							$bot->editConfigs('response', 0);
							$v->message_id = $m['result']['message_id'];
							$v->query_data = 'settingsEditProfile';
							$db->query('UPDATE telegraph SET short_name = ?, author_name = ?, author_url = ? WHERE username = ?', [$user['account']['short_name'], $user['account']['author_name'], $user['account']['author_url'], $user['account']['username']]);
							$db->query('UPDATE users SET account = ?, accounts = ? WHERE id = ?', [json_encode($user['account']), json_encode($user['accounts']), $user['id']]);
						} else {
							$bot->sendMessage($v->chat_id, '❌ Telegraph returned an error: ' . $bot->code($r['error'], 1));
							die;
						}
					} else {
						$bot->sendMessage($v->chat_id, $tr->getTranslation('messageLimit', [$limits[$e[2]], strlen($v->text)]));
						die;
					}
				}
				$db->rdel('ITGB-' . $v->user_id . '-action');
			}
			# Edit posts contents
			elseif (strpos($action, 'editPost-') === 0) {
				$e = explode('-', $action);
				if (!in_array($e[2], ['title', 'author_name', 'author_url'])) {
				} elseif ($v->text && !$v->query_id) {
					$limits = [
						'title'			=> 256,
						'author_name'	=> 128,
						'author_url'	=> 512
					];
					if ($limits[$e[2]] >= strlen($v->text)) {
						if (!isset($e[2]) && $pages = $db->rget('ITGP-' . $user['account']['username'])) {
							$pages = json_decode($pages, 1);
						} else {
							$pages = $itg->getPageList(decrypt($user['account']['encrypted_key'], $user['account']['username'], $itg->getTempPw()), 0, 200);
							if ($pages['ok']) $db->rset('ITGP-' . $user['account']['username'], json_encode($pages), 60);
						}
						if (isset($pages['result']['pages'][$e[1]]) && $page = $pages['result']['pages'][$e[1]]) {
							if ($e[2] == 'author_url') $v->text = str_replace('@', 'https://t.me/', $v->text); 
							$page[$e[2]] = $v->text;
							$edit = $itg->editPage(decrypt($user['account']['encrypted_key'], $user['account']['username'], $itg->getTempPw()), $page['path'], $page['title'], $page['author_name'], $page['author_url'], $itg->getPage($page['path'], 1)['result']['content'], 1);
							if ($edit['ok']) {
								$bot->editConfigs('response', true);
								$m = $bot->sendMessage($v->chat_id, '✅');
								$db->rdel('ITGP-' . $user['account']['username']);
								$db->rdel('ITGB-' . $v->user_id . '-action');
								$bot->editConfigs('response', false);
								$v->message_id = $m['result']['message_id'];
								$v->query_data = 'post-' . $e[1];
							} else {
								$t = '❌ Telegraph returned an error: ' . $bot->code($edit['error'], true);
								
							}
						}
					} else {
						$t = $tr->getTranslation('messageLimit', [$limits[$e[2]], strlen($v->text)]);
					}
				}
				if ($v->query_data) {
					if (isset($t) && !empty($t)) $bot->editText($v->chat_id, $v->message_id, $t, $buttons);
					$bot->answerCBQ($v->query_id, $cbtext, $show);
				} else {
					if (isset($t) && !empty($t)) $bot->sendMessage($v->chat_id, $t, $buttons);
				}
			}
			# Revoke Token Safe Log-in
			elseif ($action == 'revokeToken') {
				# Password Input
				if (!empty($v->text) && strlen($v->text) > 8 && strlen($v->text) < 64) {
					$itgac = $db->query('SELECT tkey, username FROM telegraph WHERE username = ? LIMIT 1', [$user['account']['username']], 1);
					$decrypted_key = decrypt($itgac['tkey'], $itgac['username'], $v->text);
					$taccount = $itg->getAccountInfo($decrypted_key, ['short_name', 'author_name', 'author_url', 'page_count']);
					if ($taccount['ok']) {
						$db->rdel('ITGP-' . $user['account']['username']);
						$db->rdel('ITGB-' . $v->user_id . '-action');
						$taccount = $taccount['result'];
						$revoked = $itg->revokeAccessToken($decrypted_key);
						if ($revoked['ok']) {
							$key = $revoked['result']['access_token'];
							$db->query('UPDATE telegraph SET short_name = ?, author_name = ?, author_url = ?, tkey = ? WHERE username = ?', [$taccount['short_name'], $taccount['author_name'], $taccount['author_url'], encrypt($key, $itgac['username'], $v->text), $itgac['username']]);
							unset($user['accounts'][$user['account']['username']]);
							unset($user['account']);
							$user['account'] = $taccount;
							$user['account']['username'] = $itgac['username'];
							$user['account']['encrypted_key'] = encrypt($key, $itgac['username'], $itg->getTempPw());
							$user['accounts'][$user['account']['username']] = $user['account'];
							$db->query('UPDATE users SET account = ?, accounts = ? WHERE id = ?', [json_encode($user['account']), json_encode($user['accounts']), $v->user_id]);
							$t = $tr->getTranslation('sessionsResetSuccessfully', [$e[2]]);
							$buttons[][] = $bot->createInlineButton('← ' . $tr->getTranslation('cancel'), 'cancel|settingsEditProfile');
						} else {
							$t = $tr->getTranslation('logInWrong');
						}
					} else {
						$t = $tr->getTranslation('logInWrong');
						$buttons[][] = $bot->createInlineButton('← ' . $tr->getTranslation('cancel'), 'cancel|settingsEditProfile');
					}
				} else {
					$t = $tr->getTranslation('registerNewAccountPhase2Invalid', [8, 64]);
					$buttons[][] = $bot->createInlineButton('← ' . $tr->getTranslation('cancel'), 'cancel|settingsEditProfile');
				}
				if ($v->query_data) {
					$bot->editText($v->chat_id, $v->message_id, $t, $buttons);
					$bot->answerCBQ($v->query_id, $cbtext, $show);
				} else {
					$bot->sendMessage($v->chat_id, $t, $buttons);
				}
				die;
			}
		}
	}
		
	# Create New Telegra.ph Account
	if ($v->query_data == 'createNewAccount') { 
		$bot->editText($v->chat_id, $v->message_id, $tr->getTranslation('creatingNewAccount'));
		if ($v->user_last_name) $last = ' ' . $v->user_last_name;
		if ($v->user_username) $url = 'https://t.me/' . $v->user_username;
		$account = $itg->createAccount($v->user_first_name, $v->user_first_name . $last, $url);
		if ($account['ok']) {
			$v->query_data = 'connect-' . $account['result']['access_token'];
			$db->rset('ITGB-tempkey-' . $v->user_id, $account['result']['access_token'], (60 * 10));
		} else {
			$cbtext = 'Error while creating your new account...';
		}
	}
	
	# Add a new account
	if (strpos($v->command, 'start new') === 0 || strpos($v->query_data, 'connect-') === 0) {
		if ($v->query_data) {
			$e = explode('-', $v->query_data);
			if ($key = $db->rget('ITGB-tempkey-' . $v->user_id)) {
				# Maintain the key up until register it
				$db->redis->expire('ITGB-tempkey-' . $v->user_id, (60 * 2));
			} else {
				$key = $e[1];
				$db->rset('ITGB-tempkey-' . $v->user_id, $key, (60 * 10));
			}
		} else {
			$e = explode(' ', $v->command);
			$key = str_replace('new', '', $e[1]);
			$db->rset('ITGB-tempkey-' . $v->user_id, $key, (60 * 2));
		}
		$account = $itg->getAccountInfo($key, ['short_name', 'author_name', 'author_url', 'page_count']);
		if ($account['ok']) {
			if ($v->query_data && isset($e[1])) {
				$db->rset('ITGB-' . $v->user_id . '-action', 'RegisterAccount', (60 * 2));
				if (isset($user['settings']['temp_username'])) {
					unset($user['settings']['temp_username']);
					$db->query('UPDATE users SET settings = ? WHERE id = ?', [json_encode($user['settings']), $v->user_id]);
				}
				$t = $tr->getTranslation('registerNewAccountPhase1');
			} else {
				$t = $tr->getTranslation('addNotProtectedAccount');
				$buttons[] = [
					$bot->createInlineButton($tr->getTranslation('confirm'), 'connect-1'),
					$bot->createInlineButton($tr->getTranslation('cancel'), 'cancel')
				];
			}
		} else {
			$t = $bot->bold($tr->getTranslation('accountNotFound'));
		}
		if ($v->query_data) {
			if ($t) $bot->editText($v->chat_id, $v->message_id, $t, $buttons);
			$bot->answerCBQ($v->query_id, $cbtext, $show);
		} else {
			$bot->sendMessage($v->chat_id, $t, $buttons);
		}
		die;
	}
	# Log into ITelegraph Account
	elseif (in_array($v->query_data, ['botLogIn', 'botLogIn-remove'])) { 
		$db->rset('ITGB-' . $v->user_id . '-action', 'botLogIn', (60 * 10));
		$db->rset('ITGB-login-' . $v->user_id, true, (60 * 2));
		if (!isset($user['settings']['temp_username'])) {
			$t = $tr->getTranslation('internalLogInPhase1');
		} else {
			$t = $tr->getTranslation('internalLogInPhase2', [$user['settings']['temp_username']]);
			$buttons[][] = $bot->createInlineButton($tr->getTranslation('switchAccount'), 'botLogIn-remove');
		}
		$buttons[][] = $bot->createInlineButton($tr->getTranslation('cancel'), 'cancel|switchAccount');
	}
	# Switch Account
	elseif (strpos($v->query_data, 'switchAccount') === 0) {
		# Set Account
		if (strpos($v->query_data, 'switchAccount-') === 0) {
			$id = str_replace('switchAccount-', '', $v->query_data);
			if (isset(array_keys($user['accounts'])[$id])) {
				$user['account'] = $user['accounts'][array_keys($user['accounts'])[$id]];
				$db->query('UPDATE users SET account = ? WHERE id = ?', [json_encode($user['account']), $v->user_id]);
				$v->query_data = 'start';
				require(__FILE__);
				die;
			} else {
				unset($id);
			}
		}
		if (!empty($user['accounts'])) {
			foreach ($user['accounts'] as $username => $account) {
				$id = isset($id) ? $id + 1 : 0;
				if (isset($account['short_name'])) $buttons[][] = $bot->createInlineButton($account['short_name'], 'switchAccount-' . $id);
			}
		}
		$buttons[] = [$bot->createInlineButton($tr->getTranslation('createNewAccount'), 'createNewAccount'), $bot->createInlineButton($tr->getTranslation('login'), 'botLogIn')];
		$buttons[][] = $bot->createInlineButton('← ' . $tr->getTranslation('back'), 'settings');
		$t = $tr->getTranslation('chooseAccount');
	}
	
	# Logged-in actions
	elseif (isset($loggedIn) && $loggedIn) {
		# Home page
		if ($v->command == 'start' || $v->query_data == 'start') {
			$buttons[][] = $bot->createInlineButton($tr->getTranslation('logInAs', [$user['account']['short_name']]), 'login');
			$buttons[][] = $bot->createInlineButton($tr->getTranslation('posts'), 'myposts');
			$buttons[][] = $bot->createInlineButton($tr->getTranslation('settings'), 'settings');
			if (!isset($e[2]) && $pages = $db->rget('ITGP-' . $user['account']['username'])) {
				$pages = json_decode($pages, 1);
			} else {
				$pages = $itg->getPageList(decrypt($user['account']['encrypted_key'], $user['account']['username'], $itg->getTempPw()), 0, 200);
				if ($pages['ok']) $db->rset('ITGP-' . $user['account']['username'], json_encode($pages), 60);
			}
			if ($pages['ok']) {
				$pages = $pages['result']['pages'];
				if ($user['account']['page_count'] !== count($pages)) {
					$user['account']['page_count'] = count($pages);
					$db->query('UPDATE users SET account = ? WHERE id = ?', [json_encode($user['account']), $user['id']]);
				}
			}
			if ($user['account']['page_count'] > 0) {
				$pages = $tr->getTranslation('postCount', [$user['account']['page_count']]);
			} else {
				$pages = $tr->getTranslation('noPosts');
			}
			if (!empty($user['account']['author_url']) && !empty($user['account']['author_name'])) {
				$author = PHP_EOL . $tr->getTranslation('author') . ': ' . $bot->text_link($user['account']['author_name'], $user['account']['author_url'], 1);
			} elseif (!empty($user['account']['author_name'])) {
				$author = PHP_EOL . $tr->getTranslation('author') . ': ' . $bot->specialchars($user['account']['author_name']);
			}
			$t = $tr->getTranslation('yourAccount') . PHP_EOL . PHP_EOL . $bot->bold($user['account']['short_name'], 1) . $author . PHP_EOL . PHP_EOL . $bot->italic($pages, 1);
		} 
		# Posts list
		elseif (strpos($v->query_data, 'myposts') === 0) {
			if (strpos($v->query_data, 'myposts-') === 0) {
				$e = explode('-', $v->query_data);
				$page = round($e[1]);
			} else {
				$page = 0;
			}
			if (!isset($e[2]) && $pages = $db->rget('ITGP-' . $user['account']['username'])) {
				$pages = json_decode($pages, 1);
			} else {
				$pages = $itg->getPageList(decrypt($user['account']['encrypted_key'], $user['account']['username'], $itg->getTempPw()), 0, 200);
				if ($pages['ok']) $db->rset('ITGP-' . $user['account']['username'], json_encode($pages), 60);
			}
			if ($pages['ok']) {
				$t = $bot->bold($tr->getTranslation('postsOf') . ' ' . $user['account']['short_name'], 1);
				$pages = $pages['result']['pages'];
				if (!empty($pages)) {
					$user['account']['page_count'] = count($pages);
					$db->query('UPDATE users SET account = ? WHERE id = ?', [json_encode($user['account']), $user['id']]);
					$offset = $page * 5;
					$limit = $offset + 4;
					foreach (range($offset, $limit) as $n) {
						$tpage = $pages[$n];
						if (isset($pages[$n])) {
							$t .= PHP_EOL . PHP_EOL . round($n + 1) . ') ' . $bot->text_link($tpage['title'], $tpage['url'], 1);
							if (strlen($tpage['description']) < 256) {
								$t .= PHP_EOL . $bot->code($tpage['description'], 1);
							} else {
								$t .= PHP_EOL . $bot->code(substr($tpage['description'], 0, 253) . '...', 1);
							}
							if ($tpage['views'] === 1) {
								$t .= PHP_EOL . $bot->italic($tpage['views'] . ' ' . $tr->getTranslation('view'), 1);
							} else {
								$t .= PHP_EOL . $bot->italic($tpage['views'] . ' ' .$tr->getTranslation('views'), 1);
							}
							$buttons[0][] = $bot->createInlineButton($n + 1, 'post-' . $n);
						}
					}
					if ($page) $pagebuttons[] = $bot->createInlineButton('← ' . $tr->getTranslation('back'), 'myposts-' . ($page - 1));
					if (isset($pages[$n + 1])) $pagebuttons[] = $bot->createInlineButton($tr->getTranslation('next') . ' →', 'myposts-' . ($page + 1));
					if ($pagebuttons) $buttons[] = $pagebuttons;
				}
				$buttons[] = [
					$bot->createInlineButton($tr->getTranslation('update'), 'myposts-' . round($page) . '-1'),
					$bot->createInlineButton($tr->getTranslation('share'), '', 'switch_inline_query')
				];
				$buttons[][] = $bot->createInlineButton('← ' . $tr->getTranslation('back'), 'start');
			} else {
				$show = true;
				$cbtext = $tr->getTranslation('logInSessionExpired');
			}
			if (!empty($t)) $bot->editText($v->chat_id, $v->message_id, $t, $buttons);
			$bot->answerCBQ($v->query_id, $cbtext, $show);
		}
		# Post Management
		elseif (strpos($v->query_data, 'post-') === 0) {
			$e = explode('-', $v->query_data);
			if ($pages = $db->rget('ITGP-' . $user['account']['username'])) {
				$pages = json_decode($pages, 1);
			} else {
				$pages = $itg->getPageList(decrypt($user['account']['encrypted_key'], $user['account']['username'], $itg->getTempPw()), 0, 200);
				if ($pages['ok']) $db->rset('ITGP-' . $user['account']['username'], json_encode($pages), 60);
			}
			if ($pages['ok']) {
				$pages = $pages['result']['pages'];
				if ($page = $pages[$e[1]]) {
					# Post Author
					if ($e[2] == 'settings' && isset($e[3])) {
						$actions = [
							'title'			=> 'sendNewTitle',
							'author_name'	=> 'sendNewAuthorName',
							'author_url'	=> 'sendNewAuthorUrl'
						];
						$db->rset('ITGB-' . $v->user_id . '-action', 'editPost-' . $e[1] . '-' . $e[3], 120);
						$t = $tr->getTranslation($actions[$e[3]], [$page[$e[3]]]);
					} 
					# Export Page content
					elseif ($e[2] == 'export') {
						$filename = $page['path'] . '.json';
						file_put_contents($filename, json_encode($itg->getPage($page['path'], 1)['result'], JSON_PRETTY_PRINT));
						$bot->editConfigs('response', 1);
						$r = $bot->sendDocument($v->chat_id, $bot->createFileInput($filename, 'text/json'), '💾 ' . $tr->getTranslation('backupFile', [str_replace('T', ' ', date('c')), $page['url']]));
						unlink($filename);
						if (!$r['ok']) {
							$cbtext = 'Error on upload... Retry later!';
						}
					} 
					# Delete post
					elseif ($e[2] == 'delete') {
						if ($e[3]) {
							$content = [
								[
									'tag' => 'figure', 
									'children' => [
										[
											'tag' =>'img',
											'attrs' => [
												'src' => 'https://telegra.ph/file/bbc48a244979444c30d00.jpg'
											]
										]
									]
								]
							];
							$result = $itg->editPage(decrypt($user['account']['encrypted_key'], $user['account']['username'], $itg->getTempPw()), $page['path'], '404: Not found', 'ITelegraph', 'https://t.me/' . $configs['bots'][$bot->id], $content, true);
							$db->rdel('ITGP-' . $user['account']['username']);
							$buttons[][] = $bot->createInlineButton('← ' . $tr->getTranslation('back'), 'myposts-' . substr(($e[1] / 5), 0, 1));
							$t = $tr->getTranslation('postDeleted', [$page['url']]);
						} else {
							$buttons[] = [
								$bot->createInlineButton($tr->getTranslation('yesSure'), 'post-' . $e[1] . '-delete-yes'),
								$bot->createInlineButton($tr->getTranslation('no'), 'post-' . $e[1])
							];
							$t = $tr->getTranslation('deletePostSure', [$page['url']]);
						}
					}
					# Post management
					else {
						$t = '';
						if (isset($page['image_url']) && !empty($page['image_url'])) $t .= $bot->text_link('&#8203;', $page['image_url']);
						$t .= $bot->bold($page['title']);
						if (isset($page['author_url']) && !empty($page['author_url'])) {
							$t .= PHP_EOL . $bot->bold($tr->getTranslation('author') . ': ') . $bot->text_link($page['author_name'], $page['author_url'], 1);
						} elseif ($page['author_name']) {
							$t .= PHP_EOL . $bot->bold($tr->getTranslation('author') . ': ') . $bot->italic($page['author_name'], 1);
						}
						if ($page['views'] === 1) {
							$t .= PHP_EOL . $bot->italic($page['views'] . ' ' . $tr->getTranslation('view'), 1);
						} else {
							$t .= PHP_EOL . $bot->italic($page['views'] . ' ' .$tr->getTranslation('views'), 1);
						}
						if ($page['can_edit']) {
							$buttons[] = [
								$bot->createInlineButton($tr->getTranslation('editPostTitle'), 'post-' . $e[1] . '-settings-title'),
								$bot->createInlineButton($tr->getTranslation('editPostContent'), 'login')
							];
							$buttons[] = [
								$bot->createInlineButton($tr->getTranslation('editPostAuthorName'), 'post-' . $e[1] . '-settings-author_name'),
								$bot->createInlineButton($tr->getTranslation('editPostAuthorUrl'), 'post-' . $e[1] . '-settings-author_url')
							];
							$buttons[] = [
								$bot->createInlineButton($tr->getTranslation('deletePost'), 'post-' . $e[1] . '-delete'),
								$bot->createInlineButton($tr->getTranslation('exportFile'), 'post-' . $e[1] . '-export')
							];
						}
						$buttons[][] = $bot->createInlineButton('← ' . $tr->getTranslation('back'), 'myposts-' . substr(($e[1] / 5), 0, 1));
					}
				} else {
					$show = true;
					$cbtext = $tr->getTranslation('postNotFound');
				}
			} else {
				$show = true;
				$cbtext = $bot->bold($tr->getTranslation('logInSessionExpired'));
			}
			if (!empty($t)) $bot->editText($v->chat_id, $v->message_id, $t, $buttons, 'def', 0);
			$bot->answerCBQ($v->query_id, $cbtext, $show);
		} 
		# Settings page
		elseif ($v->command == 'settings' || strpos($v->query_data, 'settings') === 0)  {
			if ($user['account']['page_count']) {
				$pages = $tr->getTranslation('postCount', [$user['account']['page_count']]);
			} else {
				$pages = $tr->getTranslation('noPosts');
			}
			if (!empty($user['account']['author_url']) && !empty($user['account']['author_name'])) {
				$author = PHP_EOL . $tr->getTranslation('author') . ': ' . $bot->text_link($user['account']['author_name'], $user['account']['author_url'], 1);
			} elseif (!empty($user['account']['author_name'])) {
				$author = PHP_EOL . $tr->getTranslation('author') . ': ' . $bot->specialchars($user['account']['author_name']);
			}
			if ($v->query_data == 'settingsEditAccount') {
				$buttons[][] = $bot->createInlineButton($tr->getTranslation('editUsernameButton'), 'editAccount:username');
				$buttons[][] = $bot->createInlineButton($tr->getTranslation('editPasswordButton'), 'editAccount:password');
				$buttons[][] = $bot->createInlineButton('[🚫] ' . $tr->getTranslation('manageSessionsButton'), 'manageSessions');
				$buttons[][] = $bot->createInlineButton('← ' . $tr->getTranslation('back'), 'settings');
				$t = $tr->getTranslation('editITelegraphAccount');
			} elseif ($v->query_data == 'settingsEditProfile') {
				$buttons[][] = $bot->createInlineButton($tr->getTranslation('editShortNameButton'), 'editProfile:short_name');
				$buttons[][] = $bot->createInlineButton($tr->getTranslation('editAuthorNameButton'), 'editProfile:author_name');
				$buttons[][] = $bot->createInlineButton($tr->getTranslation('editAuthorUrlButton'), 'editProfile:author_url');
				$buttons[][] = $bot->createInlineButton($tr->getTranslation('resetSessionsButton'), 'resetAllSessions');
				$buttons[][] = $bot->createInlineButton('← ' . $tr->getTranslation('back'), 'settings');
				$t = $tr->getTranslation('editTelegraphProfile');
			} else {
				$buttons[][] = $bot->createInlineButton($tr->getTranslation('editAccount'), 'settingsEditAccount');
				$buttons[][] = $bot->createInlineButton($tr->getTranslation('editProfile'), 'settingsEditProfile');
				$buttons[][] = $bot->createInlineButton($tr->getTranslation('removeAccountButton'), 'botLogOut');
				$buttons[][] = $bot->createInlineButton($tr->getTranslation('switchLink'), 'switchLink');
				$buttons[][] = $bot->createInlineButton($tr->getTranslation('switchAccount'), 'switchAccount');
				$buttons[][] = $bot->createInlineButton($tr->getTranslation('switchLanguage'), 'switchLang');
				$buttons[][] = $bot->createInlineButton('← ' . $tr->getTranslation('back'), 'start');
				$t = $tr->getTranslation('yourAccount') . PHP_EOL . PHP_EOL . $bot->bold($user['account']['short_name'], 1) . $author . PHP_EOL . PHP_EOL . $bot->italic($pages, 1);
			}
		}
		# Edit ITelegraph Account
		elseif (strpos($v->query_data, 'editAccount:') === 0) {
			$edit = str_replace('editAccount:', '', $v->query_data);
			# Edit Account Username
			if ($edit == 'username') {
				$t = $tr->getTranslation('registerNewAccountPhase1');
				$buttons[][] = $bot->createInlineButton('← ' . $tr->getTranslation('cancel'), 'cancel|settingsEditAccount');
				$db->rset('ITGB-' . $v->user_id . '-action', 'editAccount-username', (60 * 5));
			}
			# Edit Account Password
			elseif ($edit == 'password') {
				$t = $tr->getTranslation('registerNewAccountPhase2');
				$buttons[][] = $bot->createInlineButton('← ' . $tr->getTranslation('cancel'), 'cancel|settingsEditAccount');
				$db->rset('ITGB-' . $v->user_id . '-action', 'editAccount-password', (60 * 5));
			}
			# Show Account Sessions
			elseif ($edit == 'sessions') {
				$show = true; $cbtext = $tr->getTranslation('inComing');
			}
		}
		# Edit Telegraph Profile
		elseif (strpos($v->query_data, 'editProfile:') === 0) {
			$edit = explode(':', $v->query_data);
			$actions = [
				'short_name'	=> 'ShortName',
				'author_name'	=> 'AuthorName',
				'author_url'	=> 'AuthorUrl'
			];
			$db->rset('ITGB-' . $v->user_id . '-action', 'editProfile-' . $edit[1], (60 * 10));
			$buttons[][] = $bot->createInlineButton('← ' . $tr->getTranslation('cancel'), 'cancel|settingsEditProfile');
			$t = $tr->getTranslation('current' . $actions[$edit[1]]) . ': ' . $bot->bold($user['account'][$edit[1]], 1) . PHP_EOL . PHP_EOL . $bot->italic($tr->getTranslation('edit' . $actions[$edit[1]]));
		}
		# Log into Telegraph Account on Brower
		elseif ($v->query_data == 'login') {
			$auth = $itg->getAccountInfo(decrypt($user['account']['encrypted_key'], $user['account']['username'], $itg->getTempPw()), ['auth_url']);
			if ($auth['ok']) {
				$bot->answerCBQ($v->query_id, false, false, $auth['result']['auth_url']);
			} else {
				$bot->answerCBQ($v->query_id, $tr->getTranslation('logInSessionExpired'), true);
			}
			$v->query_data = 'start';
			require(__FILE__);
			die;
		}
		# Manage ITelegraph Sessions
		elseif ($v->query_data == 'manageSessions') {
			$show = true; $cbtext = $tr->getTranslation('inComing');
		}
		# Reset All Sessions
		elseif ($v->query_data == 'resetAllSessions') {
			$buttons[] = [
				$bot->createInlineButton($tr->getTranslation('confirmResetSessions'), 'revokeToken'),
				$bot->createInlineButton($tr->getTranslation('no'), 'settings-1')
			];
			$t = $tr->getTranslation('resetSessions', [$bot->bold($user['account']['short_name'], 1)]);
		}
		# Revoke Token
		elseif ($v->query_data == 'revokeToken') {
			$db->rset('ITGB-' . $v->user_id . '-action', 'revokeToken', (60 * 2));
			$buttons[][] = $bot->createInlineButton('← ' . $tr->getTranslation('back'), 'cancel|settings');
			$t = $tr->getTranslation('confirmPassword', [$user['account']['username']]);
		}
		# Add Account
		elseif ($v->query_data == 'botLogIn') {
			
		}
		# Remove Account
		elseif ($v->query_data == 'botLogOut') {
			$buttons[] = [
				$bot->createInlineButton($tr->getTranslation('confirmRemoveAccount'), 'botLogOutSure'),
				$bot->createInlineButton($tr->getTranslation('no'), 'settings')
			];
			$t = $tr->getTranslation('disconnectAccount', [$bot->bold($user['account']['username'], 1)]);
		}
		# Log Out from ITelegraph
		elseif ($v->query_data == 'botLogOutSure') {
			$bot->answerCBQ($v->query_id);
			unset($user['accounts'][$user['account']['username']]);
			$user['account'] = [];
			$loggedIn = false;
			$db->query('UPDATE users SET account = ?, accounts = ? WHERE id = ?', [json_encode($user['account']), json_encode($user['accounts']), $user['id']]);
			$v->query_data = 'start';
			require(__FILE__);
			die;
		}
		# Share Account
		elseif ($v->command == 'share') { 
			$url = 'https://t.me/share/url?' . http_build_query([
				'text'	=> $tr->getTranslation('accountInvite', [$user['account']['short_name']]),
				'url'	=> 'https://t.me/' . $bot->username . '?start=invite' . $user['account']['username']
			]);
			$buttons[][] = $bot->createInlineButton($tr->getTranslation('share'), $url, 'url');
			$t = $tr->getTranslation('shareAccount', [$user['account']['username']]);
		}
		# Switch Language
		elseif (strpos($v->query_data, 'switchLang') === 0) {
			$langnames = [
				'de' => '🇩🇪 Deutsch',
				'en' => '🇬🇧 English',
				'es' => '🇪🇸 Español',
				'it' => '🇮🇹 Italiano',
				'fa' => '🇮🇷 فارسی',
				'fr' => '🇫🇷 Français',
				'pt' => '🇵🇹 Português',
				'uk' => '🇺🇦 Yкраїнська',
				'zh-TW' => '🇨🇳 简体中文'
			];
			if (strpos($v->query_data, 'switchLang-') === 0) {
				$select = str_replace('switchLang-', '', $v->query_data);
				if (in_array($select, array_keys($langnames))) {
					$tr->setLanguage($user['lang'] = $select);
					$db->query('UPDATE users SET lang = ? WHERE id = ?', [$user['lang'], $user['id']]);
				}
			}
			$langnames[$user['lang']] .= ' ✅';
			$t = $tr->getTranslation('setLanguage');
			$formenu = 2;
			$mcount = 0;
			foreach ($langnames as $lang_code => $name) {
				if (isset($buttons[$mcount]) && count($buttons[$mcount]) >= $formenu) $mcount += 1;
				$buttons[$mcount][] = $bot->createInlineButton($name, 'switchLang-' . $lang_code);
			}
			$buttons[][] = $bot->createInlineButton('← ' . $tr->getTranslation('back'), 'settings');
		} 
		
		# Switch Link
		elseif (strpos($v->query_data, 'switchLink') === 0) {
			# Set Link
			if (strpos($v->query_data, 'switchLink-') === 0) {
				$link = str_replace('switchLink-', '', $v->query_data);
				if (in_array($link, $itg->links)) {
					$user['settings']['link'] = 'https://' . $link;
					$db->query('UPDATE users SET settings = ? WHERE id = ?', [json_encode($user['settings']), $v->user_id]);
				} else {
					unset($link);
				}
			}
			if (isset($itg->links) && !empty($itg->links)) {
				foreach ($itg->links as $link) {
					if ($link == str_replace('https://', '', $user['settings']['link'])) $link .= ' ✅';
					$buttons[][] = $bot->createInlineButton($link, 'switchLink-' . $link);
				}
			}
			$buttons[][] = $bot->createInlineButton('← ' . $tr->getTranslation('back'), 'settings');
			$t = $tr->getTranslation('chooseLink');
		}
		# Help / About bot
		elseif (in_array($v->command, ['help', 'about'])) {
			$t = $tr->getTranslation('aboutBot');
		}
		# Unknown callback
		elseif ($v->query_data) {
			$cbtext = $tr->getTranslation('unknownCommand');
		}
		# Unknown command
		elseif ($v->command) {
			$t = $tr->getTranslation('unknownCommand');
		}
		# Photo Uploader
		elseif ($v->photo) {
			$bot->editConfigs('telegram_bot_api', 'https://api.telegram.org');
			$photo = $bot->getFile(end($v->photo)['photo_id']);
			if ($photo['ok']) {
				$r = $itg->upload('https://api.telegram.org/file/bot' . $bot->token . '/' . $photo['result']['file_path']);
				if (isset($r[0]['src'])) {
					$t = $itg->link . $r[0]['src'];
				} else {
					$t = 'There is an error...';
				}
			} else {
				$t = 'There is an error...';
			}
		}
		# Post stats
		elseif ($v->text) {
			$t = $tr->getTranslation('statsAbout');
		}
	} 
	# No Logged-in actions
	else {
		# Disconnected Account
		if ($disconnectedNow) {
			$buttons[][] = $bot->createInlineButton($tr->getTranslation('reconnect'), 'botLogIn');
			$t = $tr->getTranslation('accountDisconnected');
		}
		# Choose Account
		else {
			if (!empty($user['accounts'])) {
				foreach ($user['accounts'] as $username => $account) {
					$id = isset($id) ? $id + 1 : 0;
					if (isset($account['short_name'])) $buttons[][] = $bot->createInlineButton($account['short_name'], 'switchAccount-' . $id);
				}
			}
			$buttons[][] = $bot->createInlineButton($tr->getTranslation('login'), 'botLogIn');
			$buttons[][] = $bot->createInlineButton($tr->getTranslation('createNewAccount'), 'createNewAccount');
			$t = $tr->getTranslation('chooseAccount');
		}
	}
	
	if ($v->query_data) {
		$bot->editText($v->chat_id, $v->message_id, $t, $buttons);
		$bot->answerCBQ($v->query_id, $cbtext, $show);
	} else {
		$bot->sendMessage($v->chat_id, $t, $buttons);
	}
}

# Auto-Leave other type of chats
elseif (in_array($v->chat_type, ['group', 'supergroup', 'broadcast', 'channel'])) {
	$bot->leave($v->chat_id);
	die;
}
?>
