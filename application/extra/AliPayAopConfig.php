<?php
use think\Env;
return [
    //支付宝Aop接口配置
    'app_id' => Env::get('AliPayAop.app_id'),
    'rsa_public_key' => Env::get('AliPayAop.rsa_public_key'),
    'rsa_private_key' => Env::get('AliPayAop.rsa_private_key'),
];
