<?php
include 'websocket2.php';
class socketServer extends Websocket
{
	/**
	 *
	 *连接socket服务器
	 * @param $port     监听的端口号
	 * @param $address  监听的IP地址，0.0.0.0表示监听本机上任何地址
	 * @param $debug    debug为调试开关，为true是会记录日志
	 */
	function __construct($port, $address = '0.0.0.0', $debug = false) {
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
	function businessHandler($socketId)
	{
		 $buffer = $this->socketListMap[$socketId]['buffer'];
		 $data   = $this->createFrame($buffer, self::FRAME_BIN);
		 
		 $this->socketSend($socketId, $data);
	}
	/**
	 *
	 * 输出接收的数据
	 * @param unknown_type $data
	 */
	function showData($data)
	{
		
	}
	function removeUnhandshakeConnect()
	{
		
	}
	/**
	 *
	 * 将header信息转换为数组
	 * @param string $headers
	 */
	function getHeaders($headers)
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
	function __destruct() {
	  	socket_close($this->serverSocket);
	}
}