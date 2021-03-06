<?php
if (!isset($argv))
	exit('Please run from command line.');

require_once('utils.php');
require_once('database.php');
require_once('config.php');
require_once('telegram-bot/class.php');
$db = new MyDB();
$TG = new Telegram();

$cmd = $argv[1] ?? '';
if ($cmd == 'update') {
	if (!isset($argv[2]))
		exit('No ID.');

	$id = $argv[2];
	$post = $db->getPostById($id);

	update_telegram($post);
	exit;
}


/* Check unfinished post */
$posts = $db->getPosts(1);
if (isset($posts[0]) && $posts[0]['status'] == 4)
	$post = $posts[0];

/* Get all pending submissions, oldest first */
if (!isset($post)) {
	$submissions = $db->getSubmissions(0, false);

	foreach ($submissions as $item) {
		if (checkEligible($item)) {
			$post = $db->setPostId($item['uid']);
			break;
		}
	}
}

if (!isset($post))
	exit;


/* Prepare post content */
assert(isset($post['id']));
$id = $post['id'];
$uid = $post['uid'];
$body = $post['body'];

/* img cannot be URL, Twitter required local file upload */
$img = $post['has_img'] ? $uid : '';

$time = strtotime($post['created_at']);
$time = date("Y 年 m 月 d 日 H:i", $time);
$link = "https://$DOMAIN/post/$id";

/* Send post to every SNS */
$sns = [
	'Telegram' => 'telegram',
	'Facebook' => 'facebook',
];
foreach ($sns as $name => $key) {
	try {
		$func = "send_$key";
		if (isset($post["{$key}_id"]) && ($post["{$key}_id"] > 0 || strlen($post["{$key}_id"]) > 1))
			continue;

		$pid = $func($id, $body, $img);

		if ($pid <= 0) { // Retry limit exceed
			$dt = time() - strtotime($post['posted_at']);
			if ($dt > 3*5*60) // Total 3 times
				$pid = 1;
		}

		if ($pid > 0)
			$db->updatePostSns($id, $key, $pid);
		$post["{$key}_id"] = $pid;
	} catch (Exception $e) {
		echo "Send $name Error " . $e->getCode() . ': ' .$e->getMessage() . "\n";
		echo $e->lastResponse . "\n\n";
	}
}

/* Update with link to other SNS */
$sns = [
	'Telegram' => 'telegram',
];
foreach ($sns as $name => $key) {
	try {
		$func = "update_$key";
		if (!isset($post["{$key}_id"]) || $post["{$key}_id"] < 0)
			continue;  // not posted, could be be edit

		$func($post);
	} catch (Exception $e) {
		echo "Edit $name Error " . $e->getCode() . ': ' .$e->getMessage() . "\n";
		echo $e->lastResponse . "\n\n";
	}
}

/* Remove vote keyboard in Telegram */
$msgs = $db->getTgMsgsByUid($uid);
foreach ($msgs as $item) {
	$TG->deleteMsg($item['chat_id'], $item['msg_id']);
	$db->deleteTgMsg($uid, $item['chat_id']);
}


function checkEligible(array $post): bool {
	/* Prevent publish demo post */
	if ($post['status'] != 3)
		return false;

	$dt = time() - strtotime($post['created_at']);
	$vote = $post['approvals'] - $post['rejects'];

	/* Rule for Logged-in users */
	if (!empty($post['author_id'])) {
		if ($dt < 4*60)
			return false;
		if ($vote < 0)
			return false;

		return true;
	}

	/* Rule for NCTU IP address */
	if ($post['author_name'] == '匿名, 交大') {
		/* Night mode: 02:00 - 07:59 */
		if (2 <= idate('H') && idate('H') <= 7) {
			if ($vote < 3)
				return false;
		}

		/* Less than 10 min */
		if ($dt < 9*60)
			return false;

		/* 10 min - 1 hour */
		if ($dt < 59*60 && $vote < 2)
			return false;

		/* More than 1 hour */
		if ($vote < 0)
			return false;

		return true;
	}

	/* Rule for Taiwan IP address */
	if (strpos($post['author_name'], '境外') === false) {
		/* If no reject & more than 10 min */
		if ($post['rejects'] == 0)
			if ($dt > 9*60 && $vote >= 5)
				return true;

		/* Less than 20 min */
		if ($dt < 19*60)
			return false;

		/* 20 min - 1 hour */
		 if ($dt < 59*60 && $vote < 7)
			 return false;

		 /* 1 hour - 3 hour */
		 if ($dt < 179*60 && $vote < 5)
			 return false;

		 /* More than 3 hour */
		 if ($vote < 3)
			 return false;

		 return true;
	}

	/* Rule for Foreign IP address */
	if (true) {
		return false;
	}
}

