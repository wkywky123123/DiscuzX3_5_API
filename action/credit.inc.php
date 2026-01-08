<?php
/**
 * 模块：积分变更操作
 */
if(!defined('IN_DISCUZ')) exit('Access Denied');

// 1. 接收参数
    $uid = isset($_REQUEST['uid']) ? intval($_REQUEST['uid']) : 0;
    $type = isset($_REQUEST['type']) ? trim($_REQUEST['type']) : ''; // 格式如 extcredits1, extcredits2
    $value = isset($_REQUEST['value']) ? intval($_REQUEST['value']) : 0; // 增减值，正数为加，负数为减
    $reason = isset($_REQUEST['reason']) ? trim($_REQUEST['reason']) : 'API Operation'; // 变动原因

    // 2. 基础校验
    if(!$uid || !$type || !$value) {
        api_return(-3, 'Missing parameters (uid, type, value)');
    }

    // 检查积分字段格式是否正确 (extcredits1 - extcredits8)
    if(!preg_match('/^extcredits[1-8]$/', $type)) {
        api_return(-7, 'Invalid credit type (must be extcredits1 to extcredits8)');
    }

    // 检查用户是否存在
    $member = C::t('common_member')->fetch($uid);
    if(!$member) api_return(-6, 'User not found');

    // 3. 检查该积分项在 Discuz 后台是否已启用
    loadcache('setting');
    $credit_idx = intval(substr($type, -1)); // 提取数字部分
    if(!isset($_G['setting']['extcredits'][$credit_idx])) {
        api_return(-7, 'This credit type is not enabled in forum settings');
    }
    
    $credit_info = $_G['setting']['extcredits'][$credit_idx];
    $credit_name = $credit_info['title']; // 获取积分名称，如 "金钱"、"威望"

    // 4. 获取变动前的余额 (用于返回对比)
    $old_count = C::t('common_member_count')->fetch($uid);
    $old_val = $old_count[$type];

    // 5. 调用 Discuz 核心函数更新积分
    // updatemembercount 参数: UID, 变动数组, 是否检查上限, 记录类型, 相关ID, 来源UID, 原因
    require_once libfile('function/post'); // 引入积分相关函数库
    updatemembercount($uid, array($credit_idx => $value), true, 'API', 0, 0, $reason);

    // 6. 发送系统通知（提醒用户）
    $val_str = ($value > 0 ? '+' : '') . $value;
    $note = "您的 {$credit_name} 有变动：{$val_str} {$credit_info['unit']}。原因：{$reason}";
    notification_add($uid, 'system', $note, array(), 1);

    // 7. 获取变动后的余额
    $new_count = C::t('common_member_count')->fetch($uid);

    api_return(0, 'Credit updated successfully', array(
        'uid' => $uid,
        'username' => $member['username'],
        'credit_type' => $type,
        'credit_name' => $credit_name,
        'old_value' => $old_val,
        'change_value' => $value,
        'new_value' => $new_count[$type]
    ));