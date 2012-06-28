<?php
//根据服务器操作系统，定义目录分隔符常量
if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
	define('SMUSH_OS_SLASH', '\\');
}
else {
    define('SMUSH_OS_SLASH', '/');
}
/*
 *Smushit类定义
 */
class Smushit {
    var $config = array();
    var $debug = false;
    var $dbg = array();
    var $last_status = '';
    var $last_command = '';

    /* 构造
     * 完成一些参数的初始化
     */
    function Smushit($conf = false, $convertGif = null) {
        //读取默认的config.ini文件
        if (!$conf) {
        /*
         * __FILE__为当前PHP脚本所在路径 + 文件名
         * dirname(__FILE__)返回当前PHP脚本所在路径
         * $conf为当前路径下的config文件路径 + 文件名
         */
            $conf = dirname(__FILE__) . SMUSH_OS_SLASH . 'config.ini';
        }
        //获取ini文件的多维数组
        $this->config = @parse_ini_file($conf, true);
        //是否debug
        $this->debug = (strcasecmp($this->config['debug']['enabled'], "yes") == 0);
		    $this->target = $target;

        //this->convertGif默认为true
		if(null === $convertGif) {
			$this->convertGif = (boolean)$this->config['operation']['convert_gif'];
		} else {
			$this->convertGif = (boolean)$convertGif;
		}
		$this->host = $this->config['path']['smush-host'];
    }


    //创建出一个不重复的文件？
    function noDupes($dest) {

        if (strlen($dest) > 256) { // 256 is a cool number, no special reason to picking it, just making
                                   // sure we don't get extremely long filenames
            $dest = dirname($dest) .  SMUSH_OS_SLASH . substr(md5($dest), 0, 8);
        }

        $i = 1;
        $orig = $dest;

        while (file_exists($dest)) {
          $dest = substr_replace($orig, $i++, -4, -4); // -4 is where the extension is, if exists
                                                       // if not a normal extension, what the hell matters
        }
        return $dest;
    }

    /*
     * 优化图片文件，并返回优化前后的数据数组
     */
    function optimize($filename, $output) {
		$this->dest = $output;
        //文件大小
        $src_size = filesize($filename);
        if (!$src_size) {
            return array(
			  'error' => 'Error reading the input file'
			);
        }
        //文件类型
        $type = $this->getType($filename);
		
        // gif animations return one "gif" for every frame
        if (substr($type, 0, 6) === 'gifgif') {
            $type = 'gifgif';
        }
        if ('gif' === $type && false === $this->convertGif) {
          $type = 'gifgif';
        }
        $dest = '';
        /*
         * 分为4个文件类型处理
         * jpg&jpeg、gif&bmp、gifgif、png
         */
        switch ($type) {
            case 'jpg':
            case 'jpeg':
                $dest = $this->jpegtran($filename);
                break;
            case 'gif':
            case 'bmp': // yeah, I know!
              //创建出一个同名png文件
              $png  = $this->toPNG($filename);
              if (!$png) {
                  return array('error' => 'Failed to convert '.$type.' file to png format.');
              }
              //需要创建过度的替代的png图片
              $dest = $this->crush($png, true);
              //？？？？？？？？？？？？？？？？？？？？？？？？？如果将过渡的png文件转换为目标gif文件
				/**
				 * do not delete the mid-file to increase the performance.
                unlink($png);
                rename($dest, $png);
                $dest = $png;
				*/
                break;
                //动态文件
            case 'gifgif':
              //获取颜色值
              $gifColors = $this->getGifInfo($filename);
              //进行图片优化，颜色值大于256时附加颜色值优化
              $dest = (256 < $gifColors) ? $this->gifsicle($filename, true) : $this->gifsicle($filename);
              break;
            case 'png':
              //优化PNG图片
              $dest = $this->crush($filename);
              break;
            case '':
                return array('error'=>'Cannot determine the type, is this an image?');
                break;
            default:
                return array('error'=>'Cannot do anythig about this file type:' . $type);
        }
        //优化后的图片文件大小
        $dest_size = filesize($dest);
        if (!$dest_size) {
            return array('error'=>'Error writing the optimized file');
        }
        //优化的大小百分比
        $percent = 100 * ($src_size - $dest_size) / $src_size;
        $percent = number_format($percent, 2);

        $result = array(
            'src' => $this->host . $filename,
            'src_size' =>  $src_size,
            'dest' => $this->host . $dest,
            'dest_size' => $dest_size,
            'percent' => $percent,
        );

        return $result;
    }

