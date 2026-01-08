<?php
/**
 * 模块：用户登录
 */
if(!defined('IN_DISCUZ')) exit('Access Denied');


$username = isset($_REQUEST['username']) ? trim($_REQUEST['username']) : '';
    $password = isset($_REQUEST['password']) ? $_REQUEST['password'] : '';
    
    if(!$username || !$password) {
        api_return(-3, 'Username and password are required');
    }
    
    require_once libfile('function/member');
    // 调用 Discuz 标准登录函数
    $result = userlogin($username, $password, 0, '', 'username');
    
    if($result['status'] == 1) {
        // 登录成功，设置 Discuz 全局登录状态
        setloginstatus($result['member'], 1296000);
        
        // 准备返回给 App 的 Cookie 信息（用于 WebView 同步）
        $cookie_info = array(
            'prefix' => $_G['config']['cookie']['cookiepre'],
            'auth' => getglobal('auth', 'cookie'),
            'saltkey' => getglobal('saltkey', 'cookie'),
            'domain' => $_G['config']['cookie']['cookiedomain'],
            'path' => $_G['config']['cookie']['cookiepath']
        );

        api_return(0, 'Login successful', array(
            'uid' => $result['member']['uid'],
            'username' => $result['member']['username'],
            'email' => $result['member']['email'],
            'cookie_info' => $cookie_info
        ));
    } else {
        // 登录失败
        api_return(-4, 'Login failed: ' . $result['status']);
    }