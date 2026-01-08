<?php
/**
 * 模块：修改个人资料
 */
if(!defined('IN_DISCUZ')) exit('Access Denied');

    // 1. 接收参数
    $uid = isset($_REQUEST['uid']) ? intval($_REQUEST['uid']) : 0;
    
    if(!$uid) {
        api_return(-3, 'User ID (uid) is required');
    }

    // 2. 检查用户是否存在
    $member = C::t('common_member')->fetch($uid);
    if(!$member) {
        api_return(-6, 'User not found');
    }

    // 3. 加载后台的用户栏目设置
    // 包含了所有标准字段(gender, bio)和自定义字段(field1, field2...)
    loadcache('profilesetting'); 
    $settings = $_G['cache']['profilesetting'];

    $profile_data = array();
    
    // 4. 动态遍历所有启用的字段，寻找匹配的请求参数
    if(is_array($settings)) {
        foreach($settings as $fieldid => $conf) {
            // 仅处理后台启用的字段
            if(!$conf['available']) continue;

            $val = null;

            // [逻辑A] 尝试匹配 字段ID (如 'gender', 'mobile', 'field1')
            // 这是最稳健的方式
            if(isset($_REQUEST[$fieldid])) {
                $val = trim($_REQUEST[$fieldid]);
            }
            // [逻辑B] 尝试匹配 字段标题 (如 '出生年份', '毕业院校')
            // 兼容 userinfo 接口返回的中文 Key
            elseif(isset($_REQUEST[$conf['title']])) {
                $val = trim($_REQUEST[$conf['title']]);
            }

            // 如果找到了对应的值，则加入更新队列
            if($val !== null) {
                // 特殊字段类型处理
                if($fieldid == 'gender') {
                    $val = intval($val);
                }
                elseif($fieldid == 'bio') {
                    $val = mb_substr($val, 0, 500, 'utf-8');
                }
                // 生日相关字段强制转数字
                elseif(in_array($fieldid, array('birthyear', 'birthmonth', 'birthday'))) {
                    $val = intval($val);
                }
                
                $profile_data[$fieldid] = $val;
            }
        }
    }

    // 执行 Profile 表更新
    if(!empty($profile_data)) {
        C::t('common_member_profile')->update($uid, $profile_data);
    }

    // 5. 处理个人签名 (独立存储在 common_member_field_forum 表)
    $signature_updated = false;
    if(isset($_REQUEST['signature'])) {
        $sightml = trim($_REQUEST['signature']);
        $sightml = strip_tags($sightml, '<b><i><u><a><img><br>'); 
        C::t('common_member_field_forum')->update($uid, array('sightml' => $sightml));
        $signature_updated = true;
    }

    api_return(0, 'Profile updated successfully', array(
        'uid' => $uid,
        'updated_fields' => array_keys($profile_data), // 返回实际被更新的字段ID列表
        'signature_updated' => $signature_updated
    ));