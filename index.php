<?php


$str = <<<str
GET /websocket/websocket.php HTTP/1.1
Host: 192.168.5.67:8000
Connection: Upgrade
Pragma: no-cache
Cache-Control: no-cache
Upgrade: websocket
Origin: http://127.0.0.1
Sec-WebSocket-Version: 13
User-Agent: Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/54.0.2840.71 Safari/537.36
Accept-Encoding: gzip, deflate, sdch
Accept-Language: zh-CN,zh;q=0.8
Sec-WebSocket-Key: vjmDzoCYiocOjS1mKPbckw==
Sec-WebSocket-Extensions: permessage-deflate; client_max_window_bits

"
str;


$headData = explode("\r\n",$str);

$newData  = array();
foreach ($headData as $item){
	if(strpos($item,':')){
		$itemdata = explode(':',$item);
		$newData[$itemdata[0]] = $itemdata[1];
	}
}

var_dump($newData);