<?php
/**
 * 模块：管理发送提醒
 */
if(!defined('IN_DISCUZ')) exit('Access Denied');

// 1. 接收参数
    $uid = isset($_REQUEST['uid']) ? intval($_REQUEST['uid']) : 0;
    $message = isset($_REQUEST['message']) ? trim($_REQUEST['message']) : '';
    $url = isset($_REQUEST['url']) ? trim($_REQUEST['url']) : ''; // 点击提醒后跳转的链接（可选）

    // 2. 基础校验
    if(!$uid || !$message) {
        api_return(-3, 'Missing parameters (uid, message)');
    }

    // 检查用户是否存在
    $member = C::t('common_member')->fetch($uid);
    if(!$member) {
        api_return(-6, 'User not found');
    }

    // 3. 处理带链接的消息内容
    // 如果提供了 URL，我们将消息包装成 HTML 链接，这样用户在网页端点击提醒时能直接跳转
    if($url) {
        $message = '<a href="' . $url . '" target="_blank">' . $message . '</a>';
    }

    // 4. 调用 Discuz 原生函数发送提醒
    // 参数说明：接收者UID, 提醒类型(system), 消息内容, 模板变量, 是否强制显示
    require_once libfile('function/home'); // 确保加载了 home 模块下的通知函数
    notification_add($uid, 'system', $message, array(), 1);

    // 5. 返回成功
    api_return(0, 'Notification sent successfully', array(
        'uid' => $uid,
        'username' => $member['username'],
        'sent_content' => $message
    ));