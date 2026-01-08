<?php
/**
 * 模块：个人资料/积分
 */
if(!defined('IN_DISCUZ')) exit('Access Denied');

$q = isset($_REQUEST['query']) ? trim($_REQUEST['query']) : '';
        if(empty($q)) {
            api_return(-3, 'Query parameter (UID or Username) is required');
    }

    // 1. 确定 UID (支持输入数字 UID 或 用户名)
    $uid = is_numeric($q) ? intval($q) : C::t('common_member')->fetch_uid_by_username($q);
    
    // 2. 获取用户主表数据
    $member = C::t('common_member')->fetch($uid);
    if(!$member) {
        api_return(-6, 'User not found');
    }
    
    // 3. 获取其他关联表数据 (积分、统计、资料)
    $profile = C::t('common_member_profile')->fetch($uid);
    $count = C::t('common_member_count')->fetch($uid);
    $status = C::t('common_member_status')->fetch($uid);
    $group = C::t('common_usergroup')->fetch($member['groupid']);

    // 4. [核心功能] 动态处理后台设置的个人资料栏目
    loadcache('profilesetting');
    $profile_data = array();
    
    if(is_array($_G['cache']['profilesetting'])) {
        foreach($_G['cache']['profilesetting'] as $fieldid => $setting) {
            // 只读取后台设置为“启用”的栏目
            if($setting['available']) {
                $val = isset($profile[$fieldid]) ? $profile[$fieldid] : '';
                // 使用后台设置的“栏目名称”作为 Key，这样 App 端可以直接显示名称
                $profile_data[$setting['title']] = $val;
            }
        }
    }

    // 5. 组装返回数据
    $user_info = array(
        'uid' => $member['uid'],
        'username' => $member['username'],
        'email' => $member['email'],
        'avatar' => $_G['siteurl'] . 'uc_server/avatar.php?uid='.$uid.'&size=middle',
        'groupid' => $member['groupid'],
        'groupname' => $group['grouptitle'],
        'credits' => $member['credits'],
        'regdate' => dgmdate($member['regdate'], 'u'), // 格式化日期
        
        // 动态资料列表
        'profile' => $profile_data,
        
        // 积分详细统计
        'extcredits' => array(
            '1' => $count['extcredits1'],
            '2' => $count['extcredits2'],
            '3' => $count['extcredits3'],
            '4' => $count['extcredits4'],
            '5' => $count['extcredits5'],
            '6' => $count['extcredits6'],
            '7' => $count['extcredits7'],
            '8' => $count['extcredits8'],
        ),
        
        // 帖子统计
        'posts' => $count['posts'],
        'threads' => $count['threads'],
        
        // 最后活跃
        'lastactivity' => dgmdate($status['lastactivity'], 'u')
    );
    
    api_return(0, 'Get user info success', $user_info);