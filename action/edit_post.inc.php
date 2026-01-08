<?php
/**
 * 模块：编辑/删除帖子
 */
if(!defined('IN_DISCUZ')) exit('Access Denied');

    // 1. 接收参数
    $uid = isset($_REQUEST['uid']) ? intval($_REQUEST['uid']) : 0;
    $pid = isset($_REQUEST['pid']) ? intval($_REQUEST['pid']) : 0;
    $tid_input = isset($_REQUEST['tid']) ? intval($_REQUEST['tid']) : 0;
    
    $subject = isset($_REQUEST['subject']) ? trim($_REQUEST['subject']) : '';
    $message = isset($_REQUEST['message']) ? trim($_REQUEST['message']) : '';
    $delete = isset($_REQUEST['delete']) ? intval($_REQUEST['delete']) : 0; 

    // 2. 基础验证
    if (!$uid) api_return(-3, 'User ID (uid) is required');
    if (!$pid && !$tid_input) api_return(-3, 'Post ID (pid) or TID is required');

    // 3. 获取帖子数据
    $post = false;
    if($pid) {
        $post = C::t('forum_post')->fetch('tid:0', $pid);
        if(!$post) $post = DB::fetch_first("SELECT * FROM ".DB::table('forum_post')." WHERE pid=$pid");
    }
    if(!$post && $tid_input) {
        $post = DB::fetch_first("SELECT * FROM ".DB::table('forum_post')." WHERE tid=$tid_input AND first=1");
    }

    if (!$post) api_return(-8, 'Post not found');

    // 4. 权限校验
    if ($post['authorid'] != $uid) {
        api_return(-9, 'Access denied. You are not the author.');
    }

    $pid = $post['pid'];
    $tid = $post['tid'];
    $fid = $post['fid'];
    $is_first = $post['first']; 

    // --- 逻辑 A: 执行删除 ---
    if ($delete > 0) {
        // 1. 尝试使用 Discuz 标准方式加载函数库
        if(!function_exists('deletethreads')) {
            @include_once libfile('function/post');
            @include_once libfile('function/forum');
            @include_once libfile('function/delete');
        }
        
        if ($is_first) {
            // A. 如果系统函数可用，走系统流程 (最安全，会自动扣分和清理附件)
            if (function_exists('deletethreads')) {
                deletethreads(array($tid));
                api_return(0, 'Thread deleted by core function', array('tid' => $tid));
            } else {
                // B. 【保底方案】直接操作数据库删除 (防止函数库加载失败)
                // 删除主题、所有帖子、审核记录
                DB::query("DELETE FROM ".DB::table('forum_thread')." WHERE tid='$tid'");
                DB::query("DELETE FROM ".DB::table('forum_post')." WHERE tid='$tid'");
                DB::query("DELETE FROM ".DB::table('forum_threadmod')." WHERE tid='$tid'");
                // 更新版块统计
                C::t('forum_forum')->update_forum_counter($fid, -1, -1, -1);
                api_return(0, 'Thread deleted by direct DB operation', array('tid' => $tid));
            }
        } else {
            // 删除普通回帖
            if (function_exists('deletepost')) {
                deletepost(array($pid), 'pid');
                C::t('forum_thread')->decrease($tid, array('replies' => 1));
                C::t('forum_forum')->update_forum_counter($fid, 0, -1, -1);
                api_return(0, 'Post deleted by core function', array('pid' => $pid));
            } else {
                // 【保底方案】直接删除帖子
                DB::query("DELETE FROM ".DB::table('forum_post')." WHERE pid='$pid'");
                C::t('forum_thread')->decrease($tid, array('replies' => 1));
                C::t('forum_forum')->update_forum_counter($fid, 0, -1, -1);
                api_return(0, 'Post deleted by direct DB operation', array('pid' => $pid));
            }
        }
    }

    // --- 逻辑 B: 执行编辑 ---
    if (empty($message)) api_return(-3, 'Message is required for editing');

    $update_data = array('message' => $message);
    if ($is_first && !empty($subject)) {
        C::t('forum_thread')->update($tid, array('subject' => $subject));
        $update_data['subject'] = $subject;
    }

    C::t('forum_post')->update('tid:'.$tid, $pid, $update_data);

    api_return(0, 'Post updated successfully', array('pid' => $pid, 'tid' => $tid));
    