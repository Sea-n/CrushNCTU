<?php
/* Only Command-line Execution Allowed */
if (!isset($argv[1]))
	exit;

require('utils.php');
require('database.php');
require_once('telegram-bot/class.php');
$db = new MyDB();
$TG = new Telegram();


switch ($argv[1]) {
case 'dump':
	$data = [];
	$posts = [];

	$tables = ['posts', 'votes', 'users', 'tg_msg'];
	foreach ($tables as $table) {
		$sql = "SELECT * FROM $table ORDER BY created_at DESC";
		$stmt = $db->pdo->prepare($sql);
		$stmt->execute();
		$data[$table] = [];
		while ($item = $stmt->fetch()) {
			if (isset($item['nctu_id']))
				$item['nctu_id'] = idToDep($item['nctu_id']) . ' ' . $item['nctu_id'];

			if ($table == 'posts')
				$posts[ $item['uid'] ] = $item;

			if ($table == 'votes') {
				$item['uid'] .= ' ' . mb_substr($posts[ $item['uid'] ]['body'], 0, 20) . '..';

				$item['voter'] = idToDep($item['voter']) . ' ' . $item['voter'];

				$item['vote'] = ($item['vote'] == '1' ? '✅ 通過' : '❌ 駁回');
			}

			$data[$table][] = $item;
		}
	}

	echo json_encode($data, JSON_PRETTY_PRINT);
	break;

case 'reject':
	$posts = $db->getSubmissions(0);
	foreach ($posts as $post) {
		/* Prevent reject demo post */
		if ($post['status'] != 3)
			continue;

		$uid = $post['uid'];
		$dt = time() - strtotime($post['created_at']);
		$vote = $post['approvals'] - $post['rejects'];

		/* Before 3 hour */
		if ($dt < 3*60*60)
			if ($vote > -20)
				continue;

		/* 3 hour - 6 hour*/
		if ($dt < 6*60*60)
			if ($vote > -10)
				continue;

		/* 6 hour - 12 hour*/
		if ($dt < 12*60*60)
			if ($vote > -5)
				continue;

		/* 12 hour - 48 hour */
		if ($dt < 48*60*60)
			if ($vote >= 0)
				continue;

		/*
		 * 48 hour - 60 hour
		 *
		 * It should be reject after 48 hour immediately,
		 * but if someone approved foreign submission and
		 * make it eligible for post during sleep time,
		 * it will be frustrating if the submission just
		 * deleted with +10 vote.
		 *
		 * The workaround is give it 12 hour buffer time
		 * if the submission have +10 vote.
		 */
		if ($dt < 60*60*60)
			if ($vote >= 10)
				continue;

		$db->deleteSubmission($uid, -2, '已駁回');

		/* Remove vote keyboard in Telegram */
		$msgs = $db->getTgMsgsByUid($uid);
		foreach ($msgs as $item) {
			$TG->deleteMsg($item['chat_id'], $item['msg_id']);
			$db->deleteTgMsg($uid, $item['chat_id']);
		}
	}

	$sql = "SELECT * FROM posts WHERE status = 0";
	$stmt = $db->pdo->prepare($sql);
	$stmt->execute();
	while ($post = $stmt->fetch()) {
		$dt = time() - strtotime($post['created_at']);

		/* Before 24 hour */
		if ($dt < 24*60*60)
			continue;

		$db->deleteSubmission($post['uid'], -13, '逾期未確認');
	}

	break;

case 'delete':
	if ($argc < 4)
		exit('Usage: delete [uid] [reason] [status]');

	$uid = $argv[2];
	$reason = $argv[3];
	$status = $argv[4] ?? -4;

	/* Remove vote keyboard in Telegram */
	$msgs = $db->getTgMsgsByUid($uid);
	foreach ($msgs as $item) {
		$TG->deleteMsg($item['chat_id'], $item['msg_id']);
		$db->deleteTgMsg($uid, $item['chat_id']);
	}

	$db->deleteSubmission($uid, $status, $reason);
	break;

default:
	echo "Unknown argument: {$argv[1]}";
}