    /*
     * 通过config.ini配置文件中的命令行和传入的参数，拼装出命令行执行，并返回执行的命令结果
     */
    private function exec($command_name, $data) {
      /* 
       * 0 => %test_home%
       * 1 => %yunying%
       */
        $find = array_keys($this->config['path']);
        foreach($find as $k=>$v) {
            $find[$k] = "%$v%";
        }
        //获取配置文件中的命令列表
        $command = $this->config['command'][$command_name];
        //在$command里查找$find中的值，并用$this->config['path']替换
        $command = str_replace($find, array_values($this->config['path']), $command);

        /*
         * 用传入的$data参数来代替命令列表中的缺省占位符
         */
        $find = array_keys($data);
        foreach($find as $k=>$v) {
            $find[$k] = "%$v%";
        }

        //安全处理
        $data = array_map('escapeshellarg', $data);
        $command = str_replace($find, $data, $command);
		

        //error_log($command);
        exec($command, $ret, $status);
        //执行后的状态码、命令行
        $this->last_status = $status;
        $this->last_command = $command;
        //debug模式
        if ($this->debug) {
            $this->dbg[] = array(
                'command' => $command,
                'output' => $ret,
                'return_code' => $status
            );
        }
        if ($status == 1) {
            return -1;
        }
        //返回所有执行输出
        return $ret;
    }
    
    /*
     * 获取文件的类型
     */
    function getType($filename) {
        $ret = $this->exec('identify', array('src' => $filename));
		$retType = "";
		if ($ret !== -1 && !empty($ret[0])) {
			foreach($ret as $retStr) {
			//	$retStr = $ret[0];
      //或者两个空格之间的内容，即可理解为前后空格清除吧
				$beginPos = strpos($retStr, " ");
				$endPos = strpos($retStr, " ", $beginPos + 1);
				$fType = substr($retStr, $beginPos + 1, $endPos - $beginPos - 1);
        //转换为小写
				$retType .= strtolower($fType);
			}
			return $retType;
        }
        return false;
    }

    /*
     * 根据指定的文件创建出新png文件（不是优化）
     */
    function toPNG($filename, $force8 = false) {
      //创建出相关的目录
        $dest = $this->dest;
        if ($dest === -1) {
            return false;
        }
        //目标转换为png文件
        $dest = str_replace(".gif", ".png", $dest);
        //应该为‘topng’
        $exec_which = $force8 ? 'topng8' : 'topng';
        /*
         * 调用exec方法，根据传入的参数，执行topng命令行
         * 根据gif或者bmp文件，创建同名的png新文件
         */
        $ret = $this->exec($exec_which, array(
            'src' => $filename,
            'dest'=> $dest
           )
        );
        if ($ret === -1) {
            return false;
        }
        return $dest;
    }

    /*
     * 优化png图片
     */
    function crush($filename, $already_in_smush = false) {
        $dest = ($already_in_smush) ? $this->noDupes($filename) : $this->dest;
        if ($dest === -1) {
            return false;
        }
        //调用exec方法，根据传入的参数，执行pngcrush命令行，返回处理后的文件
        $ret = $this->exec('pngcrush', array(
            'src' => $filename,
            'dest'=> $dest
           )
        );
        if ($ret === -1) {
            return false;
        }
        return $dest;
    }

	function compress($filename, $rate) {
		$res = $this->exec('compress', array(
			'src' => $filename,
			'dest' => $filename,
			'rate' => $rate
			));
	}

	function crop($filename, $params) {
		$res = $this->exec('crop', array(
			'src' => $filename,
			'dest' => $filename,
			'params' => $params
			));
	}

