<?php
/**
 * 模块：发布投票贴
 */
if(!defined('IN_DISCUZ')) exit('Access Denied');

    // 1. 接收基础参数
    $uid = isset($_REQUEST['uid']) ? intval($_REQUEST['uid']) : 0;
    $fid = isset($_REQUEST['fid']) ? intval($_REQUEST['fid']) : 0;
    $subject = isset($_REQUEST['subject']) ? trim($_REQUEST['subject']) : '';
    $message = isset($_REQUEST['message']) ? trim($_REQUEST['message']) : '';
    $typeid = isset($_REQUEST['typeid']) ? intval($_REQUEST['typeid']) : 0;

    // 2. 接收投票设置参数
    $maxchoices = isset($_REQUEST['maxchoices']) ? max(1, intval($_REQUEST['maxchoices'])) : 1;
    $days = isset($_REQUEST['expiration']) ? intval($_REQUEST['expiration']) : 0;
    $expiration = $days > 0 ? (TIMESTAMP + $days * 86400) : 0;
    $visible = isset($_REQUEST['visible']) ? intval($_REQUEST['visible']) : 0;
    $overt = isset($_REQUEST['overt']) ? intval($_REQUEST['overt']) : 0;
    
    // 3. [核心修改] 接收选项数据 (使用数组参数: poll_text[] 和 poll_aid[])
    // 格式例如：poll_text[]=选项A&poll_text[]=选项B&poll_aid[]=0&poll_aid[]=1055
    $poll_texts = isset($_REQUEST['poll_text']) ? (array)$_REQUEST['poll_text'] : array();
    $poll_aids = isset($_REQUEST['poll_aid']) ? (array)$_REQUEST['poll_aid'] : array(); // 确保是数组

    // 4. 校验
    if(!$uid || !$fid || !$subject || !$message) api_return(-3, 'Missing basic parameters');
    if(empty($poll_texts) || !is_array($poll_texts)) api_return(-3, 'Poll options required (use poll_text[] and poll_aid[])');
    if(count($poll_texts) > 20) api_return(-16, 'Too many options (Max 20)');

    $member = C::t('common_member')->fetch($uid);
    if(!$member) api_return(-6, 'User not found');

    // 5. 插入主题表 (special = 1 代表投票)
    $newthread = array(
        'fid' => $fid,
        'typeid' => $typeid,
        'author' => $member['username'],
        'authorid' => $uid,
        'subject' => $subject,
        'dateline' => TIMESTAMP,
        'lastpost' => TIMESTAMP,
        'lastposter' => $member['username'],
        'special' => 1, // [关键] 标记为投票贴
        'status' => 32,
    );
    $tid = C::t('forum_thread')->insert($newthread, true);

    // 6. 插入帖子内容表
    $pid = C::t('forum_post_tableid')->insert(array('pid' => null), true);
    C::t('forum_post')->insert($fid, array(
        'pid' => $pid, 'fid' => $fid, 'tid' => $tid, 'first' => 1,
        'author' => $member['username'], 'authorid' => $uid,
        'subject' => $subject, 'dateline' => TIMESTAMP, 'message' => $message,
        'useip' => $_G['clientip']
    ));

    // 7. 插入投票主表 (forum_poll)
    C::t('forum_poll')->insert(array(
        'tid' => $tid,
        'multiple' => $maxchoices > 1 ? 1 : 0, // 是否多选
        'visible' => $visible,
        'maxchoices' => $maxchoices,
        'expiration' => $expiration,
        'overt' => $overt,
        'voters' => 0
    ));

    // 8. [核心修改] 循环插入投票选项 (forum_polloption)
    foreach ($poll_texts as $index => $text) {
        $text = trim($text);
        // 从 poll_aids 数组中获取对应的图片 ID
        $aid = isset($poll_aids[$index]) ? intval($poll_aids[$index]) : 0;
        
        if($text === '' && $aid === 0) continue; // 跳过完全为空的选项

        $oid = DB::insert('forum_polloption', array(
            'tid' => $tid,
            'votes' => 0,
            'displayorder' => $index, // 使用循环索引作为排序
            'polloption' => $text,
            'voterids' => ''
        ), true);

        // 如果选项带有图片 (AID)
        if($aid > 0) {
            DB::insert('forum_polloption_image', array(
                'tid' => $tid,
                'poid' => $oid,
                'aid' => $aid,
                'uid' => $uid
            ));
        }
    }

    // 9. 更新统计
    C::t('forum_forum')->update_forum_counter($fid, 1, 1, 1);
    C::t('common_member_count')->increase($uid, array('threads' => 1, 'posts' => 1));

    api_return(0, 'Poll created successfully', array('tid' => $tid));