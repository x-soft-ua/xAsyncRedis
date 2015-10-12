<?php

/**
 * xAsyncRedisConnector v1.0
 *
 * @author Stas Oreshin, orstse@gmail.com
 */

/*
    Example:
        $redisCommands = [];
        $redisCommands[] = array('SET foo bar', array('unix:///tmp/redis.sock'));
        $redisCommands[] = array('GET foo1', array('tcp://127.0.0.1:6380'));
        $ps = new xAsyncRedis();
        $ps->settimeout(1000);
        $ps->setCommands($redisCommands);
        $ps->run();
        $responses = $ps->getresponse());	//get response
*/

class xAsyncRedis {
    
    const STATUS_REPLY = '+';
    const ERROR_REPLY = '-';
    const INTEGER_REPLY = ':';
    const BULK_REPLY = '$';
    const MULTI_BULK_REPLY = '*';

    private $streams = array();
    private $listeners = array();
    private $timeout = 1;	//init timeout
    private $response = array();
    private $microtime_init;
    private $conn_timeout_ms = 1000;
    
    //init constructor
    function __construct($arg = null)
    {
      //$this->microtime_init = microtime(true);
    }

    function clear()
    {
        $this->streams = array();
        $this->listeners = array();
        $this->response = array();
        return true;
    }
    
    /**
     * Redis
     */
    static function _multi_bulk_reply($cmd)
    {
        $tokens = str_getcsv($cmd, ' ', '"');
        $number_of_arguments = count($tokens);
        $multi_bulk_reply = "*$number_of_arguments\r\n";
        foreach ($tokens as $token) $multi_bulk_reply .= self::_bulk_reply($token);
        return $multi_bulk_reply;
    }
    
    static function _bulk_reply($arg)
    {
        return '$'.strlen($arg)."\r\n".$arg."\r\n";
    }
    
    static function _reply($fp, &$return)
    {
        $return = false;
        
        $reply = fgets($fp);
        if (FALSE === $reply)
        {
            $return = 'Error Reading Reply';
            return false;
        }   
        $reply = trim($reply);
        $reply_type = $reply[0];
        $data = substr($reply, 1);
        switch($reply_type)
        {
            case self::STATUS_REPLY:
                if ('ok' == strtolower($data))
                    $return = true;
                else
                    $return = $data;
                return true;
            case self::ERROR_REPLY:
                $return = substr($data, 4);
                return false;
            case self::INTEGER_REPLY:
                $return = $data;
                return true;
            case self::BULK_REPLY:
                $data_length = intval($data);
                if ($data_length < 0)
                    $return = null;
                
                $bulk_reply = stream_get_contents($fp, $data_length + strlen("\r\n"));
                if (FALSE === $bulk_reply)
                {
                    $return = 'Error Reading Bulk Reply';
                    return false;
                }
                $return =  trim($bulk_reply);
                return true;
            case self::MULTI_BULK_REPLY:
                $bulk_reply_count = intval($data);
                if ($bulk_reply_count < 0)
                    $return = null;
                
                $multi_bulk_reply = array();
                for($i = 0; $i < $bulk_reply_count; $i++)
                {
                    if(self::_reply($fp, $b))
                        $multi_bulk_reply[] = $b;
                }
                $return = $multi_bulk_reply;
                return true;
            default:
                $return = "Unknown Reply Type: $reply";
        }
        
        return false;
    }
    
    /**
     * Async connections
     */
    function add($streams)
    {
        if (!is_array($streams) || empty($streams))
                     return false;
                    
        foreach($streams as $offset => $oneStream)
        {
            if (!is_array($oneStream) || empty($oneStream))
                         return false;
                        
            if(count($oneStream) != 3)
                return false;
            
            //@array($s, $callback, $command);
            //@offset, @fp, @callback, @request_arr => (cmd, conn => (host, port))
            $this->addOne($offset, $oneStream[0], $oneStream[1], $oneStream[2]);
        }
    }
  
