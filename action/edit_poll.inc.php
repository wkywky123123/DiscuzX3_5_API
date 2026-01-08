<?php
/**
 * 模块：编辑/删除投票贴
 */
if(!defined('IN_DISCUZ')) exit('Access Denied');

    // 1. 接收参数
    $uid = isset($_REQUEST['uid']) ? intval($_REQUEST['uid']) : 0;
    $tid = isset($_REQUEST['tid']) ? intval($_REQUEST['tid']) : 0;
    $delete = isset($_REQUEST['delete']) ? intval($_REQUEST['delete']) : 0; 
    
    $subject = isset($_REQUEST['subject']) ? trim($_REQUEST['subject']) : '';
    $message = isset($_REQUEST['message']) ? trim($_REQUEST['message']) : '';
    $maxchoices = isset($_REQUEST['maxchoices']) ? max(1, intval($_REQUEST['maxchoices'])) : 1;
    $days = isset($_REQUEST['expiration']) ? intval($_REQUEST['expiration']) : 0;
    $visible = isset($_REQUEST['visible']) ? intval($_REQUEST['visible']) : 0;
    $overt = isset($_REQUEST['overt']) ? intval($_REQUEST['overt']) : 0;

    $delete_options = isset($_REQUEST['delete_options']) ? $_REQUEST['delete_options'] : ''; 
    $poll_ids = isset($_REQUEST['poll_id']) ? (array)$_REQUEST['poll_id'] : array();     
    $poll_texts = isset($_REQUEST['poll_text']) ? (array)$_REQUEST['poll_text'] : array(); 
    $poll_aids = isset($_REQUEST['poll_aid']) ? (array)$_REQUEST['poll_aid'] : array();   

    // 2. 校验
    if(!$uid || !$tid) api_return(-3, 'UID and TID required');
    
    $thread = C::t('forum_thread')->fetch($tid);
    if(!$thread) api_return(-8, 'Thread not found');
    if($thread['authorid'] != $uid) api_return(-9, 'Access denied');
    if($thread['special'] != 1) api_return(-17, 'This is not a poll thread');

    $fid = $thread['fid'];

    // --- 核心逻辑 A: 执行删除 ---
    if ($delete > 0) {
        if(!function_exists('deletethreads')) {
            @include_once libfile('function/post');
            @include_once libfile('function/forum');
            @include_once libfile('function/delete');
        }
        
        if (function_exists('deletethreads')) {
            deletethreads(array($tid));
            api_return(0, 'Thread deleted successfully');
        } else {
            // 【保底逻辑：兼容模式】
            $tables = array(
                'forum_thread', 
                'forum_post', 
                'forum_poll', 
                'forum_polloption', 
                'forum_polloption_image', 
                'forum_pollvoter', 
                'forum_threadmod',
                'forum_sofa' // 增加沙发垫表清理
            );
            
            foreach($tables as $table) {
                // 使用 'SILENT' 参数，如果表不存在或报错，则静默跳过，不中断程序
                DB::query("DELETE FROM ".DB::table($table)." WHERE tid='$tid'", 'SILENT');
            }
            
            // 尝试删除索引表（如果不报错就删，报错就跳过）
            DB::query("DELETE FROM ".DB::table('forum_threadindex')." WHERE tid='$tid'", 'SILENT');

            if(!function_exists('updateforumcount')) {
                @include_once libfile('function/forum');
            }
            if(function_exists('updateforumcount')) {
                updateforumcount($fid); 
            }

            api_return(0, 'Thread deleted by direct DB operation');
        }
    }

    // --- 核心逻辑 B: 执行编辑 ---
    if(!empty($subject)) C::t('forum_thread')->update($tid, array('subject' => $subject));
    
    if(!empty($message)) {
        $pid = DB::result_first("SELECT pid FROM ".DB::table('forum_post')." WHERE tid=$tid AND first=1");
        if($pid) C::t('forum_post')->update('tid:'.$tid, $pid, array('message' => $message));
    }

    $expiration = $days > 0 ? (TIMESTAMP + $days * 86400) : 0;
    C::t('forum_poll')->update($tid, array(
        'maxchoices' => $maxchoices,
        'expiration' => $expiration,
        'visible' => $visible,
        'overt' => $overt,
        'multiple' => $maxchoices > 1 ? 1 : 0
    ));

    if(!empty($delete_options)) {
        $del_arr = is_array($delete_options) ? $delete_options : explode(',', $delete_options);
        $del_arr = array_filter(array_map('intval', $del_arr));
        if(!empty($del_arr)) {
            $ids_str = implode(',', $del_arr);
            DB::query("DELETE FROM ".DB::table('forum_polloption')." WHERE polloptionid IN ($ids_str) AND tid=$tid");
            DB::query("DELETE FROM ".DB::table('forum_polloption_image')." WHERE poid IN ($ids_str) AND tid=$tid");
        }
    }

    if(!empty($poll_texts)) {
        foreach ($poll_texts as $index => $text) {
            $text = trim($text);
            $aid = isset($poll_aids[$index]) ? intval($poll_aids[$index]) : 0;
            $poid = isset($poll_ids[$index]) ? intval($poll_ids[$index]) : 0;

            if($text === '' && $aid === 0) continue;

            if($poid > 0) {
                $check = DB::fetch_first("SELECT polloptionid FROM ".DB::table('forum_polloption')." WHERE polloptionid=$poid AND tid=$tid");
                if($check) DB::update('forum_polloption', array('polloption' => $text), "polloptionid=$poid");
            } else {
                $max_order = DB::result_first("SELECT MAX(displayorder) FROM ".DB::table('forum_polloption')." WHERE tid=$tid");
                $poid = DB::insert('forum_polloption', array(
                    'tid' => $tid, 'votes' => 0, 'displayorder' => intval($max_order) + 1, 'polloption' => $text, 'voterids' => ''
                ), true);
            }

            if($poid > 0 && $aid > 0) {
                DB::delete('forum_polloption_image', "poid=$poid");
                DB::insert('forum_polloption_image', array('tid' => $tid, 'poid' => $poid, 'aid' => $aid, 'uid' => $uid));
            }
        }
    }

    api_return(0, 'Poll edited successfully');