function send_telegram(int $id, string $body, string $img = ''): int {
	global $TG, $link;

	/* Check latest line */
	$lines = explode("\n", $body);
	$end = end($lines);
	$is_url = filter_var($end, FILTER_VALIDATE_URL);
	if (empty($img) && $is_url)
		$msg = "<a href='$end'>\x20\x0c</a>";
	else
		$msg = "";

	$msg .= "<a href='$link'>#告白交大$id</a>\n\n" . enHTML($body);

	/* Send to @CrushNCTU */
	if (empty($img))
		$result = $TG->sendMsg([
			'chat_id' => '@CrushNCTU',
			'text' => $msg,
			'parse_mode' => 'HTML',
			'disable_web_page_preview' => !$is_url
		]);
	else
		$result = $TG->sendPhoto([
			'chat_id' => '@CrushNCTU',
			'photo' => 'https://' . DOMAIN . "/img/{$img}.jpg",
			'caption' => $msg,
			'parse_mode' => 'HTML',
		]);

	$tg_id = $result['result']['message_id'];

	return $tg_id;
}

function send_twitter(int $id, string $body, string $img = ''): int {
	global $link;
	$msg = "#靠交$id\n\n$body";
	if (strlen($msg) > 250)
		$msg = mb_substr($msg, 0, 120) . '...';
	$msg .= "\n\n✅ $link .";

	if (!empty($img)) {
		$nonce     = md5(time());
		$timestamp = time();

		$URL = 'https://upload.twitter.com/1.1/media/upload.json?media_category=tweet_image';

		$oauth = new OAuth(TWITTER_CONSUMER_KEY, TWITTER_CONSUMER_SECRET, OAUTH_SIG_METHOD_HMACSHA1);
		$oauth->enableDebug();
		$oauth->setToken(TWITTER_TOKEN, TWITTER_TOKEN_SECRET);
		$oauth->setNonce($nonce);
		$oauth->setTimestamp($timestamp);
		$signature = $oauth->generateSignature('POST', $URL);

		$auth = [
			'oauth_consumer_key' => TWITTER_CONSUMER_KEY,
			'oauth_nonce' => $nonce,
			'oauth_signature' => $signature,
			'oauth_signature_method' => 'HMAC-SHA1',
			'oauth_timestamp' => $timestamp,
			'oauth_token' => TWITTER_TOKEN
		];

		$authStr = 'OAuth ';
		foreach ($auth as $key => $val)
			$authStr .= $key . '="' . urlencode($val) . '", ';
		$authStr .= 'oauth_version="1.0"';

		$file = ['media' => curl_file_create(__DIR__ . "/img/$img.jpg")];

		$curl = curl_init();
		curl_setopt_array($curl, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_URL => $URL,
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => $file,
			CURLOPT_HTTPHEADER => [
				"Authorization: $authStr"
			]
		]);
		$result = curl_exec($curl);
		curl_close($curl);
		$result = json_decode($result, true);
		if (isset($result['media_id_string']))
			$img_id = $result['media_id_string'];
		else
			echo "Twitter upload error: " . json_encode($result) . "\n";
	}

	$nonce     = md5(time());
	$timestamp = time();

	$query = ['status' => $msg];
	if (!empty($img_id))
		$query['media_ids'] = $img_id;
	$URL = 'https://api.twitter.com/1.1/statuses/update.json?' . http_build_query($query);

	$oauth = new OAuth(TWITTER_CONSUMER_KEY, TWITTER_CONSUMER_SECRET, OAUTH_SIG_METHOD_HMACSHA1);
	$oauth->enableDebug();
	$oauth->setToken(TWITTER_TOKEN, TWITTER_TOKEN_SECRET);
	$oauth->setNonce($nonce);
	$oauth->setTimestamp($timestamp);
	$signature = $oauth->generateSignature('POST', $URL);

	$auth = [
		'oauth_consumer_key' => TWITTER_CONSUMER_KEY,
		'oauth_nonce' => $nonce,
		'oauth_signature' => $signature,
		'oauth_signature_method' => 'HMAC-SHA1',
		'oauth_timestamp' => $timestamp,
		'oauth_token' => TWITTER_TOKEN
	];

	$authStr = 'OAuth ';
	foreach ($auth as $key => $val)
		$authStr .= $key . '="' . urlencode($val) . '", ';
	$authStr .= 'oauth_version="1.0"';

	$curl = curl_init();
	curl_setopt_array($curl, [
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_URL => $URL,
		CURLOPT_POST => true,
		CURLOPT_HTTPHEADER => [
			"Authorization: $authStr"
		]
	]);
	$result = curl_exec($curl);
	curl_close($curl);

	$result = json_decode($result, true);
	if (!isset($result['id_str'])) {
		echo "Twitter error: ";
		var_dump($result);

		if ($result['errors']['message'] == "We can't complete this request because this link has been identified by Twitter or our partners as being potentially harmful. Visit our Help Center to learn more.")
			return 1;

		return 0;
	}

	return $result['id_str'];
}