    /**
     * listening to stream events
     */
    function run()
    {
        while (count($this->streams) > 0)
        {
            $events = $this->streams;
            

            //if timed out?
            if($this->conn_timeout_ms < (microtime(true)-$this->microtime_init))
            {
                $this->processStreamEvents($events, 'Connection Timed Out');
                break;
            }
            else
            {
                //iteration ever 10 ms
                if (false === ($num_changed_streams = stream_select($events, $write, $except, 0, 10000)))
                {
                    return false; //init error, return false
                }
                elseif (count($events))
                {
                    $this->processStreamEvents($events, false); //process event -> read response in array
                }
                else
                {
                    /* until next status check */
                }
            }
        }

        return true;
    }
    
    /**
     * Set Redis commands
     */
    function setCommands($redisCommands)
    {
        //init time
        $this->microtime_init = microtime(true);
        
        //result callback function
        $callback = function ($data, $id, $e = false)
        {
            //on error
            if ($e)
                $this->response[$id] = false;
            //on response
            elseif(!empty($data))
                $this->response[$id] = $data;
            //on empty response
            else
                $this->response[$id] = null;
                
            return;
        };
        
        $streams = array();
        //init streams
        foreach ($redisCommands as $i => $command)
        {
            if(count($command)!=2)
                continue;
            
            $connCmd = self::_multi_bulk_reply($command[0]);
            
            $host = $command[1];
            $s = @stream_socket_client($host, $errno, $errstr, $this->timeout);
            if($s)
            {
                //write redis command
                fwrite($s, $connCmd);
                //add to streams
                $streams[$i] = array($s, $callback, $command);
            }
            else
            {
                call_user_func($callback, array('response' => null, 'err' => 'Stream socket read timeout', 'request'=> $command), $i, true); //gateway timed out
            }
      
        }
        //@fp, @callback, @req_id, @request_arr => (cmd, conn => (host, port))
        $this->add($streams);
    }

    /**
     * Get responses
     */
    function getresponse()
    {
        $result = array();
        foreach($this->response as $id => $thisResponse)
        {
            if(empty($thisResponse) || isset($thisResponse['err']))
                $result[$id] = array(null, true, $thisResponse['err']);
            else
                $result[$id] = array($thisResponse['response'], false, null);
        }
        
        return $result;
    }
    
    /**
     * Set connection time-out
     */
    function settimeout($time)
    {
        if(is_numeric($time) && $time > 0)
        {
            $this->conn_timeout_ms = $time/1000;
            return $this->conn_timeout_ms;
        }
        else
            return false;
    }
    
    
    private function processStreamEvents($events, $errorMsg = '')
    {
    
        if(!empty($errorMsg) )
        {
            foreach ($events as $fp)
            {
                stream_socket_shutdown($fp,STREAM_SHUT_WR);
                $id = array_search($fp, $this->streams);
                $data = [];
                $data['request'] = $this->listeners[$id][2];
                $data['err'] = !empty($errorMsg) ? $errorMsg : '';
                call_user_func($this->listeners[$id][1], $data, $id, true);
                unset($this->streams[$id]);
            }
            return;
        }
        
        
        foreach ($events as $fp)
        {
            $id = array_search($fp, $this->streams);
            
            $this->invokeListener($fp, $id);
            stream_socket_shutdown($fp,STREAM_SHUT_WR); //fclose($fp);
            unset($this->streams[$id]);
        }
    }
    
    private function invokeListener($fp, $id)
    {
        foreach ($this->listeners as $index => $spec)
        {
            if ($spec[0] == $fp)
            {
                $data = array();
                $data['response'] = '';
                $data['request'] = $spec[2]; //insert request into return array
                
                if($this->_reply($fp, $b))
                    $data['response'] = $b;
                else
                {
                    $data['response'] = null;
                    $data['err'] = $b;
                }
                
                call_user_func($spec[1], $data, $index);
                unset($this->listeners[$index]);
                return ;
            }
        }
  }
  
  //@offset, @fp, @callback, @request_arr => (cmd, conn => (host, port))
  private function addOne($offset, $stream, $callback, $request)
  {
        $this->streams[$offset] = $stream;
        $this->listeners[$offset] = array($stream, $callback, $request);
  }
}

class xAsyncRedisException extends RuntimeException {}


?>