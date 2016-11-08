<?php

$websocket = new websocket();
$websocket->run();

class websocket
{
	protected $master;
	protected $max_connects = 100; //最大连接数
	protected $send_size    = 1024; //发送数据最大字节
	protected $sockets = array();
	protected $users   = array();
	protected $config  = array('domain'=>'192.168.5.85','port'=>8000);
	
	/**
	 *初始化
	 */
	public function __construct()
	{
		$this->master = $this->connect();
		if(!empty($this->master)){
			$this->sockets = array($this->master);
		}
	}
	public function run()
	{
		//最大连接数
		$count = count($this->sockets);
		if($count <= $this->max_connects){
			while ($count <= $this->max_connects){
				$clients = $this->sockets;
				$write   = NULL;
				$except  = NULL;
				$state   = socket_select($clients,$write,$except,NULL);
				if($state){
					foreach($clients as $socket){
						if($socket == $this->master){
							//放入用户池
							$this->add_users($socket);
						}else{
							//接收数据和发送
							$size = 0;
							$data = '';
							socket_recv($socket, $data, $this->send_size, 0);
//							do{
//								$length = socket_recv($socket, $buf, $this->send_size, 0);
//								if($length !== false){
//									$size  += $length;
//									$data  .= $buf;
//								}else{
//									$errno = socket_last_error();
//									$errormsg = socket_strerror($errno);
//									$this->log($errormsg);
//								}
//							}while ($size == $this->send_size);
							
							$userKey = $this->get_client_key($socket);
							if($size != 0){
								$this->logout($userKey);
								continue;
							}else{
								if(!$this->users[$userKey]['receive']){
									$this->receive_data($userKey,$data);
								}else{
									$data = $this->uncode($data,$userKey);
//									if($data==false){
//										continue;
//									}
//									$this->send($userKey,$data);
								}
							}
						}
					}
				}
			}
		}else{
			$this->log("已满员,进入失败");
		}
	}
	/**
	 *
	 *链接
	 */
	private function connect()
	{
		$address= $this->config['domain'];
		$port   = $this->config['port'];
		
		$server = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
		socket_set_option($server, SOL_SOCKET, SO_REUSEADDR, 1);
		socket_bind($server, $address, $port);
		socket_listen($server);
		
		$this->log('服务器启动时间 : '.date('Y-m-d H:i:s'));
		$this->log('监听端口: '.$address.' port '.$port);
		
		return $server;
	}
	/**
	 * 将客户端连接放入用户池和连接池
	 * @param resource $socket
	 */
	private function add_users($socket)
	{
		$client = socket_accept($socket);
		$userKey= $this->create_cd_key();
		
		$this->sockets[] = $client;
		$this->users[$userKey] = array(
			'socket' => $client,
			'receive'=> false
		);
	}
	/**
	 *获取客户端的标识
	 * @param resource $socket
	 */
	private function get_client_key($socket)
	{
		$return = false;
		if($this->users && $socket){
			foreach($this->users as $userKey=>$user){
				if($user['socket'] == $socket){
					$return = $userKey;
				}
			}
		}
		return $return;
	}
	/**
	 *接收数据
	 * @param srting $userKey
	 * @param string $data
	 */
	private function receive_data($userKey,$data)
	{
		$buf  = substr($data,strpos($data,'Sec-WebSocket-Key:')+18);
		$key  = trim(substr($buf,0,strpos($buf,"\r\n")));

		$new_key = base64_encode(sha1($key."258EAFA5-E914-47DA-95CA-C5AB0DC85B11",true));
		
		$new_message  = "HTTP/1.1 101 Switching Protocols\r\n";
		$new_message .= "Upgrade: websocket\r\n";
		$new_message .= "Sec-WebSocket-Version: 13\r\n";
		$new_message .= "Connection: Upgrade\r\n";
		$new_message .= "Sec-WebSocket-Accept: " . $new_key . "\r\n\r\n";
		
		socket_write($this->users[$userKey]['socket'],$new_message,strlen($new_message));
		$this->users[$userKey]['receive'] = true;
	}
	/**
	 * 解密数据
	 * @param string $data
	 * @param string $userKey
	 */
	private function uncode($buffer,$userKey){
		$len = ord($buffer) & 127;
		if ($len === 126) {
            $masks = substr($buffer, 4, 4);
            $data = substr($buffer, 8);
        }
        else if ($len === 127) {
            $masks = substr($buffer, 10, 4);
            $data = substr($buffer, 14);
        }
        else {
            $masks = substr($buffer, 2, 4);
            $data = substr($buffer, 6);
        }
        $decoded = '';
        echo $len;
        var_dump($buffer);
        for ($index = 0; $index < strlen($data); $index++) {
            $decoded .= $data[$index] ^ $masks[$index % 4];
        }
        var_dump($decoded);
	}
	/**
	 *加密数据
	 * @param string $data
	 */
	function code($data){
		// Json编码得到包体
        $body_json_str = json_encode($data);
        // 计算整个包的长度，首部4字节+包体字节数
        $total_length = 4 + strlen($body_json_str);
        // 返回打包的数据
        return pack('H*',$total_length.$body_json_str);
	}
	/**
	 * 转码
	 * @param string $data
	 */
	function ord_hex($data)  {
		$msg = '';
		$l = strlen($data);
		for ($i= 0; $i<$l; $i++) {
			$msg .= dechex(ord($data{$i}));
		}
		return $msg;
	}
	/**
	 * 发送信息
	 * @param string $senduserKey 发送者标识
	 * @param array  $data        发送数据
	 * @param string $userKey     接收者标识,all 所有人
	 */
	private function send($senduserKey,$data,$userKey='all'){
		$data['send_code'] = $userKey;
		$data['code']  = $senduserKey;
		$data['time']  = date('m-d H:i:s');
		$content       = $this->code($data);
		$lenth         = strlen($content);
		
		if($userKey == 'all'){
			//有新加入，更新当前客户端数据
			if($data['type'] == 'in'){
				$data['type']  = 'user_in';
				$data['users'] = $this->get_user_list();
				$welcome       = $this->code($data);
				socket_write($this->users[$senduserKey]['socket'],$welcome,strlen($welcome));
				unset($this->users[$senduserKey]);
			}
			//有新加入，更新所有客户端数据
			foreach($this->users as $v){
				socket_write($v['socket'],$content,$lenth);
			}
		}else{
			//客户端之间交互
			socket_write($this->users[$senduserKey]['socket'],$content,$lenth);
			socket_write($this->users[$userKey]['socket'],$content,$lenth);
		}
	}
	/**
	 *退出
	 * @param string $userKey
	 */
	private function logout($userKey)
	{
		$this->close($userKey);

		$data = array(
			'type' => 'out',
			'nrong'=> $userKey
		);
		$this->send(false, $ar);
	}
	/**
	 *客户端退出后，从连接池清除
	 * @param $userKey
	 */
	private function close($userKey)
	{
		socket_close($this->users[$userKey]['socket']);
		
		unset($this->users[$userKey]);
		$this->sockets = array($this->master);
		
		foreach($this->users as $v){
			$this->sockets[] = $v['socket'];
		}
		$this->log("key:$userKey close");
	}
	/**
	 *获取用户列表
	 */
	private function get_user_list(){
		$data = array();
		foreach($this->users as $k=>$v){
			$data[] = array(
				'code' => $k,
				'name' => $v['name']
			);
		}
		return $data;
	}
	/**
	 *创建cd-key
	 * @param 自定义加密串 $key
	 * @param 非自定义时，取随机加密码串长度 $len
	 * @param 加密方式 $cry
	 */
	private function create_cd_key($key='',$len=16,$cry='md5')
	{
	      //生成随机key
	      $data   = array(
	            'a','b','c','d','e','f','g','h','i','j','k','l','m','n','o','p','q','r','s','t','u','v','w','x','y','z',
	            'A','B','C','D','E','F','G','H','I','J','K','L','M','N','O','P','Q','R','S','T','U','V','W','X','Y','Z',
	            '0','1','2','3','4','5','6','7','8','9'
	      );
	      if($key == ''){
	            $max = count($data) - 1;
	            for($i=0;$i<$len;$i++){
	                  $key .= $data[mt_rand(0, $max)];
	            }
	      }
	      //加密方法
	      $crys = hash_algos();
	      
	      $unique = '';
	      if(in_array($cry, $crys)){
	            //支持的加密处理，md5做唯一字符串,sha1做密码序列
	            $unique = hash($cry,uniqid(mt_rand(),true).$key);
	      }
	      
	      return $unique;
	}
	/**
	 *
	 *日志
	 * @param string $str
	 */
	private function log($str){
		$str=$str."\n";
		echo iconv('utf-8','gbk//IGNORE',$str);
	}
}
