<?php
/**
 * 模块：账号静默验证
 */
if(!defined('IN_DISCUZ')) exit('Access Denied');

    // 1. 接收参数
    $username = isset($_REQUEST['username']) ? trim($_REQUEST['username']) : '';
    $password = isset($_REQUEST['password']) ? $_REQUEST['password'] : '';

    if(empty($username) || empty($password)) {
        api_return(-3, 'Username and password required');
    }

    // 2. 加载 Discuz 核心函数
    require_once libfile('function/member');
    loaducenter();

    // 3. 先检查用户是否存在以及账号状态
    $user = C::t('common_member')->fetch_by_username($username);
    if(!$user) {
        api_return(-6, 'User not found');
    }
    
    // 检查是否被禁止访问 (status = -1)
    if($user['status'] == -1) {
        api_return(-7, 'Account is banned', array('uid' => $user['uid'], 'status' => 'banned'));
    }

    // 4. 调用核心登录函数进行密码比对
    // 注意：这里仅做校验，不产生登录 Session
    $login_result = userlogin($username, $password, '', '', 'username', '');

    if($login_result['status'] == 1) {
        // 验证成功
        api_return(0, 'Account verification successful', array(
            'uid' => $user['uid'],
            'username' => $user['username'],
            'email' => $user['email'],
            'groupid' => $user['groupid'],
            'status' => 'normal'
        ));
    } else {
        // 验证失败：密码错误或其它 UC 错误
        api_return(-8, 'Invalid password or verification failed', array('status_code' => $login_result['status']));
    }