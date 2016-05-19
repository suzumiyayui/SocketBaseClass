<?php

        const MSG_TYPE_HANDSHAKE = 0; //握住信息
        const MSG_TYPE_MESSAGE = 1; //正常聊天信息
        const MSG_TYPE_DISCONNECT = -1; //退出信息
        const MSG_TYPE_JOIN = 2; //请求加入信息，给特定用户
        const MSG_TYPE_LOGIN = 3; //加入聊天信息，给全体发

class SocketBase {

    static public $instance;
    public $socketpool;
    public $master;

    public function __construct() {
        
    }

    static function getinstance() {

        if (is_null(self::$instance)) {

            self::$instance = new self;
        }

        return self::$instance;
    }

    public function run($host, $port, $maxClient) {

        $this->master = socket_create(AF_INET, SOCK_STREAM, SOL_TCP); //注意不是SQL
        if ($this->master === FALSE) {
            echo 'socket_create() failed:' . socket_strerror(socket_last_error());
            exit();
        }
        socket_set_option($this->master, SOL_SOCKET, SO_REUSEADDR, 1); //一个端口释放可立即使用，测试其实还是不可用
        $bind = socket_bind($this->master, $host, $port);
        if ($bind === FALSE) {
            echo 'socket_bind() failed:' . socket_strerror(socket_last_error());
            exit();
        }
        $listen = socket_listen($this->master, $maxClient); //超过最大监听数会有WSAECONNREFUSED错误
        if ($listen === FALSE) {
            echo 'socket_listen() failed:' . socket_strerror(socket_last_error());
            exit();
        }

        $this->socketpool = array(); //初始化用户池



        while (true) {

            $this->pushToPool($this->master); //监听主机端口的这个
            $write = NULL; //函数参数是传递引用，必须定义变量
            $except = NULL;
            $tv_sec = NULL;
            socket_select($this->socketpool, $write, $except, $tv_sec); //多路选择，监听哪些socket有状态变化，返回时将有状态变化的保留在$sockets中，其他都删除之！
            //循环有状态变化的socket


            foreach ($this->socketpool as $socket) {

                $time = date('Y-m-d H:i:s', time());  //处理时间

                if ($socket === $this->master) { //监听主机端口的socket有状太变化，说明有新用户接入
                    $Newclient = socket_accept($this->master); //创建新socket负责该用户通信

                    if ($Newclient === FALSE) {
                        echo 'socket_accept() failed:' . socket_strerror(socket_last_error());
                    } else {

                        $this->pushToPool($Newclient); //加入用户池

                        $this->doHandshake($Newclient); //进行握手

                        socket_getpeername($Newclient, $ip); //获取用户IP地址

                        $response = $this->frameEncode(json_encode(array('type' => MSG_TYPE_HANDSHAKE, 'msg' => $ip . ' 欢迎你加入聊天室 ', 'time' => $time))); //编码数据帧

                        $this->sendMessage($response, $Newclient);

                        echo "new connected $ip\r\n";
                    }
                } else { //其他线程通信
                    $bytes = socket_recv($socket, $buf, 1024, 0); //读取发送过来的信息的字节数


                    $data = $this->frameDecode($buf); //正常信息为json字符串，


                    if ($bytes === FALSE) {  //获取失败
                        
                        echo 'socket_recv() failed:' . socket_strerror(socket_last_error());
                        
                    } elseif ($bytes <= 6 || empty($data) || !is_object(json_decode($data))) {  //丢失信号
                        
                        $index = array_search($socket, $this->socketpool); //寻找该socket在用户列表中的位置

                        $response = $this->frameEncode(json_encode(array('type' => MSG_TYPE_DISCONNECT, 'msg' => 'sone one has lift', 'time' => $time)));

                        $this->sendMessage($response);

                        unset($this->socketpool[$index]); //删除用户

                        socket_close($socket);

                        echo "user $socket disconnect\r\n";
                        
                    } else {

                        //正常通信
                        $data = json_decode($data); //对象
                        
                        
                        if ($data->type == MSG_TYPE_JOIN) {
                            //握手成功请求加入
                            $index = array_search($socket, $this->socketpool);
                         
                            echo "ask to join in \r\n";
                        } elseif ($data->type == MSG_TYPE_MESSAGE) {
                            $response = $this->frameEncode(json_encode(array('type' => MSG_TYPE_MESSAGE, 'msg' => 'msss', 'time' => $time, 'username' => 'username')));
                            $this->sendMessage($response);
                            echo "receive message\r\n";
                        }
                        
                        
                        
                        
                    }
                }
            }//循环监听所有端口
        }
    }

    /**
     * 进入用户池
     * Enter description here ...
     * @param unknown_type $content
     */
    protected function pushToPool($content) {

        $this->socketpool[] = $content;
    }

    /**
     * 发送信息
     * Enter description here ...
     * @param unknown_type $msg
     */
    protected function sendMessage($msg, $receiver = '') {
        if (!empty($receiver)) {
            socket_write($receiver, $msg, strlen($msg));
        } else {

            foreach ($this->socketpool as $client) {
                socket_write($client, $msg, strlen($msg));
            }
        }
    }

    /**
     * 握手操作
     * Enter description here ...
     * @param unknown_type $client
     */
    protected function doHandshake($client) {
        $header = socket_read($client, 1024); //读取头信息
        if (preg_match("/Sec-WebSocket-Key: (.*)\r\n/", $header, $match)) {//冒号后面有个空格
            $secKey = $match[1];
            $secAccept = base64_encode(sha1($secKey . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', TRUE)); //握手算法固定的
            $upgrade = "HTTP/1.1 101 Web Socket Protocol Handshake\r\n" .
                    "Upgrade: websocket\r\n" .
                    "Connection: Upgrade\r\n" .
                    "Sec-WebSocket-Accept:$secAccept\r\n\r\n";
            socket_write($client, $upgrade, strlen($upgrade));
        }
    }

    /**
     * 编码数据帧
     * Enter description here ...
     * @param unknown_type $text
     */
    protected function frameEncode($text) {
        $b1 = 0x80 | (0x1 & 0x0f);
        $length = strlen($text);

        if ($length <= 125) {
            $header = pack('CC', $b1, $length);
        } elseif ($length > 125 && $length < 65536) {
            $header = pack('CCn', $b1, 126, $length);
        } elseif ($length >= 65536) {
            $header = pack('CCNN', $b1, 127, $length);
        }
        return $header . $text;
    }

    /**
     * 解码数据帧
     * Enter description here ...
     * @param unknown_type $text
     */
    protected function frameDecode($text) {
        $length = ord($text[1]) & 127;
        if ($length == 126) {
            $masks = substr($text, 4, 4);
            $data = substr($text, 8);
        } elseif ($length == 127) {
            $masks = substr($text, 10, 4);
            $data = substr($text, 14);
        } else {
            $masks = substr($text, 2, 4);
            $data = substr($text, 6);
        }
        $text = "";
        for ($i = 0; $i < strlen($data); ++$i) {
            $text .= $data[$i] ^ $masks[$i % 4];
        }
        return $text;
    }

    /**
     * 解码Json格式到数组
     * Enter description here ...
     * @param json $object
     */
    protected function json_to_array(&$object) {
        $object = json_decode($object);
        $object = json_decode(json_encode($object), true);
        return $object;
    }

}