function send_plurk(int $id, string $body, string $img = ''): int {
	global $link;

	$msg = empty($img) ? '' : ('https://' . DOMAIN . "/img/$img.jpg\n");
	$msg .= "#靠交$id\n$body";

	if (mb_strlen($msg) > 290)
		$msg = mb_substr($msg, 0, 290) . '...';

	$msg .= "\n\n✅ $link ($link)";

	$nonce     = md5(time());
	$timestamp = time();

	/* Add Plurk */
	$URL = 'https://www.plurk.com/APP/Timeline/plurkAdd?' . http_build_query([
		'content' => $msg,
		'qualifier' => 'says',
		'lang' => 'tr_ch',
	]);

	$oauth = new OAuth(PLURK_CONSUMER_KEY, PLURK_CONSUMER_SECRET, OAUTH_SIG_METHOD_HMACSHA1);
	$oauth->enableDebug();
	$oauth->setToken(PLURK_TOKEN, PLURK_TOKEN_SECRET);
	$oauth->setNonce($nonce);
	$oauth->setTimestamp($timestamp);
	$signature = $oauth->generateSignature('POST', $URL);

	try {
		$oauth->fetch($URL);
		$result = $oauth->getLastResponse();
		$result = json_decode($result, true);
		return $result['plurk_id'];
	} catch (Exception $e) {
		echo "Plurk Message: $msg\n\n";
		echo 'Error ' . $e->getCode() . ': ' .$e->getMessage() . "\n";
		echo $e->lastResponse . "\n";
		return 0;
	}
}

function send_facebook(int $id, string $body, string $img = ''): int {
	global $link, $time;
	$msg = "#告白交大$id\n\n";
	$msg .= "$body\n\n";
	$msg .= "投稿時間：$time\n";
	$msg .= "✅ $link";

	$URL = 'https://graph.facebook.com/v6.0/' . FB_PAGES_ID . (empty($img) ? '/feed' : '/photos');
   
	$data = ['access_token' => FB_ACCESS_TOKEN];
	if (empty($img)) {
		$data['message'] = $msg;

		$lines = explode("\n", $body);
		$end = end($lines);
		if (filter_var($end, FILTER_VALIDATE_URL) && strpos($end, 'facebook') === false)
			$data['link'] = $end;
	} else {
		$data['url'] = 'https://' . DOMAIN . "/img/$img.jpg";
		$data['caption'] = $msg;
	}

	$curl = curl_init();
	curl_setopt_array($curl, [
		CURLOPT_URL => $URL,
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_POSTFIELDS => $data
	]);

	$result = curl_exec($curl);
	curl_close($curl);
	$result = json_decode($result, true);

	$fb_id = $result['post_id'] ?? $result['id'] ?? '0_0';
	$post_id = (int) explode('_', $fb_id)[1];

	if ($post_id == 0) {
		echo "Facebook result error:";
		var_dump($result);
	}

	return $post_id;
}

function update_telegram(array $post) {
	global $TG;

	$plurk = base_convert($post['plurk_id'], 10, 36);
	$TG->editMarkup([
		'chat_id' => '@CrushNCTU',
		'message_id' => $post['telegram_id'],
		'reply_markup' => [
			'inline_keyboard' => [
				[
					[
						'text' => 'Facebook',
						'url' => "https://www.facebook.com/CrushNCTU/posts/{$post['facebook_id']}"
					],
				]
			]
		]
	]);
}
