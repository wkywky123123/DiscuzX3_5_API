<?php
/**
 * 模块：发送私信消息
 */
if(!defined('IN_DISCUZ')) exit('Access Denied');

    // 1. 接收参数
    $from_uid = isset($_REQUEST['uid']) ? intval($_REQUEST['uid']) : 0;     // 发送者 UID
    $to_uid = isset($_REQUEST['touid']) ? intval($_REQUEST['touid']) : 0;   // 接收者 UID
    $message = isset($_REQUEST['message']) ? trim($_REQUEST['message']) : ''; // 消息内容
    $subject = isset($_REQUEST['subject']) ? trim($_REQUEST['subject']) : ''; // 标题 (可选)

    // 2. 基础校验
    if (!$from_uid || !$to_uid || !$message) {
        api_return(-3, 'Missing parameters (uid, touid, message)');
    }

    if ($from_uid == $to_uid) {
        api_return(-11, 'You cannot send a message to yourself');
    }

    // 3. 加载 UCenter 核心
    require_once libfile('function/member');
    loaducenter();

    // 4. 检查收件人是否存在
    $to_member = C::t('common_member')->fetch($to_uid);
    if (!$to_member) {
        api_return(-6, 'Recipient user not found');
    }

    // 5. 调用 UCenter 函数发送私信
    // uc_pm_send 参数：发件人UID, 收件人UID/用户名, 标题, 内容, 是否立即发送, 回复ID, 是否为用户名, 消息类型
    // 第 7 个参数设为 0 表示通过 UID 发送
    $result = uc_pm_send($from_uid, $to_uid, $subject, $message, 1, 0, 0);

    // 6. 处理结果
    if ($result > 0) {
        // 发送成功，返回消息 ID (pmid)
        api_return(0, 'Message sent successfully', array(
            'pmid' => $result,
            'from_uid' => $from_uid,
            'to_uid' => $to_uid
        ));
    } else {
        // 发送失败，映射 UCenter 错误代码
        $error_msg = 'Send failed';
        if ($result == -1) $error_msg = 'Exceed maximum messages per day (每日发送上限)';
        if ($result == -2) $error_msg = 'Minimum interval time limit (发送间隔太快)';
        if ($result == -3) $error_msg = 'Recipient does not exist';
        if ($result == -4) $error_msg = 'Too many recipients';
        if ($result == -8) $error_msg = 'Recipient has a full inbox (对方收件箱已满)';
        
        api_return(-11, $error_msg, array('uc_error' => $result));
    }