    /*
     * 处理jpg&jpeg类型文件
     */
    function jpegtran($filename) {
        $dest = $this->dest;
        if ($dest === -1) {
            return false;
        }

      /*
       * 调用exec方法，根据传入的参数，执行convert命令行
       * 创建*.tmp.jpeg的新文件
       */
        $ret = $this->exec('convert', array(
            'src' => $filename,
            'dest'=> $filename . ".tmp.jpeg"
           )
        );
        if ($ret === -1) {
            return false;
        }
      //将新的压缩文件拷贝为目标文件
        $ret = $this->exec('jpegtran', array(
            'src' => $filename . ".tmp.jpeg",
            'dest'=> $dest
           )
        );
        if ($ret === -1) {
            return false;
        }
        return $dest;
    }

    /*
     * 调用exec方法，根据传入的参数，执行gifsicle_reduce_color或者gifsicle命令行
     * 根据getGifInfo方法的返回颜色值决定是否需要优化颜色数
     */
    function gifsicle($filename, $reduceColors = false) {
        $dest = $this->dest;
        if ($dest === -1) {
          return false;
        }
        //根据颜色值来决定调用的命令行
        $cmd = $reduceColors ? "gifsicle_reduce_color" : "gifsicle";

        $ret = $this->exec($cmd, array(
            'src' => $filename,
            'dest'=> $dest
        ));
        if ($ret === -1) {
            return false;
        }
        return $dest;
    }

    function copy($src, $dest) {
        if (file_exists($src)) {
            return copy($src, $dest);
        }
        if (!strstr($src, 'http://') && !strstr($src, 'https://')) {
            return false;
        }
        if (function_exists('curl_init')) {
            $ch = curl_init($src);
            $fp = fopen($dest, 'w');
            curl_setopt($ch, CURLOPT_FILE, $fp);
            curl_setopt($ch, CURLOPT_HEADER, 0);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_USERAGENT, $this->config['env']['ua']);
            curl_exec($ch);
            $mimetype = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            curl_close($ch);
            fclose($fp);
            if (is_null($mimetype) || strncmp($mimetype, "image/", 6) !== 0) {
                // not an image
                if (file_exists($dest)) {
                    unlink($dest);
                }
                return false;
            }
            return file_exists($dest);
        }
        else {
            return copy($src, $dest);
        }
    }

    function getDirectoryListing($dir) {
        if (!is_dir($dir)) {
            return "Not a directory";
        }
        $files = array();
        if ($dh = opendir($dir)) {
            while (($file = readdir($dh)) !== false) {
                if ($file == '.' || $file == '..') continue;
                $file = trim($dir, SMUSH_OS_SLASH)  . SMUSH_OS_SLASH . $file;
                if (is_dir($file)) {
                    continue;
                }
                $files[] = $file;
            }
            closedir($dh);
        }
        return $files;
    }

    function isTheSameImage($file1, $file2)
    {
        $i1 = imagecreatefromstring(file_get_contents($file1));
        $i2 = imagecreatefromstring(file_get_contents($file2));

        $sx1 = imagesx($i1);
        $sy1 = imagesy($i1);
        if ($sx1 != imagesx($i2) || $sy1 != imagesy($i2)) {
            //image geometric size does not match
            return false;
        }

        for ($x = 0; $x < $sx1; $x++) {
            for ($y = 0; $y < $sy1; $y++) {

                $rgb1 = imagecolorat($i1, $x, $y);
                $pix1 = imagecolorsforindex($i1, $rgb1);

                $rgb2 = imagecolorat($i2, $x, $y);
                $pix2 = imagecolorsforindex($i2, $rgb2);

                if ($pix1 != $pix2) {
                    return false;
                }
            }
        }
        return true;
    }
  /*
   * 获取gif图片的颜色值，并返回
   */
	function getGifInfo($gifPic)
	{
		$ret = $this->exec("gifcolors", array(
			"src" => $gifPic,
		));
		$totalColors = 0;
		foreach($ret as $retStr) {
			//$retStr = $ret[0];
			$beginPos = strpos($retStr, "[");
			$endPos = strpos($retStr, "]");
			$colorNum = (int)substr($retStr, $beginPos + 1, $endPos - $beginPos - 1);
			$totalColors += $colorNum;
		}
		return $totalColors;
	}
}

