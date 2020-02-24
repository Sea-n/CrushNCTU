<?php
session_start();
require_once('utils.php');
require_once('database.php');
$db = new MyDB();

if (isset($_GET['id'])) {
	if (!($post = $db->getPostById($_GET['id']))) {
		http_response_code(404);
		exit('Post not found. 文章不存在');
	}
	$posts = [$post];
} else
	$posts = $db->getPosts(50);

?>
<!DOCTYPE html>
<html lang="zh-TW">
	<head>
<?php
if (isset($post)) {
	$hashtag = "#靠交{$post['id']}";

	$DESC = $post['body'];
	$TITLE = "$hashtag $DESC";

	if (mb_strlen($TITLE) > 40)
		$TITLE = mb_substr($TITLE, 0, 40) . '...';

	if (mb_strlen($DESC) > 150)
		$DESC = mb_substr($DESC, 0, 150) . '...';

	if ($post['img'])
		$IMG = "https://x.nctu.app/img/{$post['img']}.jpg";
} else {
	$TITLE = '文章列表';
	$IMG = 'https://x.nctu.app/assets/img/logo.png';
}
include('includes/head.php');
?>
		<script src="/assets/js/review.js"></script>
	</head>
	<body>
<?php include('includes/nav.php'); ?>
		<header class="ts fluid vertically padded heading slate">
			<div class="ts narrow container">
				<h1 class="ts header">文章列表</h1>
				<div class="description">靠北交大 2.0</div>
			</div>
		</header>
		<div class="ts container" name="main">
<?php
foreach ($posts as $post) {
	$id = $post['id'];
	$img = "/img/{$post['img']}.jpg";
	$body = toHTML($post['body']);
	$time = humanTime($post['submitted_at']);

	unset($author);
	if (!empty($post['author_id'])) {
		$author = $db->getUserByNctu($post['author_id']);
		$author_name = toHTML($author['name']);
	} else
		$author_name = toHTML($post['author_name']);

if (isset($post['deleted_at'])) {
?>
			<div class="ts negative message">
				<div class="header">此文已刪除</div>
				<p>刪除原因：<?= $post['delete_note'] ?? '(無)' ?></p>
			</div>
<?php } ?>
			<div class="ts card" id="post-<?= $id ?>" style="margin-bottom: 42px;">
<?php if (!empty($post['img'])) { ?>
				<div class="image">
					<img class="post-image" src="<?= $img ?>" />
				</div>
<?php } ?>
				<div class="content">
<?php if (isset($_GET['id'])) { ?>
					<div class="header">#靠交<?= $id ?></div>
<?php } else { ?>
					<div class="header"><a href="?id=<?= $id ?>">#靠交<?= $id ?></a></div>
<?php } ?>
					<p><?= $body ?></p>
				</div>
				<div class="extra content">
<?php if (isset($USER) && empty($post['author_id'])) { ?>
					<p>發文者 IP 位址：<?= ip_mask($post['ip']) ?></p>
<?php }
if (isset($_GET['id'])) {
$plurk = base_convert($post['plurk_id'], 10, 36);

$data = $db->getVotersBySubmission($post['uid']);
$votes = [1=>0, -1=>0];
foreach ($data as $item)
	++$votes[ $item['vote'] ];
?>
					<p><span><i class="telegram icon"></i> Telegram: <a target="_blank" href="https://t.me/s/xNCTU/<?= $post['telegram_id'] ?>">@xNCTU/<?= $post['telegram_id'] ?></a></span><br>
					<span><i class="facebook icon"></i> Facebook: <a target="_blank" href="https://www.facebook.com/xNCTU/posts/<?= $post['facebook_id'] ?>">@xNCTU/<?= $post['facebook_id'] ?></a></span><br>
					<span><i class="talk icon"></i> Plurk: <a target="_blank" href="https://www.plurk.com/p/<?= $plurk ?>">@xNCTU/<?= $plurk ?></a></span><br>
					<span><i class="twitter icon"></i> Twitter: <a target="_blank" href="https://twitter.com/x_NCTU/status/<?= $post['twitter_id'] ?>">@x_NCTU/<?= $post['twitter_id'] ?></a></span></p>
					<p>審核結果：通過 <?= $votes[1] ?> 票、駁回 <?= $votes[-1] ?> 票</p>
<?php
}

$photo = $author['tg_photo'] ?? '';
?>
					<div class="right floated author">
						<img class="ts circular avatar image" src="<?= $photo ?>" onerror="this.src='/assets/img/avatar.jpg';"> <?= $author_name ?></img>
					</div>
					<span>投稿時間：<?= $time ?></span>
				</div>
			</div>
<?php }
if (isset($_GET['id']) && isset($USER)) {
	$votes = $db->getVotersBySubmission($post['uid']);
	if (count($votes) > 0) {
?>
			<table class="ts votes table">
				<thead>
					<tr>
						<th>#</th>
						<th></th>
						<th>系級</th>
						<th>暱稱</th>
						<th>理由</th>
					</tr>
				</thead>
				<tbody>
<?php
	foreach ($votes as $i => $vote) {
		$type = $vote['vote'] == 1 ? '✅ 通過' : '❌ 駁回';
		$id = $vote['voter'];
		$user = $db->getUserByNctu($id);
		$dep = idToDep($id);
		$name = toHTML($user['name']);
?>
					<tr>
						<td><?= $i+1 ?></td>
						<td><?= $type ?></td>
						<td><?= $dep ?></td>
						<td><?= $name ?></td>
						<td><?= toHTML($vote['reason']) ?></td>
					</tr>
<?php } ?>
				</tbody>
			</table>
<?php } } ?>
		</div>
<?php include('includes/footer.php'); ?>
	</body>
</html>
