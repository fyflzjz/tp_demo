<?php
namespace payment\Wxpay;
/*
 * 新版app微信支付
 * 
 */
ini_set('date.timezone', 'Asia/Shanghai');
error_reporting(E_ERROR);

require_once EXTEND_PATH . "payment" . DS . "Wxpay" . DS . "lib" . DS . "WxPay.Api.php";
require_once EXTEND_PATH . "payment" . DS . "Wxpay" . DS . "lib" . DS . "WxPay.Exception.php";
require_once EXTEND_PATH . "payment" . DS . "Wxpay" . DS . "lib" . DS . "WxPay.Config.php";
require_once EXTEND_PATH . "payment" . DS . "Wxpay" . DS . "lib" . DS . "WxPay.Data.php";
require_once EXTEND_PATH . "payment" . DS . "Wxpay" . DS . "CLogFileHandler.php";

class WxPay
{
    //微信流水号
    private $transaction_id = '';

    //总金额（分）
    private $total_fee = '';

    //退款金额（分）
    private $refund_fee = '';

    //构造函数
    public function __construct($transaction_id = '', $total_fee = '', $refund_fee = '')
    {

        //初始化日志
        $log_path = RUNTIME_PATH . 'payment' . DS . 'Wxpay' . DS . 'logs' . DS;
        if (is_dir($log_path) || @mkdir($log_path, 0777, true)) {
            $log_path .= 'app_' . date('Y-m-d') . '.log';
            $logHandler = new CLogFileHandler($log_path);
            LogNew::Init($logHandler, 15);
        }

        $this->transaction_id = $transaction_id;
        $this->total_fee = $total_fee;
        $this->refund_fee = $refund_fee;
    }

    //统一下单
    public function get_prepay_id($body, $out_sn, $total_fee, $attach = '', $is_recharge = false)
    {

        //构造要请求的参数
        $input = new WxPayUnifiedOrder();
        $input->SetBody($body);

        //附加数据，在查询API和支付通知中原样返回
        $input->SetAttach($attach);

        $input->SetOut_trade_no($out_sn);
        $input->SetTotal_fee($total_fee);
        $input->SetTime_start(date("YmdHis"));
        //$input->SetTime_expire(date("YmdHis", time() + 600));
        //$input->SetGoods_tag("test");
        //$input->SetProduct_id("123456789");

        $input->SetNotify_url(WxPayConfig::NOTIFY_URL);//异步通知url

        $input->SetTrade_type("APP");

        //判定充值不允许使用信用卡
        if ($is_recharge) {
            $input->SetLimit_Pay("no_credit");
        }
        $wxPayApi = new WxPayApi();
        $result = $wxPayApi->unifiedOrder($input);

        if ($result['result_code'] != 'SUCCESS' || $result['return_code'] != 'SUCCESS') {
            LogNew::INFO(json_encode($result));
        }
        return $result;
    }

    //创建APP支付参数
    public function createAppPayData($prepay_id)
    {
        $array = array(
            'appid' => WxPayConfig::APPID,
            'noncestr' => WxPayApi::getNonceStr(),
            'package' => 'Sign=WXPay',
            'partnerid' => WxPayConfig::MCHID,
            'prepayid' => $prepay_id,
            'timestamp' => (string)time(),
        );

        $array['sign'] = $this->AppMakeSign($array);
        unset($array['appkey']);

        return $array;
    }

    //查询订单
    public function orderquery($out_sn = '', $trade_no = '')
    {
        //构造要请求的参数
        $input = new WxPayOrderQuery();

        if ($out_sn) {
            //通过商户订单号查询
            $input->SetOut_trade_no($out_sn);
        } elseif ($trade_no) {
            //通过支付流水号查询
            $input->SetTransaction_id($trade_no);
        } else {
            return false;
        }

        $result = WxPayApi::orderQuery($input);
        return $result;
    }

    //退款
    public function send()
    {
        //构造要请求的参数
        $input = new WxPayRefund();
        $input->SetTransaction_id($this->transaction_id);
        $input->SetTotal_fee($this->total_fee);
        $input->SetRefund_fee($this->refund_fee);
        $input->SetOut_refund_no(WxPayConfig::MCHID . date("YmdHis"));
        $input->SetOp_user_id(WxPayConfig::MCHID);
        $result = WxPayApi::refund($input);
        return $result;
    }

    /**
     * 退款查询
     * @param string $refund_id 微信退款单号
     * @param string $batch_no 商户退款单号
     * @param string $trade_no 微信交易流水号
     * @param string $out_sn 商户订单号
     * @return bool|array
     */
    public function refundQuery($refund_id = '', $batch_no = '', $trade_no = '', $out_sn = '')
    {
        //构造要请求的参数
        $input = new WxPayRefundQuery();
        if ($refund_id) {
            //通过微信退款单号查询退款
            $input->SetRefund_id($refund_id);
        } elseif ($batch_no) {
            $input->SetOut_refund_no($batch_no);
        } elseif ($trade_no) {
            $input->SetOut_trade_no($trade_no);
        } elseif ($out_sn) {
            $input->SetTransaction_id($out_sn);
        } else {
            return false;
        }

        $result = WxPayApi::refundQuery($input);
        return $result;
    }

    //异步通知
    public function check_notify()
    {
        //构造要请求的参数
        //$input = new WxPayRefund();
        $result = WxPayApi::notify();
        return $result;
    }

    //下载对账单
    public function downloadBill($data)
    {
        //构造要请求的参数
        $input = new WxPayDownloadBill();
        //对账单日期
        $input->SetBill_date($data);

        //账单类型
        /*
        ALL，返回当日所有订单信息，默认值
        SUCCESS，返回当日成功支付的订单
        REFUND，返回当日退款订单
        RECHARGE_REFUND，返回当日充值退款订单（相比其他对账单多一栏“返还手续费”）
         */
        $input->SetBill_type('ALL');
        $result = WxPayApi::downloadBill($input);

        return $result;
    }

    /**
     * 生成签名
     * @return 签名，本函数不覆盖sign成员变量，如要设置签名需要调用SetSign方法赋值
     */
    public function AppMakeSign($array)
    {
        //签名步骤一：按字典序排序参数
        ksort($array);
        $string = $this->AppToUrlParams($array);
        //签名步骤二：在string后加入KEY
        $string = $string . "&key=" . WxPayConfig::KEY;
        //签名步骤三：MD5加密
        $string = md5($string);
        //签名步骤四：所有字符转为大写
        $result = strtoupper($string);
        return $result;
    }

    /**
     * 格式化参数格式化成url参数
     */
    public function AppToUrlParams($array)
    {
        $buff = "";
        foreach ($array as $k => $v) {
            if ($k != "sign" && $v != "" && !is_array($v)) {
                $buff .= $k . "=" . $v . "&";
            }
        }

        $buff = trim($buff, "&");
        return $buff;
    }

    /**
     * 返回结果给微信服务器
     * @param $data
     */
    public static function resultXmlToWx($data)
    {
        WxPayApi::resultXmlToWx($data);
        die;
    }

}

?>