<?php
/**
 * 模块：帖子评分
 */
if(!defined('IN_DISCUZ')) exit('Access Denied');

    $uid = isset($_REQUEST['uid']) ? intval($_REQUEST['uid']) : 0;
    $pid = isset($_REQUEST['pid']) ? intval($_REQUEST['pid']) : 0;
    $credit_id = isset($_REQUEST['credit_id']) ? intval($_REQUEST['credit_id']) : 0;
    $score = isset($_REQUEST['score']) ? intval($_REQUEST['score']) : 0;
    $reason = isset($_REQUEST['reason']) ? trim($_REQUEST['reason']) : 'API评分';

    if (!$uid || !$pid || !$credit_id || !$score) api_return(-3, 'Missing parameters');

    // 1. 获取帖子和作者
    $post = DB::fetch_first("SELECT tid, fid, authorid, author FROM ".DB::table('forum_post')." WHERE pid=%d", array($pid));
    if (!$post) api_return(-8, 'Post not found');
    
    // 权限：禁止给自己评分
    if ($post['authorid'] == $uid) api_return(-9, 'You cannot rate your own post');

    // 2. 【核心新增】检查是否已经评分过
    // Discuz 逻辑：同一用户对同一 PID 只能评分一次
    $has_rated = DB::result_first("SELECT COUNT(*) FROM ".DB::table('forum_ratelog')." WHERE pid=%d AND uid=%d", array($pid, $uid));
    if ($has_rated) {
        api_return(-15, 'You have already rated this post');
    }

    // 3. 获取权限和余额
    $member = C::t('common_member')->fetch($uid);
    $member_count = C::t('common_member_count')->fetch($uid);
    $usergroup = C::t('common_usergroup_field')->fetch($member['groupid']);
    
    $credit_field = 'extcredits'.$credit_id;
    $user_balance = isset($member_count[$credit_field]) ? intval($member_count[$credit_field]) : 0;

    // 4. 强制授权逻辑 (针对管理员或异常数据兜底)
    $raterange = @unserialize($usergroup['raterange']);
    if ($member['groupid'] == 1 || empty($raterange)) {
        $conf = array('deduct' => 1, 'min' => -1000, 'max' => 1000, 'daily' => 999999);
    } else {
        $conf = isset($raterange[$credit_id]) ? $raterange[$credit_id] : array('min' => -10, 'max' => 10, 'daily' => 100, 'deduct' => 1);
    }

    // 5. 执行数据库操作 (强制 SQL 驱动)
    
    // A. 记录评分日志
    DB::insert('forum_ratelog', array(
        'pid' => $pid, 'uid' => $uid, 'username' => $member['username'],
        'extcredits' => $credit_id, 'score' => $score, 'dateline' => TIMESTAMP, 'reason' => $reason
    ));

    // B. 更新帖子评分次数
    DB::query("UPDATE ".DB::table('forum_post')." SET ratetimes=ratetimes+1 WHERE pid=%d", array($pid));

    // C. 给作者加分
    DB::query("UPDATE ".DB::table('common_member_count')." SET {$credit_field}={$credit_field}+%d WHERE uid=%d", array($score, $post['authorid']));

    // D. 如果扣除自身，给评分人减分
    if ($conf['deduct']) {
        DB::query("UPDATE ".DB::table('common_member_count')." SET {$credit_field}={$credit_field}-%d WHERE uid=%d", array(abs($score), $uid));
    }

    // 6. 发送实时通知
    require_once libfile('function/home');
    loadcache('setting');
    $c_name = $_G['setting']['extcredits'][$credit_id]['title'];
    $c_unit = $_G['setting']['extcredits'][$credit_id]['unit'];
    
    $user_link = '<a href="home.php?mod=space&uid='.$uid.'" class="xw1">'.$member['username'].'</a>';
    $msg = "{$user_link} 评分了您的帖子，您获得了 [b]".($score > 0 ? '+'.$score : $score)." {$c_unit}{$c_name}[/b]。";
    if($reason) $msg .= "<br />理由：{$reason}";

    notification_add($post['authorid'], 'system', $msg, array('from_id' => $uid, 'from_idtype' => 'rate'), 1);

    api_return(0, 'Rated successfully', array(
        'score' => $score,
        'new_balance' => $user_balance - abs($score)
    ));