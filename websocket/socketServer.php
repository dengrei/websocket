<?php
include 'websocket2.php';
class socketServer extends Websocket
{
	private static $obj = null;
	
	public static function getInstance()
	{
		if(self::$obj === null){
			self::$obj = new socketServer();
		}
		return self::$obj;
	}
	/**
	 *
	 *连接socket服务器
	 * @param $address  监听的IP地址，0.0.0.0表示监听本机上任何地址
	 * @param $port     监听的端口号
	 * @param $debug    debug为调试开关，为true是会记录日志
	 */
	public function bootServer($address = '0.0.0.0',$port, $debug = false)
	{
		  $this->debug        = $debug;
		  $this->serverSocket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
		  socket_set_option($this->serverSocket, SOL_SOCKET, SO_REUSEADDR, 1);
		  socket_set_option($this->serverSocket, SOL_SOCKET, TCP_NODELAY, 1);
		  //socket绑定
		  if (socket_bind($this->serverSocket, $address, $port) === false) {
			    if ($debug) {
			      	echo $this->getLastErrMsg();
			    }
			    return;
		  }
		  //监听开始
		  if (socket_listen($this->serverSocket) === false) {
			    if ($debug) {
			      	echo $this->getLastErrMsg();
			    }
			    return;
		  }
		
		  $this->onstarted($this->serverSocket);
		  $this->lastHealthCheck = time();
		  $this->run();
	}
	/**
	 *
	 * 业务逻辑
	 * @param $socketId
	 */
	public function businessHandler($socketId)
	{
		 $buffer = $this->socketListMap[$socketId]['buffer'];
		 
		 //发送数据
		 $data   = $this->createFrame($buffer, self::FRAME_BIN);
		 
		 foreach($this->socketListMap as $socketId=>$item){
		 	$this->socketSend($socketId, $data);
		 }
	}
	/**
	 *
	 * 输出接收的数据
	 * @param unknown_type $data
	 */
	public function showData($data)
	{
		
	}
	public function removeUnhandshakeConnect()
	{
		
	}
	/**
	 *
	 * 将header信息转换为数组
	 * @param string $headers
	 */
	public function getHeaders($headers)
	{
		$headData = explode("\r\n",$headers);
	
		$newData  = array();
		foreach ($headData as $item){
			if(strpos($item,':')){
				$itemdata = explode(':',$item);
				$newData[$itemdata[0]] = trim($itemdata[1]);
			}
		}
		return $newData;
	}
	/**
	 * 子协议选择
	 *@param $protocols 子协议
	 */
	protected function selectProtocol($protocols)
	{
		$pros = explode(',', $protocols);
		
		return $pros;
	}
	/**
	 * (non-PHPdoc)
	 * @see websocket/Websocket::log()
	 */
	public function log($msg,$show=false)
	{
		if($this->debug || $show){
			//直接输出
			echo "+++++++++++++++++++++++++++++++++++++++\n";
			$msg = iconv('utf-8', 'gbk//IGNORE', $msg);
			echo $msg."\n";
		}else{
			//记录日志文件
			$filename = APP_PATH.'data/log/socket.log.txt';
			$filesize = 0;
			if(file_exists($filename)){
				$filesize = filesize($filename);
			}
			//日志超出指定大小，则删除重新记录
			if($filesize < 63353){
				file_put_contents($filename, $msg,FILE_APPEND);
			}else{
				unlink($filename);
			}
		}
	}
	public function __destruct() {
	  	socket_close($this->serverSocket);
	}
}